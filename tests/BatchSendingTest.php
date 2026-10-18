<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Mailer;
use ZenoMailer\MailerBuilder;
use ZenoMailer\Transport\SequentialBatchSending;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;

final class BatchSendingTest extends TestCase
{
    private function envelope(string $to): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo($to)
            ->setSubject('Batch')
            ->setTextBody('hi');
    }

    public function testTraitProvidesSequentialBatch(): void
    {
        $transport = new class () implements TransportInterface {
            use SequentialBatchSending;

            /** @var list<string> */
            public array $sent = [];

            public function send(Envelope $envelope): TransportResult
            {
                $this->sent[] = $envelope->getAllRecipients()[0]->getEmail();

                return TransportResult::success('sent', 'id-' . count($this->sent));
            }
        };

        $results = $transport->sendBatch([$this->envelope('a@example.com'), $this->envelope('b@example.com')]);

        self::assertCount(2, $results);
        self::assertSame(['a@example.com', 'b@example.com'], $transport->sent);
        self::assertSame('id-1', $results[0]->getMessageId());
        self::assertSame('id-2', $results[1]->getMessageId());
    }

    public function testMailerSendBatchRunsMiddlewareOnEveryEnvelope(): void
    {
        // THE regression test for the P2 fix: batch envelopes must go through
        // the pipeline (the old README example bypassed middleware entirely).
        $transport = new class () implements TransportInterface {
            use SequentialBatchSending;

            /** @var list<Envelope> */
            public array $received = [];

            public function send(Envelope $envelope): TransportResult
            {
                $this->received[] = $envelope;

                return TransportResult::success('sent', 'id');
            }
        };

        $mailer = new Mailer($transport);
        $mailer->getPipeline()->pipe(new class () implements \ZenoMailer\Middleware\MiddlewareInterface {
            public function process(Envelope $envelope, \ZenoMailer\Middleware\HandlerInterface $handler): Envelope
            {
                $envelope->addHeader('X-Processed', 'yes');

                return $handler->handle($envelope);
            }
        });

        $results = $mailer->sendBatch([$this->envelope('a@example.com'), $this->envelope('b@example.com')]);

        self::assertCount(2, $results);
        self::assertTrue($result = $results[0]->isSuccess());
        foreach ($transport->received as $received) {
            self::assertSame('yes', $received->getHeaders()['X-Processed'], 'Middleware must run for batch envelopes');
        }
    }

    public function testResilienceDecoratorsApplyToBatches(): void
    {
        // RetryTransport's sendBatch must retry per envelope (via its send()).
        $flaky = new class () implements TransportInterface {
            use SequentialBatchSending;

            public int $attempts = 0;

            public function send(Envelope $envelope): TransportResult
            {
                $this->attempts++;

                // fail transiently on the first call only
                return $this->attempts === 1
                    ? TransportResult::failure('transient', [], true)
                    : TransportResult::success('sent', 'id');
            }
        };

        $retry = new \ZenoMailer\Transport\RetryTransport($flaky, 3, 1.0);
        $results = $retry->sendBatch([$this->envelope('a@example.com'), $this->envelope('b@example.com')]);

        self::assertCount(2, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertTrue($results[1]->isSuccess());
        self::assertSame(3, $flaky->attempts, 'First envelope retried once (2 attempts), second sent once');
    }

    public function testBuilderWithBrevoStyleBatchViaMailer(): void
    {
        // A transport overriding sendBatch (like Brevo's async version) is
        // reachable through Mailer::sendBatch.
        $mailer = MailerBuilder::create()
            ->withTransport(new class () implements TransportInterface {
                public function send(Envelope $envelope): TransportResult
                {
                    return TransportResult::failure('use the batch endpoint');
                }

                public function sendBatch(array $envelopes): array
                {
                    return array_map(
                        static fn () => TransportResult::success('batched', 'batch-id'),
                        $envelopes
                    );
                }
            })
            ->build();

        $results = $mailer->sendBatch([$this->envelope('a@example.com')]);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertSame('batch-id', $results[0]->getMessageId());
    }
}
