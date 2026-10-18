<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

/**
 * AWS Signature Version 4 request signer (the hash chain is verified
 * against the official AWS SigV4 test suite in the unit tests).
 */
final class SigV4
{
    /**
     * @param array<string, string> $headers Headers to sign and send (any casing, sorted internally)
     * @param string $path Canonical URI (e.g. "/")
     * @param string $query Canonical query string ("" when none)
     * @return string[] Header lines to send, including Authorization
     */
    public static function sign(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $payload,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service,
        string $amzDate
    ): array {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim($value);
        }
        ksort($canonical, SORT_STRING);

        $canonicalHeaderString = '';
        foreach ($canonical as $name => $value) {
            $canonicalHeaderString .= $name . ':' . $value . "\n";
        }

        $signedHeaderNames = implode(';', array_keys($canonical));
        $payloadHash = hash('sha256', $payload);

        $canonicalRequest = $method . "\n" . $path . "\n" . $query . "\n"
            . $canonicalHeaderString . "\n" . $signedHeaderNames . "\n" . $payloadHash;

        $dateStamp = substr($amzDate, 0, 8);
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $lines[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaderNames . ', Signature=' . $signature;

        return $lines;
    }
}
