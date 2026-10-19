<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * Implemented by transports (or decorator chains) that hold a spool of
 * failed messages which can be re-sent later.
 */
interface SpoolCapableTransport
{
    /**
     * Re-send every queued envelope through the inner transport; successful
     * entries are removed, failures stay queued with an incremented attempt
     * count.
     *
     * @return list<TransportResult> One result per queued envelope
     */
    public function flush(): array;

    public function queuedCount(): int;
}
