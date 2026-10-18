<?php

declare(strict_types=1);

namespace ZenoMailer\Http;

/**
 * Immutable HTTP response value object.
 */
final class HttpResponse
{
    public function __construct(
        private readonly int $status,
        private readonly string $body
    ) {
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Decode the body as a JSON object/array, or null when the body is not valid JSON.
     *
     * @return array<mixed>|null
     */
    public function getJson(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
