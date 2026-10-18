<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Retries transient failures (as classified by TransportResult::isRetryable())
 * with exponential backoff and full jitter.
 */
final class RetryTransport implements TransportInterface, ClosableTransport
{
    use SequentialBatchSending;

    private LoggerInterface $logger;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly float $baseDelayMs = 500.0,
        private readonly float $maxDelayMs = 5000.0,
        ?LoggerInterface $logger = null
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1');
        }

        $this->logger = $logger ?? new NullLogger();
    }

    public function disconnect(): void
    {
        if ($this->inner instanceof ClosableTransport) {
            $this->inner->disconnect();
        }
    }

    public function send(Envelope $envelope): TransportResult
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $this->inner->send($envelope);

            if ($result->isSuccess() || !$result->isRetryable() || $attempt >= $this->maxAttempts) {
                if (!$result->isSuccess() && $attempt > 1) {
                    $this->logger->warning('Send failed after retries', [
                        'attempts' => $attempt,
                        'error' => $result->getMessage(),
                    ]);
                }

                return $result;
            }

            $delayMs = $this->backoffDelayMs($attempt);
            $this->logger->info('Retrying transient failure', [
                'attempt' => $attempt,
                'delay_ms' => round($delayMs, 1),
                'error' => $result->getMessage(),
            ]);

            usleep((int)($delayMs * 1000));
        }
    }

    /**
     * Exponential backoff with full jitter: a random delay in
     * [0, min(maxDelay, baseDelay * 2^(attempt-1))].
     */
    public function backoffDelayMs(int $attempt): float
    {
        $ceiling = min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($attempt - 1)));

        return mt_rand() / mt_getrandmax() * $ceiling;
    }
}
