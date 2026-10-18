<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Http\HttpResponse;
use ZenoMailer\Tests\MockHttpClient;
use ZenoMailer\Transport\SesTransport;

final class SesTransportTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com')
            ->setSubject('Hello')
            ->setTextBody('Text');
    }

    /**
     * @return array{url: string, headers: string[], params: array<string, string>, raw: string}
     */
    private function captureRequest(MockHttpClient $client): array
    {
        $request = $client->requests[0];

        // parse_str() converts dots to underscores, so parse the query manually
        // to keep keys like "Destinations.member.1" intact.
        $params = [];
        foreach (explode('&', (string)$request['body']) as $pair) {
            $parts = explode('=', $pair, 2);
            $params[urldecode($parts[0])] = urldecode($parts[1] ?? '');
        }

        $raw = base64_decode($params['RawMessage.Data'] ?? '', true) ?: '';

        return [
            'url' => $request['url'],
            'headers' => $request['headers'],
            'params' => $params,
            'raw' => $raw,
        ];
    }

    public function testSendRawEmailPayloadStructure(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '<MessageId>ses-123</MessageId>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'eu-west-1', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('ses-123', $result->getMessageId());

        $captured = $this->captureRequest($client);
        self::assertSame('https://email.eu-west-1.amazonaws.com/', $captured['url']);
        self::assertSame('SendRawEmail', $captured['params']['Action'] ?? null);
        self::assertSame('sender@example.com', $captured['params']['Source'] ?? null);
    }

    public function testBccRecipientsArePassedAsDestinations(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '<MessageId>m</MessageId>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $transport->send($this->envelope()->addCc('cc@example.com')->addBcc('bcc@example.com'));

        $captured = $this->captureRequest($client);

        self::assertSame('to@example.com', $captured['params']['Destinations.member.1'] ?? null);
        self::assertSame('cc@example.com', $captured['params']['Destinations.member.2'] ?? null);
        self::assertSame('bcc@example.com', $captured['params']['Destinations.member.3'] ?? null);
    }

    public function testBccHeaderIsNotInRawMessage(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '<MessageId>m</MessageId>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $transport->send($this->envelope()->addBcc('bcc@example.com'));

        $captured = $this->captureRequest($client);
        self::assertStringNotContainsString('Bcc:', $captured['raw']);
        self::assertStringContainsString('To: to@example.com', $captured['raw']);
    }

    public function testAttachmentsAreIncludedInRawMessage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'PDF-CONTENT');

        try {
            $client = new MockHttpClient(new HttpResponse(200, '<MessageId>m</MessageId>'));
            $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

            $transport->send($this->envelope()->addAttachment($path, 'doc.pdf', 'application/pdf'));

            $captured = $this->captureRequest($client);
            self::assertStringContainsString('multipart/mixed', $captured['raw']);
            self::assertStringContainsString('application/pdf', $captured['raw']);
            self::assertStringContainsString(base64_encode('PDF-CONTENT'), $captured['raw']);
        } finally {
            unlink($path);
        }
    }

    public function testAuthorizationHeaderIsSigV4(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '<MessageId>m</MessageId>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $transport->send($this->envelope());

        $headers = $client->requests[0]['headers'];
        $auth = '';
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization: ')) {
                $auth = $header;
            }
        }

        self::assertStringStartsWith('Authorization: AWS4-HMAC-SHA256 Credential=AKIA/', $auth);
        self::assertStringContainsString('us-east-1/ses/aws4_request', $auth);

        $hasAmzDate = false;
        foreach ($headers as $header) {
            if (str_starts_with($header, 'X-Amz-Date: ')) {
                $hasAmzDate = true;
            }
        }
        self::assertTrue($hasAmzDate);
    }

    public function testSesThrottlingIsMarkedRetryable(): void
    {
        $client = new MockHttpClient(new HttpResponse(400, '<ErrorResponse><Error><Code>Throttling</Code></Error></ErrorResponse>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isRetryable(), 'SES Throttling is transient');
    }

    public function testUtf8LocalPartIsRejectedExplicitly(): void
    {
        $client = new MockHttpClient();
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $envelope = (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo('用户@example.com')
            ->setTextBody('hi');

        $result = $transport->send($envelope);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('SMTPUTF8', $result->getMessage());
        self::assertCount(0, $client->requests);
    }

    public function testApiErrorReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(403, '<Error><Code>AccessDenied</Code></Error>'));
        $transport = new SesTransport('AKIA', 'SECRET', 'us-east-1', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('403', $result->getMessage());
    }
}
