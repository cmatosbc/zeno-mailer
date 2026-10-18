# ZenoMailer — Complete Usage Guide

Everything you need to use ZenoMailer, from first send to advanced configuration.

---

## 1. Installation & Requirements

```bash
composer require zenomailer/zenomailer
```

| Requirement | Needed for |
|---|---|
| PHP 8.1+ | always |
| ext-curl | HTTP API transports (Brevo, Postmark, SES, JMAP) |
| psr/log | telemetry middleware (installed automatically) |
| ext-intl | internationalized domains (IDN → punycode) |
| ext-mbstring | RFC 2047 header encoding for non-UTF-8 charsets (UTF-8 works without it) |
| ext-openssl | SMTP TLS (STARTTLS / implicit TLS), DKIM signing |

---

## 2. Core Concepts

ZenoMailer separates email sending into three independent layers:

```
Envelope ──► Middleware Pipeline ──► Transport ──► TransportResult
 (data)       (processing)            (delivery)    (outcome)
```

- **Envelope** — a pure data object holding everything about one message
- **Middleware** — composable processors that can inspect/modify the Envelope (CSS inlining, logging, your own logic)
- **Transport** — the delivery mechanism (SMTP or an HTTP API)
- **TransportResult** — what happened: success/failure, message ID, metadata

You build an Envelope, hand it to a Mailer, and get a TransportResult back. That's the whole API surface.

---

## 3. Quick Start

```php
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->build();

$envelope = (new Envelope())
    ->setFrom('sender@example.com', 'Sender Name')
    ->addTo('recipient@example.com', 'Recipient Name')
    ->setSubject('Hello from ZenoMailer')
    ->setTextBody('Hello World')
    ->setHtmlBody('<h1>Hello World</h1>');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo 'Sent! Message-ID: ' . $result->getMessageId();
} else {
    echo 'Failed: ' . $result->getMessage();
}
```

---

## 4. The Envelope — Full Reference

All setters return `$this` (fluent chaining).

### Recipients

```php
$envelope->setFrom(string $email, string $name = '');
$envelope->addTo(string $email, string $name = '');    // repeatable
$envelope->addCc(string $email, string $name = '');    // repeatable
$envelope->addBcc(string $email, string $name = '');   // repeatable (never appears in message headers)
$envelope->setReplyTo(string $email, string $name = '');
$envelope->getAllRecipients(): Address[];              // to + cc + bcc, in that order
```

Invalid addresses (bad syntax, CR/LF injection attempts) throw `InvalidArgumentException` immediately.

### Content

```php
$envelope->setSubject(string $subject);        // CR/LF rejected
$envelope->setTextBody(string $body);
$envelope->setHtmlBody(string $body);
$envelope->setCharset(string $charset);        // default 'utf-8'
$envelope->setEncoding(string $encoding);      // '8bit' (default) | '7bit' | 'base64' | 'quoted-printable'
```

Notes:
- Provide **both** text and HTML bodies when possible — they become a `multipart/alternative` structure (better deliverability and client compatibility).
- With `7bit` encoding, non-ASCII bodies are automatically upgraded to `quoted-printable`.
- Line endings in bodies are normalized to CRLF automatically.

### Attachments

```php
$envelope->addAttachment(string $path, string $name = '', string $type = '', string $encoding = 'base64');
```

- `$name` defaults to the file's basename; `$type` defaults to `application/octet-stream`
- `$encoding`: `base64` (default), `quoted-printable`, `7bit`, `8bit`
- File contents are read lazily (at send time) and cached per Attachment instance

### Embedded images (CID)

```php
$envelope->addEmbeddedImage(string $path, string $cid, string $name = '', string $type = '');
```

