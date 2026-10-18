<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * Thrown when the SMTP server rejects a command; carries the numeric
 * reply code so failures can be classified (4xx transient, 5xx permanent).
 */
final class SmtpReplyException extends \RuntimeException
{
    private readonly int $replyCode;

    public function __construct(string $what, string $response)
    {
        $this->replyCode = (int)substr($response, 0, 3);

        parent::__construct("{$what} failed: {$response}");
    }

    public function getReplyCode(): int
    {
        return $this->replyCode;
    }

    /**
     * SMTP 4xx replies are transient (RFC 5321 4.2.1); 5xx are permanent.
     */
    public function isTransient(): bool
    {
        return $this->replyCode >= 400 && $this->replyCode < 500;
    }
}
