<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

/**
 * Queues failed sends in a spool instead of losing them: a failed send()
 * stores the envelope and returns a failure result carrying the spool id.
 * flush() re-sends queued envelopes through the inner transport.
 *
 * Place this layer OUTSIDE the retry layer, so a message is stored once
 * after retries are exhausted (not once per attempt).
 */
final class SpoolTransport implements TransportInterface, ClosableTransport, SpoolCapableTransport
{
    use SequentialBatchSending;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly FileSpool $spool
    ) {
    }

    public function send(Envelope $envelope): TransportResult
    {
        $result = $this->inner->send($envelope);

        if ($result->isSuccess()) {
            return $result;
        }

        $spoolId = $this->spool->store($envelope, $result->getMessage(), 1);

        return TransportResult::failure(
            $result->getMessage() . ' (queued for retry as ' . $spoolId . ')',
            [...$result->getMetadata(), 'spool_id' => $spoolId]
        );
    }

    public function flush(): array
    {
        $results = [];

        foreach ($this->spool->load() as $entry) {
            $result = $this->inner->send($entry['envelope']);

            if ($result->isSuccess()) {
                $this->spool->remove($entry['id']);
            } else {
                // Keep it queued with the updated attempt count
                $this->spool->remove($entry['id']);
                $this->spool->store($entry['envelope'], $result->getMessage(), $entry['attempts'] + 1);
            }

            $results[] = $result;
        }

        return $results;
    }

    public function queuedCount(): int
    {
        return $this->spool->count();
    }

    public function disconnect(): void
    {
        if ($this->inner instanceof ClosableTransport) {
            $this->inner->disconnect();
        }
    }
}
