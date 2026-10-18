<?php

require __DIR__ . '/../vendor/autoload.php';

use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;
use ZenoMailer\Middleware\HandlerInterface;
use ZenoMailer\Middleware\MiddlewareInterface;

class SignatureMiddleware implements MiddlewareInterface
{
    private string $signature;

    public function __construct(string $signature)
    {
        $this->signature = $signature;
    }

    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        $htmlBody = $envelope->getHtmlBody();
        if ($htmlBody !== '') {
            $envelope->setHtmlBody($htmlBody . '<br><br>' . $this->signature);
        }

        $textBody = $envelope->getTextBody();
        if ($textBody !== '') {
            $envelope->setTextBody($textBody . "\n\n" . strip_tags($this->signature));
        }

        return $handler->handle($envelope);
    }
}

class UnsubscribeLinkMiddleware implements MiddlewareInterface
{
    private string $unsubscribeUrl;

    public function __construct(string $unsubscribeUrl)
    {
        $this->unsubscribeUrl = $unsubscribeUrl;
    }

    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        $envelope->addHeader('List-Unsubscribe', '<' . $this->unsubscribeUrl . '>');
        $envelope->addHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $handler->handle($envelope);
    }
}

$signature = '<div style="color: #666; font-size: 12px;">
    <strong>John Doe</strong><br>
    CEO, Example Corp<br>
    john@example.com | +1-555-0123
</div>';

$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587')
    ->withMiddleware(new UnsubscribeLinkMiddleware('https://example.com/unsubscribe?token=abc123'))
    ->withMiddleware(new SignatureMiddleware($signature))
    ->enableCssInlining()
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('john@example.com', 'John Doe')
    ->addTo('subscriber@example.com')
    ->setSubject('Monthly Newsletter')
    ->setHtmlBody('<h1>This Month\'s Updates</h1><p>Here\'s what\'s new...</p>');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo "Email sent with custom middleware (signature + unsubscribe link)!\n";
}
