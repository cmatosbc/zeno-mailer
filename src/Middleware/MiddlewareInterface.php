<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;

interface MiddlewareInterface
{
    public function process(Envelope $envelope, HandlerInterface $handler): Envelope;
}
