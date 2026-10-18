<?php

declare(strict_types=1);

namespace ZenoMailer;

final class Address
{
    private string $email;
    private string $name;
    private bool $requiresSmtpUtf8;

    public function __construct(string $email, string $name = '')
    {
        if (strpbrk($email, "\r\n") !== false) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }

        if (strpbrk($name, "\r\n") !== false) {
            throw new \InvalidArgumentException('Address name must not contain CR/LF characters');
        }

        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }

        $localPart = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $localIsAscii = HeaderEncoder::isAscii($localPart);

        // Internationalized domain (IDN): convert to punycode BEFORE validation,
        // because FILTER_VALIDATE_EMAIL only accepts ASCII domains.
        if (!HeaderEncoder::isAscii($domain)) {
            if (!function_exists('idn_to_ascii')) {
                throw new \InvalidArgumentException(
                    "Internationalized domain {$domain} requires the ext-intl extension (or use its punycode form)"
                );
            }

            $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($asciiDomain === false) {
                throw new \InvalidArgumentException("Invalid internationalized domain: {$domain}");
            }

            $domain = $asciiDomain;
        }

        // ASCII local part: standard validation. UTF-8 local part (RFC 6531
        // SMTPUTF8): only valid with the unicode flag.
        $validated = $localIsAscii
            ? filter_var($localPart . '@' . $domain, FILTER_VALIDATE_EMAIL)
            : filter_var($localPart . '@' . $domain, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);

        if ($validated === false) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }

        $this->requiresSmtpUtf8 = !$localIsAscii;

        $this->email = $localPart . '@' . $domain;
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * True when the local part contains non-ASCII characters, which requires
     * the SMTPUTF8 extension (RFC 6531) on the delivery path.
     */
    public function requiresSmtpUtf8(): bool
    {
        return $this->requiresSmtpUtf8;
    }

    public function toString(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        $name = HeaderEncoder::encodeAddressName($this->name);

        return sprintf('%s <%s>', $name, $this->email);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
