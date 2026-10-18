<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;

interface HandlerInterface
{
    public function handle(Envelope $envelope): Envelope;
}
