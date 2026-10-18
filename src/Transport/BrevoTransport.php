<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\Http\CurlHttpClient;
use ZenoMailer\Http\HttpClientInterface;

final class BrevoTransport implements TransportInterface
{
    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    private string $apiKey;
    /** @var array<string, mixed> */
    private array $options;
    private bool $async;
    private string $apiUrl;
    private HttpClientInterface $http;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $apiKey, array $options = [], ?HttpClientInterface $httpClient = null)
    {
        $this->apiKey = $apiKey;
        $this->options = $options;
        $this->async = filter_var($options['async'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->apiUrl = (string)($options['base_url'] ?? self::API_URL);
        $this->http = $httpClient ?? new CurlHttpClient((int)($options['timeout'] ?? 30));
    }

    public function send(Envelope $envelope): TransportResult
    {
        return $this->sendSync($envelope);
    }

    /**
     * Send multiple envelopes concurrently via curl_multi.
     *
     * @param Envelope[] $envelopes
     * @return TransportResult[]
     */
    public function sendBatch(array $envelopes): array
    {
        if (!$this->async || $envelopes === []) {
            return array_map(fn (Envelope $envelope): TransportResult => $this->sendSync($envelope), $envelopes);
        }

        return $this->sendAsync($envelopes);
    }

    private function sendSync(Envelope $envelope): TransportResult
    {
        try {
            $payload = json_encode($this->buildPayload($envelope), JSON_THROW_ON_ERROR);

            $response = $this->http->post($this->apiUrl, $this->headers(), $payload, $this->httpOptions());
            $responseData = $response->getJson();

            if ($response->getStatus() >= 200 && $response->getStatus() < 300) {
                return TransportResult::success(
                    'Email sent via Brevo',
                    is_array($responseData) ? ($responseData['messageId'] ?? null) : null,
                    ['response' => $responseData]
                );
            }

            $errorMessage = is_array($responseData) ? ($responseData['message'] ?? null) : null;
            if ($errorMessage === null) {
                $errorMessage = $this->snippet($response->getBody());
            }

            return TransportResult::failure("Brevo API error: {$errorMessage}", [
                'http_code' => $response->getStatus(),
                'response' => $responseData,
            ], self::isRetryableHttpStatus($response->getStatus()));
        } catch (\JsonException $e) {
            return TransportResult::failure('Brevo payload encoding failed: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return TransportResult::failure($e->getMessage());
        } catch (\Exception $e) {
            // Network-level errors (DNS, connect, timeout) are transient
            return TransportResult::failure($e->getMessage(), [], true);
        }
    }

    /**
     * HTTP 408/429/5xx are transient; other 4xx are permanent.
     */
    private static function isRetryableHttpStatus(int $status): bool
    {
        return $status === 429 || $status === 408 || $status >= 500;
    }

    /**
     * @param Envelope[] $envelopes
     * @return TransportResult[]
     */
    private function sendAsync(array $envelopes): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($envelopes as $index => $envelope) {
            $payload = json_encode($this->buildPayload($envelope), JSON_THROW_ON_ERROR);

            $ch = curl_init($this->apiUrl);
            if ($ch === false) {
                throw new \RuntimeException('Unable to initialize cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $this->headers(),
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => (int)($this->options['timeout'] ?? 30),
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['index' => $index, 'handle' => $ch];
        }

        $running = null;

        do {
            curl_multi_exec($mh, $running);

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $handleId = (int)$ch;

                if (isset($handles[$handleId])) {
                    $results[$handles[$handleId]['index']] = $this->interpretResponse(
                        (string)curl_multi_getcontent($ch),
                        (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                        curl_error($ch)
                    );

                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($handles[$handleId]);
                }
            }

            if ($running > 0) {
                if (curl_multi_select($mh, 1.0) === -1) {
                    usleep(100);
                }
            }
        } while ($running > 0);

        foreach ($handles as $entry) {
            curl_multi_remove_handle($mh, $entry['handle']);
            curl_close($entry['handle']);
        }
        curl_multi_close($mh);

        ksort($results);

        return array_values($results);
    }

    private function interpretResponse(string $response, int $httpCode, string $error): TransportResult
    {
        if ($error !== '') {
            return TransportResult::failure("cURL error: {$error}");
        }

        $responseData = json_decode($response, true);
        $responseData = is_array($responseData) ? $responseData : null;

        if ($httpCode >= 200 && $httpCode < 300) {
            return TransportResult::success(
                'Email sent via Brevo (async)',
                $responseData['messageId'] ?? null,
                ['response' => $responseData]
            );
        }

        $errorMessage = $responseData['message'] ?? $this->snippet($response);

        return TransportResult::failure("Brevo API error: {$errorMessage}", [
            'http_code' => $httpCode,
            'response' => $responseData,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Envelope $envelope): array
    {
        if ($envelope->getEmbeddedImages() !== []) {
            throw new \InvalidArgumentException(
                'Brevo API does not support inline (CID) images - use hosted image URLs in the HTML body instead'
            );
        }

        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

        $sender = ['email' => $from->getEmail()];
        if ($from->getName() !== '') {
            $sender['name'] = $from->getName();
        }

        $payload = [
            'sender' => $sender,
            // Brevo rejects empty-string names ("name is missing") - omit the key instead
            'to' => array_map(static fn ($addr) => $addr->getName() !== ''
                ? ['email' => $addr->getEmail(), 'name' => $addr->getName()]
                : ['email' => $addr->getEmail()], $envelope->getTo()),
            'subject' => $envelope->getSubject(),
        ];

        if (!empty($envelope->getCc())) {
            $payload['cc'] = array_map(static fn ($addr) => $addr->getName() !== ''
                ? ['email' => $addr->getEmail(), 'name' => $addr->getName()]
                : ['email' => $addr->getEmail()], $envelope->getCc());
        }

        if (!empty($envelope->getBcc())) {
            $payload['bcc'] = array_map(static fn ($addr) => $addr->getName() !== ''
                ? ['email' => $addr->getEmail(), 'name' => $addr->getName()]
                : ['email' => $addr->getEmail()], $envelope->getBcc());
        }

        $replyTo = $envelope->getReplyTo();
        if ($replyTo !== null) {
            $payload['replyTo'] = $replyTo->getName() !== ''
                ? ['email' => $replyTo->getEmail(), 'name' => $replyTo->getName()]
                : ['email' => $replyTo->getEmail()];
        }

        if ($envelope->getHtmlBody() !== '') {
            $payload['htmlContent'] = $envelope->getHtmlBody();
        }

        if ($envelope->getTextBody() !== '') {
            $payload['textContent'] = $envelope->getTextBody();
        }

        if (!empty($envelope->getAttachments())) {
            $payload['attachment'] = array_map(static fn ($att) => [
                'name' => $att->getName(),
                'content' => base64_encode($att->getContent()),
            ], $envelope->getAttachments());
        }

        if (!empty($envelope->getHeaders())) {
            $payload['headers'] = $envelope->getHeaders();
        }

        return $payload;
    }

    /**
     * @return string[]
     */
    private function headers(): array
    {
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $this->apiKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        return ['timeout' => (int)($this->options['timeout'] ?? 30)];
    }

    private function snippet(string $body): string
    {
        return mb_substr(trim($body), 0, 200) ?: 'Unknown error';
    }
}
