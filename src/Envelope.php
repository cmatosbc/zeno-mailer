<?php

declare(strict_types=1);

namespace ZenoMailer;

final class Envelope
{
    /** @var Address[] */
    private array $to = [];
    /** @var Address[] */
    private array $cc = [];
    /** @var Address[] */
    private array $bcc = [];
    private ?Address $from = null;
    private ?Address $replyTo = null;
    private string $subject = '';
    private string $textBody = '';
    private string $htmlBody = '';
    /** @var Attachment[] */
    private array $attachments = [];
    /** @var EmbeddedImage[] */
    private array $embeddedImages = [];
    /** @var array<string, string> */
    private array $headers = [];
    private string $charset = 'utf-8';
    private string $encoding = '8bit';

    public function addTo(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);
        return $this;
    }

    public function addCc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);
        return $this;
    }

    public function addBcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);
        return $this;
    }

    public function setFrom(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);
        return $this;
    }

    public function setReplyTo(string $email, string $name = ''): self
    {
        $this->replyTo = new Address($email, $name);
        return $this;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function setTextBody(string $body): self
    {
        $this->textBody = $body;
        return $this;
    }

    public function setHtmlBody(string $body): self
    {
        $this->htmlBody = $body;
        return $this;
    }

    public function addAttachment(string $path, string $name = '', string $type = '', string $encoding = 'base64'): self
    {
        $this->attachments[] = new Attachment($path, $name, $type, $encoding);
        return $this;
    }

    /**
     * Embed an image for reference from the HTML body via CID:
     * `<img src="cid:logo">`.
     */
    public function addEmbeddedImage(string $path, string $cid, string $name = '', string $type = ''): self
    {
        $this->embeddedImages[] = new EmbeddedImage($path, $cid, $name, $type);
        return $this;
    }

    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setCharset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function setEncoding(string $encoding): self
    {
        $this->encoding = $encoding;
        return $this;
    }

    /** @return Address[] */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return Address[] */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @return Address[] */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    public function getReplyTo(): ?Address
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTextBody(): string
    {
        return $this->textBody;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    /** @return Attachment[] */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /** @return EmbeddedImage[] */
    public function getEmbeddedImages(): array
    {
        return $this->embeddedImages;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    /** @return Address[] */
    public function getAllRecipients(): array
    {
        return array_merge($this->to, $this->cc, $this->bcc);
    }

    /**
     * Serializable representation for the spool/outbox. Attachments and
     * embedded images are stored by PATH — the files must still exist when
     * the spool is flushed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $addressToArray = static fn (?Address $a): ?array => $a === null
            ? null
            : ['email' => $a->getEmail(), 'name' => $a->getName()];

        return [
            'from' => $addressToArray($this->from),
            'replyTo' => $addressToArray($this->replyTo),
            'to' => array_map($addressToArray, $this->to),
            'cc' => array_map($addressToArray, $this->cc),
            'bcc' => array_map($addressToArray, $this->bcc),
            'subject' => $this->subject,
            'textBody' => $this->textBody,
            'htmlBody' => $this->htmlBody,
            'attachments' => array_map(
                static fn (Attachment $a): array => [
                    'path' => $a->getPath(), 'name' => $a->getName(),
                    'type' => $a->getType(), 'encoding' => $a->getEncoding(),
                ],
                $this->attachments
            ),
            'embeddedImages' => array_map(
                static fn (EmbeddedImage $i): array => [
                    'path' => $i->getPath(), 'cid' => $i->getCid(),
                    'name' => $i->getName(), 'type' => $i->getType(),
                ],
                $this->embeddedImages
            ),
            'headers' => $this->headers,
            'charset' => $this->charset,
            'encoding' => $this->encoding,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $addressFromArray = static function (array $a): Address {
            return new Address((string)($a['email'] ?? ''), (string)($a['name'] ?? ''));
        };

        $envelope = new self();

        $from = $data['from'] ?? null;
        if (is_array($from)) {
            $envelope->from = $addressFromArray($from);
        }

        $replyTo = $data['replyTo'] ?? null;
        if (is_array($replyTo)) {
            $envelope->replyTo = $addressFromArray($replyTo);
        }

        foreach (['to', 'cc', 'bcc'] as $list) {
            $items = $data[$list] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $envelope->{$list}[] = $addressFromArray($item);
                    }
                }
            }
        }

        $envelope->subject = (string)($data['subject'] ?? '');
        $envelope->textBody = (string)($data['textBody'] ?? '');
        $envelope->htmlBody = (string)($data['htmlBody'] ?? '');

        $attachments = $data['attachments'] ?? [];
        if (is_array($attachments)) {
            foreach ($attachments as $a) {
                if (is_array($a)) {
                    $envelope->attachments[] = new Attachment(
                        (string)($a['path'] ?? ''),
                        (string)($a['name'] ?? ''),
                        (string)($a['type'] ?? ''),
                        (string)($a['encoding'] ?? 'base64')
                    );
                }
            }
        }

        $embedded = $data['embeddedImages'] ?? [];
        if (is_array($embedded)) {
            foreach ($embedded as $i) {
                if (is_array($i)) {
                    $envelope->embeddedImages[] = new EmbeddedImage(
                        (string)($i['path'] ?? ''),
                        (string)($i['cid'] ?? ''),
                        (string)($i['name'] ?? ''),
                        (string)($i['type'] ?? '')
                    );
                }
            }
        }

        $headers = $data['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $envelope->headers[$name] = $value;
                }
            }
        }

        $envelope->charset = (string)($data['charset'] ?? 'utf-8');
        $envelope->encoding = (string)($data['encoding'] ?? '8bit');

        return $envelope;
    }

    /**
     * True when any address on this envelope has a UTF-8 local part,
     * requiring the SMTPUTF8 extension (RFC 6531) on the delivery path.
     */
    public function requiresSmtpUtf8(): bool
    {
        $addresses = $this->getAllRecipients();
        if ($this->from !== null) {
            $addresses[] = $this->from;
        }
        if ($this->replyTo !== null) {
            $addresses[] = $this->replyTo;
        }

        foreach ($addresses as $address) {
            if ($address->requiresSmtpUtf8()) {
                return true;
            }
        }

        return false;
    }
}
