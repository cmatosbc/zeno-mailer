<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use ZenoMailer\Envelope;
use ZenoMailer\Transport\SequentialBatchSending;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;

/**
 * Test transport with scripted behavior: fails (transiently) for the first
 * `failUntil` send() calls, then succeeds. Records attempts.
 */
final class ScriptedTransport implements TransportInterface
{
    use SequentialBatchSending;

    public int $attempts = 0;

    public function __construct(private int $failUntil)
    {
    }

    public function recover(): void
    {
        $this->failUntil = 0;
    }

    public function send(Envelope $envelope): TransportResult
    {
        $this->attempts++;

        return $this->attempts <= $this->failUntil
            ? TransportResult::failure('provider down', [], true)
            : TransportResult::success('sent', 'id-' . $this->attempts);
    }
}
