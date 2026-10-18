<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('postmark://YOUR_SERVER_TOKEN@default?track_opens=true&track_links=HtmlAndText')
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('notifications@yourdomain.com', 'Notifications')
    ->addTo('customer@example.com')
    ->setSubject('Your Weekly Report')
    ->setHtmlBody('<h2>Weekly Summary</h2><p>Here are your stats for this week...</p>');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent via Postmark with tracking enabled!\n";
    echo "Message ID: " . $result->getMessageId() . "\n";
}
