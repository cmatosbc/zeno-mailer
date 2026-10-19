<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * Stream filter implementing RFC 5321 section 4.5.2 dot-stuffing on the fly:
 * every line starting with "." gets an extra leading dot. Works chunk-by-chunk
 * with constant memory, including lines split across chunk boundaries.
 *
 * Register once: stream_filter_register('zeno.dotstuff', DotStuffStreamFilter::class)
 */
final class DotStuffStreamFilter extends \php_user_filter
{
    public const NAME = 'zeno.dotstuff';

    /** @var string Incomplete trailing line, waiting for more input */
    private string $pending = '';

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->pending .= $bucket->data;
            $consumed = $consumed + (int)$bucket->datalen;

            $lines = explode("\n", $this->pending);
            $this->pending = array_pop($lines);

            $stuffed = '';
            foreach ($lines as $line) {
                $stuffed .= ($line !== '' && $line[0] === '.') ? '.' . $line : $line;
                $stuffed .= "\n";
            }

            if ($stuffed !== '') {
                stream_bucket_append($out, stream_bucket_new($this->stream, $stuffed));
            }
        }

        if ($closing) {
            // Flush the trailing line (the message's last line has no newline)
            if ($this->pending !== '' && $this->pending[0] === '.') {
                $this->pending = '.' . $this->pending;
            }
            if ($this->pending !== '') {
                stream_bucket_append($out, stream_bucket_new($this->stream, $this->pending));
                $this->pending = '';
            }
        }

        return PSFS_PASS_ON;
    }

    /**
     * Idempotent registration of the filter name.
     */
    public static function register(): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }
}
