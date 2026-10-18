<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Tests\FakeSmtpServer;
use ZenoMailer\Transport\SmtpTransport;

final class SmtpTransportTest extends TestCase
{
    public function testDotStuffingEscapesLeadingDots(): void
    {
        $message = "From: a@example.com\r\n\r\n.Line one\r\nnormal line\r\n..already stuffed\r\nend";

        $stuffed = SmtpTransport::dotStuff($message);

        self::assertSame(
            "From: a@example.com\r\n\r\n..Line one\r\nnormal line\r\n...already stuffed\r\nend",
            $stuffed
        );
    }

    public function testDotStuffingHandlesMessageStartingWithDot(): void
    {
        self::assertSame('..start', SmtpTransport::dotStuff('.start'));
    }

    public function testFullSmtpSessionAgainstFakeServer(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com', 'Sender')
                ->addTo('to@example.com', 'To')
                ->addCc('cc@example.com')
                ->addBcc('bcc@example.com')
                ->setSubject('Integration test')
                ->setTextBody("Line one\n.Line two\nEnd");

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}@example\.com$/', (string)$result->getMessageId());

            $transport->disconnect();
            $transcript = $server->getTranscript();
            $commands = $transcript['commands'];

            self::assertStringStartsWith('EHLO ', $commands[0] ?? '');
            self::assertContains('AUTH PLAIN', $commands);

            $mailFrom = '';
            foreach ($commands as $command) {
                if (str_starts_with($command, 'MAIL FROM:<sender@example.com>')) {
                    $mailFrom = $command;
                }
            }
            self::assertNotSame('', $mailFrom, 'MAIL FROM command not found');

            self::assertContains('RCPT TO:<to@example.com>', $commands);
            self::assertContains('RCPT TO:<cc@example.com>', $commands);
            self::assertContains('RCPT TO:<bcc@example.com>', $commands);
            self::assertContains('DATA', $commands);
            self::assertContains('QUIT', $commands);

            // AUTH PLAIN credentials are base64("\0user\0pass")
            $authLine = '';
            foreach ($commands as $command) {
                if (str_starts_with($command, 'AUTH_PLAIN_RESPONSE:')) {
                    $authLine = substr($command, strlen('AUTH_PLAIN_RESPONSE:'));
                }
            }
            self::assertSame("\0user@example.com\0secret", base64_decode($authLine, true) ?: '');

            // Dot-stuffing: raw DATA contains the stuffed line; unstuffing restores it
            $raw = $transcript['raw_data'] ?? '';
            self::assertStringContainsString("\r\n..Line two\r\n", $raw);
            $unstuffed = self::unstuff($raw);

