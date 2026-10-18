# ZenoMailer

**Modern PHP 8.1+ email library with decoupled architecture**

[![Latest Version](https://img.shields.io/packagist/v/zenomailer/zenomailer.svg?v=1)](https://packagist.org/packages/zenomailer/zenomailer)
[![Total Downloads](https://img.shields.io/packagist/dt/zenomailer/zenomailer.svg?v=1)](https://packagist.org/packages/zenomailer/zenomailer)
[![Tests](https://github.com/cmatosbc/zeno-mailer/actions/workflows/tests.yml/badge.svg)](https://github.com/cmatosbc/zeno-mailer/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/zenomailer/zenomailer.svg?v=1)](https://packagist.org/packages/zenomailer/zenomailer)
[![License](https://img.shields.io/packagist/l/zenomailer/zenomailer.svg?v=1)](LICENSE)

A clean-slate email library built on modern principles with a strict separation of concerns.

## Why ZenoMailer?

Traditional email libraries are monolithic. ZenoMailer embraces **modern PHP architecture**:

- **Decoupled Services** - Envelope (data) → Middleware (processing) → Transport (delivery)
- **PSR-15-style Middleware** - Composable "onion" pipeline for features like CSS inlining, dark mode, telemetry
- **Multiple Transports** - SMTP, HTTP APIs (Brevo, Postmark, SES), JMAP - all via simple DSN strings
- **Type-Safe** - Full PHP 8.1+ type declarations with strict mode
- **Performance** - HTTP APIs are 2-5x faster than SMTP; SMTP batches get ~2x more from connection reuse (measured)
- **Live-Verified** - every transport exercised against real providers (Gmail, Brevo, Postmark, Stalwart); every failure hit in testing became a tested, documented behavior

## Quick Start

### SMTP (Universal)

```php
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.gmail.com:587?starttls=true')
    ->build();

$envelope = new Envelope();
$envelope
    ->setFrom('sender@example.com', 'Sender Name')
    ->addTo('recipient@example.com', 'Recipient Name')
    ->setSubject('Hello from ZenoMailer')
    ->setHtmlBody('<h1>Hello World</h1>')
    ->setTextBody('Hello World');

$result = $mailer->send($envelope);
if ($result->isSuccess()) {
    echo "Sent! ID: " . $result->getMessageId();
}
```

### Brevo API (Fast, Free tier available)

```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://YOUR_API_KEY@default')
    ->build();

// Same envelope code as above
$result = $mailer->send($envelope);
```

### Postmark API

```php
$mailer = MailerBuilder::create()
    ->withDsn('postmark://SERVER_TOKEN@default')
    ->build();

$result = $mailer->send($envelope);
```

### Amazon SES

```php
$mailer = MailerBuilder::create()
    ->withDsn('ses://ACCESS_KEY:SECRET_KEY@default?region=us-east-1')
    ->build();

$result = $mailer->send($envelope);
```

### With Middleware (CSS Inlining, Dark Mode, Telemetry)

```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://YOUR_API_KEY@default')
    ->enableCssInlining()        // Inline CSS automatically
    ->enableDarkModeGuard()      // Ensure dark mode compatibility
    ->enableTelemetry()          // Log with PSR-3 logger
    ->build();

$result = $mailer->send($envelope);
```

## Usage Guide

### Building an Envelope

```php
use ZenoMailer\Envelope;
use ZenoMailer\Address;

$envelope = new Envelope();

// Basic fields
$envelope->setFrom('sender@example.com', 'Sender Name');
$envelope->addTo('recipient@example.com', 'Recipient Name');
$envelope->setSubject('Email Subject');

// HTML and text content
$envelope->setHtmlBody('<h1>Hello</h1><p>HTML content</p>');
$envelope->setTextBody('Hello\nText content');

// Optional fields
$envelope->addCc('cc@example.com', 'CC Name');
$envelope->addBcc('bcc@example.com');
$envelope->setReplyTo('reply@example.com', 'Reply Name');

// Attachments
$envelope->addAttachment('/path/to/file.pdf', 'document.pdf');

// Embedded image, referenced from the HTML body via CID
$envelope->addEmbeddedImage('/path/to/logo.png', 'logo');
$envelope->setHtmlBody('<img src="cid:logo" alt="Logo"><h1>Hello</h1>');

// Custom headers
$envelope->setHeaders(['X-Custom' => 'value']);
```

### Configuring Transports

**SMTP with STARTTLS:**
```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->build();
```

**SMTP with implicit TLS:**
```php
$mailer = MailerBuilder::create()
    ->withDsn('smtps://user:pass@smtp.example.com:465')
    ->build();
```

**SMTP connection reuse (keepalive):**

By default the transport reuses one authenticated connection across sends (validated with NOOP, reconnecting automatically if the server dropped it). This makes batch sending ~2x faster. Disable it, or cap messages per connection, via DSN options:

```php
// One connection for the whole batch (default)
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->build();

foreach ($envelopes as $envelope) {
    $mailer->send($envelope);   // reuses the connection
}

$mailer->getTransport()->disconnect(); // optional: close explicitly

// Fresh connection per email:
// ->withDsn('smtp://user:pass@host:587?starttls=true&keepalive=false')
// Recycle the connection after N messages:
// ->withDsn('smtp://user:pass@host:587?max_messages_per_connection=100')
```

**Brevo (with async support):**
```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://API_KEY@default?async=true')  // async=true for concurrent sending
    ->build();
```

### Middleware Pipeline

Middleware processes emails before sending:

```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://API_KEY@default')
    ->enableCssInlining()          // Inline <style> tags
    ->enableDarkModeGuard()        // Add dark mode support
    ->enableTelemetry()            // Log with PSR-3
    ->build();
```

### DKIM Signing (SMTP & SES)

Sign outgoing messages with DKIM (RFC 6376, rsa-sha256, relaxed/relaxed) so receivers can validate them against your DNS:

```php
# Generate a key pair (once):
openssl genrsa -out dkim.private.pem 2048
openssl rsa -in dkim.private.pem -pubout -out dkim.public.pem
```

```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->withDkim('example.com', 's1', '/path/to/dkim.private.pem', ['verify_dns' => true])
    ->build();
```

> **⚠️ Publish the key BEFORE signing.** An unverifiable signature is worse than
> no signature: receivers evaluate DKIM as *failed*, and Gmail hard-rejects such
> mail with `550 5.7.30` (verified empirically). The `verify_dns` option makes
> the signer refuse to sign until the TXT record is live (result is cached per
> signer instance).

Publish the public key in DNS as a TXT record at `s1._domainkey.example.com`:

```
v=DKIM1; k=rsa; p=<base64 public key without headers>
```

Test your setup with [aboutmy.email](https://aboutmy.email) or by sending to a Gmail address and checking "Show original" (should show `DKIM: PASS`).

### Embedded Images (CID)

Embed images directly in the HTML body (no external hosting needed). Supported by SMTP, SES and Postmark. The Brevo API does not support inline images - use hosted image URLs there.

```php
$envelope
    ->setHtmlBody('<img src="cid:logo" alt="Logo"><h1>Hello</h1>')
    ->addEmbeddedImage('/path/to/logo.png', 'logo');   // type guessed from extension
```

### Internationalized Addresses (IDN & SMTPUTF8)

Non-ASCII domains are punycode-converted automatically (requires ext-intl); UTF-8 local parts are supported via RFC 6531 SMTPUTF8, which the SMTP transport negotiates automatically:

```php
$envelope
    ->setFrom('sender@example.com')
    ->addTo('用户@example.com')      // UTF-8 local part -> SMTPUTF8 negotiated
    ->addTo('info@例え.test');       // IDN domain -> punycode (xn--r8jz45g.test)

$mailer->send($envelope);
```

Transport support: SMTP ✓ (negotiates SMTPUTF8, fails clearly if the server doesn't advertise it), Brevo/Postmark ✓ (JSON APIs), **SES ✗** (AWS does not support SMTPUTF8 - UTF-8 local parts are rejected explicitly; IDN domains work via punycode).

### Batch Sending (Async)

For HTTP transports with async enabled:

```php
use ZenoMailer\Transport\BrevoTransport;

$transport = new BrevoTransport($apiKey, ['async' => true]);

$envelopes = [/* array of Envelope objects */];
$results = $transport->sendBatch($envelopes);

foreach ($results as $result) {
    if ($result->isSuccess()) {
        echo "Sent: " . $result->getMessageId();
    }
}
```

## Features

### 🎯 Core Architecture

- **Envelope** - Pure data object for message content (no logic)
- **Middleware Pipeline** - Composable processing layers (PSR-15 style)
- **Transport Layer** - Pluggable delivery mechanisms

### 🚀 Transports

- **SMTP** - Universal, works everywhere
- **Brevo** - Free tier (300 emails/day), 2-3x faster than SMTP, async support
- **Postmark** - Premium deliverability and tracking
- **Amazon SES** - Scalable, pay-as-you-go
- **JMAP** - Modern JSON-based protocol

### 🎨 Middleware

- **CSS Inliner** - Automatically inline CSS for email clients
- **Dark Mode Guard** - Ensure compatibility with dark mode
- **Telemetry** - PSR-3 logging and monitoring
- **Custom** - Easy to create your own

### 🛡️ Resilience

- **Auto-retry** - transient failures (SMTP 4xx, HTTP 429/5xx) retried with exponential backoff + jitter
- **Rate limiting** - token bucket per transport (`withRateLimit(300, 'day')`)
- **Failover** - backup transport chain (`withFailover($backup)`)

### 🔐 Deliverability

- **DKIM signing** - RFC 6376, rsa-sha256, relaxed/relaxed (SMTP & SES)
- **Embedded images** - CID references from the HTML body (SMTP, SES, Postmark)
- **Internationalized addresses** - IDN punycode + RFC 6531 SMTPUTF8
- **RFC-compliant MIME** - RFC 2047 header encoding, line folding, dot-stuffing, automatic line-length protection (overlong bodies upgrade to quoted-printable)

## Installation

Available on [Packagist](https://packagist.org/packages/zenomailer/zenomailer).

```bash
composer require zenomailer/zenomailer
```

> **Beta note:** while the project is in beta, require it with a stability flag:
>
> ```bash
> composer require zenomailer/zenomailer:^0.1.0@beta
> ```

## Documentation

- [Complete Usage Guide](docs/USAGE.md) - every option, transport and middleware, reference-style
- [PHPMailer vs ZenoMailer](docs/COMPARISON.md) - honest feature-by-feature comparison
- [Migration Guide](MIGRATION.md) - Coming from PHPMailer?
- [Benchmark Results](BENCHMARK.md)

## Requirements

- PHP 8.1 or later
- ext-curl (for HTTP API transports)
- ext-openssl (for SMTP TLS and DKIM signing)
- ext-intl (for internationalized domains)
- ext-mbstring (optional - needed only for non-UTF-8 charsets)

## Performance

### Benchmark Results

Run benchmarks to compare transports:

```bash
# Compare SMTP vs Brevo (sync)
php benchmark-brevo.php

# Compare SMTP vs Brevo (sync and async)
php benchmark-async.php
```

**Typical (measured):**
- SMTP, fresh connection per email: ~3.9s/email (~84s for 20)
- SMTP, connection reuse (default): ~1.7s/email (~10s for 20)
- Brevo Sync: ~0.75s/email (~15s for 20)
- Brevo Async: ~1-2s for 20 (concurrent HTTP requests) ⚡

HTTP APIs are **2-5x faster** than SMTP per email; async batching is faster still.

## Examples

Check the `examples/` directory:

```
examples/
├── modern-basic-usage.php           # SMTP example
├── modern-brevo-api.php             # Brevo API example
├── modern-postmark-api.php          # Postmark example
├── modern-ses-api.php               # Amazon SES example
├── modern-jmap.php                  # JMAP protocol example
├── modern-custom-middleware.php     # Custom middleware
├── modern-dkim.php                  # DKIM signing
└── modern-with-logging.php          # PSR-3 logging
```

## License

MIT
