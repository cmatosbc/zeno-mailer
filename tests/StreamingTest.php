<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Attachment;
use ZenoMailer\EmbeddedImage;
use ZenoMailer\Envelope;
use ZenoMailer\MessageSerializer;
use ZenoMailer\StreamedMessage;
use ZenoMailer\Transport\DotStuffStreamFilter;

final class StreamingTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function createFile(string $content): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'zeno-stream') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($this->tempFile, $content);

        return $this->tempFile;
    }

    // ---------- DotStuffStreamFilter ----------

    /**
     * Read $input through the registered dot-stuff filter, in chunks of
     * $chunkSize bytes (1 = worst-case chunk boundaries).
     *
     * @param positive-int $chunkSize
     */
    private function filterString(string $input, int $chunkSize = 65536): string
    {
        DotStuffStreamFilter::register();

        $source = fopen('php://temp', 'r+b') ?: throw new \RuntimeException('stream open failed');
        fwrite($source, $input);
        rewind($source);

        $filter = stream_filter_append($source, DotStuffStreamFilter::NAME, STREAM_FILTER_READ);
        self::assertNotFalse($filter);

        $output = '';
        while (($chunk = fread($source, $chunkSize)) !== false && $chunk !== '') {
            $output .= $chunk;
        }
        fclose($source);

        return $output;
    }

    public function testDotStuffFilterStuffsLeadingDots(): void
    {
        $input = "line one\r\n.Line two\r\nnormal\r\n..already\r\n";
        $expected = "line one\r\n..Line two\r\nnormal\r\n...already\r\n";

        self::assertSame($expected, $this->filterString($input));
    }

    public function testDotStuffFilterHandlesMessageStartingWithDot(): void
    {
        self::assertSame('..start', $this->filterString('.start'));
    }

    public function testDotStuffFilterFlushesTrailingLineWithoutNewline(): void
    {
        self::assertSame("..trailing", $this->filterString('.trailing'));
        self::assertSame("a\r\nb", $this->filterString("a\r\nb"));
    }

    public function testDotStuffFilterSurvivesByteByByteChunks(): void
    {
        $input = "line\r\n.dot\r\nend.no newline";
        $expected = "line\r\n..dot\r\nend.no newline";

        self::assertSame($expected, $this->filterString($input, 1), 'Byte-sized chunks must not corrupt line state');
    }

    public function testDotStuffFilterMatchesTheStringImplementation(): void
    {
        $message = "From: a@example.com\r\n\r\n.Line one\r\nnormal\r\n..already\r\nend";

        self::assertSame(
            \ZenoMailer\Transport\SmtpTransport::dotStuff($message),
            $this->filterString($message)
        );
    }

    // ---------- Attachment::streamEncodedTo ----------

    public function testAttachmentStreamsBase64IdenticalToStringEncoding(): void
    {
        $content = random_bytes(100_000);
        $path = $this->createFile($content);
        $attachment = new Attachment($path, 'data.bin');

        $dest = fopen('php://temp', 'r+b') ?: throw new \RuntimeException('stream open failed');
        $attachment->streamEncodedTo($dest);
        rewind($dest);
        $streamed = (string)stream_get_contents($dest);
        fclose($dest);

        $expected = chunk_split(base64_encode($content), 76, "\r\n");

        self::assertSame(rtrim($expected, "\r\n"), rtrim($streamed, "\r\n"), 'Same payload, modulo trailing line breaks');
        foreach (explode("\r\n", $streamed) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
    }

    public function testAttachmentStreamsQuotedPrintable(): void
    {
        $content = "hello = world\n" . str_repeat('x', 200);
        $path = $this->createFile($content);
        $attachment = new Attachment($path, 'x.txt', '', 'quoted-printable');

        $dest = fopen('php://temp', 'r+b') ?: throw new \RuntimeException('stream open failed');
        $attachment->streamEncodedTo($dest);
        rewind($dest);
        $streamed = (string)stream_get_contents($dest);
        fclose($dest);

        self::assertSame(quoted_printable_encode($content), $streamed);
    }

    public function testAttachmentStreamThrowsForMissingFile(): void
    {
        $attachment = new Attachment('/nonexistent/file.bin');

        $dest = fopen('php://temp', 'r+b') ?: throw new \RuntimeException('stream open failed');
        $this->expectException(\RuntimeException::class);
        $attachment->streamEncodedTo($dest);
    }

    // ---------- Serializer streamed path ----------

    public function testStreamedMessageMaterializesIdenticallyToStructure(): void
    {
        $content = random_bytes(50_000);
        $path = $this->createFile($content);

        $envelope = (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com')
            ->setSubject('Streamed')
            ->setTextBody('Hello')
            ->setHtmlBody('<h1>Hello</h1>')
            ->addAttachment($path, 'data.bin', 'application/octet-stream');

        $serializer = new MessageSerializer();
        $streamed = $serializer->serializeStreamed($envelope);

        self::assertInstanceOf(StreamedMessage::class, $streamed);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}@example\.com$/', $streamed->getMessageId());

        $message = $streamed->getMessage();

        // Same structure as the string path
        self::assertStringContainsString('Content-Type: multipart/mixed; boundary="', $message);
        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="', $message);
        self::assertStringContainsString('Content-Type: application/octet-stream; name="data.bin"', $message);
        self::assertStringContainsString('Content-Disposition: attachment; filename="data.bin"', $message);
        self::assertStringContainsString('Hello', $message);
        self::assertStringContainsString('<h1>Hello</h1>', $message);
        $chunked = chunk_split(base64_encode($content), 76, "\r\n");
        self::assertStringContainsString(rtrim($chunked, "\r\n"), $message);

        // Line-length compliance
        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }

        // Exactly one header/body separator before the first boundary
        $headerBlock = substr($message, 0, (int)strpos($message, "\r\n\r\n"));
        foreach (explode("\r\n", $headerBlock) as $line) {
            self::assertStringStartsNotWith('--', $line);
        }
    }

    public function testStreamedEmbeddedImages(): void
    {
        $imageContent = "\x89PNG\r\n\x1a\n" . random_bytes(30_000);
        $imagePath = $this->createFile($imageContent);

        $envelope = (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo('to@example.com')
            ->setSubject('Streamed images')
            ->setHtmlBody('<img src="cid:logo">')
            ->addEmbeddedImage($imagePath, 'logo', 'logo.png', 'image/png');

        $message = (new MessageSerializer())->serializeStreamed($envelope)->getMessage();

        self::assertStringContainsString('Content-Type: multipart/related; boundary="', $message);
        self::assertStringContainsString('Content-ID: <logo>', $message);
        self::assertStringContainsString('Content-Disposition: inline; filename="logo.png"', $message);
        self::assertStringContainsString(rtrim(chunk_split(base64_encode($imageContent), 76, "\r\n"), "\r\n"), $message);
    }

    public function testStreamedPathRejectsInvalidEnvelopes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MessageSerializer())->serializeStreamed((new Envelope())->setTextBody('no from'));
    }
}
