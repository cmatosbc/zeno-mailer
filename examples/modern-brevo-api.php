<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('brevo://YOUR_API_KEY@default')
    ->enableTelemetry()
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('sender@example.com', 'Your App')
    ->addTo('user@example.com', 'User Name')
    ->addCc('manager@example.com')
    ->setSubject('Transaction Confirmation')
    ->setHtmlBody('<h1>Thank you for your purchase!</h1><p>Your order has been confirmed.</p>')
    ->setTextBody('Thank you for your purchase! Your order has been confirmed.')
    ->addAttachment('/path/to/invoice.pdf', 'invoice.pdf');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent via Brevo API!\n";
    print_r($result->getMetadata());
} else {
    echo "Error: " . $result->getMessage() . "\n";
}
