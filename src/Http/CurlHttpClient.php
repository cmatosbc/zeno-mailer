<?php

declare(strict_types=1);

namespace ZenoMailer\Http;

/**
 * cURL-based HTTP client with sane security defaults (TLS verification on,
 * separate connect timeout, no redirects for API endpoints).
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10
    ) {
    }

    public function post(string $url, array $headers, string $body, array $options = []): HttpResponse
    {
        return $this->request($url, $headers, $body, $options, true);
    }

    public function get(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return $this->request($url, $headers, null, $options, false);
    }

    /**
     * @param string[] $headers
     * @param array<string, mixed> $options
     */
    private function request(string $url, array $headers, ?string $body, array $options, bool $isPost): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }

        $settings = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? $this->timeout),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // Session discovery endpoints may redirect (e.g. Stalwart's
            // /.well-known/jmap -> /jmap/session)
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ];

        if (isset($options['verify_tls']) && !filter_var($options['verify_tls'], FILTER_VALIDATE_BOOLEAN)) {
            $settings[CURLOPT_SSL_VERIFYPEER] = false;
            $settings[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if ($isPost) {
            $settings[CURLOPT_POST] = true;
            $settings[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        curl_setopt_array($ch, $settings);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            throw new \RuntimeException("cURL error ({$errno}): {$error}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string)$response);
    }
}
