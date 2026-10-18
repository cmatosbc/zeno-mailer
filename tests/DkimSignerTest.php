<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Address;
use ZenoMailer\DkimSigner;
use ZenoMailer\Envelope;
use ZenoMailer\MessageSerializer;
use ZenoMailer\Tests\FakeSmtpServer;
use ZenoMailer\MailerBuilder;
use ZenoMailer\Transport\SmtpTransport;

final class DkimSignerTest extends TestCase
{
    private string $privateKeyPem;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            self::fail('openssl_pkey_new failed');
        }
        $exported = '';
        if (openssl_pkey_export($key, $exported) !== true) {
            self::fail('openssl_pkey_export failed');
        }
        $this->privateKeyPem = $exported;

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            self::fail('openssl_pkey_get_details failed');
        }
        $this->publicKeyPem = $details['key'];
    }

    private function signedMessage(?Envelope $envelope = null): string
    {
        $envelope ??= $this->envelope();
        $serialized = (new MessageSerializer())->serialize($envelope);
        $signer = new DkimSigner('example.com', 'test', $this->privateKeyPem);

        return $signer->sign($serialized)->getMessage();
    }

    private function envelope(): Envelope
    {
        return (new Envelope())
            ->setFrom('sender@example.com', 'Sender Name')
            ->addTo('to@example.com', 'Recipient')
            ->setSubject('DKIM test')
            ->setTextBody("Hello\nWorld");
    }

    /**
     * Independent DKIM verifier: parses the DKIM-Signature header, recomputes
     * both hashes with relaxed canonicalization and verifies the RSA signature.
     *
     * @return int|bool openssl_verify result (1 = valid)
     */
    private function verify(string $message): int|bool
    {
        $separator = strpos($message, "\r\n\r\n");
        if ($separator === false) {
            return false;
        }

        $headerBlock = substr($message, 0, $separator);
        $body = substr($message, $separator + 4);

        $lines = explode("\r\n", $headerBlock);
        self::assertStringStartsWith('DKIM-Signature:', $lines[0]);

        $sigLines = [$lines[0]];
        $restLines = [];
        $inSignature = true;
        foreach (array_slice($lines, 1) as $line) {
            if ($inSignature && ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))) {
                $sigLines[] = $line;
            } else {
                $inSignature = false;
                $restLines[] = $line;
            }
        }

        // Unfold and parse tags
        $unfolded = str_replace("\r\n", '', implode('', $sigLines));
        $value = substr($unfolded, strlen('DKIM-Signature: '));
        $tags = [];
        foreach (explode(';', $value) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $eq = strpos($tag, '=');
            if ($eq === false) {
                continue;
            }
            $tags[substr($tag, 0, $eq)] = substr($tag, $eq + 1);
        }

        // Body hash - a mismatch means the body was tampered with
        $expectedBh = base64_encode(hash('sha256', DkimSigner::relaxedBody($body), true));
        if (($tags['bh'] ?? '') !== $expectedBh) {
            return false;
        }

        // Canonicalize the signed headers (last occurrence of each h= name)
        $data = '';
        foreach (explode(':', $tags['h'] ?? '') as $name) {
            $name = strtolower(trim($name));
            $raw = $this->findLastHeader($restLines, $name);
            if ($raw === null) {
                return false;
            }
            [$hName, $hValue] = $raw;
            $data .= $name . ':' . DkimSigner::relaxedHeaderValue($hValue) . "\r\n";
        }

        // DKIM-Signature itself, canonicalized with an empty b= value
        $bPos = strrpos($unfolded, 'b=');
        if ($bPos === false) {
            return false;
        }
        $sigWithoutB = substr($unfolded, 0, $bPos + 2);
        $data .= 'dkim-signature:' . DkimSigner::relaxedHeaderValue(substr($sigWithoutB, strlen('DKIM-Signature: ')));

        $signature = base64_decode(preg_replace('/\s+/', '', $tags['b'] ?? '') ?? '', true);
        if ($signature === false) {
            return false;
        }

        return openssl_verify($data, $signature, $this->publicKeyPem, OPENSSL_ALGO_SHA256);
    }

    /**
     * @param string[] $lines
     * @return array{0: string, 1: string}|null
     */
    private function findLastHeader(array $lines, string $name): ?array
    {
        $result = null;
        $currentName = null;
        $currentValue = '';

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($currentName !== null) {
                    $currentValue .= "\r\n" . $line;
                }
                continue;
            }

            if ($currentName === $name) {
                $result = [$currentName, $currentValue];
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                $currentName = null;
                continue;
            }

            $currentName = strtolower(trim(substr($line, 0, $colon)));
            $currentValue = ltrim(substr($line, $colon + 1));
        }

        if ($currentName === $name) {
            $result = [$currentName, $currentValue];
        }

        return $result;
    }

    public function testSignatureVerifies(): void
    {
        self::assertSame(1, $this->verify($this->signedMessage()));
    }

    public function testSignatureHeaderIsPrependedAndWellFormed(): void
    {
        $message = $this->signedMessage();

        self::assertStringStartsWith('DKIM-Signature: v=1; a=rsa-sha256;', $message);
        self::assertStringContainsString('d=example.com; s=test;', $message);
        self::assertStringContainsString('c=relaxed/relaxed;', $message);
        self::assertStringContainsString('q=dns/txt;', $message);

        foreach (explode("\r\n", $message) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
    }

    public function testSignatureSurvivesFoldedEncodedSubject(): void
    {
        $envelope = $this->envelope()->setSubject(str_repeat('ä ', 60));

        self::assertSame(1, $this->verify($this->signedMessage($envelope)));
    }

    public function testSignatureSurvivesMultipartAndAttachments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zeno') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($path, 'PDF-CONTENT');

        try {
            $envelope = $this->envelope()
                ->setHtmlBody('<h1>Hello</h1>')
                ->addAttachment($path, 'doc.pdf', 'application/pdf');

            self::assertSame(1, $this->verify($this->signedMessage($envelope)));
        } finally {
            unlink($path);
        }
    }

    public function testTamperedBodyFailsVerification(): void
    {
        $message = $this->signedMessage();
        $message = str_replace('World', 'W0rld', $message);

        self::assertNotSame(1, $this->verify($message));
    }

    public function testTamperedSubjectFailsVerification(): void
    {
        $message = $this->signedMessage();
        $message = str_replace('Subject: DKIM test', 'Subject: DKIM test2', $message);

        self::assertNotSame(1, $this->verify($message));
    }

    public function testRelaxedBodyCanonicalization(): void
    {
        self::assertSame("a b\r\nc\r\n", DkimSigner::relaxedBody("a  b\t\r\nc   \r\n\r\n\r\n"));
        self::assertSame('', DkimSigner::relaxedBody(''));
        self::assertSame('', DkimSigner::relaxedBody("\r\n\r\n"));
        self::assertSame("x\r\n", DkimSigner::relaxedBody('x'));
    }

    public function testRelaxedHeaderValueCanonicalization(): void
    {
        // Unfolding removes the line break but keeps the leading WSP of the
        // continuation line, so the words stay separated by a space.
        self::assertSame('a b c', DkimSigner::relaxedHeaderValue("a \t b\r\n \tc"));
        self::assertSame('folded value', DkimSigner::relaxedHeaderValue("folded\r\n value"));
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private key');
        new DkimSigner('example.com', 'test', 'not-a-key');
    }

    public function testInvalidDomainIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DkimSigner("example.com\r\nBcc: x@y.z", 'test', $this->privateKeyPem);
    }

    public function testInvalidSelectorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DkimSigner('example.com', 'bad selector', $this->privateKeyPem);
    }

    public function testKeyFileIsLoaded(): void
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'zeno-key') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($keyFile, $this->privateKeyPem);

        try {
            $signer = new DkimSigner('example.com', 'test', $keyFile);
            $serialized = (new MessageSerializer())->serialize($this->envelope());
            $message = $signer->sign($serialized)->getMessage();

            self::assertSame(1, $this->verify($message));
        } finally {
            unlink($keyFile);
        }
    }

    public function testVerifyDnsRefusesToSignWithoutPublishedKey(): void
    {
        $signer = new DkimSigner('example.com', 'test', $this->privateKeyPem, [
            'verify_dns' => true,
            'dns_resolver' => static fn (string $host): array => [],
        ]);

        $serialized = (new MessageSerializer())->serialize($this->envelope());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('5.7.30');
        $signer->sign($serialized);
    }

    public function testVerifyDnsAcceptsPublishedKeyAndCachesLookup(): void
    {
        $lookups = 0;

        $signer = new DkimSigner('example.com', 'test', $this->privateKeyPem, [
            'verify_dns' => true,
            'dns_resolver' => static function (string $host) use (&$lookups): array {
                $lookups++;
                self::assertSame('test._domainkey.example.com', $host);

                return [['txt' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkq']];
            },
        ]);

        $serialized = (new MessageSerializer())->serialize($this->envelope());
        $signer->sign($serialized);
        $signer->sign($serialized);

        self::assertSame(1, $lookups, 'DNS lookup must be cached across signs');
    }

    public function testVerifyDnsAcceptsSegmentedTxtRecords(): void
    {
        $signer = new DkimSigner('example.com', 'test', $this->privateKeyPem, [
            'verify_dns' => true,
            'dns_resolver' => static fn (string $host): array => [
                ['entries' => ['v=DKIM1; k=rsa;', 'p=MIIBIjANBgkq']],
            ],
        ]);

        $serialized = (new MessageSerializer())->serialize($this->envelope());
        $signed = $signer->sign($serialized);

        self::assertStringStartsWith('DKIM-Signature:', $signed->getMessage());
    }

    public function testEndToEndThroughSmtpTransport(): void
    {
        $server = new FakeSmtpServer('ok', 1);

        try {
            $mailer = MailerBuilder::create()
                ->withDsn("smtp://127.0.0.1:{$server->port}")
                ->withDkim('example.com', 'test', $this->privateKeyPem)
                ->build();

            $envelope = $this->envelope()
                ->setTextBody("Leading dot line\n.next line\nEnd");

            $result = $mailer->send($envelope);
            self::assertTrue($result->isSuccess(), 'Send failed: ' . $result->getMessage());

            $transport = $mailer->getTransport();
            if ($transport instanceof SmtpTransport) {
                $transport->disconnect();
            }

            $raw = $server->getTranscript()['raw_data'] ?? '';
            $received = preg_replace('/^\.\./m', '.', $raw) ?? $raw;

            // The received message (after dot-unstuffing) must verify
            self::assertSame(1, $this->verify($received));
        } finally {
            $server->stop();
        }
    }

    public function testBuilderRejectsDkimOnNonSignableTransport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support DKIM');

        MailerBuilder::create()
            ->withDsn('brevo://key@default')
            ->withDkim('example.com', 'test', $this->privateKeyPem)
            ->build();
    }
}
