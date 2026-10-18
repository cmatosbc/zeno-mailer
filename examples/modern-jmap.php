<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('jmap://ACCOUNT_ID:API_TOKEN@jmap.example.com')
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('you@example.com', 'Your Name')
    ->addTo('recipient@example.com')
    ->setSubject('Testing JMAP - The Future of Email')
    ->setHtmlBody('<h1>JMAP Protocol</h1><p>This email was sent using the modern JMAP protocol!</p>');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent via JMAP (JSON Mail Access Protocol)!\n";
    echo "This is the future of email - stateless, JSON-based, and efficient!\n";
}
