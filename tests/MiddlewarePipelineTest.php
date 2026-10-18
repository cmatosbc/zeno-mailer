<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Mailer;
use ZenoMailer\Middleware\HandlerInterface;
use ZenoMailer\Middleware\MiddlewareInterface;
use ZenoMailer\Middleware\MiddlewarePipeline;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewareRunsInPipeOrder(): void
    {
        /** @var list<string> $order */
        $order = [];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(self::recordingMiddleware($order, 'first'));
        $pipeline->pipe(self::recordingMiddleware($order, 'second'));

        $pipeline->process(new Envelope());

        self::assertSame(['first', 'second'], $order);
    }

    public function testFallbackHandlerIsCalledAfterMiddleware(): void
    {
        /** @var list<string> $order */
        $order = [];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(self::recordingMiddleware($order, 'middleware'));

        $fallback = new class ($order) implements HandlerInterface {
            /** @param list<string> $order */
            public function __construct(private array &$order)
            {
            }

            public function handle(Envelope $envelope): Envelope
            {
                $this->order[] = 'fallback';

                return $envelope;
            }

            /** @return list<string> */
            public function getOrder(): array
            {
                return $this->order;
            }
        };
        $pipeline->setFallbackHandler($fallback);

        $pipeline->process(new Envelope());

        self::assertSame(['middleware', 'fallback'], $fallback->getOrder());
    }

    public function testProcessDoesNotConsumeThePipeline(): void
    {
        /** @var list<string> $order */
        $order = [];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(self::recordingMiddleware($order, 'm1'));

        $pipeline->process(new Envelope());
        $pipeline->process(new Envelope());

        self::assertSame(['m1', 'm1'], $order);
    }

    public function testEmptyPipelineWithoutFallbackReturnsEnvelope(): void
    {
        $pipeline = new MiddlewarePipeline();
        $envelope = new Envelope();

        self::assertSame($envelope, $pipeline->process($envelope));
    }

    public function testMailerSendRunsPipelineThenTransport(): void
    {
        /** @var list<string> $order */
        $order = [];

        $transport = new class implements TransportInterface {
            use \ZenoMailer\Transport\SequentialBatchSending;

            public ?Envelope $received = null;

            public function send(Envelope $envelope): TransportResult
            {
                $this->received = $envelope;

                return TransportResult::success('sent', 'id-123');
            }
        };

        $mailer = new Mailer($transport);
        $mailer->getPipeline()->pipe(self::recordingMiddleware($order, 'm'));

        $envelope = (new Envelope())->setSubject('modified by middleware');
        $result = $mailer->send($envelope);

        self::assertSame(['m'], $order);
        self::assertTrue($result->isSuccess());
        self::assertSame('id-123', $result->getMessageId());
        self::assertSame($envelope, $transport->received);
    }

    /**
     * @param list<string> $order
     */
    private static function recordingMiddleware(array &$order, string $name): MiddlewareInterface
    {
        return new class ($order, $name) implements MiddlewareInterface {
            /** @param list<string> $order */
            public function __construct(
                private array &$order,
                private readonly string $name
            ) {
            }

            public function process(Envelope $envelope, HandlerInterface $handler): Envelope
            {
                $this->order[] = $this->name;

                return $handler->handle($envelope);
            }

            /** @return list<string> */
            public function getOrder(): array
            {
                return $this->order;
            }
        };
    }
}
