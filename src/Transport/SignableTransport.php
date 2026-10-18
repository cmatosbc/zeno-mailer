<?php

declare(strict_types=1);

namespace ZenoMailer\Transport;

use ZenoMailer\DkimSigner;

/**
 * Transports that deliver a raw RFC 5322 message and can therefore
 * apply DKIM signatures.
 */
interface SignableTransport
{
    public function setDkimSigner(DkimSigner $signer): void;
}
