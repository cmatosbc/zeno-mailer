<?php

declare(strict_types=1);

namespace ZenoMailer;

use ZenoMailer\DkimSigner;
use ZenoMailer\Middleware\CssInlinerMiddleware;
use ZenoMailer\Middleware\DarkModeGuardMiddleware;
use ZenoMailer\Middleware\MiddlewareInterface;
use ZenoMailer\Transport\FailoverTransport;
use ZenoMailer\Transport\RateLimitedTransport;
use ZenoMailer\Transport\RateLimiter;
use ZenoMailer\Transport\RetryTransport;
use ZenoMailer\Transport\SignableTransport;
use ZenoMailer\Transport\TelemetryTransport;
use ZenoMailer\Transport\TransportFactory;
use ZenoMailer\Transport\TransportInterface;
use Psr\Log\LoggerInterface;

final class MailerBuilder
{
    private ?TransportInterface $transport = null;
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];
    private bool $enableCssInlining = false;
    private bool $enableDarkModeGuard = false;
    private bool $enableTelemetry = false;
    private ?LoggerInterface $logger = null;
    private bool $telemetryLogRecipients = true;
    private bool $telemetryLogContent = false;
    private ?DkimSigner $dkimSigner = null;
    /** @var list<TransportInterface> */
    private array $failovers = [];
    private ?RateLimiter $rateLimiter = null;
    /** @var array{0: int, 1: float, 2: float}|null */
    private ?array $retryConfig = null;

    public static function create(): self
    {
        return new self();
    }

    public function withTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    public function withDsn(string $dsn): self
    {
        $this->transport = TransportFactory::fromDsn($dsn);
        return $this;
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function enableCssInlining(bool $enable = true): self
    {
        $this->enableCssInlining = $enable;
        return $this;
    }

    public function enableDarkModeGuard(bool $enable = true): self
    {
        $this->enableDarkModeGuard = $enable;
        return $this;
    }

    public function enableTelemetry(
        bool $enable = true,
        ?LoggerInterface $logger = null,
        bool $logRecipients = true,
        bool $logContent = false
    ): self {
        $this->enableTelemetry = $enable;
        $this->logger = $logger;
        $this->telemetryLogRecipients = $logRecipients;
        $this->telemetryLogContent = $logContent;
        return $this;
    }

    /**
     * DKIM-sign outgoing messages (RFC 6376, relaxed/relaxed, rsa-sha256).
     * Supported by the SMTP and SES transports.
     *
     * @param string $privateKey PEM string or path to a PEM file
     * @param array<string, mixed> $options Supported: passphrase (string)
     */
    public function withDkim(string $domain, string $selector, string $privateKey, array $options = []): self
    {
        $this->dkimSigner = new DkimSigner($domain, $selector, $privateKey, $options);
        return $this;
    }

    /**
     * Retry transient failures (SMTP 4xx, HTTP 429/5xx, network errors)
     * with exponential backoff and jitter.
     *
     * @param int $maxAttempts Total attempts (including the first)
     * @param float $baseDelayMs Delay before the first retry
     * @param float $maxDelayMs Backoff ceiling
     */
    public function withRetry(int $maxAttempts = 3, float $baseDelayMs = 500.0, float $maxDelayMs = 5000.0): self
    {
        $this->retryConfig = [$maxAttempts, $baseDelayMs, $maxDelayMs];
        return $this;
    }

    /**
     * Throttle sends with a token bucket, e.g. withRateLimit(300, 'day')
     * for Brevo's free tier, or withRateLimit(60) for 60/minute.
     *
     * @param string|float $period 'second', 'minute', 'hour', 'day' or seconds
     */
    public function withRateLimit(int $maxPerPeriod, string|float $period = 'minute'): self
    {
        $seconds = match (true) {
            is_float($period) => $period,
            $period === 'second' => 1.0,
            $period === 'minute' => 60.0,
            $period === 'hour' => 3600.0,
            $period === 'day' => 86400.0,
            default => throw new \InvalidArgumentException(
                "Invalid rate limit period: {$period} (use 'second', 'minute', 'hour', 'day' or a float of seconds)"
            ),
        };

        $this->rateLimiter = new RateLimiter($maxPerPeriod, $seconds);
        return $this;
    }

    /**
     * Add a backup transport: when the primary (and any earlier backups)
     * fail, the next candidate is tried. Each candidate gets the retry and
     * rate-limit configuration.
     */
    public function withFailover(TransportInterface $transport): self
    {
        $this->failovers[] = $transport;
        return $this;
    }

    public function build(): Mailer
    {
        if ($this->transport === null) {
            throw new \RuntimeException('Transport must be configured before building Mailer');
        }

        // DKIM applies to the raw primary transport, before resilience wrapping
        if ($this->dkimSigner !== null) {
            if (!$this->transport instanceof SignableTransport) {
                throw new \RuntimeException(
                    'Transport ' . get_class($this->transport) . ' does not support DKIM signing'
                );
            }
            $this->transport->setDkimSigner($this->dkimSigner);
        }

        // Resilience: rate limit inside retry (every attempt consumes budget),
        // failover outermost (each candidate gets the full resilience stack).
        $candidates = [$this->transport, ...$this->failovers];
        $wrapped = array_map(fn (TransportInterface $transport): TransportInterface => $this->wrapResilience($transport), $candidates);
        $finalTransport = count($wrapped) > 1 ? new FailoverTransport($wrapped) : $wrapped[0];

        $mailer = new Mailer($finalTransport);
        $pipeline = $mailer->getPipeline();

        if ($this->enableCssInlining) {
            $pipeline->pipe(new CssInlinerMiddleware());
        }

        if ($this->enableDarkModeGuard) {
            $pipeline->pipe(new DarkModeGuardMiddleware());
        }

        foreach ($this->middleware as $middleware) {
            $pipeline->pipe($middleware);
        }

        return $mailer;
    }

    private function wrapResilience(TransportInterface $transport): TransportInterface
    {
        if ($this->rateLimiter !== null) {
            $transport = new RateLimitedTransport($transport, $this->rateLimiter);
        }

        if ($this->retryConfig !== null) {
            [$attempts, $baseDelayMs, $maxDelayMs] = $this->retryConfig;
            $transport = new RetryTransport($transport, $attempts, $baseDelayMs, $maxDelayMs, $this->logger);
        }

        if ($this->enableTelemetry) {
            $transport = new TelemetryTransport($transport, $this->logger, $this->telemetryLogRecipients, $this->telemetryLogContent);
        }

        return $transport;
    }
}
