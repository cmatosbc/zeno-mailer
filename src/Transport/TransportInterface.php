<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

interface TransportInterface
{
    public function send(Envelope $envelope): TransportResult;
}
