<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

// 1. Generate a DKIM key pair (do this once, store the private key securely):
//
//    openssl genrsa -out dkim.private.pem 2048
//    openssl rsa -in dkim.private.pem -pubout -out dkim.public.pem
//
// 2. Publish the public key in DNS as a TXT record at "<selector>._domainkey.<domain>":
//
//    v=DKIM1; k=rsa; p=<base64 public key without the BEGIN/END lines>
//
// 3. Sign your mail:

$privateKeyPath = __DIR__ . '/dkim.private.pem';
if (!file_exists($privateKeyPath)) {
    fwrite(STDERR, "Generate a key pair first (see the comments in this file).\n");
    exit(1);
}

$mailer = MailerBuilder::create()
    ->withDsn('smtp://username:password@smtp.example.com:587?starttls=true')
    ->withDkim('example.com', 's1', $privateKeyPath)
    ->build();

$envelope = (new Envelope())
    ->setFrom('sender@example.com', 'Example Corp')
    ->addTo('recipient@example.com', 'Jane Smith')
    ->setSubject('DKIM-signed newsletter')
    ->setHtmlBody('<h1>Hello!</h1><p>This message carries a DKIM-Signature header.</p>')
    ->setTextBody('This message carries a DKIM-Signature header.');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "DKIM-signed email sent!\n";
} else {
    echo 'Failed: ' . $result->getMessage() . "\n";
}
