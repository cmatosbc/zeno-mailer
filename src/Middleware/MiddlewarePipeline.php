<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;

final class MiddlewarePipeline implements HandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];
    private ?HandlerInterface $fallbackHandler = null;

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function setFallbackHandler(HandlerInterface $handler): self
    {
        $this->fallbackHandler = $handler;
        return $this;
    }

    public function handle(Envelope $envelope): Envelope
    {
        $middleware = array_shift($this->middleware);
        if ($middleware === null) {
            return $this->fallbackHandler?->handle($envelope) ?? $envelope;
        }

        return $middleware->process($envelope, $this);
    }

    public function process(Envelope $envelope): Envelope
    {
        $pipeline = clone $this;
        return $pipeline->handle($envelope);
    }
}
