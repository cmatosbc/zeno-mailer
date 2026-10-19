<?php

declare(strict_types=1);

namespace ZenoMailer;

final class Attachment
{
    private string $path;
    private string $name;
    private string $type;
    private string $encoding;
    private ?string $content = null;

    public function __construct(string $path, string $name = '', string $type = '', string $encoding = 'base64')
    {
        if (strpbrk($name, "\r\n") !== false) {
            throw new \InvalidArgumentException('Attachment name must not contain CR/LF characters');
        }

        $this->path = $path;
        $this->name = $name ?: basename($path);
        $this->type = $type ?: 'application/octet-stream';
        $this->encoding = $encoding;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function getContent(): string
    {
        if ($this->content === null) {
            if (!file_exists($this->path) || !is_readable($this->path)) {
                throw new \RuntimeException("Cannot read attachment file: {$this->path}");
            }
            $content = file_get_contents($this->path);
            if ($content === false) {
                throw new \RuntimeException("Failed to read attachment file: {$this->path}");
            }
            $this->content = $content;
        }
        
        return $this->content;
    }

    public function getEncodedContent(): string
    {
        $content = $this->getContent();

        return match ($this->encoding) {
            'base64' => base64_encode($content),
            'quoted-printable' => quoted_printable_encode($content),
            default => $content,
        };
    }

    /**
     * Stream the encoded content to a writable stream with constant memory
     * (chunked read + PHP's native base64 stream filter). Used by the
     * SMTP transport for large attachments.
     *
     * Note: quoted-printable attachments fall back to string encoding —
     * PHP's QP stream filter does not wrap lines (RFC 2045 requires it).
     *
     * @param resource $dest
     */
    public function streamEncodedTo($dest): void
    {
        if (!file_exists($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException("Cannot read attachment file: {$this->path}");
        }

        if ($this->encoding === 'quoted-printable') {
            $written = fwrite($dest, quoted_printable_encode($this->getContent()));
            if ($written === false) {
                throw new \RuntimeException("Failed to write attachment content: {$this->path}");
            }

            return;
        }

        if ($this->encoding !== 'base64') {
            // 7bit/8bit: raw copy
            $source = fopen($this->path, 'rb');
            if ($source === false) {
                throw new \RuntimeException("Failed to open attachment file: {$this->path}");
            }
            $copied = stream_copy_to_stream($source, $dest);
            fclose($source);
            if ($copied === false) {
                throw new \RuntimeException("Failed to stream attachment content: {$this->path}");
            }

            return;
        }

        $source = fopen($this->path, 'rb');
        if ($source === false) {
            throw new \RuntimeException("Failed to open attachment file: {$this->path}");
        }

        $filter = stream_filter_append(
            $source,
            'convert.base64-encode',
            STREAM_FILTER_READ,
            ['line-length' => 76, 'line-end-chars' => "\r\n"]
        );

        if ($filter === false) {
            fclose($source);
            throw new \RuntimeException('Failed to attach the base64 stream filter');
        }

        $copied = stream_copy_to_stream($source, $dest);
        if ($copied === false) {
            fclose($source);
            throw new \RuntimeException("Failed to stream attachment content: {$this->path}");
        }

        fclose($source);
    }
}
