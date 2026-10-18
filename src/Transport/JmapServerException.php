<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * A JMAP-level error (server response), carrying the response context and
 * whether the failure is transient.
 */
final class JmapServerException extends \RuntimeException
{
    private bool $retryable;

    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $message, array $metadata = [], bool $retryable = false)
    {
        parent::__construct($message);
        $this->metadata = $metadata;
        $this->retryable = $retryable;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
