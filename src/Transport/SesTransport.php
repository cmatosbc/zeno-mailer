<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\DkimSigner;
use ZenoMailer\Http\CurlHttpClient;
use ZenoMailer\Http\HttpClientInterface;
use ZenoMailer\MessageSerializer;

final class SesTransport implements TransportInterface, SignableTransport
{
    private string $accessKey;
    private string $secretKey;
    private string $region;
    /** @var array<string, mixed> */
    private array $options;
    private HttpClientInterface $http;
    private MessageSerializer $serializer;
    private ?DkimSigner $dkimSigner = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $accessKey,
        string $secretKey,
        string $region = 'us-east-1',
        array $options = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->options = $options;
        $this->http = $httpClient ?? new CurlHttpClient((int)($options['timeout'] ?? 30));
        $this->serializer = new MessageSerializer();
    }

    public function send(Envelope $envelope): TransportResult
    {
        try {
            if ($envelope->requiresSmtpUtf8()) {
                // AWS SES does not support SMTPUTF8 (RFC 6531): address strings
                // must be 7-bit ASCII. IDN domains are fine (punycode-converted
                // by Address), UTF-8 local parts are not.
                throw new \InvalidArgumentException(
                    'Amazon SES does not support SMTPUTF8 - addresses with UTF-8 local parts cannot be sent via SES'
                );
            }

            $endpoint = "https://email.{$this->region}.amazonaws.com/";
            $rawMessage = $this->serializer->serialize($envelope);
            if ($this->dkimSigner !== null) {
                $rawMessage = $this->dkimSigner->sign($rawMessage);
            }
            $payload = $this->buildPayload($rawMessage->getMessage(), $envelope);

            $headers = $this->signRequest($payload, $endpoint);

            $response = $this->http->post($endpoint, $headers, $payload, $this->httpOptions());

            if ($response->getStatus() >= 200 && $response->getStatus() < 300) {
                return TransportResult::success(
                    'Email sent via Amazon SES',
                    $this->extractMessageId($response->getBody()),
                    ['response' => $response->getBody()]
                );
            }

            return TransportResult::failure("SES API error: HTTP {$response->getStatus()}", [
                'http_code' => $response->getStatus(),
                'response' => $response->getBody(),
            ]);
        } catch (\Exception $e) {
            return TransportResult::failure($e->getMessage());
        }
    }

    private function buildPayload(string $rawMessage, Envelope $envelope): string
    {
        $params = [
            'Action' => 'SendRawEmail',
            'Source' => $envelope->getFrom()?->getEmail() ?? '',
            'RawMessage.Data' => base64_encode($rawMessage),
        ];

        // Recipients (including Bcc) must be passed explicitly for SendRawEmail,
        // otherwise Bcc recipients would silently never receive the message.
        foreach ($envelope->getAllRecipients() as $index => $recipient) {
            $params['Destinations.member.' . ($index + 1)] = $recipient->getEmail();
        }

        return http_build_query($params);
    }

    public function setDkimSigner(DkimSigner $signer): void
    {
        $this->dkimSigner = $signer;
    }

    /**
     * @return string[]
     */
    private function signRequest(string $payload, string $endpoint): array
    {
        $host = parse_url($endpoint, PHP_URL_HOST) ?: '';
        $date = gmdate('Ymd\THis\Z');

        return SigV4::sign(
            'POST',
            '/',
            '',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Host' => $host,
                'X-Amz-Date' => $date,
            ],
            $payload,
            $this->accessKey,
            $this->secretKey,
            $this->region,
            'ses',
            $date
        );
    }

    private function extractMessageId(string $response): ?string
    {
        if (preg_match('/<MessageId>([^<]+)<\/MessageId>/', $response, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        return ['timeout' => (int)($this->options['timeout'] ?? 30)];
    }
}
