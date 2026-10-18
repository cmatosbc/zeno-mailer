<?php

declare(strict_types=1);

namespace ZenoMailer\Http;

/**
 * Minimal HTTP client abstraction so transports can be tested with mocks
 * and can later be swapped for PSR-18 implementations.
 */
interface HttpClientInterface
{
    /**
     * @param string[] $headers List of "Name: value" header strings
     * @param array<string, mixed> $options Supported: timeout (int, seconds)
     */
    public function post(string $url, array $headers, string $body, array $options = []): HttpResponse;

    /**
     * @param string[] $headers List of "Name: value" header strings
     * @param array<string, mixed> $options Supported: timeout (int, seconds)
     */
    public function get(string $url, array $headers = [], array $options = []): HttpResponse;
}
