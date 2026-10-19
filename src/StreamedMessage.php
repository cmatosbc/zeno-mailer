<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * A message built on a stream (php://temp) instead of a single string —
 * the memory-safe path for messages with large attachments. The stream is
 * readable and rewound; attachment content is streamed from disk on demand.
 */
final class StreamedMessage
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream Readable stream positioned at the start
     */
    public function __construct($stream, private readonly string $messageId)
    {
        $this->stream = $stream;
    }

    /**
     * @return resource Readable stream positioned at the start
     */
    public function getStream()
    {
        rewind($this->stream);

        return $this->stream;
    }

    /**
     * Materialize the whole message as a string (same memory cost as the
     * non-streamed path — used by transports that need the full bytes,
     * e.g. SES's base64 payload, and by tests).
     */
    public function getMessage(): string
    {
        $stream = $this->getStream();
        $content = stream_get_contents($stream);

        return $content === false ? '' : $content;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
