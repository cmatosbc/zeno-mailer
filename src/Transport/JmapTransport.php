<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;
use ZenoMailer\Http\CurlHttpClient;
use ZenoMailer\Http\HttpClientInterface;
use ZenoMailer\Http\HttpResponse;

final class JmapTransport implements TransportInterface
{
    use SequentialBatchSending;

    private string $host;
    private string $accountId;
    private string $authScheme;
    private string $apiToken;
    /** @var array<string, mixed> */
    private array $options;
    private HttpClientInterface $http;
    /** @var array<string, mixed>|null */
    private ?array $session = null;
    private ?string $draftsMailboxId = null;
    /** @var array<string, string> */
    private array $identityIds = [];

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
        $this->host = str_starts_with($host, 'http://') || str_starts_with($host, 'https://')
            ? $host
            : 'https://' . $host;
        $this->accountId = $accountId;
        $this->authScheme = ($options['auth'] ?? 'bearer') === 'basic' ? 'basic' : 'bearer';
        $this->apiToken = $apiToken;
        $this->options = $options;
        $this->http = $httpClient ?? new CurlHttpClient((int)($options['timeout'] ?? 30));
    }

    /**
     * JMAP session objects may return relative URLs (e.g. Stalwart's
     * apiUrl "/jmap/") - resolve them against the session host.
     */
    private function resolveUrl(string $url): string
    {
        if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $parts = parse_url($this->host);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return $origin . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Authorization header for JMAP requests: Bearer by default, or Basic
     * (username:password) for self-hosted servers that use account
     * credentials instead of API tokens.
     */
    private function authHeader(): string
    {
        if ($this->authScheme === 'basic') {
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
            $apiUrl = $this->options['base_url'] ?? $session['apiUrl']
                ?? throw new \RuntimeException('Invalid JMAP session response: missing apiUrl');
            $apiUrl = $this->resolveUrl((string)$apiUrl);
            $accountId = $this->resolveAccountId($session);
            $draftsMailboxId = $this->resolveDraftsMailboxId($accountId, $apiUrl);
            $from = $envelope->getFrom();
            if ($from === null) {
                throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
            }
            $identityId = $this->resolveIdentityId($accountId, $apiUrl, $from->getEmail(), $from->getName());

            // Step 1: create the draft email
            $createPayload = json_encode($this->buildEmailCreatePayload($envelope, $accountId, $draftsMailboxId), JSON_THROW_ON_ERROR);
            $createResponse = $this->postJmap($apiUrl, $createPayload);
            $draftId = $this->extractCreatedId($createResponse, 'Email/set');

            // Step 2: submit it with a concrete emailId (more compatible across
            // servers than a same-request backreference)
            $submissionPayload = json_encode($this->buildSubmissionPayload($accountId, $draftId, $envelope, $identityId), JSON_THROW_ON_ERROR);
            $submissionResponse = $this->postJmap($apiUrl, $submissionPayload);

            return $this->interpretSubmissionResponse($submissionResponse);
        } catch (\JsonException $e) {
            return TransportResult::failure('JMAP payload encoding failed: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return TransportResult::failure($e->getMessage());
        } catch (\Exception $e) {
            // Network-level errors (DNS, connect, timeout) are transient
            return TransportResult::failure($e->getMessage(), [], true);
        }
    }

    private function postJmap(string $apiUrl, string $payload): \ZenoMailer\Http\HttpResponse
    {
        $response = $this->http->post($apiUrl, ['Content-Type: application/json', $this->authHeader()], $payload, $this->httpOptions());

        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            $responseData = $response->getJson();
            $errorMessage = is_array($responseData) ? ($responseData['type'] ?? null) : null;
            $errorMessage ??= mb_substr(trim($response->getBody()), 0, 200) ?: 'Unknown error';

            $retryable = $response->getStatus() === 429
                || $response->getStatus() === 408
                || $response->getStatus() >= 500;

            throw new JmapServerException("JMAP error: {$errorMessage}", [
                'http_code' => $response->getStatus(),
                'response' => $responseData,
            ], $retryable);
        }

        return $response;
    }

    /**
     * Extract the created object id from a Foo/set response; maps
     * notCreated entries to a JMAPServerException.
     *
     * @return string The created object id
     */
    private function extractCreatedId(\ZenoMailer\Http\HttpResponse $response, string $method): string
    {
        $responseData = $response->getJson();
        $methodResponses = is_array($responseData) ? ($responseData['methodResponses'] ?? []) : [];

        foreach (is_array($methodResponses) ? $methodResponses : [] as $methodResponse) {
            if (!is_array($methodResponse) || ($methodResponse[0] ?? null) !== $method) {
                continue;
            }

            $arguments = $methodResponse[1] ?? [];
            if (!is_array($arguments)) {
                continue;
            }

            $notCreated = $arguments['notCreated'] ?? [];
            if (is_array($notCreated) && $notCreated !== []) {
                $first = reset($notCreated);
                $description = is_array($first) ? ($first['description'] ?? 'rejected') : 'rejected';

                throw new JmapServerException("JMAP error: {$description}", ['response' => $responseData]);
            }

            $created = $arguments['created'] ?? [];
            if (is_array($created)) {
                foreach ($created as $entry) {
                    if (is_array($entry) && isset($entry['id']) && is_string($entry['id'])) {
                        return $entry['id'];
                    }
                }
            }
        }

        throw new JmapServerException("JMAP error: no {$method} response found", ['response' => $responseData]);
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

        throw new JmapServerException('JMAP error: no EmailSubmission/set response found', [
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
        // With basic auth the DSN user part is the login username, not the
        // JMAP account id - the account id always auto-discovers (or comes
        // from the explicit account_id option).
        $explicit = $this->options['account_id'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($this->authScheme !== 'basic' && $this->accountId !== '') {
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
            'No JMAP account id available - pass it via the account_id option or ensure the session exposes primaryAccounts'
        );
    }

    /**
     * JMAP mailbox ids are server-assigned, so the Drafts mailbox must be
     * looked up by role (and created on first use). An explicit
     * drafts_mailbox_id option bypasses the lookup.
     */
    private function resolveDraftsMailboxId(string $accountId, string $apiUrl): string
    {
        $explicit = $this->options['drafts_mailbox_id'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($this->draftsMailboxId !== null) {
            return $this->draftsMailboxId;
        }

        $query = json_encode([
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Mailbox/query', [
                    'accountId' => $accountId,
                    'filter' => ['role' => 'drafts'],
                    'limit' => 1,
                ], 'm0'],
            ],
        ], JSON_THROW_ON_ERROR);

        $responseData = $this->postJmap($apiUrl, $query)->getJson();
        $methodResponse = is_array($responseData) ? ($responseData['methodResponses'][0] ?? null) : null;
        $arguments = is_array($methodResponse) ? ($methodResponse[1] ?? []) : [];
        $ids = is_array($arguments) ? ($arguments['ids'] ?? []) : [];

        if (is_array($ids) && $ids !== [] && is_string($ids[0])) {
            $this->draftsMailboxId = $ids[0];

            return $this->draftsMailboxId;
        }

        // No Drafts mailbox yet - create one
        $create = json_encode([
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Mailbox/set', [
                    'accountId' => $accountId,
                    'create' => ['drafts' => ['name' => 'Drafts', 'role' => 'drafts']],
                ], 'm0'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->draftsMailboxId = $this->extractCreatedId($this->postJmap($apiUrl, $create), 'Mailbox/set');

        return $this->draftsMailboxId;
    }

    /**
     * Resolve the JMAP identity to submit as: match the From address among
     * the account's identities, creating one on first use. Cached per address.
     */
    private function resolveIdentityId(string $accountId, string $apiUrl, string $email, string $name): string
    {
        if (isset($this->identityIds[$email])) {
            return $this->identityIds[$email];
        }

        $get = json_encode([
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:submission'],
            'methodCalls' => [
                ['Identity/get', ['accountId' => $accountId], 'm0'],
            ],
        ], JSON_THROW_ON_ERROR);

        $responseData = $this->postJmap($apiUrl, $get)->getJson();
        $methodResponse = is_array($responseData) ? ($responseData['methodResponses'][0] ?? null) : null;
        $arguments = is_array($methodResponse) ? ($methodResponse[1] ?? []) : [];
        $list = is_array($arguments) ? ($arguments['list'] ?? []) : [];

        if (is_array($list)) {
            foreach ($list as $identity) {
                if (is_array($identity) && ($identity['email'] ?? null) === $email && isset($identity['id']) && is_string($identity['id'])) {
                    $this->identityIds[$email] = $identity['id'];

                    return $identity['id'];
                }
            }
        }

        // No identity for this address yet - create one
        $create = json_encode([
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:submission'],
            'methodCalls' => [
                ['Identity/set', [
                    'accountId' => $accountId,
                    'create' => ['identity' => ['name' => $name, 'email' => $email]],
                ], 'm0'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->identityIds[$email] = $this->extractCreatedId($this->postJmap($apiUrl, $create), 'Identity/set');

        return $this->identityIds[$email];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function buildEmailCreatePayload(Envelope $envelope, string $accountId, string $draftsMailboxId): array
    {
        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

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
                                'mailboxIds' => [$draftsMailboxId => true],
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
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubmissionPayload(string $accountId, string $draftId, Envelope $envelope, string $identityId): array
    {
        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

        return [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:submission'],
            'methodCalls' => [
                [
                    'EmailSubmission/set',
                    [
                        'accountId' => $accountId,
                        'create' => [
                            'submission-1' => [
                                'identityId' => $identityId,
                                'emailId' => $draftId,
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
                    'c0',
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
