<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

/**
 * Spawns the fake SMTP server script in a background process and exposes
 * its port and the recorded transcript.
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private $process;

    private string $transcriptFile;

    public readonly int $port;

    public function __construct(string $mode = 'ok', int $expectedSessions = 1)
    {
        $this->transcriptFile = tempnam(sys_get_temp_dir(), 'zeno-smtp') . '.json';

        $command = [
            PHP_BINARY,
            __DIR__ . '/Integration/fake_smtp_server.php',
            $this->transcriptFile,
            $mode,
            (string)$expectedSessions,
        ];

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start fake SMTP server process');
        }

        $this->process = $process;

        $read = [$pipes[1]];
        $write = null;
        $except = null;
        $selected = stream_select($read, $write, $except, 10);

        if ($selected !== 1) {
            $this->stop();
            throw new \RuntimeException('Fake SMTP server did not report its port in time');
        }

        $line = fgets($pipes[1], 64);
        if ($line === false || !str_starts_with($line, 'PORT=')) {
            $this->stop();
            throw new \RuntimeException('Fake SMTP server did not report a port: ' . var_export($line, true));
        }

        $this->port = (int)substr($line, 5);
    }

    /**
     * Waits for the server to finish and returns the recorded transcript.
     *
     * @return array{mode: string, commands: list<string>, messages: list<string>, raw_data: ?string}
     */
    public function getTranscript(): array
    {
        if (isset($this->process) && is_resource($this->process)) {
            $deadline = microtime(true) + 10;
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
            throw new \RuntimeException('Fake SMTP server transcript is not valid JSON');
        }

        /** @var array{mode: string, commands: list<string>, messages: list<string>, raw_data: ?string} $decoded */
        return $decoded;
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
