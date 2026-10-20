<?php

declare(strict_types=1);

namespace ZenoMailer\Tests;

use PHPUnit\Framework\TestCase;
use ZenoMailer\Envelope;
use ZenoMailer\Exception;
use ZenoMailer\Transport\SmtpReplyException;
use ZenoMailer\Transport\SpoolException;

final class ExceptionTaxonomyTest extends TestCase
{
    public function testTransportExceptionsExtendTheLibraryBase(): void
    {
        $smtpReply = new SmtpReplyException('MAIL FROM', '451 temporary');
        self::assertInstanceOf(Exception::class, $smtpReply);
        self::assertInstanceOf(\RuntimeException::class, $smtpReply, 'BC: catch(RuntimeException) keeps working');
        self::assertTrue($smtpReply->isTransient());

        $permanent = new SmtpReplyException('RCPT TO', '550 nope');
        self::assertFalse($permanent->isTransient());
        self::assertSame(550, $permanent->getReplyCode());
    }

    public function testSpoolIoFailureThrowsSpoolException(): void
    {
        // A path that cannot be a directory (a FILE exists there)
        $blocker = tempnam(sys_get_temp_dir(), 'zeno-block') ?: throw new \RuntimeException('tempnam failed');

        try {
            new \ZenoMailer\Transport\FileSpool($blocker);
            self::fail('Expected SpoolException');
        } catch (SpoolException $e) {
            self::assertInstanceOf(Exception::class, $e);
        } finally {
            unlink($blocker);
        }
    }

    public function testCatchingTheLibraryBaseCatchesTransportErrors(): void
    {
        try {
            throw new SmtpReplyException('DATA', '421 server shutting down');
        } catch (Exception $e) {
            self::assertInstanceOf(SmtpReplyException::class, $e);
            self::assertSame(421, $e->getReplyCode());
        }
    }
}
