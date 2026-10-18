<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * DKIM signer (RFC 6376) using rsa-sha256 with relaxed/relaxed
 * canonicalization - the combination recommended by major receivers.
 *
 * Publish the public key in DNS as a TXT record at
 * "{selector}._domainkey.{domain}".
 */
final class DkimSigner
{
    private const CRLF = "\r\n";

    private string $domain;
    private string $selector;
    private string $privateKeyPem;
    private ?string $passphrase;
    private bool $verifyDns;

    /** @var callable(string): (array<mixed>|false) */
    private $dnsResolver;

    private ?bool $dnsReady = null;

    /**
     * @param string $privateKey PEM string or path to a PEM file
     * @param array<string, mixed> $options Supported: passphrase (string),
     *        verify_dns (bool, default false - refuse to sign unless a public
     *        key is published in DNS), dns_resolver (callable for testing)
     */
    public function __construct(string $domain, string $selector, string $privateKey, array $options = [])
    {
        if (preg_match('/^[a-z0-9.-]+$/i', $domain) !== 1) {
            throw new \InvalidArgumentException("Invalid DKIM domain: {$domain}");
        }

        if (preg_match('/^[a-z0-9._-]+$/i', $selector) !== 1) {
            throw new \InvalidArgumentException("Invalid DKIM selector: {$selector}");
        }

        $this->domain = strtolower($domain);
        $this->selector = strtolower($selector);
        $this->passphrase = isset($options['passphrase']) ? (string)$options['passphrase'] : null;
        $this->verifyDns = filter_var($options['verify_dns'] ?? false, FILTER_VALIDATE_BOOLEAN);

        /** @var callable(string): (array<mixed>|false) $resolver */
        $resolver = $options['dns_resolver'] ?? static fn (string $host): array|false => dns_get_record($host, DNS_TXT);
        $this->dnsResolver = $resolver;

        if (is_file($privateKey)) {
            $loaded = file_get_contents($privateKey);
            if ($loaded === false) {
                throw new \RuntimeException("Failed to read DKIM private key file: {$privateKey}");
            }
            $privateKey = $loaded;
        }

        $this->privateKeyPem = $privateKey;

        if (openssl_pkey_get_private($this->privateKeyPem, $this->passphrase ?? '') === false) {
            throw new \RuntimeException('Invalid DKIM private key: ' . (string)openssl_error_string());
        }
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    /**
     * Returns the message with a DKIM-Signature header prepended.
     */
    public function sign(SerializedMessage $message): SerializedMessage
    {
        if ($this->verifyDns) {
            $this->assertDnsReady();
        }

        $raw = $message->getMessage();
        $separator = strpos($raw, self::CRLF . self::CRLF);
        if ($separator === false) {
            throw new \RuntimeException('Cannot DKIM-sign a message without a header/body separator');
        }

        $headerBlock = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);

        $bodyHash = base64_encode(hash('sha256', self::relaxedBody($body), true));

        $headers = $this->parseHeaders($headerBlock);
        $signedNames = array_map(static fn (array $h): string => strtolower($h['name']), $headers);

        $unsignedSignatureValue = $this->buildSignatureValue(implode(':', $signedNames), $bodyHash);
        $dataToSign = '';

        foreach ($headers as $header) {
            $dataToSign .= strtolower($header['name']) . ':' . self::relaxedHeaderValue($header['value']) . self::CRLF;
        }
        $dataToSign .= 'dkim-signature:' . self::relaxedHeaderValue($unsignedSignatureValue);

        $signature = $this->rsaSign($dataToSign);
        $chunked = implode(self::CRLF . ' ', str_split(base64_encode($signature), 60));

        $output = 'DKIM-Signature: ' . self::foldTags($unsignedSignatureValue . $chunked);

        return new SerializedMessage($output . self::CRLF . $raw, $message->getMessageId());
    }

