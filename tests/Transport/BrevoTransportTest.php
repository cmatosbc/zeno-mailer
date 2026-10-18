<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Http\HttpResponse;
use ZenoMailer\Tests\MockHttpClient;
use ZenoMailer\Transport\BrevoTransport;

final class BrevoTransportTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com', 'To')
            ->setSubject('Hello')
            ->setTextBody('Text')
            ->setHtmlBody('<b>HTML</b>');
    }

    public function testSendBuildsCorrectPayload(): void
    {
        $client = new MockHttpClient(
            new HttpResponse(200, '{"messageId": "msg-123"}')
        );
        $transport = new BrevoTransport('api-key', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('msg-123', $result->getMessageId());

        $request = $client->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.brevo.com/v3/smtp/email', $request['url']);
        self::assertContains('api-key: api-key', $request['headers']);
        self::assertContains('Content-Type: application/json', $request['headers']);

        $payload = json_decode((string)$request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['email' => 'sender@example.com', 'name' => 'Sender'], $payload['sender']);
        self::assertSame([['email' => 'to@example.com', 'name' => 'To']], $payload['to']);
        self::assertSame('Hello', $payload['subject']);
        self::assertSame('Text', $payload['textContent']);
        self::assertSame('<b>HTML</b>', $payload['htmlContent']);
    }

    public function testSenderNameOmittedWhenEmpty(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"messageId": "m"}'));
        $transport = new BrevoTransport('api-key', [], $client);

        $transport->send((new Envelope())->setFrom('sender@example.com')->addTo('t@example.com')->setTextBody('x'));

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['email' => 'sender@example.com'], $payload['sender']);
    }

    public function testBccAndCcAreIncluded(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"messageId": "m"}'));
        $transport = new BrevoTransport('api-key', [], $client);

        $transport->send(
            $this->envelope()->addCc('cc@example.com')->addBcc('bcc@example.com')
        );

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cc@example.com', $payload['cc'][0]['email']);
        self::assertSame('bcc@example.com', $payload['bcc'][0]['email']);
    }

    public function testAttachmentIsBase64Encoded(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'CONTENT');

        try {
            $client = new MockHttpClient(new HttpResponse(200, '{"messageId": "m"}'));
            $transport = new BrevoTransport('api-key', [], $client);

            $transport->send($this->envelope()->addAttachment($path, 'file.txt', 'text/plain'));

            $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['name' => 'file.txt', 'content' => base64_encode('CONTENT')], $payload['attachment'][0]);
        } finally {
            unlink($path);
        }
    }

    public function testApiErrorReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(400, '{"message": "Key invalid"}'));
        $transport = new BrevoTransport('bad-key', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Key invalid', $result->getMessage());
        self::assertSame(400, $result->getMetadata()['http_code']);
    }

    public function testNonJsonErrorBodyReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(500, '<html>gateway error</html>'));
        $transport = new BrevoTransport('key', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('gateway error', $result->getMessage());
    }

    public function testClientExceptionReturnsFailure(): void
    {
        $client = new MockHttpClient(new \RuntimeException('connection refused'));
        $transport = new BrevoTransport('key', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertSame('connection refused', $result->getMessage());
    }

    public function testMissingFromReturnsFailure(): void
    {
        $client = new MockHttpClient();
        $transport = new BrevoTransport('key', [], $client);

        $result = $transport->send((new Envelope())->addTo('t@example.com')->setTextBody('x'));

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('From', $result->getMessage());
        self::assertCount(0, $client->requests);
    }

    public function testEmbeddedImagesAreRejectedExplicitly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, "\x89PNG fake");

        try {
            $client = new MockHttpClient();
            $transport = new BrevoTransport('key', [], $client);

            $result = $transport->send(
                $this->envelope()
                    ->setHtmlBody('<img src="cid:logo">')
                    ->addEmbeddedImage($path, 'logo')
            );

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('inline (CID) images', $result->getMessage());
            self::assertCount(0, $client->requests);
        } finally {
            unlink($path);
        }
    }

    public function testTransientHttpErrorsAreMarkedRetryable(): void
    {
        $envelope = $this->envelope();

        // 500, 429 -> retryable; 400 -> permanent
        foreach ([[500, true], [429, true], [400, false]] as [$status, $expected]) {
            $client = new MockHttpClient(new HttpResponse($status, '{"message": "boom"}'));
            $transport = new BrevoTransport('key', [], $client);

            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertSame(
                $expected,
                $result->isRetryable(),
                "HTTP {$status} retryable flag should be " . var_export($expected, true)
            );
        }

        // Network-level errors are transient
        $client = new MockHttpClient(new \RuntimeException('connection reset'));
        $transport = new BrevoTransport('key', [], $client);
        self::assertTrue($transport->send($envelope)->isRetryable());

        // Validation errors are permanent
        $client = new MockHttpClient();
        $transport = new BrevoTransport('key', [], $client);
        self::assertFalse($transport->send((new Envelope())->setTextBody('no from'))->isRetryable());
    }

    public function testUnnamedRecipientsOmitTheNameKey(): void
    {
        // Regression: Brevo rejects {"email": "...", "name": ""} with
        // "name is missing in to" - the key must be omitted entirely.
        $client = new MockHttpClient(new HttpResponse(200, '{"messageId": "m"}'));
        $transport = new BrevoTransport('key', [], $client);

        $transport->send(
            (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->addCc('cc@example.com')
                ->addBcc('bcc@example.com')
                ->setSubject('x')
                ->setTextBody('y')
        );

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['email' => 'to@example.com']], $payload['to']);
        self::assertSame([['email' => 'cc@example.com']], $payload['cc']);
        self::assertSame([['email' => 'bcc@example.com']], $payload['bcc']);
        self::assertArrayNotHasKey('replyTo', $payload);
    }

    public function testSendBatchWithoutAsyncIsSequential(): void
    {
        $client = new MockHttpClient(
            new HttpResponse(200, '{"messageId": "1"}'),
            new HttpResponse(200, '{"messageId": "2"}')
        );
        $transport = new BrevoTransport('key', ['async' => false], $client);

        $results = $transport->sendBatch([$this->envelope(), $this->envelope()]);

        self::assertCount(2, $results);
        self::assertSame('1', $results[0]->getMessageId());
        self::assertSame('2', $results[1]->getMessageId());
        self::assertCount(2, $client->requests);
    }

    public function testAsyncOptionAcceptsStringFalse(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"messageId": "1"}'));
        // DSN strings arrive as strings: "false" must not be truthy
        $transport = new BrevoTransport('key', ['async' => 'false'], $client);

        $results = $transport->sendBatch([$this->envelope()]);

        self::assertCount(1, $results);
        self::assertCount(1, $client->requests);
    }
}
