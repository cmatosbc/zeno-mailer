<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Mailer;
use ZenoMailer\MailerBuilder;
use ZenoMailer\Transport\FileSpool;
use ZenoMailer\Transport\SequentialBatchSending;
use ZenoMailer\Transport\SpoolCapableTransport;
use ZenoMailer\Transport\SpoolTransport;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Tests\ScriptedTransport;
use ZenoMailer\Transport\TransportResult;

final class SpoolTest extends TestCase
{
    private string $spoolDir = '';

    protected function tearDown(): void
    {
        if ($this->spoolDir !== '' && is_dir($this->spoolDir)) {
            foreach (glob($this->spoolDir . '/*.json') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->spoolDir);
        }
    }

    private function spoolDir(): string
    {
        $this->spoolDir = sys_get_temp_dir() . '/zeno-spool-' . bin2hex(random_bytes(4));

        return $this->spoolDir;
    }

    private function envelope(string $to = 'to@example.com'): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo($to)
            ->setSubject('Spool test')
            ->setTextBody('hi');
    }

    // ---------- Envelope serialization round trip ----------

    public function testEnvelopeToArrayFromArrayRoundTrip(): void
    {
        $docPath = tempnam(sys_get_temp_dir(), 'zeno-spool-att') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($docPath, 'attachment-content');

        try {
            $envelope = (new Envelope())
                ->setFrom('sender@example.com', 'Sender Name')
                ->addTo('to@example.com', 'To Name')
                ->addCc('cc@example.com')
                ->addBcc('bcc@example.com')
                ->setReplyTo('reply@example.com', 'Reply')
                ->setSubject('Round trip ☕')
                ->setTextBody('text body')
                ->setHtmlBody('<b>html</b>')
                ->addAttachment($docPath, 'doc.txt', 'text/plain')
                ->addHeader('X-Custom', 'value')
                ->setCharset('utf-8')
                ->setEncoding('quoted-printable');

            $restored = Envelope::fromArray($envelope->toArray());

            self::assertNotNull($restored->getFrom());
            self::assertNotNull($restored->getReplyTo());
            self::assertSame('sender@example.com', $restored->getFrom()->getEmail());
            self::assertSame('Sender Name', $restored->getFrom()->getName());
            self::assertSame('reply@example.com', $restored->getReplyTo()->getEmail());
            self::assertSame(['to@example.com', 'cc@example.com', 'bcc@example.com'], array_map(
                static fn ($a) => $a->getEmail(),
                $restored->getAllRecipients()
            ));
            self::assertSame('Round trip ☕', $restored->getSubject());
            self::assertSame('text body', $restored->getTextBody());
            self::assertSame('<b>html</b>', $restored->getHtmlBody());
            self::assertCount(1, $restored->getAttachments());
            self::assertSame('doc.txt', $restored->getAttachments()[0]->getName());
            self::assertSame(['X-Custom' => 'value'], $restored->getHeaders());
            self::assertSame('quoted-printable', $restored->getEncoding());
        } finally {
            unlink($docPath);
        }
    }

    // ---------- SpoolTransport ----------

    public function testFailedSendIsQueuedWithSpoolId(): void
    {
        $spool = new FileSpool($this->spoolDir());
        $transport = new SpoolTransport(new ScriptedTransport(999), $spool);

        $result = $transport->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('queued for retry', $result->getMessage());
        self::assertArrayHasKey('spool_id', $result->getMetadata());
        self::assertSame(1, $spool->count());
    }

    public function testSuccessfulSendIsNotQueued(): void
    {
        $spool = new FileSpool($this->spoolDir());
        $transport = new SpoolTransport(new ScriptedTransport(0), $spool);

        $result = $transport->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $spool->count());
        self::assertArrayNotHasKey('spool_id', $result->getMetadata());
    }

    public function testFlushRemovesSuccessfulEntries(): void
    {
        $spool = new FileSpool($this->spoolDir());
        $inner = new ScriptedTransport(999);
        $transport = new SpoolTransport($inner, $spool);

        $transport->send($this->envelope('a@example.com'));
        $transport->send($this->envelope('b@example.com'));
        self::assertSame(2, $spool->count());

        // The inner transport now succeeds (failUntil exhausted via reflection)
        $inner->recover();

        $results = $transport->flush();

        self::assertCount(2, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertTrue($results[1]->isSuccess());
        self::assertSame(0, $spool->count());
        self::assertSame(0, $transport->queuedCount());
    }

    public function testFlushKeepsFailuresWithAttemptCount(): void
    {
        $spool = new FileSpool($this->spoolDir());
        $inner = new ScriptedTransport(999);
        $transport = new SpoolTransport($inner, $spool);

        $transport->send($this->envelope());

        $results = $transport->flush();
        self::assertCount(1, $results);
        self::assertFalse($results[0]->isSuccess());
        self::assertSame(1, $spool->count(), 'Failed flush keeps the entry queued');

        $entries = $spool->load();
        self::assertSame(2, $entries[0]['attempts'], 'Attempt count incremented');
        self::assertSame('provider down', $entries[0]['error']);
    }

    public function testSpoolSurvivesAcrossInstances(): void
    {
        $dir = $this->spoolDir();
        $spool = new FileSpool($dir);
        $transport = new SpoolTransport(new ScriptedTransport(999), $spool);
        $transport->send($this->envelope());
        self::assertSame(1, $spool->count());

        // A NEW transport instance over the same directory sees the queue
        $freshSpool = new FileSpool($dir);
        self::assertSame(1, $freshSpool->count());

        $entries = $freshSpool->load();
        self::assertSame('Spool test', $entries[0]['envelope']->getSubject());
        self::assertSame('to@example.com', $entries[0]['envelope']->getAllRecipients()[0]->getEmail());
    }

    // ---------- Delegation through decorators ----------

    public function testFlushDelegatesThroughDecoratorLayers(): void
    {
        $spool = new FileSpool($this->spoolDir());
        $inner = new ScriptedTransport(999);
        $spoolTransport = new SpoolTransport($inner, $spool);

        // Wrap the spool in retry + telemetry (as the builder does)
        $chain = new \ZenoMailer\Transport\TelemetryTransport(
            new \ZenoMailer\Transport\RetryTransport($spoolTransport, 2, 1.0)
        );

        $spoolTransport->send($this->envelope());
        self::assertSame(1, $chain->queuedCount());

        $inner->recover();
        $results = $chain->flush();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertSame(0, $chain->queuedCount());
    }

    public function testMailerFlushSpool(): void
    {
        $dir = $this->spoolDir();
        $spool = new FileSpool($dir);

        $mailer = MailerBuilder::create()
            ->withTransport(new ScriptedTransport(999))
            ->withSpool($dir)
            ->build();

        $result = $mailer->send($this->envelope());
        self::assertFalse($result->isSuccess());
        self::assertArrayHasKey('spool_id', $result->getMetadata());
        self::assertSame(1, $mailer->queuedCount());
        self::assertSame(1, $spool->count());

        // Swap the inner transport's behavior via a fresh mailer over the same spool
        $recovered = MailerBuilder::create()
            ->withTransport(new ScriptedTransport(0))
            ->withSpool($dir)
            ->build();

        $results = $recovered->flushSpool();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertSame(0, $recovered->queuedCount());
    }

    public function testMailerWithoutSpoolReturnsEmptyFlush(): void
    {
        $mailer = MailerBuilder::create()
            ->withTransport(new ScriptedTransport(0))
            ->build();

        self::assertSame(0, $mailer->queuedCount());
        self::assertSame([], $mailer->flushSpool());
    }
}