    /**
     * Refuse to sign when no public key is published for this selector.
     * An unverifiable signature makes receivers evaluate DKIM as FAILED,
     * which is worse than not signing at all: Gmail hard-rejects such mail
     * with "550 5.7.30 ... DKIM authentication didn't pass".
     */
    private function assertDnsReady(): void
    {
        if ($this->dnsReady === true) {
            return;
        }

        $host = $this->selector . '._domainkey.' . $this->domain;
        $records = ($this->dnsResolver)($host);

        $hasKey = false;
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $texts = [];
                if (isset($record['txt']) && is_string($record['txt'])) {
                    $texts[] = $record['txt'];
                }
                if (isset($record['entries']) && is_array($record['entries'])) {
                    foreach ($record['entries'] as $entry) {
                        if (is_string($entry)) {
                            $texts[] = $entry;
                        }
                    }
                }
                if (str_contains(implode('', $texts), 'p=')) {
                    $hasKey = true;
                    break;
                }
            }
        }

        if (!$hasKey) {
            throw new \RuntimeException(
                "No DKIM public key published in DNS at {$host} (TXT record containing 'p=' required). " .
                'Signing with an unpublished key makes receivers evaluate DKIM as FAILED, which is worse than ' .
                'not signing: Gmail rejects such mail with 550 5.7.30. Publish the key first, or disable the ' .
                'verify_dns option if you accept the risk.'
            );
        }

        $this->dnsReady = true;
    }

    /**
     * RFC 6376 section 3.4.4 - relaxed body canonicalization.
     */
    public static function relaxedBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace(["\r", "\n"], "\n", $body);

        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            $line = preg_replace('/[ \t]+/', ' ', $line) ?? $line;
            $lines[$i] = rtrim($line, " \t");
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return '';
        }

        return implode(self::CRLF, $lines) . self::CRLF;
    }

    /**
     * RFC 6376 section 3.4.2 - relaxed header value canonicalization
     * (unfold, collapse WSP runs, trim).
     */
    public static function relaxedHeaderValue(string $value): string
    {
        $value = str_replace(self::CRLF, '', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<array{name: string, value: string}> Raw header pairs (value keeps folding)
     */
    private function parseHeaders(string $headerBlock): array
    {
        $pairs = [];
        $currentName = null;
        $currentValue = '';

        foreach (explode(self::CRLF, $headerBlock) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($currentName !== null) {
                    $currentValue .= self::CRLF . $line;
                }
                continue;
            }

            if ($currentName !== null) {
                $pairs[] = ['name' => $currentName, 'value' => $currentValue];
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                $currentName = null;
                $currentValue = '';
                continue;
            }

            $currentName = substr($line, 0, $colon);
            $currentValue = ltrim(substr($line, $colon + 1));
        }

        if ($currentName !== null) {
            $pairs[] = ['name' => $currentName, 'value' => $currentValue];
        }

        return $pairs;
    }

    private function buildSignatureValue(string $headerList, string $bodyHash): string
    {
        $tags = [
            'v=1',
            'a=rsa-sha256',
            'd=' . $this->domain,
            's=' . $this->selector,
            'c=relaxed/relaxed',
            'q=dns/txt',
            't=' . time(),
            'h=' . $headerList,
            'bh=' . $bodyHash,
        ];

        return implode('; ', $tags) . '; b=';
    }

    /**
     * Fold a DKIM-Signature value at "; " boundaries (78 char recommendation).
     * Every folded line keeps its trailing semicolon so the tag list stays
     * valid after unfolding. The b= value may already contain fold points,
     * which are preserved.
     */
    private static function foldTags(string $value): string
    {
        $tokens = explode('; ', $value);
        $lines = [];
        $current = '';

        foreach ($tokens as $token) {
            $candidate = $current === '' ? $token : $current . '; ' . $token;
            if (strlen($candidate) > 70 && $current !== '') {
                $lines[] = $current . ';';
                $current = $token;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return implode(self::CRLF . "\t", $lines);
    }

    private function rsaSign(string $data): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem, $this->passphrase ?? '');
        if ($key === false) {
            throw new \RuntimeException('DKIM private key could not be loaded: ' . (string)openssl_error_string());
        }

        $signature = '';
        if (openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256) !== true) {
            throw new \RuntimeException('DKIM signing failed: ' . (string)openssl_error_string());
        }

        return $signature;
    }
}
