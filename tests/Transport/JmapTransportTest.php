<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Http\HttpResponse;
use ZenoMailer\Tests\MockHttpClient;
use ZenoMailer\Transport\JmapTransport;

final class JmapTransportTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com')
            ->setSubject('Hello')
            ->setTextBody('Text');
    }

    /** Session with an absolute apiUrl (Fastmail style) */
    private function sessionResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'apiUrl' => 'https://jmap.example.com/api/',
            'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc1'],
        ]) ?: '{}');
    }

    private function mailboxQueryResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['Mailbox/query', ['ids' => ['mailbox-xyz']], 'm0'],
            ],
        ]) ?: '{}');
    }

    private function identityGetResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['Identity/get', ['list' => [['id' => 'ident-1', 'email' => 'sender@example.com']]], 'm0'],
            ],
        ]) ?: '{}');
    }

    private function emailCreateResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['Email/set', ['created' => [['id' => 'draft-real-id']]], 'c0'],
            ],
        ]) ?: '{}');
    }

    private function submissionResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['EmailSubmission/set', ['created' => [['id' => 'sub-1']]], 'c0'],
            ],
        ]) ?: '{}');
    }

    /** Full first-send mock queue (session + mailbox + identity + create + submit) */
    private function firstSendClient(HttpResponse ...$overrides): MockHttpClient
    {
        return new MockHttpClient(
            $this->sessionResponse(),
            $this->mailboxQueryResponse(),
            $this->identityGetResponse(),
            $this->emailCreateResponse(),
            ...($overrides ?: [$this->submissionResponse()])
        );
    }

    public function testFullSendFlowUsesDiscoveredIds(): void
    {
        $client = $this->firstSendClient();
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('sub-1', $result->getMessageId());

        // 1: session discovery
        self::assertSame('GET', $client->requests[0]['method']);
        self::assertSame('https://jmap.example.com/.well-known/jmap', $client->requests[0]['url']);
        // 2: mailbox lookup by role
        self::assertSame('https://jmap.example.com/api/', $client->requests[1]['url']);
        self::assertSame('Mailbox/query', json_decode((string)$client->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR)['methodCalls'][0][0]);
        // 3: identity lookup
        self::assertSame('Identity/get', json_decode((string)$client->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR)['methodCalls'][0][0]);
        // 4: draft creation in the looked-up mailbox
        $createPayload = json_decode((string)$client->requests[3]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Email/set', $createPayload['methodCalls'][0][0]);
        self::assertSame(['mailbox-xyz' => true], $createPayload['methodCalls'][0][1]['create'][array_key_first($createPayload['methodCalls'][0][1]['create'])]['mailboxIds']);
        // 5: submission with the concrete draft id + identity
        $submissionPayload = json_decode((string)$client->requests[4]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EmailSubmission/set', $submissionPayload['methodCalls'][0][0]);
        $submission = $submissionPayload['methodCalls'][0][1]['create']['submission-1'];
        self::assertSame('draft-real-id', $submission['emailId']);
        self::assertSame('ident-1', $submission['identityId']);
        self::assertSame([['email' => 'to@example.com']], $submission['envelope']['rcptTo']);
    }

    public function testSessionAndLookupsAreCachedAcrossSends(): void
    {
        $client = new MockHttpClient(
            $this->sessionResponse(),
            $this->mailboxQueryResponse(),
            $this->identityGetResponse(),
            $this->emailCreateResponse(),
            $this->submissionResponse(),
            $this->emailCreateResponse(),
            $this->submissionResponse()
        );
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $transport->send($this->envelope());
        $transport->send($this->envelope());

        self::assertCount(7, $client->requests, 'Second send must not re-fetch session, mailbox or identity');
        self::assertSame('GET', $client->requests[0]['method']);
        self::assertSame('POST', $client->requests[5]['method']);
        self::assertSame('POST', $client->requests[6]['method']);
    }

    public function testRelativeApiUrlIsResolvedAgainstSessionHost(): void
    {
        // Regression: Stalwart returns apiUrl "/jmap/" (relative); Fastmail
        // returns absolute URLs. Both must work.
        $sessionResponse = new HttpResponse(200, json_encode([
            'apiUrl' => '/jmap/',
            'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc1'],
        ]) ?: '{}');

        $client = new MockHttpClient($sessionResponse, $this->mailboxQueryResponse(), $this->identityGetResponse(), $this->emailCreateResponse(), $this->submissionResponse());
        $transport = new JmapTransport('https://jmap.example.com:8080', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('https://jmap.example.com:8080/jmap/', $client->requests[1]['url']);
    }

    public function testPayloadContainsRecipientsIncludingBcc(): void
    {
        $client = $this->firstSendClient();
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $transport->send($this->envelope()->addBcc('bcc@example.com'));

        $createPayload = json_decode((string)$client->requests[3]['body'], true, 512, JSON_THROW_ON_ERROR);
        $draft = $createPayload['methodCalls'][0][1]['create'][array_key_first($createPayload['methodCalls'][0][1]['create'])];
        self::assertSame('bcc@example.com', $draft['bcc'][0]['email']);

        $submissionPayload = json_decode((string)$client->requests[4]['body'], true, 512, JSON_THROW_ON_ERROR);
        $rcptTo = $submissionPayload['methodCalls'][0][1]['create']['submission-1']['envelope']['rcptTo'];
        self::assertSame([['email' => 'to@example.com'], ['email' => 'bcc@example.com']], $rcptTo);
    }

    public function testIdentityIsCreatedWhenNoneMatches(): void
    {
        $identityCreateResponse = new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['Identity/set', ['created' => [['id' => 'new-ident']]], 'c0'],
            ],
        ]) ?: '{}');

        $client = new MockHttpClient(
            $this->sessionResponse(),
            $this->mailboxQueryResponse(),
            // Identity/get returns a DIFFERENT address -> no match
            new HttpResponse(200, json_encode([
                'methodResponses' => [
                    ['Identity/get', ['list' => [['id' => 'other', 'email' => 'other@example.com']]], 'm0'],
                ],
            ]) ?: '{}'),
            $identityCreateResponse,
            $this->emailCreateResponse(),
            $this->submissionResponse()
        );
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());

        $identityCreate = json_decode((string)$client->requests[3]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Identity/set', $identityCreate['methodCalls'][0][0]);
        self::assertSame('sender@example.com', $identityCreate['methodCalls'][0][1]['create']['identity']['email']);

        $submissionPayload = json_decode((string)$client->requests[5]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('new-ident', $submissionPayload['methodCalls'][0][1]['create']['submission-1']['identityId']);
    }

    public function testDraftCreationFailureIsReported(): void
    {
        $notCreated = new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['Email/set', ['notCreated' => ['draft' => ['description' => 'mailbox not found']]], 'c0'],
            ],
        ]) ?: '{}');

        $client = new MockHttpClient(
            $this->sessionResponse(),
            $this->mailboxQueryResponse(),
            $this->identityGetResponse(),
            $notCreated
        );
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('mailbox not found', $result->getMessage());
    }

    public function testNotCreatedSubmissionReturnsFailure(): void
    {
        $errorResponse = new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['EmailSubmission/set', [
                    'notCreated' => ['submission-1' => ['description' => 'quota exceeded']],
                ], 'c0'],
            ],
        ]) ?: '{}');

        $client = new MockHttpClient(
            $this->sessionResponse(),
            $this->mailboxQueryResponse(),
            $this->identityGetResponse(),
            $this->emailCreateResponse(),
            $errorResponse
        );
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('quota exceeded', $result->getMessage());
    }

    public function testAttachmentsAreRejectedExplicitly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'x');

        try {
            $client = new MockHttpClient();
            $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

            $result = $transport->send($this->envelope()->addAttachment($path));

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('attachments', $result->getMessage());
            self::assertCount(0, $client->requests);
        } finally {
            unlink($path);
        }
    }

    public function testSessionFailureReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(401, 'unauthorized'));
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('401', $result->getMessage());
    }

    public function testExplicitAccountIdOptionWins(): void
    {
        $client = $this->firstSendClient();
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', ['account_id' => 'pinned-id'], $client);

        $transport->send($this->envelope());

        $payload = json_decode((string)$client->requests[3]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pinned-id', $payload['methodCalls'][0][1]['accountId']);
    }
}
