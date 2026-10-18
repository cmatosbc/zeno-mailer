<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('smtp://username:password@smtp.example.com:587?starttls=true')
    ->enableCssInlining()
    ->enableDarkModeGuard()
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('sender@example.com', 'John Doe')
    ->addTo('recipient@example.com', 'Jane Smith')
    ->setSubject('Welcome to Modern Email!')
    ->setTextBody('This is a plain text email.')
    ->setHtmlBody('<h1>Welcome!</h1><p>This is an HTML email with <strong>modern features</strong>.</p>');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent successfully!\n";
    echo "Message ID: " . $result->getMessageId() . "\n";
} else {
    echo "Failed to send email: " . $result->getMessage() . "\n";
}
