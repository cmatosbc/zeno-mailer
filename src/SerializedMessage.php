<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * Immutable result of serializing an Envelope into an RFC 5322 message.
 */
final class SerializedMessage
{
    public function __construct(
        private readonly string $message,
        private readonly string $messageId
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
