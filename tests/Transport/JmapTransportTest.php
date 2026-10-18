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

    private function sessionResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'apiUrl' => 'https://jmap.example.com/api/',
        ]) ?: '{}');
    }

    private function submissionResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['EmailSubmission/set', ['created' => [['id' => 'sub-1']]], 'c1'],
            ],
        ]) ?: '{}');
    }

    public function testSubmissionUsesApiUrlDirectly(): void
    {
        $client = new MockHttpClient($this->sessionResponse(), $this->submissionResponse());
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('sub-1', $result->getMessageId());

        self::assertSame('GET', $client->requests[0]['method']);
        self::assertSame('https://jmap.example.com/.well-known/jmap', $client->requests[0]['url']);
        self::assertSame('POST', $client->requests[1]['method']);
        // Regression test: apiUrl must be used as-is, not have "jmap" appended
        self::assertSame('https://jmap.example.com/api/', $client->requests[1]['url']);
    }

    public function testSessionIsCachedAcrossSends(): void
    {
        $client = new MockHttpClient(
            $this->sessionResponse(),
            $this->submissionResponse(),
            $this->submissionResponse()
        );
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $transport->send($this->envelope());
        $transport->send($this->envelope());

        self::assertCount(3, $client->requests, 'Second send must not re-fetch the session');
        self::assertSame('GET', $client->requests[0]['method']);
        self::assertSame('POST', $client->requests[1]['method']);
        self::assertSame('POST', $client->requests[2]['method']);
    }

    public function testPayloadContainsEmailAndSubmissionCalls(): void
    {
        $client = new MockHttpClient($this->sessionResponse(), $this->submissionResponse());
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $transport->send($this->envelope()->addBcc('bcc@example.com'));

        $payload = json_decode((string)$client->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'], $payload['using']);
        self::assertSame('Email/set', $payload['methodCalls'][0][0]);
        self::assertSame('EmailSubmission/set', $payload['methodCalls'][1][0]);

        $rcptTo = $payload['methodCalls'][1][1]['create']['submission-1']['envelope']['rcptTo'];
        self::assertSame([['email' => 'to@example.com'], ['email' => 'bcc@example.com']], $rcptTo);
    }

    public function testAccountIdIsAutoDiscoveredFromSession(): void
    {
        $sessionResponse = new HttpResponse(200, json_encode([
            'apiUrl' => 'https://jmap.example.com/api/',
            'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'auto-discovered-id'],
        ]) ?: '{}');

        $client = new MockHttpClient($sessionResponse, $this->submissionResponse());
        $transport = new JmapTransport('https://jmap.example.com', '', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());

        $payload = json_decode((string)$client->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('auto-discovered-id', $payload['methodCalls'][0][1]['accountId']);
        self::assertSame('auto-discovered-id', $payload['methodCalls'][1][1]['accountId']);
    }

    public function testBasicAuthSchemeForSelfHostedServers(): void
    {
        $client = new MockHttpClient($this->sessionResponse(), $this->submissionResponse());
        $transport = new JmapTransport('https://jmap.example.com', 'user@host', 'secret', ['auth' => 'basic'], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertContains(
            'Authorization: Basic ' . base64_encode('user@host:secret'),
            $client->requests[0]['headers']
        );
        self::assertContains(
            'Authorization: Basic ' . base64_encode('user@host:secret'),
            $client->requests[1]['headers']
        );
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

    public function testNotCreatedReturnsFailure(): void
    {
        $errorResponse = new HttpResponse(200, json_encode([
            'methodResponses' => [
                ['EmailSubmission/set', [
                    'notCreated' => ['submission-1' => ['description' => 'quota exceeded']],
                ], 'c1'],
            ],
        ]) ?: '{}');

        $client = new MockHttpClient($this->sessionResponse(), $errorResponse);
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('quota exceeded', $result->getMessage());
    }

    public function testSessionFailureReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(401, 'unauthorized'));
        $transport = new JmapTransport('https://jmap.example.com', 'account', 'token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('401', $result->getMessage());
    }
}
