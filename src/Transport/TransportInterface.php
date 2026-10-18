<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

interface TransportInterface
{
    public function send(Envelope $envelope): TransportResult;

    /**
     * Send a batch of envelopes.
     *
     * @param list<Envelope> $envelopes
     * @return list<TransportResult> Results in the same order as the envelopes
     */
    public function sendBatch(array $envelopes): array;
}
