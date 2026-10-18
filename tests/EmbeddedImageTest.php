<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\EmbeddedImage;
use ZenoMailer\Envelope;
use ZenoMailer\MessageSerializer;

final class EmbeddedImageTest extends TestCase
{
    private MessageSerializer $serializer;

    private string $imageFile = '';

    protected function setUp(): void
    {
        $this->serializer = new MessageSerializer();
    }

    protected function tearDown(): void
    {
        if ($this->imageFile !== '' && file_exists($this->imageFile)) {
            unlink($this->imageFile);
        }
    }

    private function createPng(): string
    {
        $this->imageFile = tempnam(sys_get_temp_dir(), 'zeno-img') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($this->imageFile, "\x89PNG\r\n\x1a\n" . 'fake-png-data');

        return $this->imageFile;
    }

    private function htmlEnvelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo('to@example.com')
            ->setSubject('Embedded image test')
            ->setHtmlBody('<h1>Hello</h1><img src="cid:logo" alt="logo">');
    }

    public function testEmbeddedImageGuessesTypeFromExtension(): void
    {
        $path = $this->createPng() . '.png';
        rename($this->imageFile, $path);
        $this->imageFile = $path;

        $image = new EmbeddedImage($path, 'logo');

        self::assertSame('image/png', $image->getType());
        self::assertSame('logo', $image->getCid());
        self::assertSame(basename($path), $image->getName());
    }

    public function testInvalidCidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmbeddedImage('/tmp/x.png', "bad cid\r\nBcc: x@y.z");
    }

    public function testCrLfInNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmbeddedImage('/tmp/x.png', 'logo', "name\r\ninject");
    }

    public function testMissingFileThrowsOnContentAccess(): void
    {
        $image = new EmbeddedImage('/nonexistent/image.png', 'logo');

        $this->expectException(\RuntimeException::class);
        $image->getContent();
    }

    public function testEmbeddedImageBuildsMultipartRelated(): void
    {
        $path = $this->createPng();
        $envelope = $this->htmlEnvelope()->addEmbeddedImage($path, 'logo', 'logo.png', 'image/png');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertMatchesRegularExpression(
            '/Content-Type: multipart\/related; boundary="[^"]+"; type="text\/html"/',
            $message
        );
        self::assertStringContainsString('Content-Type: image/png; name="logo.png"', $message);
        self::assertStringContainsString('Content-ID: <logo>', $message);
        self::assertStringContainsString('Content-Disposition: inline; filename="logo.png"', $message);
        self::assertStringContainsString(base64_encode("\x89PNG\r\n\x1a\n" . 'fake-png-data'), $message);
        self::assertStringContainsString('<h1>Hello</h1><img src="cid:logo" alt="logo">', $message);

        // The header block (before the first blank line) must contain the
        // top-level structure and no boundary delimiter lines
        $headerBlock = substr($message, 0, (int)strpos($message, "\r\n\r\n"));
        self::assertStringContainsString('multipart/related', $headerBlock);
        foreach (explode("\r\n", $headerBlock) as $line) {
            self::assertStringStartsNotWith('--', $line, 'Boundary delimiter found inside the header block');
        }
    }

    public function testEmbeddedImageWithTextAndHtmlNestsAlternativeInsideRelated(): void
    {
        $path = $this->createPng();
        $envelope = $this->htmlEnvelope()
            ->setTextBody('Hello')
            ->addEmbeddedImage($path, 'logo', 'logo.png', 'image/png');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertMatchesRegularExpression('/Content-Type: multipart\/related; boundary="[^"]+"/', $message);
        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="', $message);
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $message);
        self::assertStringContainsString('Content-ID: <logo>', $message);
    }

    public function testEmbeddedImageWithAttachmentBuildsMixedOfRelated(): void
    {
        $imagePath = $this->createPng();
        $docPath = tempnam(sys_get_temp_dir(), 'zeno-doc') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($docPath, 'PDF');

        try {
            $envelope = $this->htmlEnvelope()
                ->addEmbeddedImage($imagePath, 'logo', 'logo.png', 'image/png')
                ->addAttachment($docPath, 'doc.pdf', 'application/pdf');

            $message = $this->serializer->serialize($envelope)->getMessage();

            self::assertMatchesRegularExpression('/Content-Type: multipart\/mixed; boundary="[^"]+"/', $message);
            self::assertStringContainsString('Content-Type: multipart/related; boundary="', $message);
            self::assertStringContainsString('Content-ID: <logo>', $message);
            self::assertStringContainsString('application/pdf', $message);
            self::assertStringContainsString('Content-Disposition: attachment', $message);
        } finally {
            unlink($docPath);
        }
    }

    public function testEmbeddedImageWithoutHtmlIsRejected(): void
    {
        $path = $this->createPng();
        $envelope = (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo('to@example.com')
            ->setTextBody('text only')
            ->addEmbeddedImage($path, 'logo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTML body');
        $this->serializer->serialize($envelope);
    }

    public function testMultipleEmbeddedImages(): void
    {
        $path = $this->createPng();
        $envelope = $this->htmlEnvelope()
            ->addEmbeddedImage($path, 'logo1', 'a.png', 'image/png')
            ->addEmbeddedImage($path, 'logo2', 'b.png', 'image/png');

        $message = $this->serializer->serialize($envelope)->getMessage();

        self::assertStringContainsString('Content-ID: <logo1>', $message);
        self::assertStringContainsString('Content-ID: <logo2>', $message);
    }

    public function testEnvelopeFluentApi(): void
    {
        $path = $this->createPng();
        $envelope = new Envelope();

        self::assertSame($envelope, $envelope->addEmbeddedImage($path, 'logo'));
        self::assertCount(1, $envelope->getEmbeddedImages());
        self::assertSame('logo', $envelope->getEmbeddedImages()[0]->getCid());
    }
}