            self::assertStringStartsWith('From: Sender <sender@example.com>', $unstuffed);
            self::assertStringContainsString('To: To <to@example.com>', $unstuffed);
            self::assertStringContainsString('Subject: Integration test', $unstuffed);
            self::assertStringContainsString('Message-ID: <' . $result->getMessageId() . '>', $unstuffed);
            self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $unstuffed);
            self::assertStringContainsString("\r\n\r\nLine one\r\n.Line two\r\nEnd", $unstuffed);
            self::assertStringNotContainsString('Bcc:', $unstuffed);
            self::assertDoesNotMatchRegularExpression('/[^\r]\n/', $unstuffed);
        } finally {
            $server->stop();
        }
    }

    public function testAuthLoginIsUsedWhenPlainNotAdvertised(): void
    {
        $server = new FakeSmtpServer('login_only');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            $transport->disconnect();

            $commands = $server->getTranscript()['commands'];
            self::assertContains('AUTH LOGIN', $commands);
            self::assertContains('AUTH_USER:' . base64_encode('user@example.com'), $commands);
            self::assertContains('AUTH_PASS:' . base64_encode('secret'), $commands);
        } finally {
            $server->stop();
        }
    }

    public function testEightBitMimeIsDeclaredWhenSupported(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            $transport->disconnect();

            $commands = $server->getTranscript()['commands'];
            $mailFrom = '';
            foreach ($commands as $command) {
                if (str_starts_with($command, 'MAIL FROM')) {
                    $mailFrom = $command;
                }
            }

            self::assertSame('MAIL FROM:<sender@example.com> BODY=8BITMIME', $mailFrom);
        } finally {
            $server->stop();
        }
    }

    public function testRejectedRecipientReturnsFailure(): void
    {
        $server = new FakeSmtpServer('reject_rcpt');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('good@example.com')
                ->addTo('bad@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false);
            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('550', $result->getMessage());
            self::assertStringContainsString('bad@example.com', $result->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function testStarttlsRequestedButNotAdvertisedFails(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, null, null, ['starttls' => true]);
            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('STARTTLS', $result->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function testStarttlsStringFalseIsNotTruthy(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            // "false" as a string (as it arrives from a DSN) must not enable STARTTLS
            $transport = new SmtpTransport('127.0.0.1', $server->port, false, null, null, ['starttls' => 'false']);
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function testConnectionIsReusedAcrossSends(): void
    {
        $server = new FakeSmtpServer('ok', 1);

        try {
            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');

            for ($i = 1; $i <= 3; $i++) {
                $envelope = (new Envelope())
                    ->setFrom('sender@example.com')
                    ->addTo("recipient{$i}@example.com")
                    ->setSubject("Keepalive test {$i}")
                    ->setTextBody("Message {$i}");

                $result = $transport->send($envelope);

                self::assertTrue($result->isSuccess(), "Send {$i} failed: " . $result->getMessage());
                // Send 1 establishes the connection; sends 2-3 reuse it
                self::assertSame(
                    $i > 1,
                    (bool)$result->getMetadata()['reused_connection'],
                    "Send {$i} reused_connection flag is wrong"
                );
            }

            $transport->disconnect();

            $transcript = $server->getTranscript();
            $commands = $transcript['commands'];

            // One connection setup, three messages over it
            self::assertSame(1, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'EHLO'))));
            self::assertSame(1, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'AUTH '))));
            self::assertSame(3, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'MAIL FROM'))));
            self::assertSame(3, count(array_filter($commands, static fn ($c) => $c === 'DATA')));
            // Two NOOPs validate the reused connection before sends 2 and 3
            self::assertSame(2, count(array_filter($commands, static fn ($c) => $c === 'NOOP')));
            self::assertCount(3, $transcript['messages']);
            self::assertStringContainsString('Message 3', (string)$transcript['raw_data']);
        } finally {
            $server->stop();
        }
    }

    public function testKeepaliveFalseUsesFreshConnectionPerSend(): void
    {
        $server = new FakeSmtpServer('ok', 2);

        try {
            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret', [
                'keepalive' => false,
            ]);

            for ($i = 1; $i <= 2; $i++) {
                $envelope = (new Envelope())
                    ->setFrom('sender@example.com')
                    ->addTo("recipient{$i}@example.com")
                    ->setTextBody("Message {$i}");

                $result = $transport->send($envelope);

                self::assertTrue($result->isSuccess(), "Send {$i} failed: " . $result->getMessage());
                self::assertFalse((bool)$result->getMetadata()['reused_connection']);
            }

            $transcript = $server->getTranscript();
            $commands = $transcript['commands'];

            // Two full sessions: EHLO + AUTH per message
            self::assertSame(2, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'EHLO'))));
            // One real AUTH command per session ("AUTH_PLAIN_RESPONSE:" entries
            // are excluded by matching the trailing space after AUTH)
            self::assertSame(2, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'AUTH '))));
        } finally {
            $server->stop();
        }
    }

    public function testDisconnectIsIdempotent(): void
    {
        $server = new FakeSmtpServer('ok', 1);

        try {
            $transport = new SmtpTransport('127.0.0.1', $server->port, false);

            $transport->disconnect();
            $transport->disconnect();

            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            self::assertTrue($transport->send($envelope)->isSuccess());
            $transport->disconnect();
        } finally {
            $server->stop();
        }
    }

    public function testErrorDiscardsTheConnection(): void
    {
        $server = new FakeSmtpServer('reject_rcpt', 2);

        try {
            $transport = new SmtpTransport('127.0.0.1', $server->port, false);

            $badEnvelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('bad@example.com')
                ->setTextBody('hi');

            $result = $transport->send($badEnvelope);
            self::assertFalse($result->isSuccess());

            // The failed connection must have been discarded: the next send
            // starts a fresh session (second server session accepted).
            $goodEnvelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('good@example.com')
                ->setTextBody('hi');

            $result = $transport->send($goodEnvelope);
            self::assertTrue($result->isSuccess(), 'Recovery send failed: ' . $result->getMessage());
            self::assertFalse((bool)$result->getMetadata()['reused_connection']);

            $transport->disconnect();

            $commands = $server->getTranscript()['commands'];
            self::assertSame(2, count(array_filter($commands, static fn ($c) => str_starts_with($c, 'EHLO'))));
        } finally {
            $server->stop();
        }
    }

    public function testSmtpUtf8IsDeclaredWhenRequired(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('用户@example.com')
                ->addTo('recipient@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            $transport->disconnect();

            $commands = $server->getTranscript()['commands'];
            $mailFrom = '';
            foreach ($commands as $command) {
                if (str_starts_with($command, 'MAIL FROM')) {
                    $mailFrom = $command;
                }
            }

            self::assertSame('MAIL FROM:<用户@example.com> BODY=8BITMIME SMTPUTF8', $mailFrom);
        } finally {
            $server->stop();
        }
    }

    public function testSmtpUtf8WithoutServerSupportFails(): void
    {
        $server = new FakeSmtpServer('no_utf8');

        try {
            $envelope = (new Envelope())
                ->setFrom('用户@example.com')
                ->addTo('recipient@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('SMTPUTF8', $result->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function testAsciiMailFromHasNoSmtpUtf8Param(): void
    {
        $server = new FakeSmtpServer('ok');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('to@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false, 'user@example.com', 'secret');
            $result = $transport->send($envelope);

            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            $transport->disconnect();

            $commands = $server->getTranscript()['commands'];
            $mailFrom = '';
            foreach ($commands as $command) {
                if (str_starts_with($command, 'MAIL FROM')) {
                    $mailFrom = $command;
                }
            }

            self::assertStringNotContainsString('SMTPUTF8', $mailFrom);
        } finally {
            $server->stop();
        }
    }

    public function testTransientSmtpReplyIsMarkedRetryable(): void
    {
        $server = new FakeSmtpServer('tempfail');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('bad@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false);
            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertStringContainsString('451', $result->getMessage());
            self::assertTrue($result->isRetryable(), 'SMTP 4xx replies are transient and retryable');
        } finally {
            $server->stop();
        }
    }

    public function testPermanentSmtpReplyIsNotRetryable(): void
    {
        $server = new FakeSmtpServer('reject_rcpt');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com')
                ->addTo('bad@example.com')
                ->setTextBody('hi');

            $transport = new SmtpTransport('127.0.0.1', $server->port, false);
            $result = $transport->send($envelope);

            self::assertFalse($result->isSuccess());
            self::assertFalse($result->isRetryable(), 'SMTP 5xx replies are permanent');
        } finally {
            $server->stop();
        }
    }

    private static function unstuff(string $raw): string
    {
        return preg_replace('/^\.\./m', '.', $raw) ?? $raw;
    }
}
