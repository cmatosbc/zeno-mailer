<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\Exception;

/**
 * Spool storage I/O failures (directory unwritable, entry unreadable).
 * These propagate to the caller: a send that failed AND could not be
 * queued must not be silently lost.
 */
class SpoolException extends Exception
{
}
