<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;

final class EnvelopeTest extends TestCase
{
    public function testFluentSettersReturnSelf(): void
    {
        $envelope = new Envelope();

        self::assertSame($envelope, $envelope->setFrom('a@example.com'));
        self::assertSame($envelope, $envelope->addTo('b@example.com'));
        self::assertSame($envelope, $envelope->setSubject('Hi'));
        self::assertSame($envelope, $envelope->setTextBody('text'));
        self::assertSame($envelope, $envelope->setHtmlBody('<b>text</b>'));
        self::assertSame($envelope, $envelope->setCharset('iso-8859-1'));
        self::assertSame($envelope, $envelope->setEncoding('quoted-printable'));
    }

    public function testDefaults(): void
    {
        $envelope = new Envelope();

        self::assertSame('utf-8', $envelope->getCharset());
        self::assertSame('8bit', $envelope->getEncoding());
        self::assertSame('', $envelope->getSubject());
        self::assertNull($envelope->getFrom());
    }

    public function testGetAllRecipientsMergesToCcAndBcc(): void
    {
        $envelope = (new Envelope())
            ->addTo('to@example.com')
            ->addCc('cc@example.com')
            ->addBcc('bcc@example.com');

        $emails = array_map(static fn ($addr) => $addr->getEmail(), $envelope->getAllRecipients());

        self::assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], $emails);
    }

    public function testAddHeaderOverwritesDuplicates(): void
    {
        $envelope = (new Envelope())
            ->addHeader('X-Custom', 'first')
            ->addHeader('X-Custom', 'second');

        self::assertSame(['X-Custom' => 'second'], $envelope->getHeaders());
    }

    public function testAttachments(): void
    {
        $envelope = (new Envelope())->addAttachment('/tmp/report.pdf', 'report.pdf', 'application/pdf');

        self::assertCount(1, $envelope->getAttachments());
        self::assertSame('report.pdf', $envelope->getAttachments()[0]->getName());
    }

    public function testRequiresSmtpUtf8AggregatesAllAddresses(): void
    {
        self::assertFalse((new Envelope())->requiresSmtpUtf8());

        $ascii = (new Envelope())
            ->setFrom('a@example.com')
            ->addTo('b@example.com')
            ->addCc('c@example.com')
            ->addBcc('d@example.com')
            ->setReplyTo('e@example.com');
        self::assertFalse($ascii->requiresSmtpUtf8());

        $international = (new Envelope())
            ->setFrom('a@example.com')
            ->addTo('b@example.com')
            ->addBcc('用户@example.com');
        self::assertTrue($international->requiresSmtpUtf8());

        $fromOnly = (new Envelope())->setFrom('用户@example.com');
        self::assertTrue($fromOnly->requiresSmtpUtf8());

        $replyToOnly = (new Envelope())->setReplyTo('用户@example.com');
        self::assertTrue($replyToOnly->requiresSmtpUtf8());
    }
}
