<?php

declare(strict_types=1);

namespace ZenoMailer\Middleware;

use ZenoMailer\Envelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TelemetryMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;
    private bool $logRecipients;
    private bool $logContent;

    public function __construct(
        ?LoggerInterface $logger = null,
        bool $logRecipients = true,
        bool $logContent = false
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->logRecipients = $logRecipients;
        $this->logContent = $logContent;
    }

    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        $startTime = microtime(true);
        
        $this->logBefore($envelope);
        
        try {
            $result = $handler->handle($envelope);
            
            $duration = microtime(true) - $startTime;
            $this->logSuccess($envelope, $duration);
            
            return $result;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $this->logFailure($envelope, $e, $duration);
            
            throw $e;
        }
    }

    private function logBefore(Envelope $envelope): void
    {
        $context = [
            'subject' => $envelope->getSubject(),
            'from' => $envelope->getFrom()?->getEmail(),
        ];

        if ($this->logRecipients) {
            $context['recipients'] = array_map(
                fn($addr) => $addr->getEmail(),
                $envelope->getAllRecipients()
            );
            $context['recipient_count'] = count($envelope->getAllRecipients());
        }

        if ($this->logContent) {
            $context['has_html'] = $envelope->getHtmlBody() !== '';
            $context['has_text'] = $envelope->getTextBody() !== '';
            $context['attachment_count'] = count($envelope->getAttachments());
        }

        $this->logger->info('Processing email', $context);
    }

    private function logSuccess(Envelope $envelope, float $duration): void
    {
        $this->logger->info('Email processed successfully', [
            'subject' => $envelope->getSubject(),
            'duration_ms' => round($duration * 1000, 2),
        ]);
    }

    private function logFailure(Envelope $envelope, \Throwable $e, float $duration): void
    {
        $this->logger->error('Email processing failed', [
            'subject' => $envelope->getSubject(),
            'error' => $e->getMessage(),
            'exception_class' => get_class($e),
            'duration_ms' => round($duration * 1000, 2),
        ]);
    }
}
