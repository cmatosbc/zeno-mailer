<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

/**
 * PSR-3 logger that records every call for assertions.
 */
final class SpyLogger implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    /** @var array<string, list<array<string, mixed>>> */
    public array $records = [];

    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->records[(string)$message][] = $context;
    }
}
