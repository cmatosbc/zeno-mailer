<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('ses://AWS_ACCESS_KEY:AWS_SECRET_KEY@default?region=us-east-1')
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('noreply@yourdomain.com', 'Your Service')
    ->addTo('user@example.com')
    ->setSubject('Password Reset Request')
    ->setHtmlBody('<p>Click <a href="https://example.com/reset">here</a> to reset your password.</p>')
    ->setTextBody('Click this link to reset your password: https://example.com/reset');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent via Amazon SES!\n";
}
