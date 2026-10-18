<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Http\HttpResponse;
use ZenoMailer\Tests\MockHttpClient;
use ZenoMailer\Transport\PostmarkTransport;

final class PostmarkTransportTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com')
            ->setSubject('Hello')
            ->setTextBody('Text');
    }

    public function testSendBuildsCorrectPayload(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"MessageID": "pm-123"}'));
        $transport = new PostmarkTransport('server-token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame('pm-123', $result->getMessageId());

        $request = $client->requests[0];
        self::assertSame('https://api.postmarkapp.com/email', $request['url']);
        self::assertContains('X-Postmark-Server-Token: server-token', $request['headers']);

        $payload = json_decode((string)$request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Sender <sender@example.com>', $payload['From']);
        self::assertSame('to@example.com', $payload['To']);
        self::assertSame('Hello', $payload['Subject']);
        self::assertSame('Text', $payload['TextBody']);
    }

    public function testCustomHeadersAreMapped(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"MessageID": "m"}'));
        $transport = new PostmarkTransport('token', [], $client);

        $transport->send($this->envelope()->addHeader('X-Custom', 'value'));

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['Name' => 'X-Custom', 'Value' => 'value']], $payload['Headers']);
    }

    public function testTrackOpensStringTrueIsParsedAsBoolean(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"MessageID": "m"}'));
        // DSN strings arrive as strings: "true" must become a real boolean
        $transport = new PostmarkTransport('token', ['track_opens' => 'true'], $client);

        $transport->send($this->envelope());

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['TrackOpens']);
    }

    public function testMessageStreamOption(): void
    {
        $client = new MockHttpClient(new HttpResponse(200, '{"MessageID": "m"}'));
        $transport = new PostmarkTransport('token', ['message_stream' => 'outbound'], $client);

        $transport->send($this->envelope());

        $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('outbound', $payload['MessageStream']);
    }

    public function testEmbeddedImagesGetContentId(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, "\x89PNG fake");

        try {
            $client = new MockHttpClient(new HttpResponse(200, '{"MessageID": "m"}'));
            $transport = new PostmarkTransport('token', [], $client);

            $transport->send(
                $this->envelope()
                    ->setHtmlBody('<img src="cid:logo">')
                    ->addEmbeddedImage($path, 'logo', 'logo.png', 'image/png')
            );

            $payload = json_decode((string)$client->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([
                'Name' => 'logo.png',
                'Content' => base64_encode("\x89PNG fake"),
                'ContentType' => 'image/png',
                'ContentID' => 'logo',
            ], $payload['Attachments'][0]);
        } finally {
            unlink($path);
        }
    }

    public function testApiErrorReturnsFailure(): void
    {
        $client = new MockHttpClient(new HttpResponse(422, '{"Message": "Invalid email"}'));
        $transport = new PostmarkTransport('token', [], $client);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Invalid email', $result->getMessage());
    }

    public function testMissingToRecipientFails(): void
    {
        $client = new MockHttpClient();
        $transport = new PostmarkTransport('token', [], $client);

        $envelope = (new Envelope())->setFrom('a@example.com')->addBcc('b@example.com')->setTextBody('x');
        $result = $transport->send($envelope);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('To recipient', $result->getMessage());
    }
}
