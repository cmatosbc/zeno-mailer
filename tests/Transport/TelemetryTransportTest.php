<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Mailer;
use ZenoMailer\Transport\SequentialBatchSending;
use ZenoMailer\Transport\TelemetryTransport;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;
use ZenoMailer\Tests\SpyLogger;

final class TelemetryTransportTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender')
            ->addTo('to@example.com')
            ->setSubject('Telemetry test')
            ->setTextBody('hi');
    }

    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    public function testLogsDeliveryOutcomeWithMessageIdAndDuration(): void
    {
                $inner = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                usleep(5_000); // 5ms

                return TransportResult::success('sent', 'msg-42');
            }
        };

        $telemetry = new TelemetryTransport($inner, $this->logger);
        $result = $telemetry->send($this->envelope());

        self::assertTrue($result->isSuccess());

        $delivered = $this->logger->records['Email delivered'] ?? [];
        self::assertCount(1, $delivered, 'One delivery log expected');
        self::assertSame('msg-42', $delivered[0]['message_id']);
        self::assertSame('Telemetry test', $delivered[0]['subject']);
        self::assertSame('sender@example.com', $delivered[0]['from']);
        self::assertSame(['to@example.com'], $delivered[0]['recipients']);
        self::assertGreaterThanOrEqual(4.0, $delivered[0]['duration_ms'], 'Duration must reflect the actual send');
        self::assertArrayHasKey('transport', $delivered[0]);
    }

    public function testLogsDeliveryFailuresWithRetryability(): void
    {
                $inner = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                return TransportResult::failure('server unavailable', [], true);
            }
        };

        $result = (new TelemetryTransport($inner, $this->logger))->send($this->envelope());

        self::assertFalse($result->isSuccess());

        $failed = $this->logger->records['Delivery failed'] ?? [];
        self::assertCount(1, $failed);
        self::assertSame('server unavailable', $failed[0]['error']);
        self::assertTrue($failed[0]['retryable']);
    }

    public function testLogsExceptionsAndRethrows(): void
    {
                $inner = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                throw new \RuntimeException('socket exploded');
            }
        };

        $telemetry = new TelemetryTransport($inner, $this->logger);

        try {
            $telemetry->send($this->envelope());
            self::fail('Exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('socket exploded', $e->getMessage());
        }

        self::assertCount(1, $this->logger->records['Delivery threw an exception'] ?? []);
        self::assertSame('socket exploded', $this->logger->records['Delivery threw an exception'][0]['error']);
    }

    public function testRecipientLoggingCanBeDisabled(): void
    {
                $inner = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                return TransportResult::success('sent', 'm');
            }
        };

        (new TelemetryTransport($inner, $this->logger, logRecipients: false))->send($this->envelope());

        $delivered = $this->logger->records['Email delivered'][0];
        self::assertArrayNotHasKey('recipients', $delivered, 'Recipients are PII - must be omittable');
    }

    public function testBatchSendsAreLoggedIndividually(): void
    {
                $inner = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                return TransportResult::success('sent', 'm');
            }
        };

        $telemetry = new TelemetryTransport($inner, $this->logger);
        $results = $telemetry->sendBatch([$this->envelope(), $this->envelope()]);

        self::assertCount(2, $results);
        self::assertCount(2, $this->logger->records['Email delivered'] ?? []);
    }

    public function testMailerTelemetrySeesRealDeliveryResults(): void
    {
        // THE regression test for the P2 fix: telemetry must log the DELIVERY
        // outcome, not "processed successfully" before the transport runs.
        
        $failingTransport = new class () implements TransportInterface {
            use SequentialBatchSending;

            public function send(Envelope $envelope): TransportResult
            {
                return TransportResult::failure('Brevo API error: unavailable');
            }
        };

        $telemetryMailer = new Mailer(new TelemetryTransport($failingTransport, $this->logger));
        $result = $telemetryMailer->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertCount(1, $this->logger->records['Delivery failed'] ?? [], 'A failed delivery must be logged as failed');
        self::assertArrayNotHasKey('Email processed successfully', $this->logger->records, 'The old misleading log must be gone');
    }
}
