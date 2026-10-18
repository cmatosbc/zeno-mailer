<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * Serializes an Envelope into a standards-compliant RFC 5322 message:
 *
 * - validates and sanitizes all header input (no CR/LF injection possible)
 * - RFC 2047 encodes non-ASCII header text (subjects, display names, filenames)
 * - folds long headers (78 char recommendation, 998 hard limit)
 * - applies the requested Content-Transfer-Encoding to bodies and attachments
 * - builds single-part, multipart/alternative and multipart/mixed structures
 * - never emits a Bcc header (recipients stay in the transport envelope)
 */
final class MessageSerializer
{
    private const CRLF = "\r\n";

    /** Header names that must not be set through Envelope::addHeader(). */
    private const FORBIDDEN_HEADERS = [
        'bcc',
        'content-type',
        'content-transfer-encoding',
        'content-disposition',
        'mime-version',
        'to',
        'cc',
        'from',
        'subject',
        'reply-to',
    ];

    public function serialize(Envelope $envelope): SerializedMessage
    {
        $this->validate($envelope);

        $from = $envelope->getFrom();
        if ($from === null) {
            throw new \InvalidArgumentException('Envelope must have a From address (call setFrom())');
        }

        if ($envelope->getAllRecipients() === []) {
            throw new \InvalidArgumentException('Envelope must have at least one recipient');
        }

        $text = $envelope->getTextBody();
        $html = $envelope->getHtmlBody();
        $attachments = $envelope->getAttachments();
        $embeddedImages = $envelope->getEmbeddedImages();

        if ($text === '' && $html === '' && $attachments === [] && $embeddedImages === []) {
            throw new \InvalidArgumentException('Envelope must have a text body, an HTML body or attachments');
        }

        if ($embeddedImages !== [] && $html === '') {
            throw new \InvalidArgumentException(
                'Embedded images require an HTML body (reference them with <img src="cid:...">)'
            );
        }

        $mixedBoundary = $attachments !== [] ? $this->generateBoundary('mixed') : null;
        $relatedBoundary = $embeddedImages !== [] ? $this->generateBoundary('related') : null;
        $altBoundary = ($text !== '' && $html !== '') ? $this->generateBoundary('alt') : null;

        if ($mixedBoundary === null && $relatedBoundary === null && $altBoundary === null) {
            $contentType = ($html !== '' ? 'text/html' : 'text/plain') . '; charset=' . $envelope->getCharset();
            $content = $html !== '' ? $html : $text;
            [$encoded, $transferEncoding] = $this->encodeBody($content, $envelope->getEncoding());

            $headers = $this->buildHeaders($envelope, $from, $contentType, $transferEncoding);
            $body = $encoded;
        } else {
            $contentType = match (true) {
                $mixedBoundary !== null => 'multipart/mixed; boundary="' . $mixedBoundary . '"',
                $relatedBoundary !== null => 'multipart/related; boundary="' . $relatedBoundary . '"; type="text/html"',
                default => 'multipart/alternative; boundary="' . $altBoundary . '"',
            };

            $headers = $this->buildHeaders($envelope, $from, $contentType, null);
            $body = $this->buildMultipartBody($envelope, $mixedBoundary, $relatedBoundary, $altBoundary);
        }

        $message = implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $body;

        return new SerializedMessage($message, $this->resolveMessageId($headers));
    }

    private function validate(Envelope $envelope): void
    {
        if (strpbrk($envelope->getSubject(), "\r\n") !== false) {
            throw new \InvalidArgumentException('Subject must not contain CR/LF characters');
        }

        foreach ($envelope->getHeaders() as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
                throw new \InvalidArgumentException("Invalid header name: {$name}");
            }

            if (strpbrk($value, "\r\n\0") !== false) {
                throw new \InvalidArgumentException(
                    "Header value for {$name} must not contain CR/LF/NUL characters"
                );
            }

            if (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
                throw new \InvalidArgumentException(
                    "Header {$name} is managed by the serializer and cannot be overridden"
                );
            }
        }

