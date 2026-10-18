<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Address;

final class AddressTest extends TestCase
{
    public function testAcceptsValidEmail(): void
    {
        $address = new Address('user@example.com', 'User Name');

        self::assertSame('user@example.com', $address->getEmail());
        self::assertSame('User Name', $address->getName());
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Address('not-an-email');
    }

    public function testRejectsCrLfInEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Address("user@example.com\r\nBcc: victim@example.com");
    }

    public function testRejectsCrLfInName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Address('user@example.com', "Name\r\nBcc: victim@example.com");
    }

    public function testToStringWithoutName(): void
    {
        self::assertSame('user@example.com', (new Address('user@example.com'))->toString());
    }

    public function testToStringWithName(): void
    {
        self::assertSame('Jane Smith <jane@example.com>', (new Address('jane@example.com', 'Jane Smith'))->toString());
    }

    public function testToStringQuotesNameWithSpecialCharacters(): void
    {
        $address = new Address('jane@example.com', 'Smith, Jane');

        self::assertSame('"Smith, Jane" <jane@example.com>', $address->toString());
    }

    public function testToStringEncodesNonAsciiName(): void
    {
        $address = new Address('jane@example.com', 'José García');
        $string = $address->toString();

        self::assertStringEndsWith('<jane@example.com>', $string);
        self::assertSame('José García', mb_decode_mimeheader(substr($string, 0, strpos($string, '<') - 1) ?: ''));
    }

    public function testMagicToString(): void
    {
        self::assertSame('user@example.com', (string)new Address('user@example.com'));
    }

    public function testIdnDomainIsConvertedToPunycode(): void
    {
        $address = new Address('user@例え.test');

        self::assertMatchesRegularExpression('/^user@xn--[a-z0-9-]+\.test$/', $address->getEmail());
        self::assertSame('例え.test', idn_to_utf8(substr($address->getEmail(), 5), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46));
        self::assertFalse($address->requiresSmtpUtf8());
    }

    public function testUtf8LocalPartIsAcceptedAndRequiresSmtpUtf8(): void
    {
        $address = new Address('用户@example.com');

        self::assertSame('用户@example.com', $address->getEmail());
        self::assertTrue($address->requiresSmtpUtf8());
    }

    public function testUtf8LocalPartWithIdnDomain(): void
    {
        $address = new Address('用户@例え.test');

        self::assertMatchesRegularExpression('/^用户@xn--[a-z0-9-]+\.test$/', $address->getEmail());
        self::assertTrue($address->requiresSmtpUtf8());
    }

    public function testInvalidUtf8LocalPartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Address('spaced user@example.com');
    }

    public function testToStringKeepsUtf8LocalPartRaw(): void
    {
        $address = new Address('用户@example.com', 'Zhang San');

        $string = $address->toString();

        // ASCII display name stays as-is; the UTF-8 addr-spec stays raw
        // (valid under SMTPUTF8, which the SMTP transport negotiates)
        self::assertSame('Zhang San <用户@example.com>', $string);
    }
}
