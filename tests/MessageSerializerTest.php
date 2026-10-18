<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Attachment;
use ZenoMailer\Envelope;
use ZenoMailer\MessageSerializer;

final class MessageSerializerTest extends TestCase
{
    private MessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new MessageSerializer();
    }

    private function simpleEnvelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender Name')
            ->addTo('to@example.com', 'To Name')
            ->setSubject('Hello')
            ->setTextBody('Hello World');
    }

    public function testSerializesSimpleTextMessage(): void
    {
        $serialized = $this->serializer->serialize($this->simpleEnvelope());
        $message = $serialized->getMessage();

        self::assertStringStartsWith('From: Sender Name <sender@example.com>', $message);
        self::assertStringContainsString('To: To Name <to@example.com>', $message);
        self::assertStringContainsString('Subject: Hello', $message);
        self::assertStringContainsString('Date: ', $message);
        self::assertStringContainsString('MIME-Version: 1.0', $message);
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $message);
        self::assertStringContainsString('Content-Transfer-Encoding: 8bit', $message);
        self::assertStringContainsString("\r\n\r\nHello World", $message);
        self::assertStringNotContainsString('Bcc:', $message);
        self::assertDoesNotMatchRegularExpression('/[^\r]\n/', $message, 'Bare LF found in message');
    }

    public function testMessageIdUsesFromDomain(): void
    {
        $serialized = $this->serializer->serialize($this->simpleEnvelope());

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}@example\.com$/', $serialized->getMessageId());
        self::assertStringContainsString('Message-ID: <' . $serialized->getMessageId() . '>', $serialized->getMessage());
    }

    public function testCustomMessageIdIsHonored(): void
    {
        $envelope = $this->simpleEnvelope()->addHeader('Message-ID', 'my-custom-id@example.com');
        $serialized = $this->serializer->serialize($envelope);

        self::assertSame('my-custom-id@example.com', $serialized->getMessageId());
        self::assertStringContainsString('Message-ID: <my-custom-id@example.com>', $serialized->getMessage());
    }

    public function testBccHeaderIsNeverEmitted(): void
    {
        $envelope = $this->simpleEnvelope()->addBcc('secret@example.com');
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringNotContainsString('Bcc:', $message);
        self::assertStringNotContainsString('secret@example.com', $message);
    }

    public function testTextAndHtmlBuildMultipartAlternative(): void
    {
        $envelope = $this->simpleEnvelope()
            ->setHtmlBody('<h1>Hello</h1>');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertMatchesRegularExpression('/Content-Type: multipart\/alternative; boundary="[^"]+"/', $message);
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $message);
        self::assertStringContainsString('Content-Type: text/html; charset=utf-8', $message);
        self::assertStringContainsString('<h1>Hello</h1>', $message);
    }

    public function testAttachmentsBuildMultipartMixed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'PDF-CONTENT');

        try {
            $envelope = $this->simpleEnvelope()
                ->setHtmlBody('<h1>Hello</h1>')
                ->addAttachment($path, 'document.pdf', 'application/pdf');

            $message = $this->serializer->serialize($envelope)->getMessage();

            self::assertMatchesRegularExpression('/Content-Type: multipart\/mixed; boundary="[^"]+"/', $message);
            self::assertStringContainsString('Content-Type: multipart/alternative; boundary="', $message);
            self::assertStringContainsString('Content-Type: application/pdf; name="document.pdf"', $message);
            self::assertStringContainsString('Content-Disposition: attachment; filename="document.pdf"', $message);
            self::assertStringContainsString(base64_encode('PDF-CONTENT'), $message);
        } finally {
            unlink($path);
        }
    }

    public function testBase64AttachmentLinesAreChunked(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, random_bytes(500));

        try {
            $envelope = $this->simpleEnvelope()->addAttachment($path, 'data.bin');
            $message = $this->serializer->serialize($envelope)->getMessage();

            foreach (explode("\r\n", $message) as $line) {
                self::assertLessThanOrEqual(998, strlen($line));
            }
        } finally {
            unlink($path);
        }
    }

    public function testSubjectWithCrLfIsRejected(): void
    {
        $envelope = $this->simpleEnvelope()->setSubject("Hello\r\nBcc: victim@example.com");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Subject');
        $this->serializer->serialize($envelope);
    }

    public function testInvalidHeaderNameIsRejected(): void
    {
        $envelope = $this->simpleEnvelope()->addHeader('X-Bad Header;inject', 'value');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('header name');
        $this->serializer->serialize($envelope);
    }

    public function testHeaderValueWithCrLfIsRejected(): void
    {
        $envelope = $this->simpleEnvelope()->addHeader('X-Custom', "value\r\nBcc: victim@example.com");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR/LF');
        $this->serializer->serialize($envelope);
    }

    public function testForbiddenHeadersAreRejected(): void
    {
        foreach (['Bcc', 'Content-Type', 'MIME-Version'] as $header) {
            $envelope = $this->simpleEnvelope()->addHeader($header, 'x');

            try {
                $this->serializer->serialize($envelope);
                self::fail("Header {$header} should have been rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('managed by the serializer', $e->getMessage());
            }
        }
    }

    public function testMissingFromIsRejected(): void
    {
        $envelope = (new Envelope())->addTo('to@example.com')->setTextBody('Hi');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('From');
        $this->serializer->serialize($envelope);
    }

    public function testMissingRecipientsIsRejected(): void
    {
        $envelope = (new Envelope())->setFrom('a@example.com')->setTextBody('Hi');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient');
        $this->serializer->serialize($envelope);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $envelope = (new Envelope())->setFrom('a@example.com')->addTo('b@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('body');
        $this->serializer->serialize($envelope);
    }

    public function testNonAsciiSubjectIsEncoded(): void
    {
        $envelope = $this->simpleEnvelope()->setSubject('Café ☕ Special Offer');
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertMatchesRegularExpression('/^Subject: =\?UTF-8\?B\?[^\r\n]+\r\n/m', $message);
        self::assertStringNotContainsString('Café', $message, 'Raw UTF-8 must not leak into headers');
    }

    public function testLongNonAsciiSubjectStaysWellFormed(): void
    {
        $envelope = $this->simpleEnvelope()->setSubject(str_repeat('ä ', 60));
        $message = $this->serializer->serialize($envelope)->getMessage();

        // Exactly one blank line separates headers from body - an empty line
        // inside the header block would truncate the headers.
        self::assertSame(1, substr_count($message, "\r\n\r\n"));
        self::assertGreaterThan((int)strpos($message, "\r\n\r\n"), (int)strpos($message, 'Hello World'));

        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }

        // Unfolding restores a single valid Subject header
        $subjectLine = '';
        foreach (explode("\r\n", substr($message, 0, (int)strpos($message, "\r\n\r\n"))) as $line) {
            if (str_starts_with($line, 'Subject:') || (str_starts_with($line, ' ') && $subjectLine !== '')) {
                $subjectLine .= $line;
            } elseif ($subjectLine !== '') {
                break;
            }
        }
        self::assertMatchesRegularExpression(
            '/^Subject: (=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=)( =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=)*$/',
            $subjectLine
        );

        // RFC 5322 unfolding removes only the CRLF; the space between encoded
        // words remains and the decoder reassembles the original text.
        $unfolded = str_replace("\r\n", '', $subjectLine);
        self::assertSame('Subject: ' . str_repeat('ä ', 60), mb_decode_mimeheader($unfolded));
    }

    public function testVeryLongAddressListIsFolded(): void
    {
        $envelope = $this->simpleEnvelope();
        for ($i = 0; $i < 60; $i++) {
            $envelope->addTo("recipient{$i}@example.com");
        }

        $message = $this->serializer->serialize($envelope)->getMessage();

        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }

        // Unfolding restores the full recipient list
        $unfolded = str_replace("\r\n", '', $message);
        for ($i = 0; $i < 60; $i++) {
            self::assertStringContainsString("recipient{$i}@example.com", $unfolded);
        }
        self::assertDoesNotMatchRegularExpression('/  +/', $unfolded, 'No doubled spaces from folding');
    }

    public function testQuotedPrintableEncodingIsAppliedToBody(): void
    {
        $envelope = $this->simpleEnvelope()
            ->setTextBody("Line with trailing space \nand = sign")
            ->setEncoding('quoted-printable');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $message);
        self::assertStringContainsString(quoted_printable_encode("Line with trailing space \r\nand = sign"), $message);
    }

    public function testBase64EncodingIsAppliedToBody(): void
    {
        $envelope = $this->simpleEnvelope()->setTextBody('Hello World')->setEncoding('base64');
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: base64', $message);
        self::assertStringContainsString(
            rtrim(chunk_split(base64_encode('Hello World'), 76, "\r\n"), "\r\n"),
            $message
        );
    }

    public function testSevenBitWithNonAsciiBodyUpgradesToQuotedPrintable(): void
    {
        $envelope = $this->simpleEnvelope()->setTextBody('Café au lait')->setEncoding('7bit');
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $message);
        self::assertStringContainsString(quoted_printable_encode('Café au lait'), $message);
    }

    public function testEightBitBodyWithOverlongLineUpgradesToQuotedPrintable(): void
    {
        // Minified HTML - a single very long line, common in production
        $minified = '<!DOCTYPE html><html><body>' . str_repeat('<p>lorem ipsum </p>', 80) . '</body></html>';
        self::assertGreaterThan(998, strlen($minified));

        $envelope = $this->simpleEnvelope()->setHtmlBody($minified);
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $message);
        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line), 'No line may exceed the RFC 5322 hard limit');
        }
        // Content is preserved: the QP encoding of the original appears verbatim
        self::assertStringContainsString(quoted_printable_encode($minified), $message);
    }

    public function testEightBitBodyWithShortLinesStaysEightBit(): void
    {
        $envelope = $this->simpleEnvelope()->setTextBody("short line\nanother short line");
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: 8bit', $message);
    }

    public function testSevenBitAsciiBodyWithOverlongLineUpgradesToQuotedPrintable(): void
    {
        $longAscii = str_repeat('word ', 300);
        $envelope = $this->simpleEnvelope()->setTextBody($longAscii)->setEncoding('7bit');
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $message);
        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
    }

    public function testNewlinesAreNormalizedToCrLf(): void
    {
        $envelope = $this->simpleEnvelope()->setTextBody("line1\nline2\r\nline3");
        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString("\r\n\r\nline1\r\nline2\r\nline3", $message);
        self::assertDoesNotMatchRegularExpression('/[^\r]\n/', $message);
    }

    public function testCustomHeadersAreIncluded(): void
    {
        $envelope = $this->simpleEnvelope()
            ->addHeader('X-Custom', 'value')
            ->addHeader('List-Unsubscribe', '<https://example.com/unsub>');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('X-Custom: value', $message);
        self::assertStringContainsString('List-Unsubscribe: <https://example.com/unsub>', $message);
    }

    public function testAttachmentFilenameWithSpecialCharsIsEscaped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'x');

        try {
            $envelope = $this->simpleEnvelope()->addAttachment($path, 'my "report".pdf');
            $message = $this->serializer->serialize($envelope)->getMessage();

            self::assertStringContainsString('filename="my \\"report\\".pdf"', $message);
        } finally {
            unlink($path);
        }
    }

    public function testNonAsciiAttachmentFilenameIsEncoded(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'x');

        try {
            $envelope = $this->simpleEnvelope()->addAttachment($path, 'résumé.pdf');
            $message = $this->serializer->serialize($envelope)->getMessage();

            self::assertStringContainsString('filename==?UTF-8?B?', $message);
            self::assertStringNotContainsString('résumé', $message);
        } finally {
            unlink($path);
        }
    }
}
