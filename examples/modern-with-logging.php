<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;
use Psr\Log\AbstractLogger;

class SimpleLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        echo "[{$timestamp}] [{$level}] {$message} {$contextStr}\n";
    }
}

$logger = new SimpleLogger();

$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587')
    ->enableTelemetry(true, $logger)
    ->enableCssInlining()
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('app@example.com', 'My App')
    ->addTo('user@example.com')
    ->setSubject('Test Email with Logging')
    ->setHtmlBody('<style>h1 { color: blue; }</style><h1>Hello World!</h1>');

echo "Sending email with telemetry enabled...\n\n";

$result = $mailer->send($envelope);

echo "\n";
if ($result->isSuccess()) {
    echo "✓ Email sent successfully!\n";
} else {
    echo "✗ Failed: " . $result->getMessage() . "\n";
}
