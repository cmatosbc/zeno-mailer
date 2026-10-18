<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * Token-bucket rate limiter. Blocks acquire() until a token is available,
 * refilling continuously at maxPerPeriod / periodSeconds tokens per second.
 */
final class RateLimiter
{
    private float $tokens;
    private float $lastRefill;

    public function __construct(
        private readonly int $maxPerPeriod,
        private readonly float $periodSeconds
    ) {
        if ($maxPerPeriod < 1 || $periodSeconds <= 0) {
            throw new \InvalidArgumentException('Rate limit must be at least 1 per a positive period');
        }

        $this->tokens = (float)$maxPerPeriod;
        $this->lastRefill = microtime(true);
    }

    /**
     * Blocks until one token has been consumed.
     */
    public function acquire(): void
    {
        while (true) {
            $now = microtime(true);
            $elapsed = $now - $this->lastRefill;
            $this->tokens = min(
                (float)$this->maxPerPeriod,
                $this->tokens + $elapsed * ($this->maxPerPeriod / $this->periodSeconds)
            );
            $this->lastRefill = $now;

            if ($this->tokens >= 1.0) {
                $this->tokens -= 1.0;

                return;
            }

            $waitSeconds = (1.0 - $this->tokens) * ($this->periodSeconds / $this->maxPerPeriod);
            usleep((int)max(1_000, $waitSeconds * 1_000_000));
        }
    }
}
