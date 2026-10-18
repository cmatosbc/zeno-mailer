<?php

declare(strict_types=1);

namespace ZenoMailer;

use ZenoMailer\Middleware\HandlerInterface;
use ZenoMailer\Middleware\MiddlewarePipeline;
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

    public function send(Envelope $envelope): TransportResult
    {
        $processedEnvelope = $this->pipeline->process($envelope);
        
        return $this->transport->send($processedEnvelope);
    }

    public function handle(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
