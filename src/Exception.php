<?php

declare(strict_types=1);

namespace ZenoMailer;

/**
 * Base exception for ZenoMailer runtime failures that escape to the caller
 * (spool I/O, transport-level errors rethrown by decorators).
 *
 * Input validation throws idiomatic \InvalidArgumentException instead —
 * that is a documented part of the contract, not part of this hierarchy.
 */
class Exception extends \RuntimeException
{
}
