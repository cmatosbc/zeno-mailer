<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

/**
 * Blocks (rate-limits) every send through a token bucket.
 * Note: applies to send(); batch methods on the inner transport bypass it.
 */
final class RateLimitedTransport implements TransportInterface, ClosableTransport
{
    use SequentialBatchSending;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly RateLimiter $limiter
    ) {
    }

    public function disconnect(): void
    {
        if ($this->inner instanceof ClosableTransport) {
            $this->inner->disconnect();
        }
    }

    public function send(Envelope $envelope): TransportResult
    {
        $this->limiter->acquire();

        return $this->inner->send($envelope);
    }
}
