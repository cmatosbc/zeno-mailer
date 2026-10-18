<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;
use ZenoMailer\Transport\FailoverTransport;
use ZenoMailer\Transport\RateLimitedTransport;
use ZenoMailer\Transport\RateLimiter;
use ZenoMailer\Transport\RetryTransport;
use ZenoMailer\Transport\TransportInterface;
use ZenoMailer\Transport\TransportResult;

final class ResilienceTest extends TestCase
{
    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo('to@example.com')
            ->setTextBody('hi');
    }

    /**
     * @param list<bool|null> $script true = success, false = permanent failure, null = transient failure
     * @return TransportInterface&object{attempts: int}
     */
    private function scriptedTransport(array $script): TransportInterface
    {
        $transport = new class ($script) implements TransportInterface {
            public int $attempts = 0;

            /** @param list<bool|null> $script */
            public function __construct(private array $script)
            {
            }

            public function send(Envelope $envelope): TransportResult
            {
                $index = min($this->attempts, count($this->script) - 1);
                // array_key_exists, not ?? - null (transient) must not fall through to false
                $behavior = array_key_exists($index, $this->script) ? $this->script[$index] : false;
                $this->attempts++;

                return match ($behavior) {
                    true => TransportResult::success('sent', 'id-' . $this->attempts),
                    null => TransportResult::failure('temporary failure', [], true),
                    default => TransportResult::failure('permanent failure'),
                };
            }
        };

        return $transport;
    }

    // ---------- RetryTransport ----------

    public function testRetrySucceedsWithoutRetriesWhenFirstAttemptWorks(): void
    {
        $inner = $this->scriptedTransport([true]);
        $retry = new RetryTransport($inner, 3, 1.0);

        $result = $retry->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $inner->attempts);
    }

    public function testRetryRetriesTransientFailuresUntilSuccess(): void
    {
        $inner = $this->scriptedTransport([null, null, true]);
        $retry = new RetryTransport($inner, 5, 1.0);

        $result = $retry->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(3, $inner->attempts);
    }

    public function testRetryDoesNotRetryPermanentFailures(): void
    {
        $inner = $this->scriptedTransport([false]);
        $retry = new RetryTransport($inner, 5, 1.0);

        $result = $retry->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertSame('permanent failure', $result->getMessage());
        self::assertSame(1, $inner->attempts);
    }

    public function testRetryExhaustsAttemptsAndReturnsLastFailure(): void
    {
        $inner = $this->scriptedTransport([null, null, null, null]);
        $retry = new RetryTransport($inner, 3, 1.0);

        $result = $retry->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertSame(3, $inner->attempts);
        self::assertSame('temporary failure', $result->getMessage());
    }

    public function testBackoffDelayStaysWithinJitterBounds(): void
    {
        $retry = new RetryTransport(new class () implements TransportInterface {
            public function send(Envelope $envelope): TransportResult
            {
                return TransportResult::failure('x');
            }
        }, 3, 100.0, 400.0);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $ceiling = min(400.0, 100.0 * (2 ** ($attempt - 1)));
            for ($i = 0; $i < 50; $i++) {
                $delay = $retry->backoffDelayMs($attempt);
                self::assertGreaterThanOrEqual(0.0, $delay);
                self::assertLessThanOrEqual($ceiling + 0.001, $delay);
            }
        }
    }

    // ---------- RateLimiter / RateLimitedTransport ----------

    public function testRateLimiterAllowsBurstUpToCapacity(): void
    {
        $limiter = new RateLimiter(5, 60.0); // 5 per minute, full bucket

        $t0 = microtime(true);
        for ($i = 0; $i < 5; $i++) {
            $limiter->acquire();
        }
        $elapsed = microtime(true) - $t0;

        self::assertLessThan(0.1, $elapsed, 'A full bucket should not block');
    }

    public function testRateLimiterBlocksBeyondCapacity(): void
    {
        $limiter = new RateLimiter(2, 1.0); // 2 per second

        $t0 = microtime(true);
        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire(); // third must wait ~0.5s for a refill token
        $elapsed = microtime(true) - $t0;

        self::assertGreaterThan(0.3, $elapsed, 'Third acquire should have blocked for ~0.5s');
        self::assertLessThan(2.0, $elapsed);
    }

    public function testRateLimitedTransportPassesThroughToInner(): void
    {
        $inner = $this->scriptedTransport([true, true]);
        $limited = new RateLimitedTransport($inner, new RateLimiter(10, 1.0));

        $result = $limited->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $inner->attempts);
    }

    // ---------- FailoverTransport ----------

    public function testFailoverUsesPrimaryWhenItSucceeds(): void
    {
        $primary = $this->scriptedTransport([true]);
        $backup = $this->scriptedTransport([true]);
        $failover = new FailoverTransport([$primary, $backup]);

        $result = $failover->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $primary->attempts);
        self::assertSame(0, $backup->attempts);
    }

    public function testFailoverFallsBackWhenPrimaryFails(): void
    {
        $primary = $this->scriptedTransport([false]);
        $backup = $this->scriptedTransport([true]);
        $failover = new FailoverTransport([$primary, $backup]);

        $result = $failover->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $primary->attempts);
        self::assertSame(1, $backup->attempts);
    }

    public function testFailoverTriesCandidatesInOrder(): void
    {
        $first = $this->scriptedTransport([null]);
        $second = $this->scriptedTransport([false]);
        $third = $this->scriptedTransport([true]);
        $failover = new FailoverTransport([$first, $second, $third]);

        $result = $failover->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $first->attempts);
        self::assertSame(1, $second->attempts);
        self::assertSame(1, $third->attempts);
    }

    public function testFailoverAggregatesWhenAllFail(): void
    {
        $failover = new FailoverTransport([
            $this->scriptedTransport([false]),
            $this->scriptedTransport([null]),
        ]);

        $result = $failover->send($this->envelope());

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('All 2 transports failed', $result->getMessage());
        self::assertCount(2, $result->getMetadata()['attempts']);
    }

    public function testFailoverRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FailoverTransport([]);
    }

    // ---------- Builder wiring ----------

    public function testBuilderWrapsWithResilienceLayers(): void
    {
        $mailer = MailerBuilder::create()
            ->withTransport($this->scriptedTransport([true]))
            ->withRetry(3, 10.0)
            ->withRateLimit(100, 'minute')
            ->build();

        $transport = $mailer->getTransport();

        self::assertInstanceOf(RetryTransport::class, $transport);
        $property = new \ReflectionProperty($transport, 'inner');
        $property->setAccessible(true);
        self::assertInstanceOf(RateLimitedTransport::class, $property->getValue($transport));
    }

    public function testBuilderFailoverWrapsAllCandidates(): void
    {
        $mailer = MailerBuilder::create()
            ->withTransport($this->scriptedTransport([false]))
            ->withFailover($this->scriptedTransport([true]))
            ->build();

        $transport = $mailer->getTransport();

        self::assertInstanceOf(FailoverTransport::class, $transport);

        $result = $mailer->send($this->envelope());
        self::assertTrue($result->isSuccess(), 'Failover should rescue the send');
    }

    public function testBuilderRetryPlusFailoverExhaustsPrimaryBeforeSwitching(): void
    {
        $primary = $this->scriptedTransport([null, null, false]); // transient twice, then permanent
        $backup = $this->scriptedTransport([true]);

        $mailer = MailerBuilder::create()
            ->withTransport($primary)
            ->withRetry(3, 1.0)
            ->withFailover($backup)
            ->build();

        $result = $mailer->send($this->envelope());

        self::assertTrue($result->isSuccess());
        self::assertSame(3, $primary->attempts, 'Primary should be exhausted (3 attempts) before failover');
        self::assertSame(1, $backup->attempts);
    }

    public function testInvalidRateLimitPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailerBuilder::create()->withRateLimit(10, 'fortnight');
    }
}