        foreach ($envelope->getAttachments() as $attachment) {
            if (strpbrk($attachment->getName(), "\r\n") !== false) {
                throw new \InvalidArgumentException('Attachment name must not contain CR/LF characters');
            }
        }
    }

    /**
     * @return string[] Header lines
     */
    private function buildHeaders(
        Envelope $envelope,
        Address $from,
        string $contentType,
        ?string $transferEncoding
    ): array {
        $custom = $envelope->getHeaders();
        $dateOverride = null;
        $messageIdOverride = null;

        foreach ($custom as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'date') {
                $dateOverride = $value;
            } elseif ($lower === 'message-id') {
                $messageIdOverride = $value;
            }
        }

        $headers = [];
        $headers[] = 'From: ' . $from->toString();
        $this->appendAddressList($headers, 'To', $envelope->getTo());
        $this->appendAddressList($headers, 'Cc', $envelope->getCc());

        $replyTo = $envelope->getReplyTo();
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo->toString();
        }

        $headers[] = HeaderEncoder::headerLine('Subject', $envelope->getSubject(), $envelope->getCharset());

        $headers[] = 'Date: ' . ($dateOverride ?? date(DATE_RFC2822));
        $headers[] = 'Message-ID: <' . ($messageIdOverride ?? $this->generateMessageId($from)) . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: ' . $contentType;

        if ($transferEncoding !== null) {
            $headers[] = 'Content-Transfer-Encoding: ' . $transferEncoding;
        }

        foreach ($custom as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'date' || $lower === 'message-id') {
                continue;
            }
            $headers[] = HeaderEncoder::headerLine((string) $name, (string) $value, $envelope->getCharset());
        }

        return $headers;
    }

    private function buildMultipartBody(
        Envelope $envelope,
        ?string $mixedBoundary,
        ?string $relatedBoundary,
        ?string $altBoundary
    ): string {
        $text = $envelope->getTextBody();
        $html = $envelope->getHtmlBody();
        $attachments = $envelope->getAttachments();
        $embeddedImages = $envelope->getEmbeddedImages();

        // Innermost content: alternative pair or a single part
        $contentPart = null;
        if ($altBoundary !== null) {
            $alternativeBody = $this->renderMultipart($altBoundary, [
                $this->renderTextPart('text/plain', $text, $envelope),
                $this->renderTextPart('text/html', $html, $envelope),
            ]);
            $contentPart = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"'
                . self::CRLF . self::CRLF . $alternativeBody;
        } elseif ($html !== '') {
            [$encoded, $transferEncoding] = $this->encodeBody($html, $envelope->getEncoding());
            $contentPart = 'Content-Type: text/html; charset=' . $envelope->getCharset()
                . self::CRLF . 'Content-Transfer-Encoding: ' . $transferEncoding
                . self::CRLF . self::CRLF . $encoded;
        } elseif ($text !== '') {
            [$encoded, $transferEncoding] = $this->encodeBody($text, $envelope->getEncoding());
            $contentPart = 'Content-Type: text/plain; charset=' . $envelope->getCharset()
                . self::CRLF . 'Content-Transfer-Encoding: ' . $transferEncoding
                . self::CRLF . self::CRLF . $encoded;
        }

        // Wrap the content in multipart/related when there are embedded images
        if ($relatedBoundary !== null) {
            if ($contentPart === null) {
                throw new \LogicException('Embedded images require a content part');
            }

            $imageParts = array_map(
                fn (EmbeddedImage $image): string => $this->buildEmbeddedImagePart($image, $envelope->getCharset()),
                $embeddedImages
            );

            $relatedBody = $this->renderMultipart($relatedBoundary, [$contentPart, ...$imageParts]);
            $contentPart = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"; type="text/html"'
                . self::CRLF . self::CRLF . $relatedBody;
        }

        if ($mixedBoundary === null) {
            if ($relatedBoundary === null) {
                if ($altBoundary === null) {
                    throw new \LogicException('Multipart body requested without a boundary');
                }

                return $this->renderMultipart($altBoundary, [
                    $this->renderTextPart('text/plain', $text, $envelope),
                    $this->renderTextPart('text/html', $html, $envelope),
                ]);
            }

            return $contentPart;
        }

        $parts = [];
        if ($contentPart !== null) {
            $parts[] = $contentPart;
        }

        foreach ($attachments as $attachment) {
            $parts[] = $this->buildAttachmentPart($attachment, $envelope->getCharset());
        }

        return $this->renderMultipart($mixedBoundary, $parts);
    }

    private function renderTextPart(string $contentType, string $content, Envelope $envelope): string
    {
        [$encoded, $transferEncoding] = $this->encodeBody($content, $envelope->getEncoding());

        return 'Content-Type: ' . $contentType . '; charset=' . $envelope->getCharset()
            . self::CRLF . 'Content-Transfer-Encoding: ' . $transferEncoding
            . self::CRLF . self::CRLF . $encoded;
    }

    /**
     * @return array{0: string, 1: string} Encoded body and the effective transfer encoding
     */
    private function encodeBody(string $content, string $encoding): array
    {
        $content = $this->normalizeNewlines($content);

        return match ($encoding) {
            'base64' => [rtrim(chunk_split(base64_encode($content), 76, self::CRLF), self::CRLF), 'base64'],
            'quoted-printable' => [quoted_printable_encode($content), 'quoted-printable'],
            '7bit' => HeaderEncoder::isAscii($content) && !$this->hasOverlongLine($content)
                ? [$content, '7bit']
                : [quoted_printable_encode($content), 'quoted-printable'],
            // 8bit bodies containing lines longer than the RFC 5322 hard limit
            // (998 chars - e.g. minified HTML) are upgraded to quoted-printable,
            // which enforces line length without altering the content.
            default => $this->hasOverlongLine($content)
                ? [quoted_printable_encode($content), 'quoted-printable']
                : [$content, '8bit'],
        };
    }

    private function hasOverlongLine(string $content): bool
    {
        return preg_match('/[^\r\n]{999,}/', $content) === 1;
    }

    private function buildAttachmentPart(Attachment $attachment, string $charset): string
    {
        $filename = HeaderEncoder::isAscii($attachment->getName())
            ? HeaderEncoder::escapeQuotedString($attachment->getName())
            : HeaderEncoder::encodeText($attachment->getName(), $charset);

        $content = $attachment->getContent();
        [$encoded, $transferEncoding] = match ($attachment->getEncoding()) {
            'quoted-printable' => [quoted_printable_encode($content), 'quoted-printable'],
            '7bit', '8bit' => [$content, $attachment->getEncoding()],
            default => [rtrim(chunk_split(base64_encode($content), 76, self::CRLF), self::CRLF), 'base64'],
        };

        return 'Content-Type: ' . $attachment->getType() . '; name=' . $filename
            . self::CRLF . 'Content-Transfer-Encoding: ' . $transferEncoding
            . self::CRLF . 'Content-Disposition: attachment; filename=' . $filename
            . self::CRLF . self::CRLF . $encoded;
    }

    private function buildEmbeddedImagePart(EmbeddedImage $image, string $charset): string
    {
        $filename = HeaderEncoder::isAscii($image->getName())
            ? HeaderEncoder::escapeQuotedString($image->getName())
            : HeaderEncoder::encodeText($image->getName(), $charset);

        $encoded = rtrim(chunk_split(base64_encode($image->getContent()), 76, self::CRLF), self::CRLF);

        return 'Content-Type: ' . $image->getType() . '; name=' . $filename
            . self::CRLF . 'Content-Transfer-Encoding: base64'
            . self::CRLF . 'Content-ID: <' . $image->getCid() . '>'
            . self::CRLF . 'Content-Disposition: inline; filename=' . $filename
            . self::CRLF . self::CRLF . $encoded;
    }

    /**
     * @param string[] $parts Full parts ("headers CRLF CRLF body", no trailing CRLF)
     */
    private function renderMultipart(string $boundary, array $parts): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= '--' . $boundary . self::CRLF . $part . self::CRLF;
        }

        return $body . '--' . $boundary . '--';
    }

    /**
     * @param Address[] $addresses
     * @param string[] $headers
     */
    private function appendAddressList(array &$headers, string $name, array $addresses): void
    {
        if ($addresses === []) {
            return;
        }

        $entries = array_map(static fn (Address $address): string => $address->toString(), $addresses);
        $headers[] = $this->foldAddressList($name, $entries);
    }

    /**
     * @param string[] $entries
     */
    private function foldAddressList(string $name, array $entries): string
    {
        $line = $name . ': ' . implode(', ', $entries);
        if (strlen($line) <= 998) {
            return $line;
        }

        $folded = $name . ':';
        $current = '';
        $hasFolded = false;
        foreach ($entries as $entry) {
            $candidate = $current === '' ? $entry : $current . ', ' . $entry;
            if (strlen($candidate) > 900 && $current !== '') {
                $folded .= ' ' . $current . ',' . self::CRLF . ' ';
                $hasFolded = true;
                $current = $entry;
            } else {
                $current = $candidate;
            }
        }

        return $folded . ($hasFolded ? '' : ' ') . $current;
    }

    private function normalizeNewlines(string $content): string
    {
        return preg_replace('/\r\n|\r|\n/', self::CRLF, $content) ?? $content;
    }

    private function generateBoundary(string $kind): string
    {
        return '----=_Zeno_' . $kind . '_' . bin2hex(random_bytes(16));
    }

    private function generateMessageId(Address $from): string
    {
        $domain = substr(strrchr('@' . $from->getEmail(), '@') ?: '@', 1);

        return bin2hex(random_bytes(16)) . '@' . $domain;
    }

    /**
     * @param string[] $headers
     */
    private function resolveMessageId(array $headers): string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'message-id:')) {
                $value = trim(substr($header, strlen('Message-ID:')));

                return trim($value, '<>');
            }
        }

        return '';
    }
}
