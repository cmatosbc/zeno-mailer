<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\Http\CurlHttpClient;
use ZenoMailer\Http\HttpClientInterface;

final class PostmarkTransport implements TransportInterface
{
    private const API_URL = 'https://api.postmarkapp.com/email';

    private string $serverToken;
    /** @var array<string, mixed> */
    private array $options;
    private HttpClientInterface $http;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $serverToken, array $options = [], ?HttpClientInterface $httpClient = null)
    {
        $this->serverToken = $serverToken;
        $this->options = $options;
        $this->http = $httpClient ?? new CurlHttpClient((int)($options['timeout'] ?? 30));
    }

    public function send(Envelope $envelope): TransportResult
    {
        try {
            $payload = json_encode($this->buildPayload($envelope), JSON_THROW_ON_ERROR);

            $response = $this->http->post(self::API_URL, $this->headers(), $payload, $this->httpOptions());
            $responseData = $response->getJson();

            if ($response->getStatus() >= 200 && $response->getStatus() < 300) {
                return TransportResult::success(
                    'Email sent via Postmark',
                    is_array($responseData) ? ($responseData['MessageID'] ?? null) : null,
                    ['response' => $responseData]
                );
            }

            $errorMessage = is_array($responseData) ? ($responseData['Message'] ?? null) : null;
            if ($errorMessage === null) {
                $errorMessage = mb_substr(trim($response->getBody()), 0, 200) ?: 'Unknown error';
            }

            return TransportResult::failure("Postmark API error: {$errorMessage}", [
                'http_code' => $response->getStatus(),
                'response' => $responseData,
            ], $response->getStatus() === 429 || $response->getStatus() === 408 || $response->getStatus() >= 500);
        } catch (\JsonException $e) {
            return TransportResult::failure('Postmark payload encoding failed: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return TransportResult::failure($e->getMessage());
        } catch (\Exception $e) {
            // Network-level errors (DNS, connect, timeout) are transient
            return TransportResult::failure($e->getMessage(), [], true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Envelope $envelope): array
    {
        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

        if ($envelope->getTo() === []) {
            throw new \InvalidArgumentException('Envelope must have at least one To recipient for Postmark');
        }

        $payload = [
            'From' => $from->toString(),
            'To' => implode(', ', array_map(static fn ($addr) => $addr->toString(), $envelope->getTo())),
            'Subject' => $envelope->getSubject(),
        ];

        if (!empty($envelope->getCc())) {
            $payload['Cc'] = implode(', ', array_map(static fn ($addr) => $addr->toString(), $envelope->getCc()));
        }

        if (!empty($envelope->getBcc())) {
            $payload['Bcc'] = implode(', ', array_map(static fn ($addr) => $addr->toString(), $envelope->getBcc()));
        }

        $replyTo = $envelope->getReplyTo();
        if ($replyTo !== null) {
            $payload['ReplyTo'] = $replyTo->toString();
        }

        if ($envelope->getHtmlBody() !== '') {
            $payload['HtmlBody'] = $envelope->getHtmlBody();
        }

        if ($envelope->getTextBody() !== '') {
            $payload['TextBody'] = $envelope->getTextBody();
        }

        if (!empty($envelope->getAttachments())) {
            $payload['Attachments'] = array_map(static fn ($att) => [
                'Name' => $att->getName(),
                'Content' => base64_encode($att->getContent()),
                'ContentType' => $att->getType(),
            ], $envelope->getAttachments());
        }

        if (!empty($envelope->getEmbeddedImages())) {
            $inline = array_map(static fn ($img) => [
                'Name' => $img->getName(),
                'Content' => base64_encode($img->getContent()),
                'ContentType' => $img->getType(),
                'ContentID' => $img->getCid(),
            ], $envelope->getEmbeddedImages());

            $payload['Attachments'] = array_merge($payload['Attachments'] ?? [], $inline);
        }

        if (!empty($envelope->getHeaders())) {
            $payload['Headers'] = array_map(
                static fn ($name, $value) => ['Name' => $name, 'Value' => $value],
                array_keys($envelope->getHeaders()),
                $envelope->getHeaders()
            );
        }

        if (isset($this->options['track_opens'])) {
            $payload['TrackOpens'] = filter_var($this->options['track_opens'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($this->options['track_links'])) {
            $payload['TrackLinks'] = $this->options['track_links'];
        }

        if (isset($this->options['message_stream'])) {
            $payload['MessageStream'] = (string)$this->options['message_stream'];
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
            'X-Postmark-Server-Token: ' . $this->serverToken,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        return ['timeout' => (int)($this->options['timeout'] ?? 30)];
    }
}
