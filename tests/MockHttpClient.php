<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use ZenoMailer\Http\HttpClientInterface;
use ZenoMailer\Http\HttpResponse;

/**
 * Records every request and replays queued responses (or throws) in order.
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: string[], body: ?string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $responses;

    public function __construct(HttpResponse|\Throwable ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function post(string $url, array $headers, string $body, array $options = []): HttpResponse
    {
        return $this->record('POST', $url, $headers, $body, $options);
    }

    public function get(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return $this->record('GET', $url, $headers, null, $options);
    }

    /**
     * @param string[] $headers
     * @param array<string, mixed> $options
     */
    private function record(string $method, string $url, array $headers, ?string $body, array $options): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'options' => $options,
        ];

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new \RuntimeException('MockHttpClient ran out of queued responses');
        }

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
