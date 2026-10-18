<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\Http\CurlHttpClient;
use ZenoMailer\Http\HttpClientInterface;
use ZenoMailer\Http\HttpResponse;

final class JmapTransport implements TransportInterface
{
    private string $host;
    private string $accountId;
    private string $apiToken;
    /** @var array<string, mixed> */
    private array $options;
    private HttpClientInterface $http;
    /** @var array<string, mixed>|null */
    private ?array $session = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $host,
        string $accountId,
        string $apiToken,
        array $options = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->host = $host;
        $this->accountId = $accountId;
        $this->apiToken = $apiToken;
        $this->options = $options;
        $this->http = $httpClient ?? new CurlHttpClient((int)($options['timeout'] ?? 30));
    }

    /**
     * Authorization header for JMAP requests: Bearer by default, or Basic
     * (username:password) for self-hosted servers that use account
     * credentials instead of API tokens.
     */
    private function authHeader(): string
    {
        if (($this->options['auth'] ?? 'bearer') === 'basic') {
            return 'Authorization: Basic ' . base64_encode($this->accountId . ':' . $this->apiToken);
        }

        return 'Authorization: Bearer ' . $this->apiToken;
    }

    public function send(Envelope $envelope): TransportResult
    {
        try {
            if ($envelope->getAttachments() !== []) {
                throw new \InvalidArgumentException(
                    'JMAP transport does not support attachments yet (requires blob upload)'
                );
            }

            $session = $this->getSession();
            $emailSubmissionUrl = $session['apiUrl']
                ?? throw new \RuntimeException('Invalid JMAP session response: missing apiUrl');

            $payload = json_encode($this->buildJmapPayload($envelope, $session), JSON_THROW_ON_ERROR);

            $response = $this->http->post(
                $emailSubmissionUrl,
                ['Content-Type: application/json', $this->authHeader()],
                $payload,
                $this->httpOptions()
            );

            if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
                $responseData = $response->getJson();
                $errorMessage = is_array($responseData) ? ($responseData['type'] ?? null) : null;
                $errorMessage ??= mb_substr(trim($response->getBody()), 0, 200) ?: 'Unknown error';

                return TransportResult::failure("JMAP error: {$errorMessage}", [
                    'http_code' => $response->getStatus(),
                    'response' => $responseData,
                ]);
            }

            return $this->interpretSubmissionResponse($response);
        } catch (\JsonException $e) {
            return TransportResult::failure('JMAP payload encoding failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            return TransportResult::failure($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed> Cached JMAP session
     */
    private function getSession(): array
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $sessionUrl = rtrim($this->host, '/') . '/.well-known/jmap';

        $response = $this->http->get(
            $sessionUrl,
            [$this->authHeader()],
            $this->httpOptions()
        );

        if ($response->getStatus() !== 200) {
            throw new \RuntimeException("Failed to get JMAP session: HTTP {$response->getStatus()}");
        }

        $session = $response->getJson();
        if ($session === null || !isset($session['apiUrl'])) {
            throw new \RuntimeException('Invalid JMAP session response');
        }

        $this->session = $session;

        return $session;
    }

    private function interpretSubmissionResponse(HttpResponse $response): TransportResult
    {
        $responseData = $response->getJson();
        $methodResponses = is_array($responseData) ? ($responseData['methodResponses'] ?? []) : [];

        if (!is_array($methodResponses)) {
            return TransportResult::failure('JMAP error: unexpected response structure', [
                'http_code' => $response->getStatus(),
                'response' => $responseData,
            ]);
        }

        foreach ($methodResponses as $methodResponse) {
            if (!is_array($methodResponse) || ($methodResponse[0] ?? null) !== 'EmailSubmission/set') {
                continue;
            }

            $arguments = $methodResponse[1] ?? [];
            if (!is_array($arguments)) {
                continue;
            }

            $notCreated = $arguments['notCreated'] ?? [];
            if (is_array($notCreated) && $notCreated !== []) {
                $first = reset($notCreated);
                $description = is_array($first) ? ($first['description'] ?? 'submission rejected') : 'submission rejected';

                return TransportResult::failure("JMAP error: {$description}", [
                    'http_code' => $response->getStatus(),
                    'response' => $responseData,
                ]);
            }

            $created = $arguments['created'] ?? [];
            if (is_array($created)) {
                foreach ($created as $submission) {
                    if (is_array($submission) && isset($submission['id'])) {
                        return TransportResult::success('Email sent via JMAP', (string)$submission['id'], [
                            'response' => $responseData,
                        ]);
                    }
                }
            }
        }

        return TransportResult::failure('JMAP error: no EmailSubmission/set response found', [
            'http_code' => $response->getStatus(),
            'response' => $responseData,
        ]);
    }

    /**
     * Resolve the JMAP account id: the configured one, or auto-discover the
     * server's primary mail account from the session (useful when the id is
     * an opaque server-generated string).
     *
     * @param array<string, mixed> $session
     */
    private function resolveAccountId(array $session): string
    {
        if ($this->accountId !== '') {
            return $this->accountId;
        }

        $primaryAccounts = $session['primaryAccounts'] ?? [];
        if (is_array($primaryAccounts) && isset($primaryAccounts['urn:ietf:params:jmap:mail']) && is_string($primaryAccounts['urn:ietf:params:jmap:mail'])) {
            return $primaryAccounts['urn:ietf:params:jmap:mail'];
        }

        $accounts = $session['accounts'] ?? [];
        if (is_array($accounts) && $accounts !== []) {
            $keys = array_keys($accounts);
            $first = $keys[0];
            if (is_string($first)) {
                return $first;
            }
        }

        throw new \RuntimeException(
            'No JMAP account id available - pass it in the DSN user part or ensure the session exposes primaryAccounts'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function buildJmapPayload(Envelope $envelope, array $session): array
    {
        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

        $accountId = $this->resolveAccountId($session);
        $emailId = 'draft-' . bin2hex(random_bytes(8));

        return [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                [
                    'Email/set',
                    [
                        'accountId' => $accountId,
                        'create' => [
                            $emailId => [
                                'mailboxIds' => [$this->options['drafts_mailbox'] ?? 'drafts' => true],
                                'from' => [
                                    [
                                        'email' => $from->getEmail(),
                                        'name' => $from->getName(),
                                    ],
                                ],
                                'to' => array_map(static fn ($addr) => [
                                    'email' => $addr->getEmail(),
                                    'name' => $addr->getName(),
                                ], $envelope->getTo()),
                                'cc' => array_map(static fn ($addr) => [
                                    'email' => $addr->getEmail(),
                                    'name' => $addr->getName(),
                                ], $envelope->getCc()),
                                'bcc' => array_map(static fn ($addr) => [
                                    'email' => $addr->getEmail(),
                                    'name' => $addr->getName(),
                                ], $envelope->getBcc()),
                                'subject' => $envelope->getSubject(),
                                'textBody' => $envelope->getTextBody() !== '' ? [
                                    [
                                        'partId' => 'text',
                                        'type' => 'text/plain',
                                    ],
                                ] : [],
                                'htmlBody' => $envelope->getHtmlBody() !== '' ? [
                                    [
                                        'partId' => 'html',
                                        'type' => 'text/html',
                                    ],
                                ] : [],
                                'bodyValues' => array_filter([
                                    'text' => $envelope->getTextBody() !== '' ? [
                                        'value' => $envelope->getTextBody(),
                                    ] : null,
                                    'html' => $envelope->getHtmlBody() !== '' ? [
                                        'value' => $envelope->getHtmlBody(),
                                    ] : null,
                                ]),
                            ],
                        ],
                    ],
                    'c0',
                ],
                [
                    'EmailSubmission/set',
                    [
                        'accountId' => $accountId,
                        'create' => [
                            'submission-1' => [
                                'emailId' => '#' . $emailId,
                                'envelope' => [
                                    'mailFrom' => [
                                        'email' => $from->getEmail(),
                                    ],
                                    'rcptTo' => array_map(static fn ($addr) => [
                                        'email' => $addr->getEmail(),
                                    ], $envelope->getAllRecipients()),
                                ],
                            ],
                        ],
                    ],
                    'c1',
                ],
            ],
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
