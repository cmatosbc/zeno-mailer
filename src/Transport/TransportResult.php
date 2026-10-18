<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

final class TransportResult
{
    private bool $success;
    private string $message;
    private ?string $messageId;
    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        bool $success,
        string $message = '',
        ?string $messageId = null,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->messageId = $messageId;
        $this->metadata = $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(string $message = 'Email sent successfully', ?string $messageId = null, array $metadata = []): self
    {
        return new self(true, $message, $messageId, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(string $message, array $metadata = []): self
    {
        return new self(false, $message, null, $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
