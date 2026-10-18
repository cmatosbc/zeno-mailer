<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * An inline (embedded) image referenced from the HTML body via a CID:
 * <img src="cid:logo">
 */
final class EmbeddedImage
{
    private string $path;
    private string $cid;
    private string $name;
    private string $type;
    private ?string $content = null;

    public function __construct(string $path, string $cid, string $name = '', string $type = '')
    {
        if (preg_match('/^[\w.@-]+$/', $cid) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid content ID: {$cid} (allowed: letters, digits, _, ., @, -)"
            );
        }

        if (strpbrk($name, "\r\n") !== false) {
            throw new \InvalidArgumentException('Embedded image name must not contain CR/LF characters');
        }

        $this->path = $path;
        $this->cid = $cid;
        $this->name = $name ?: basename($path);
        $this->type = $type ?: $this->guessType($path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCid(): string
    {
        return $this->cid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContent(): string
    {
        if ($this->content === null) {
            if (!file_exists($this->path) || !is_readable($this->path)) {
                throw new \RuntimeException("Cannot read embedded image file: {$this->path}");
            }
            $content = file_get_contents($this->path);
            if ($content === false) {
                throw new \RuntimeException("Failed to read embedded image file: {$this->path}");
            }
            $this->content = $content;
        }

        return $this->content;
    }

    private function guessType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }
}
