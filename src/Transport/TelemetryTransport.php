<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Delivery telemetry: wraps a transport and logs what actually matters in
 * production - the delivery outcome, message id, duration and retry counts.
 *
 * This is a transport decorator (not pipeline middleware) so it can observe
 * the TransportResult, which pipeline middleware structurally cannot.
 */
final class TelemetryTransport implements TransportInterface, ClosableTransport, SpoolCapableTransport
{
    use SequentialBatchSending;

    private TransportInterface $inner;
    private LoggerInterface $logger;
    private bool $logRecipients;
    private bool $logContent;

    public function __construct(
        TransportInterface $inner,
        ?LoggerInterface $logger = null,
        bool $logRecipients = true,
        bool $logContent = false
    ) {
        $this->inner = $inner;
        $this->logger = $logger ?? new NullLogger();
        $this->logRecipients = $logRecipients;
        $this->logContent = $logContent;
    }

    public function disconnect(): void
    {
        if ($this->inner instanceof ClosableTransport) {
            $this->inner->disconnect();
        }
    }

    public function flush(): array
    {
        if ($this->inner instanceof SpoolCapableTransport) {
            return $this->inner->flush();
        }

        return [];
    }

    public function queuedCount(): int
    {
        return $this->inner instanceof SpoolCapableTransport ? $this->inner->queuedCount() : 0;
    }

    public function send(Envelope $envelope): TransportResult
    {
        $startTime = microtime(true);

        $this->logger->info('Sending email', $this->envelopeContext($envelope));

        try {
            $result = $this->inner->send($envelope);
        } catch (\Throwable $e) {
            $this->logger->error('Delivery threw an exception', [
                ...$this->envelopeContext($envelope),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $attempts = $result->getMetadata()['attempts'] ?? null;

        if ($result->isSuccess()) {
            $this->logger->info('Email delivered', [
                ...$this->envelopeContext($envelope),
                'duration_ms' => $durationMs,
                'message_id' => $result->getMessageId(),
                'transport' => get_class($this->inner),
                ...($attempts !== null ? ['attempts' => $attempts] : []),
            ]);
        } else {
            $this->logger->error('Delivery failed', [
                ...$this->envelopeContext($envelope),
                'duration_ms' => $durationMs,
                'error' => $result->getMessage(),
                'retryable' => $result->isRetryable(),
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeContext(Envelope $envelope): array
    {
        $context = [
            'subject' => $envelope->getSubject(),
            'from' => $envelope->getFrom()?->getEmail(),
        ];

        if ($this->logRecipients) {
            $context['recipients'] = array_map(
                static fn ($addr) => $addr->getEmail(),
                $envelope->getAllRecipients()
            );
        }

        if ($this->logContent) {
            $context['has_html'] = $envelope->getHtmlBody() !== '';
            $context['has_text'] = $envelope->getTextBody() !== '';
            $context['attachment_count'] = count($envelope->getAttachments());
        }

        return $context;
    }
}
