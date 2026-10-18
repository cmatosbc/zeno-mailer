<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * Transports holding resources (e.g. the SMTP persistent connection) that
 * should be released explicitly. Decorators delegate to the inner transport.
 */
interface ClosableTransport
{
    public function disconnect(): void;
}
