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
}
