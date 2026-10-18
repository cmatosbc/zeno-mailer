<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\HeaderEncoder;

final class HeaderEncoderTest extends TestCase
{
    public function testIsAsciiDetectsAsciiAndNonAscii(): void
    {
        self::assertTrue(HeaderEncoder::isAscii('Hello, world!'));
        self::assertFalse(HeaderEncoder::isAscii('Café'));
        self::assertFalse(HeaderEncoder::isAscii("emoji \xF0\x9F\x98\x80"));
    }

    public function testEncodeTextPassesAsciiThrough(): void
    {
        self::assertSame('Hello world', HeaderEncoder::encodeText('Hello world'));
    }

    public function testEncodeTextEncodesNonAsciiAsEncodedWord(): void
    {
        $encoded = HeaderEncoder::encodeText('Café');

        self::assertStringStartsWith('=?UTF-8?B?', $encoded);
        self::assertSame('Café', mb_decode_mimeheader($encoded));
    }

    public function testEncodeTextSplitsLongTextIntoFoldedEncodedWords(): void
    {
        $text = str_repeat('ä', 100);
        $encoded = HeaderEncoder::encodeText($text);

        self::assertSame($text, mb_decode_mimeheader($encoded));

        foreach (explode("\r\n ", $encoded) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'Encoded word line too long: ' . $line);
            self::assertMatchesRegularExpression('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]*\?=$/', $line);
        }
    }

    public function testEncodeAddressNameQuotesSpecialCharacters(): void
    {
        self::assertSame('"John \"The Boss\" Doe"', HeaderEncoder::encodeAddressName('John "The Boss" Doe'));
        self::assertSame('John Doe', HeaderEncoder::encodeAddressName('John Doe'));
        self::assertSame('', HeaderEncoder::encodeAddressName(''));
    }

    public function testEncodeAddressNameEncodesNonAscii(): void
    {
        $encoded = HeaderEncoder::encodeAddressName('José García');

        self::assertStringStartsWith('=?UTF-8?B?', $encoded);
        self::assertSame('José García', mb_decode_mimeheader($encoded));
    }

    public function testEscapeQuotedStringEscapesBackslashesAndQuotes(): void
    {
        self::assertSame('"a\\\\b"', HeaderEncoder::escapeQuotedString('a\\b'));
        self::assertSame('"say \\"hi\\""', HeaderEncoder::escapeQuotedString('say "hi"'));
    }

    public function testFoldUnstructuredKeepsShortValuesOnOneLine(): void
    {
        self::assertSame('Subject: Hello', HeaderEncoder::foldUnstructured('Subject', 'Hello'));
    }

    public function testFoldUnstructuredFoldsLongValuesAtWhitespace(): void
    {
        $value = implode(' ', array_fill(0, 30, 'word'));
        $folded = HeaderEncoder::foldUnstructured('Subject', $value);

        foreach (explode("\r\n", $folded) as $index => $line) {
            self::assertLessThanOrEqual(78, strlen($line), "Folded line {$index} too long");
            if ($index > 0) {
                self::assertStringStartsWith(' ', $line, 'Continuation lines must start with WSP');
            }
        }

        // RFC 5322 unfolding removes only the CRLF; the leading WSP of the
        // continuation line remains and provides the folded-away space.
        $unfolded = str_replace("\r\n", '', $folded);
        self::assertSame('Subject: ' . $value, $unfolded);
    }
}
