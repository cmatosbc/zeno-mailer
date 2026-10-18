<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

/**
 * Spawns the fake HTTP server script in a background process and exposes
 * its port and the recorded per-request timestamps.
 */
final class FakeHttpServer
{
    /** @var resource|null */
    private $process;

    private string $transcriptFile;

    public readonly int $port;

    public function __construct(int $delayMs, int $expectedRequests)
    {
        $this->transcriptFile = tempnam(sys_get_temp_dir(), 'zeno-http') . '.json';

        $command = [
            PHP_BINARY,
            __DIR__ . '/Integration/fake_http_server.php',
            $this->transcriptFile,
            (string)$delayMs,
            (string)$expectedRequests,
        ];

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start fake HTTP server process');
        }

        $this->process = $process;

        $read = [$pipes[1]];
        $write = null;
        $except = null;
        $selected = stream_select($read, $write, $except, 10);

        if ($selected !== 1) {
            $this->stop();
            throw new \RuntimeException('Fake HTTP server did not report its port in time');
        }

        $line = fgets($pipes[1], 64);
        if ($line === false || !str_starts_with($line, 'PORT=')) {
            $this->stop();
            throw new \RuntimeException('Fake HTTP server did not report a port: ' . var_export($line, true));
        }

        $this->port = (int)substr($line, 5);
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    /**
     * Waits for the server to finish and returns the recorded timestamps.
     *
     * @return list<array{arrival: float, responded_at: float|null}>
     */
    public function getTranscript(): array
    {
        if (isset($this->process) && is_resource($this->process)) {
            $deadline = microtime(true) + 35;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }

            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }

        $raw = file_get_contents($this->transcriptFile);
        $decoded = json_decode((string)$raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Fake HTTP server transcript is not valid JSON');
        }

        $requests = $decoded['requests'] ?? null;
        if (!is_array($requests)) {
            throw new \RuntimeException('Fake HTTP server transcript has no requests');
        }

        /** @var list<array{arrival: float, responded_at: float|null}> $requests */
        return $requests;
    }

    public function stop(): void
    {
        if (isset($this->process) && is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if (file_exists($this->transcriptFile)) {
            unlink($this->transcriptFile);
        }
    }
}
