<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Transport\BrevoTransport;
use ZenoMailer\Transport\JmapTransport;
use ZenoMailer\Transport\PostmarkTransport;
use ZenoMailer\Transport\SesTransport;
use ZenoMailer\Transport\SmtpTransport;
use ZenoMailer\Transport\TransportFactory;

final class TransportFactoryTest extends TestCase
{
    public function testCreatesSmtpTransport(): void
    {
        $transport = TransportFactory::fromDsn('smtp://user:pass@smtp.example.com:587');

        self::assertInstanceOf(SmtpTransport::class, $transport);
    }

    public function testCreatesSmtpsTransport(): void
    {
        $transport = TransportFactory::fromDsn('smtps://user:pass@smtp.example.com');

        self::assertInstanceOf(SmtpTransport::class, $transport);
    }

    public function testCreatesSmtpTransportWithoutCredentials(): void
    {
        $transport = TransportFactory::fromDsn('smtp://smtp.example.com');

        self::assertInstanceOf(SmtpTransport::class, $transport);
    }

    public function testCreatesBrevoTransport(): void
    {
        $transport = TransportFactory::fromDsn('brevo://my-api-key@default');

        self::assertInstanceOf(BrevoTransport::class, $transport);
    }

    public function testSendinblueSchemeIsAccepted(): void
    {
        $transport = TransportFactory::fromDsn('sendinblue://my-api-key@default');

        self::assertInstanceOf(BrevoTransport::class, $transport);
    }

    public function testBrevoWithoutApiKeyFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::fromDsn('brevo://@default');
    }

    public function testCreatesPostmarkTransport(): void
    {
        $transport = TransportFactory::fromDsn('postmark://server-token@default');

        self::assertInstanceOf(PostmarkTransport::class, $transport);
    }

    public function testPostmarkWithoutTokenFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::fromDsn('postmark://@default');
    }

    public function testCreatesSesTransport(): void
    {
        $transport = TransportFactory::fromDsn('ses://ACCESS:SECRET@default?region=eu-west-1');

        self::assertInstanceOf(SesTransport::class, $transport);
    }

    public function testSesWithoutSecretFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::fromDsn('ses://ACCESS@default');
    }

    public function testCreatesJmapTransport(): void
    {
        $transport = TransportFactory::fromDsn('jmap://account:token@jmap.example.com');

        self::assertInstanceOf(JmapTransport::class, $transport);
    }

    public function testJmapDsnBuildsHttpsUrlWithPort(): void
    {
        // Regression: the DSN must produce a scheme + port for the session URL
        $transport = TransportFactory::fromDsn('jmap://account:token@127.0.0.1:8080');

        self::assertInstanceOf(JmapTransport::class, $transport);

        $host = (new \ReflectionProperty($transport, 'host'));
        $host->setAccessible(true);
        self::assertSame('https://127.0.0.1:8080', $host->getValue($transport));
    }

    public function testJmapPlusHttpSchemeBuildsHttpUrl(): void
    {
        $transport = TransportFactory::fromDsn('jmap+http://account:token@127.0.0.1:8080');

        self::assertInstanceOf(JmapTransport::class, $transport);

        $host = new \ReflectionProperty($transport, 'host');
        $host->setAccessible(true);
        self::assertSame('http://127.0.0.1:8080', $host->getValue($transport));
    }

    public function testJmapWithoutTokenFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::fromDsn('jmap://account@jmap.example.com');
    }

    public function testUnsupportedSchemeFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported transport scheme');
        TransportFactory::fromDsn('gopher://host');
    }

    public function testMalformedDsnFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransportFactory::fromDsn('not-a-dsn');
    }

    public function testUrlEncodedCredentialsAreDecoded(): void
    {
        // "p@ss:word" URL-encoded as user info must survive parse_url
        $transport = TransportFactory::fromDsn('smtp://user:p%40ss%3Aword@smtp.example.com:587');

        self::assertInstanceOf(SmtpTransport::class, $transport);
    }
}
