<?php

declare(strict_types=1);

namespace ZenoMailer;

use ZenoMailer\DkimSigner;
use ZenoMailer\Middleware\CssInlinerMiddleware;
use ZenoMailer\Middleware\DarkModeGuardMiddleware;
use ZenoMailer\Middleware\MiddlewareInterface;
use ZenoMailer\Middleware\TelemetryMiddleware;
use ZenoMailer\Transport\SignableTransport;
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
    private ?DkimSigner $dkimSigner = null;

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

    public function enableTelemetry(bool $enable = true, ?LoggerInterface $logger = null): self
    {
        $this->enableTelemetry = $enable;
        $this->logger = $logger;
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

    public function build(): Mailer
    {
        if ($this->transport === null) {
            throw new \RuntimeException('Transport must be configured before building Mailer');
        }

        $mailer = new Mailer($this->transport);
        $pipeline = $mailer->getPipeline();

        if ($this->dkimSigner !== null) {
            if (!$this->transport instanceof SignableTransport) {
                throw new \RuntimeException(
                    'Transport ' . get_class($this->transport) . ' does not support DKIM signing'
                );
            }
            $this->transport->setDkimSigner($this->dkimSigner);
        }

        if ($this->enableTelemetry) {
            $pipeline->pipe(new TelemetryMiddleware($this->logger));
        }

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
}
