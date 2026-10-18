<?php

declare(strict_types=1);

namespace ZenoMailer;

use ZenoMailer\Middleware\HandlerInterface;
use ZenoMailer\Middleware\MiddlewarePipeline;
use ZenoMailer\Transport\ClosableTransport;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;

final class Mailer implements HandlerInterface
{
    private TransportInterface $transport;
    private MiddlewarePipeline $pipeline;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
        $this->pipeline = new MiddlewarePipeline();
        $this->pipeline->setFallbackHandler($this);
    }

    public function getPipeline(): MiddlewarePipeline
    {
        return $this->pipeline;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Close transport-held resources (e.g. the SMTP persistent connection)
     * through any decorator layers.
     */
    public function disconnect(): void
    {
        if ($this->transport instanceof ClosableTransport) {
            $this->transport->disconnect();
        }
    }

    public function send(Envelope $envelope): TransportResult
    {
        $processedEnvelope = $this->pipeline->process($envelope);

        return $this->transport->send($processedEnvelope);
    }

    /**
     * Run every envelope through the middleware pipeline, then hand the
     * processed batch to the transport (which may deliver it concurrently).
     *
     * @param list<Envelope> $envelopes
     * @return list<TransportResult> Results in the same order as the envelopes
     */
    public function sendBatch(array $envelopes): array
    {
        $processed = array_map(fn (Envelope $envelope): Envelope => $this->pipeline->process($envelope), $envelopes);

        return $this->transport->sendBatch($processed);
    }

    public function handle(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
