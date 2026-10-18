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
