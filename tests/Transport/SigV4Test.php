<?php

declare(strict_types=1);

namespace ZenoMailer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Transport\SigV4;

/**
 * Known-answer tests against the official AWS Signature Version 4 test suite
 * (get-vanilla vector, as published in the AWS documentation and mirrored in
 * awslabs/aws-crt-nodejs).
 */
final class SigV4Test extends TestCase
{
    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const EXPECTED_SIGNATURE = '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31';

    public function testGetVanillaVectorMatchesOfficialSignature(): void
    {
        $lines = SigV4::sign(
            'GET',
            '/',
            '',
            [
                'Host' => 'example.amazonaws.com',
                'X-Amz-Date' => '20150830T123600Z',
            ],
            '',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            'service',
            '20150830T123600Z'
        );

        $authorization = '';
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Authorization: ')) {
                $authorization = $line;
            }
        }

        self::assertStringContainsString(
            'Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request',
            $authorization
        );
        self::assertStringContainsString('SignedHeaders=host;x-amz-date', $authorization);
        self::assertStringContainsString('Signature=' . self::EXPECTED_SIGNATURE, $authorization);

        // The signed headers are echoed back as request headers
        self::assertContains('Host: example.amazonaws.com', $lines);
        self::assertContains('X-Amz-Date: 20150830T123600Z', $lines);
    }

    public function testPostWithPayloadProducesWellFormedAuthorization(): void
    {
        $lines = SigV4::sign(
            'POST',
            '/',
            '',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Host' => 'email.us-east-1.amazonaws.com',
                'X-Amz-Date' => gmdate('Ymd\THis\Z'),
            ],
            'Action=SendRawEmail&RawMessage.Data=abc',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'us-east-1',
            'ses',
            gmdate('Ymd\THis\Z')
        );

        $authorization = '';
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Authorization: ')) {
                $authorization = $line;
            }
        }

        self::assertStringContainsString('SignedHeaders=content-type;host;x-amz-date', $authorization);
        self::assertStringContainsString('us-east-1/ses/aws4_request', $authorization);
        self::assertMatchesRegularExpression('/Signature=[0-9a-f]{64}/', $authorization);
    }
}
