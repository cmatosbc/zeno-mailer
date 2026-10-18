<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Tests\FakeHttpServer;
use ZenoMailer\Transport\BrevoTransport;

/**
 * Empirically verifies whether the "async" batch path sends requests
 * concurrently. The fake server delays every response by DELAY_MS and
 * records when each request ARRIVES, so we can distinguish true
 * concurrency (all arrivals nearly simultaneous) from sequential sending.
 *
 * @group integration
 */
final class BrevoTransportAsyncTest extends TestCase
{
    private const DELAY_MS = 400;
    private const COUNT = 5;

    private function envelope(int $i): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com')
            ->addTo("recipient{$i}@example.com")
            ->setSubject("Async test {$i}")
            ->setTextBody('Hello');
    }

    public function testAsyncBatchSendsRequestsConcurrently(): void
    {
        $server = new FakeHttpServer(self::DELAY_MS, self::COUNT);

        try {
            $transport = new BrevoTransport('test-key', [
                'async' => true,
                'base_url' => "http://127.0.0.1:{$server->port}/v3/smtp/email",
                'timeout' => 10,
            ]);

            $envelopes = [];
            foreach (range(1, self::COUNT) as $i) {
                $envelopes[] = $this->envelope($i);
            }

            $start = microtime(true);
            $results = $transport->sendBatch($envelopes);
            $elapsedMs = (microtime(true) - $start) * 1000;

            $transcript = $server->getTranscript();
            if ($transcript === []) {
                self::fail('No requests recorded by the fake server');
            }
            $arrivals = array_map(static fn (array $r) => $r['arrival'], $transcript);
            $spreadMs = (max($arrivals) - min($arrivals)) * 1000;

            fprintf(STDERR, "\n[async]    elapsed=%6.0fms  arrival_spread=%6.0fms\n", $elapsedMs, $spreadMs);

            self::assertCount(self::COUNT, $results);
            foreach ($results as $result) {
                self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            }

            // All requests must have been received by the server
            self::assertCount(self::COUNT, $transcript);

            // True concurrency: all 5 requests arrive nearly simultaneously
            // (sequential sending would spread them over ~4 x DELAY_MS)
            self::assertLessThan(
                self::DELAY_MS,
                $spreadMs,
                "Requests arrived spread over {$spreadMs}ms - not concurrent"
            );

            // Wall clock: ~one delay, not COUNT x delay
            self::assertLessThan(
                self::DELAY_MS * self::COUNT,
                $elapsedMs,
                "Batch took {$elapsedMs}ms - behaves like sequential sending"
            );
        } finally {
            $server->stop();
        }
    }

    public function testSequentialBatchSendsRequestsOneByOne(): void
    {
        $server = new FakeHttpServer(self::DELAY_MS, self::COUNT);

        try {
            $transport = new BrevoTransport('test-key', [
                'async' => false,
                'base_url' => "http://127.0.0.1:{$server->port}/v3/smtp/email",
                'timeout' => 10,
            ]);

            $envelopes = [];
            foreach (range(1, self::COUNT) as $i) {
                $envelopes[] = $this->envelope($i);
            }

            $start = microtime(true);
            $results = $transport->sendBatch($envelopes);
            $elapsedMs = (microtime(true) - $start) * 1000;

            $transcript = $server->getTranscript();
            if ($transcript === []) {
                self::fail('No requests recorded by the fake server');
            }
            $arrivals = array_map(static fn (array $r) => $r['arrival'], $transcript);
            $spreadMs = (max($arrivals) - min($arrivals)) * 1000;

            fprintf(STDERR, "\n[sequence] elapsed=%6.0fms  arrival_spread=%6.0fms\n", $elapsedMs, $spreadMs);

            self::assertCount(self::COUNT, $results);
            foreach ($results as $result) {
                self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());
            }

            // Sequential: requests arrive one per delay interval
            self::assertGreaterThan(
                (self::COUNT - 1) * self::DELAY_MS * 0.8,
                $spreadMs,
                "Requests arrived within {$spreadMs}ms - unexpectedly concurrent"
            );
        } finally {
            $server->stop();
        }
    }
}