Reference from the HTML body: `<img src="cid:logo">`. The MIME type is guessed from the file extension (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`, ...). Requires an HTML body. Supported by SMTP, SES and Postmark; **Brevo does not support inline images** (explicit error); JMAP does not support attachments at all yet.

### Custom headers

```php
$envelope->addHeader(string $name, string $value);   // same name overwrites
$envelope->setHeaders(array $headers);               // bulk
```

Validation rules (violations throw):
- Header names: `[A-Za-z0-9-]+` only
- Values: no CR, LF or NUL characters (header-injection defense)
- These names are managed by the serializer and **cannot** be overridden: `Bcc`, `Content-Type`, `Content-Transfer-Encoding`, `Content-Disposition`, `MIME-Version`, `To`, `Cc`, `From`, `Subject`, `Reply-To`
- `Date` and `Message-ID` **can** be overridden (useful for deterministic testing)

---

## 5. Addresses in Depth

```php
use ZenoMailer\Address;

$address = new Address('user@example.com', 'Display Name');
(string)$address;              // 'Display Name <user@example.com>'
$address->getEmail();
$address->getName();
$address->requiresSmtpUtf8();  // true when the local part has non-ASCII characters
```

Behavior:
- **Display names**: ASCII names with special characters (`,;:<>"@[]\`) are quoted and escaped; non-ASCII names are RFC 2047 encoded (`=?UTF-8?B?...?=`)
- **Internationalized domains** (IDN): converted to punycode automatically — `info@例え.test` becomes `info@xn--r8jz45g.test`. Requires ext-intl.
- **UTF-8 local parts**: accepted (`用户@example.com`); sending them requires the SMTPUTF8 extension (see §13)

---

## 6. Transports

### 6.1 SMTP (`smtp://`, `smtps://`)

```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->build();
```

| DSN | Meaning |
|---|---|
| `smtp://host` | plain SMTP, port 25 |
| `smtp://user:pass@host:587?starttls=true` | STARTTLS (recommended) |
| `smtps://user:pass@host:465` | implicit TLS, port 465 default |

**All SMTP DSN options:**

| Option | Default | Meaning |
|---|---|---|
| `starttls` | `false` | Upgrade to TLS after connecting (only if the server advertises it) |
| `keepalive` | `true` | Reuse one authenticated connection across sends (see below) |
| `max_messages_per_connection` | `0` (unlimited) | Recycle the connection after N messages (some servers cap this) |
| `timeout` | `30` | Connect and read timeout (seconds) |
| `helo` | `gethostname()` | EHLO hostname |
| `verify_peer` | `true` | TLS peer certificate verification |
| `verify_peer_name` | `true` | TLS peer name verification |
| `allow_self_signed` | `false` | Allow self-signed certificates (development only) |

Boolean options accept real booleans or the strings `'true'`/`'false'`/`'1'`/`'0'` (as they arrive in DSNs).

**Connection reuse:** the first `send()` connects, negotiates TLS, authenticates and keeps the session. Subsequent sends validate the connection with `NOOP` (reconnecting transparently if the server dropped it) and send immediately — roughly 2x faster per message. Errors always discard the connection so the next send starts fresh. Close explicitly when done:

```php
$mailer->getTransport()->disconnect();
```

**Authentication:** the server's advertised mechanisms are honored — `AUTH PLAIN` preferred, `AUTH LOGIN` as fallback. Credentials are optional (relay servers without auth work too).

**DKIM:** supported — see §10.

### 6.2 Brevo (`brevo://`, `sendinblue://`)

```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://YOUR_API_KEY@default?async=true')
    ->build();
```

| Option | Default | Meaning |
|---|---|---|
| `async` | `false` | `sendBatch()` sends concurrently via curl_multi (~5x faster for batches) |
| `timeout` | `30` | Request timeout (seconds) |
| `base_url` | official API | Override the endpoint (testing) |

- The **sender must be a verified sender** in your Brevo account
- **No inline (CID) images** — the Brevo API doesn't support them; use hosted image URLs (explicit error otherwise)
- `sendBatch(array $envelopes): TransportResult[]` — concurrent when `async=true`, sequential otherwise

**Account requirements (verified against the live API):**

| Requirement | What happens if missed |
|---|---|
| Key enabled (account active) | `API Key is not enabled` — Brevo deactivates inactive accounts; reactivate in the dashboard |
| Your IP authorized | `unrecognised IP address <ip>` with the exact IP to add — whitelist it at app.brevo.com/security/authorised_ips. Residential IPs are dynamic: when your ISP changes the address, the error returns showing the new one |
| Sender verified | `sender not verified` — verify the From address in Senders & IPs |
| Recipient names non-empty | Brevo rejects `{"name": ""}` — ZenoMailer omits the `name` key automatically for unnamed recipients |

API errors are surfaced verbatim in `TransportResult::getMessage()` (including Brevo's resolution URLs), with the full response in `getMetadata()['response']`.

**Verified against the live API**: sync sends, concurrent async batch (~200ms/email), attachments, Reply-To, custom headers, unicode/emoji content, unnamed recipients, and the explicit inline-image rejection.

### 6.3 Postmark (`postmark://`)

```php
$mailer = MailerBuilder::create()
    ->withDsn('postmark://SERVER_TOKEN@default?track_opens=true&message_stream=outbound')
    ->build();
```

| Option | Default | Meaning |
|---|---|---|
| `track_opens` | unset | Open tracking (boolean) |
| `track_links` | unset | Link tracking: `None`, `HtmlAndText`, `HtmlOnly`, `TextOnly` (any casing accepted, normalized) |
| `message_stream` | unset | Message stream id |
| `timeout` | `30` | Request timeout |

- Embedded images are supported natively (`ContentID` field)
- Requires at least one `To` recipient (Postmark API rule)
- No-account testing: the token `POSTMARK_API_TEST` simulates sends against the real API

### 6.4 Amazon SES (`ses://`)

```php
$mailer = MailerBuilder::create()
    ->withDsn('ses://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1')
    ->build();
```

| Option | Default | Meaning |
|---|---|---|
| `region` | `us-east-1` | AWS region (part of the SigV4 credential scope) |
| `timeout` | `30` | Request timeout |

- Uses SigV4-signed `SendRawEmail` (signature verified against AWS's official test suite)
- Bcc recipients are passed explicitly via `Destinations` (they would otherwise be silently lost)
- **No SMTPUTF8** — AWS doesn't support it; UTF-8 local parts are rejected with a clear error. IDN domains work (punycode).
- Max 50 recipients per message (AWS limit)
- The From address must be a **verified identity** in your SES account; sandbox accounts can only send to verified recipients

### 6.5 JMAP (`jmap://`, `jmap+http://`)

```php
$mailer = MailerBuilder::create()
    ->withDsn('jmap://:API_TOKEN@api.fastmail.com')
    ->build();
```

The scheme `jmap://` implies HTTPS; use `jmap+http://` for plain-HTTP servers (local testing).

| Option | Default | Meaning |
|---|---|---|
| `auth` | `bearer` | `bearer` (API token) or `basic` (username:password, for self-hosted servers) |
| `account_id` | auto | Pin the JMAP account id (with `auth=basic` the DSN user part is the login username, so the account id always auto-discovers unless pinned here) |
| `drafts_mailbox_id` | auto | Server-assigned mailbox id for the draft; by default the Drafts mailbox is looked up by role (and created on first use) |
| `api_url` | from session | Override the submission endpoint (reverse proxies, testing) |
| `timeout` | `30` | Request timeout |

- The **account id is auto-discovered** from the session's primary mail account (server-assigned ids like Fastmail's are opaque)
- Session discovery (`/.well-known/jmap`, following redirects) is cached per transport instance, as are the mailbox and identity lookups
- The send flow: `Mailbox/query` (Drafts by role) → `Identity/get` (match the From address, create one if missing) → `Email/set` (draft) → `EmailSubmission/set` (concrete ids — more compatible across servers than backreferences)
- **No attachments yet** (requires JMAP blob upload) — explicit error
- **Verified live** against a local Stalwart server: full send loop including auto-provisioning of the Drafts mailbox and identity

### 6.6 DSN Quick Reference

```
smtp://[user:pass@]host[:port][?options]        port 25 default
smtps://[user:pass@]host[:port][?options]       port 465, implicit TLS
brevo://API_KEY@default[?async=true]            (sendinblue:// alias)
postmark://SERVER_TOKEN@default[?options]
ses://KEY:SECRET@default[?region=...]           URL-encode special characters
jmap://[accountId:]token@host[?auth=basic]        (jmap+http:// for plain HTTP)
```

Credentials containing `@`, `:`, `/` or `%` must be URL-encoded (`rawurlencode()`).

---

## 7. Resilience (Retry, Rate Limiting, Failover)

Three composable layers make sending production-proof. Failures are classified structurally: SMTP 4xx replies, HTTP 429/408/5xx, SES throttling and network errors are **transient** (retryable); SMTP 5xx, HTTP 4xx and validation errors are **permanent**.

### Retry with exponential backoff + jitter

```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->withRetry(3)                    // up to 3 total attempts, 500ms base delay, 5s ceiling
    ->withRetry(5, 200.0, 10000.0)    // or fully customized
    ->build();
```

Each retry waits a random delay in `[0, min(maxDelay, baseDelay * 2^attempt)]` (full jitter, the pattern AWS recommends) so concurrent senders don't synchronize.

### Rate limiting (token bucket)

```php
$mailer = MailerBuilder::create()
    ->withDsn('brevo://API_KEY@default')
    ->withRateLimit(300, 'day')       // Brevo free tier
    ->withRateLimit(60)               // default period: minute
    ->withRateLimit(10, 'second')     // or 'hour', or a float of seconds
    ->build();
```

`send()` blocks until a token is available; the bucket starts full and refills continuously. Every retry attempt also consumes budget (rate limiting sits inside the retry layer).

### Failover transport chain

```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')   // primary
    ->withFailover(TransportFactory::fromDsn('brevo://API_KEY@default'))
    ->withFailover(TransportFactory::fromDsn('postmark://TOKEN@default'))
    ->build();
```

Candidates are tried in order; the first success wins. Each candidate gets the retry and rate-limit configuration, so the primary is fully exhausted (including retries) before switching. When all fail, the result carries every attempt's error in `getMetadata()['attempts']`.

Notes:
- DKIM applies to the primary transport only (failover backups send unsigned unless they are configured with DKIM directly)
- The resilience layers wrap `send()`; Brevo's `sendBatch()` bypasses them (call it on the transport directly)

## 8. Middleware

Middleware wraps the pipeline: each middleware receives the Envelope, may modify it, and decides when to call the next handler.

```php
use ZenoMailer\Middleware\HandlerInterface;
use ZenoMailer\Middleware\MiddlewareInterface;

final class MyMiddleware implements MiddlewareInterface
{
    public function process(Envelope $envelope, HandlerInterface $handler): Envelope
    {
        // ... inspect or modify $envelope ...
        return $handler->handle($envelope);   // continue down the pipeline
    }
}

$mailer = MailerBuilder::create()
    ->withDsn('...')
    ->withMiddleware(new MyMiddleware())
    ->build();
```

### Built-in middleware

| Middleware | Enabled via | What it does |
|---|---|---|
| `TelemetryMiddleware` | `enableTelemetry(bool $enable = true, ?LoggerInterface $logger = null)` | PSR-3 logging around pipeline processing: subject, from, recipients, duration, failures. `logRecipients`/`logContent` constructor flags control detail (recipients are PII — disable in sensitive environments) |
| `CssInlinerMiddleware` | `enableCssInlining()` | Inlines `<style>` block CSS into `style=""` attributes (simple selectors: element, `.class`, `#id`). The style block is removed after inlining. Regex-based — best for simple, classic email HTML |
| `DarkModeGuardMiddleware` | `enableDarkModeGuard()` | Adds `color-scheme`/`supported-color-schemes` meta tags and a `prefers-color-scheme` style block that pins light colors |

### Pipeline order

Middleware runs in this order: **Telemetry → CSS Inliner → Dark Mode Guard → your middleware (in the order added)**. The pipeline is stateless per send — running it twice processes twice, identically.

---

## 9. DKIM Signing

DKIM (RFC 6376) signs the message so receivers can verify it against your DNS. Supported by the SMTP and SES transports.

```php
$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:pass@smtp.example.com:587?starttls=true')
    ->withDkim('example.com', 's1', '/path/to/dkim.private.pem', ['verify_dns' => true])
    ->build();
```

**Setup:**

```bash
# 1. Generate a key pair (once; keep the private key secret)
openssl genrsa -out dkim.private.pem 2048
openssl rsa -in dkim.private.pem -pubout -out dkim.public.pem

# 2. Publish the public key in DNS as a TXT record at "<selector>._domainkey.<domain>":
#    v=DKIM1; k=rsa; p=<base64 body of dkim.public.pem, without the BEGIN/END lines>
```

**Options:** `passphrase` (for encrypted keys), `verify_dns` (refuse to sign until the TXT record is live — cached per signer).

> **⚠️ Publish the key BEFORE signing.** An unverifiable signature is worse than none:
> receivers evaluate DKIM as *failed*, and Gmail hard-rejects such mail with
> `550 5.7.30`. With `verify_dns` the signer refuses to sign until the record exists.

Technical details: rsa-sha256, relaxed/relaxed canonicalization, all message headers signed in order plus the body; the signature is verified against an independent verifier in the test suite.

---

## 10. Internationalization

| Feature | Behavior |
|---|---|
| IDN domains | `info@例え.test` → `info@xn--r8jz45g.test` automatically (ext-intl) |
| UTF-8 local parts | Accepted; `Envelope::requiresSmtpUtf8()` reports whether any address needs them |
| SMTPUTF8 (RFC 6531) | SMTP transport negotiates it when needed; fails clearly if the server doesn't advertise it |
| Non-ASCII subjects/names | RFC 2047 encoded automatically |
| Non-ASCII bodies | Handled per `setEncoding()` (8bit default; 7bit auto-upgrades to quoted-printable) |

Transport support: SMTP ✓ · Brevo ✓ · Postmark ✓ · SES ✗ (no SMTPUTF8; IDN domains OK) · JMAP ✓

---

## 11. Error Handling

`send()` never throws for delivery problems — it returns a `TransportResult`:

```php
$result->isSuccess();
$result->getMessage();      // human-readable outcome or error
$result->getMessageId();    // server/transport message id, or null
$result->getMetadata();     // array: http_code, response, host, port, reused_connection, attempts, ...
$result->isRetryable();     // true for transient failures (SMTP 4xx, HTTP 429/5xx, network errors)
```

What DOES throw (before any network I/O):
- `InvalidArgumentException` from Envelope/Address/Attachment/EmbeddedImage constructors on invalid input
- `RuntimeException` from `MailerBuilder::build()` on misconfiguration (missing transport, DKIM on an unsupported transport)

Invalid envelopes (missing From, no recipients, empty body, header injection attempts) are caught by the serializer and returned as **failure results** with a precise message — so a single bad email can't crash a batch loop.

---

## 12. Batch Sending

```php
// SMTP: a loop reuses the connection automatically (keepalive)
foreach ($envelopes as $envelope) {
    $result = $mailer->send($envelope);
}
$mailer->getTransport()->disconnect();

// Brevo with async=true: concurrent HTTP
$results = $mailer->getTransport()->sendBatch($envelopes);
```

---

## 13. Testing Your Setup

- **Postmark without an account**: use the `POSTMARK_API_TEST` server token — the real API simulates the send
- **Local SMTP testing**: run a fake SMTP server (the test suite ships one in `tests/Integration/`) or use smtp4dev / MailHog
- **Local JMAP testing**: run [Stalwart](https://stalw.art) in Docker and connect with `jmap+http://user:pass@127.0.0.1:8080?auth=basic`
- **Deliverability audit**: send to an address at [aboutmy.email](https://aboutmy.email) for a full SPF/DKIM/DMARC report
- **Gmail check**: send to a Gmail address → ⋮ → *Show original* → verify `SPF: PASS`, `DKIM: PASS`
- **Unit-testing your own code**: transports accept an injected `HttpClientInterface` — mock HTTP without network

---

## 14. Troubleshooting

| Symptom | Cause & fix |
|---|---|
| `550 5.7.30 ... DKIM authentication didn't pass` | DKIM signature present but not verifiable (DNS record missing). Publish the key or remove `withDkim()` — a failing signature is worse than none |
| `Brevo API error: API Key is not enabled` | Key revoked or Brevo account deactivated (inactivity). Regenerate in the dashboard |
| `Brevo API error: unrecognised IP address ...` | Whitelist your current IP at app.brevo.com/security/authorised_ips (both IPv4 and IPv6); residential IPs change over time |
| `Brevo API error: name is missing in to` | Fixed in ZenoMailer (empty names are omitted) — if you still see it, update your version |
| `sender not verified` (Brevo/Postmark/SES) | Verify the From address in the provider's dashboard first |
| `SMTP server does not advertise STARTTLS support` | Server can't do TLS — remove `starttls=true` or fix the server |
| `Envelope contains internationalized addresses ... does not advertise SMTPUTF8` | Server lacks RFC 6531 support; use ASCII local parts or a modern provider |
| `Amazon SES does not support SMTPUTF8` | SES limitation — use ASCII local parts |
| `Failed to get JMAP session: HTTP 401` | Wrong credentials or wrong `auth` scheme (self-hosted servers usually need `auth=basic`) |
| `Header ... is managed by the serializer` | You tried to override a structural header via `addHeader()` — use the dedicated Envelope methods |
| `Invalid envelope: ...` in the result | Envelope validation failed (missing From/recipients/body) — check the message |

---

## 15. Security Notes

- All header values (subjects, names, custom headers, filenames) are validated and encoded — CRLF header injection is rejected at the boundary
- Bcc recipients never appear in message headers
- TLS peer verification is ON by default for SMTP; disable only for local testing
- DKIM private keys are never logged; telemetry logs recipients only if you enable it
- Keep credentials in `.env` (gitignored), never in code or chat

---

## 16. Coming from PHPMailer?

See the [Migration Guide](../MIGRATION.md) for side-by-side examples of every common task.
