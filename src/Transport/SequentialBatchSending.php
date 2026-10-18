<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

/**
 * Default sendBatch() implementation: sequential sends through send().
 * Transports with native batch endpoints override it (e.g. Brevo's
 * concurrent curl_multi implementation).
 */
trait SequentialBatchSending
{
    /**
     * @param list<Envelope> $envelopes
     * @return list<TransportResult>
     */
    public function sendBatch(array $envelopes): array
    {
        $results = [];
        foreach ($envelopes as $envelope) {
            $results[] = $this->send($envelope);
        }

        return $results;
    }
}
