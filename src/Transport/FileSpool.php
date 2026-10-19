<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Envelope;

/**
 * File-based spool: one JSON file per queued message. Attachments and
 * embedded images are stored by path — the files must still exist when the
 * spool is flushed.
 */
final class FileSpool
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Failed to create the spool directory: {$directory}");
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException("Spool directory is not writable: {$directory}");
        }
    }

    /**
     * @return string The spool entry id (file name)
     */
    public function store(Envelope $envelope, string $error, int $attempts): string
    {
        $id = bin2hex(random_bytes(8));
        $entry = [
            'id' => $id,
            'attempts' => $attempts,
            'error' => $error,
            'createdAt' => date(DATE_ATOM),
            'envelope' => $envelope->toArray(),
        ];

        $bytes = json_encode($entry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $written = file_put_contents($this->pathFor($id), $bytes);
        if ($written === false) {
            throw new \RuntimeException("Failed to write spool entry: {$id}");
        }

        return $id;
    }

    /**
     * @return list<array{id: string, envelope: Envelope, error: string, attempts: int, createdAt: string}>
     */
    public function load(): array
    {
        $entries = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue; // corrupt entry - skip
            }

            if (!is_array($decoded) || !isset($decoded['id']) || !is_array($decoded['envelope'] ?? null)) {
                continue;
            }

            $entries[] = [
                'id' => (string)$decoded['id'],
                'envelope' => Envelope::fromArray($decoded['envelope']),
                'error' => (string)($decoded['error'] ?? ''),
                'attempts' => (int)($decoded['attempts'] ?? 0),
                'createdAt' => (string)($decoded['createdAt'] ?? ''),
            ];
        }

        return $entries;
    }

    public function remove(string $id): void
    {
        $file = $this->pathFor($id);
        if (file_exists($file) && !unlink($file)) {
            throw new \RuntimeException("Failed to remove spool entry: {$id}");
        }
    }

    public function count(): int
    {
        return count(glob($this->directory . '/*.json') ?: []);
    }

    private function pathFor(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
