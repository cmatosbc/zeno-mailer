<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Attachment;

final class AttachmentTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function createTempFile(string $content): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'zeno-att') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($this->tempFile, $content);

        return $this->tempFile;
    }

    public function testDefaults(): void
    {
        $path = $this->createTempFile('hello');
        $attachment = new Attachment($path);

        self::assertSame($path, $attachment->getPath());
        self::assertSame(basename($path), $attachment->getName());
        self::assertSame('application/octet-stream', $attachment->getType());
        self::assertSame('base64', $attachment->getEncoding());
    }

    public function testCustomValues(): void
    {
        $attachment = new Attachment('/tmp/report.pdf', 'report.pdf', 'application/pdf');

        self::assertSame('report.pdf', $attachment->getName());
        self::assertSame('application/pdf', $attachment->getType());
    }

    public function testRejectsCrLfInName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Attachment('/tmp/x.pdf', "name.pdf\r\nBcc: victim@example.com");
    }

    public function testGetContentReadsFile(): void
    {
        $path = $this->createTempFile('binary-content');
        $attachment = new Attachment($path);

        self::assertSame('binary-content', $attachment->getContent());
    }

    public function testGetContentThrowsForMissingFile(): void
    {
        $attachment = new Attachment('/nonexistent/path/file.pdf');

        $this->expectException(\RuntimeException::class);
        $attachment->getContent();
    }

    public function testGetEncodedContentBase64(): void
    {
        $path = $this->createTempFile('hello world');
        $attachment = new Attachment($path);

        self::assertSame(base64_encode('hello world'), $attachment->getEncodedContent());
    }

    public function testGetEncodedContentQuotedPrintable(): void
    {
        $path = $this->createTempFile('hello = world');
        $attachment = new Attachment($path, '', '', 'quoted-printable');

        self::assertSame(quoted_printable_encode('hello = world'), $attachment->getEncodedContent());
    }
}
