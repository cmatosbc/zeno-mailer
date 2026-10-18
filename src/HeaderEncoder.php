<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * RFC 2047 / RFC 5322 header text encoding utilities.
 */
final class HeaderEncoder
{
    private const ENCODED_WORD_MAX_LENGTH = 75;

    public static function isAscii(string $text): bool
    {
        return preg_match('/[\x80-\xFF]/', $text) !== 1;
    }

    /**
     * Encode arbitrary header text (e.g. Subject) as one or more RFC 2047
     * encoded-words when it contains non-ASCII bytes. ASCII text is returned
     * unchanged (folding is the caller's responsibility).
     */
    public static function encodeText(string $text, string $charset = 'UTF-8'): string
    {
        if ($text === '' || self::isAscii($text)) {
            return $text;
        }

        if (function_exists('mb_encode_mimeheader')) {
            $previous = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
            try {
                return mb_encode_mimeheader($text, $charset, 'B', "\r\n");
            } finally {
                mb_internal_encoding($previous);
            }
        }

        if (strcasecmp($charset, 'UTF-8') !== 0) {
            throw new \RuntimeException(
                'Encoding non-ASCII header text requires ext-mbstring for charsets other than UTF-8'
            );
        }

        return self::encodeUtf8Manually($text, $charset);
    }

    /**
     * Build a complete "Name: value" header line, encoding non-ASCII text and
     * folding the result so no line exceeds the recommended 78 characters.
     */
    public static function headerLine(string $name, string $value, string $charset = 'UTF-8'): string
    {
        if (!self::isAscii($value)) {
            // encodeText() emits properly folded encoded-words; folding again
            // would split inside them and corrupt the header.
            return $name . ': ' . self::encodeText($value, $charset);
        }

        return self::foldUnstructured($name, $value);
    }

    /**
     * Encode a display name for use in an address header (RFC 5322 + RFC 2047).
     * Returns a bare atom, a quoted-string, or folded encoded-words.
     */
    public static function encodeAddressName(string $name, string $charset = 'UTF-8'): string
    {
        if ($name === '') {
            return '';
        }

        if (!self::isAscii($name)) {
            return self::encodeText($name, $charset);
        }

        if (preg_match('/[\x00-\x1F\x7F",;:<>@\[\]\\\\]/', $name) === 1) {
            return '"' . addslashes($name) . '"';
        }

        return $name;
    }

    /**
     * Escape a string for use inside a quoted-string parameter value
     * (e.g. attachment filenames).
     */
    public static function escapeQuotedString(string $value): string
    {
        return '"' . addslashes($value) . '"';
    }

    /**
     * Fold a long unstructured ASCII header value at whitespace so no line
     * exceeds the recommended 78 characters (hard limit 998).
     */
    public static function foldUnstructured(string $name, string $value): string
    {
        $line = $name . ': ' . $value;
        if (strlen($line) <= 78) {
            return $line;
        }

        $words = preg_split('/ +/', $value) ?: [$value];
        $lines = [];
        $current = '';
        $limit = 78 - strlen($name . ': ');

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($candidate) > $limit && $current !== '') {
                $lines[] = $current;
                $current = $word;
                $limit = 77;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $name . ': ' . implode("\r\n ", $lines);
    }

    private static function encodeUtf8Manually(string $text, string $charset): string
    {
        $prefix = '=?' . $charset . '?B?';
        $overhead = strlen($prefix) + 2;
        $maxPayload = self::ENCODED_WORD_MAX_LENGTH - $overhead;
        $maxBytes = intdiv($maxPayload, 4) * 3;

        $chunks = [];
        $current = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = str_split($text);
        }
        foreach ($chars as $char) {
            $charBytes = strlen($char);
            if ($charBytes > $maxBytes) {
                continue;
            }
            if (strlen($current) + $charBytes > $maxBytes) {
                $chunks[] = $current;
                $current = $char;
            } else {
                $current .= $char;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        $words = array_map(
            static fn (string $chunk): string => $prefix . base64_encode($chunk) . '?=',
            $chunks
        );

        return implode("\r\n ", $words);
    }
}
