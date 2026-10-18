<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

final class TransportFactory
{
    public static function fromDsn(string $dsn): TransportInterface
    {
        $parsed = parse_url($dsn);
        
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException("Invalid DSN: {$dsn}");
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? null;
        $user = isset($parsed['user']) && $parsed['user'] !== '' ? urldecode($parsed['user']) : null;
        $pass = isset($parsed['pass']) && $parsed['pass'] !== '' ? urldecode($parsed['pass']) : null;

        parse_str($parsed['query'] ?? '', $options);
        /** @var array<string, mixed> $options */

        $requireUser = static function () use ($user, $dsn): string {
            if ($user === null) {
                throw new \InvalidArgumentException("DSN requires credentials in the user part: {$dsn}");
            }

            return $user;
        };

        return match ($scheme) {
            'smtp', 'smtps' => new SmtpTransport(
                $host,
                $port ?? ($scheme === 'smtps' ? 465 : 25),
                $scheme === 'smtps',
                $user,
                $pass,
                $options
            ),
            'brevo', 'sendinblue' => new BrevoTransport($requireUser(), $options),
            'postmark' => new PostmarkTransport($requireUser(), $options),
            'ses' => new SesTransport(
                $requireUser(),
                $pass ?? throw new \InvalidArgumentException("SES requires the secret key in the DSN password part: {$dsn}"),
                (string)($options['region'] ?? 'us-east-1'),
                $options
            ),
            'jmap' => new JmapTransport(
                $host,
                $user ?? '',
                $pass ?? throw new \InvalidArgumentException("JMAP requires the API token in the DSN password part: {$dsn}"),
                $options
            ),
            default => throw new \InvalidArgumentException("Unsupported transport scheme: {$scheme}"),
        };
    }
}
