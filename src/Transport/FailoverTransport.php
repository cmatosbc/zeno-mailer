<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

/**
 * Tries transports in order; the first success wins. When all fail, the
 * last failure is returned with every attempt's error in the metadata.
 */
final class FailoverTransport implements TransportInterface, ClosableTransport
{
    use SequentialBatchSending;

    /** @var list<TransportInterface> */
    private array $transports;

    /** @param list<TransportInterface> $transports */
    public function __construct(array $transports)
    {
        if ($transports === []) {
            throw new \InvalidArgumentException('FailoverTransport needs at least one transport');
        }

        $this->transports = $transports;
    }

    public function disconnect(): void
    {
        foreach ($this->transports as $transport) {
            if ($transport instanceof ClosableTransport) {
                $transport->disconnect();
            }
        }
    }

    public function send(Envelope $envelope): TransportResult
    {
        $failures = [];

        foreach ($this->transports as $transport) {
            $result = $transport->send($envelope);

            if ($result->isSuccess()) {
                return $result;
            }

            $failures[] = $result->getMessage();
        }

        $last = $failures[count($failures) - 1];

        return TransportResult::failure(
            sprintf('All %d transports failed. Last error: %s', count($failures), $last),
            ['attempts' => $failures]
        );
    }
}
