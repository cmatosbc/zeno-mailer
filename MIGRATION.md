# Migration Guide — Coming from PHPMailer

This guide maps every common PHPMailer task to its ZenoMailer equivalent. For the complete reference of every option, see the [Usage Guide](docs/USAGE.md).

---

## At a Glance

| PHPMailer | ZenoMailer |
|---|---|
| One god class (`PHPMailer`) | `Envelope` (data) → `Mailer` + middleware (processing) → `Transport` (delivery) |
| Property configuration (`->Host`, `->Port`, ...) | DSN strings + fluent builder |
| `send()` returns bool, errors in `->ErrorInfo` | `send()` returns a `TransportResult` |
| SMTP / sendmail / `mail()` | SMTP + Brevo, Postmark, SES, JMAP (HTTP APIs) |
| PHP 5.5+ | PHP 8.1+, strict types |
| `SMTPKeepAlive` (opt-in, manual) | connection reuse on by default, validated with NOOP |

---

## Quick Translation

**Before (PHPMailer):**

```php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.example.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'user@example.com';
    $mail->Password   = 'secret';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('from@example.com', 'Mailer');
    $mail->addAddress('joe@example.net', 'Joe User');
    $mail->addReplyTo('info@example.com', 'Information');
    $mail->addCC('cc@example.com');
    $mail->addBCC('bcc@example.com');

    $mail->addAttachment('/var/tmp/file.tar.gz');
    $mail->addAttachment('/tmp/image.jpg', 'new.jpg');

    $mail->isHTML(true);
    $mail->Subject = 'Here is the subject';
    $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
```

**After (ZenoMailer):**

```php
use ZenoMailer\Envelope;
use ZenoMailer\MailerBuilder;

$mailer = MailerBuilder::create()
    ->withDsn('smtp://user:secret@smtp.example.com:587?starttls=true')
    ->build();

$envelope = (new Envelope())
    ->setFrom('from@example.com', 'Mailer')
    ->addTo('joe@example.net', 'Joe User')
    ->setReplyTo('info@example.com', 'Information')
    ->addCc('cc@example.com')
    ->addBcc('bcc@example.com')
    ->setSubject('Here is the subject')
    ->setHtmlBody('This is the HTML message body <b>in bold!</b>')
    ->setTextBody('This is the body in plain text for non-HTML mail clients')
    ->addAttachment('/var/tmp/file.tar.gz')
    ->addAttachment('/tmp/image.jpg', 'new.jpg');

$result = $mailer->send($envelope);

if ($result->isSuccess()) {
    echo 'Message has been sent. Message-ID: ' . $result->getMessageId();
} else {
    echo 'Message could not be sent. Error: ' . $result->getMessage();
}
```

Note: credentials containing `@`, `:`, `/` or `%` must be URL-encoded in the DSN (`rawurlencode()`).

---

## Task-by-Task Mapping

| PHPMailer | ZenoMailer |
|---|---|
| `new PHPMailer(true)` + `isSMTP()` + `Host`/`Port`/`SMTPAuth`/`Username`/`Password`/`SMTPSecure` | `MailerBuilder::create()->withDsn('smtp://user:pass@host:587?starttls=true')->build()` |
| `->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS` (port 465) | DSN scheme `smtps://` |
| `->setFrom($email, $name)` | `$envelope->setFrom($email, $name)` |
| `->addAddress($email, $name)` | `$envelope->addTo($email, $name)` |
| `->addCC()` / `->addBCC()` / `->addReplyTo()` | `$envelope->addCc()` / `->addBcc()` / `->setReplyTo()` |
| `->isHTML(true)` + `->Body` | `$envelope->setHtmlBody(...)` |
| `->AltBody` | `$envelope->setTextBody(...)` |
| `->Subject` | `$envelope->setSubject(...)` |
| `->CharSet` | `$envelope->setCharset(...)` (default `utf-8`) |
| `->Encoding` | `$envelope->setEncoding(...)` (`8bit` default; `7bit` auto-upgrades non-ASCII to quoted-printable) |
| `->addAttachment($path, $name)` | `$envelope->addAttachment($path, $name)` |
| `->addEmbeddedImage($path, $cid)` | `$envelope->addEmbeddedImage($path, $cid)` + `<img src="cid:...">` in the HTML |
| `->addCustomHeader($name, $value)` | `$envelope->addHeader($name, $value)` (injection-safe; structural headers are managed by the serializer) |
| `->msgHTML($html, $dir)` | `$envelope->setHtmlBody($html)` + explicit `addEmbeddedImage()` calls per image |
| `->SMTPKeepAlive = true` / `->smtpClose()` | on by default; close with `$mailer->getTransport()->disconnect()` |
| `->DKIM_domain`, `->DKIM_selector`, `->DKIM_private` | `MailerBuilder::create()->withDkim($domain, $selector, $keyPath, ['verify_dns' => true])` |
| `->SMTPDebug = SMTP::DEBUG_SERVER` | `->enableTelemetry(true, $psr3Logger)` (structured PSR-3 logging, not raw protocol dumps) |
| `->send()` + `catch (Exception)` + `->ErrorInfo` | `$result = $mailer->send($envelope)` + `$result->isSuccess()` / `$result->getMessage()` |

---

## The Error-Handling Model

PHPMailer throws exceptions for everything and keeps the last error in `->ErrorInfo`. ZenoMailer separates the two:

- **Delivery outcomes** (server rejected, DNS failed, API error) → returned as a `TransportResult` with `isSuccess()`, `getMessage()`, `getMessageId()`, `getMetadata()`. Nothing throws — a failed send can't crash your loop.
- **Programming errors** (invalid address syntax, CR/LF in a subject, missing From) → `InvalidArgumentException` thrown immediately at the point you set the data, so you catch mistakes where you make them.
- **Misconfiguration** (no transport configured, DKIM on a transport that can't sign) → `RuntimeException` from `build()`.

---

## What ZenoMailer Adds

- **HTTP API transports**: Brevo, Postmark, Amazon SES, JMAP — one identical API for all
- **Concurrent batch sending**: Brevo `sendBatch()` with `async=true` runs requests in parallel via curl_multi (measured ~5x faster than sequential)
- **Connection reuse by default**: SMTP sends after the first skip connect/TLS/auth (~2x faster batches, measured against Gmail)
- **Middleware pipeline**: composable processing (CSS inlining, dark mode guard, PSR-3 telemetry, your own)
- **DKIM with a safety guard**: `verify_dns` refuses to sign until the public key is published — an unverifiable signature is worse than none (Gmail hard-rejects with `550 5.7.30`)
- **Internationalized addresses**: IDN domains punycode-converted automatically; UTF-8 local parts via RFC 6531 SMTPUTF8 negotiation

## What PHPMailer Has That ZenoMailer Doesn't (Yet)

- `mail()` and sendmail transports (ZenoMailer is SMTP + HTTP APIs only)
- S/MIME signing and encryption
- XOAUTH2 / Google OAuth wiring
- Automatic word wrap for long unencoded lines
- POP-before-SMTP authentication
- iCal event attachments

If you depend on any of these, stay on PHPMailer for that code path or keep both libraries side by side during migration.

---

## Testing Your Migration

- **Postmark without an account**: the server token `POSTMARK_API_TEST` simulates sends against the real API
- **Unit tests**: inject a mock transport — `new Mailer($mockTransport)` — or mock `HttpClientInterface` for API transports
- **Deliverability audit**: send to an address at [aboutmy.email](https://aboutmy.email) for a full SPF/DKIM/DMARC report
- **Gmail check**: send to a Gmail address, then *Show original* → `SPF: PASS`, `DKIM: PASS`

---

## Troubleshooting

| Symptom | Cause & fix |
|---|---|
| `550 5.7.30 ... DKIM authentication didn't pass` | DKIM signature present but not verifiable (DNS record missing). Publish the key or remove `withDkim()` |
| `Brevo API error: API Key is not enabled` | Key revoked or Brevo account deactivated (inactivity). Regenerate in the dashboard |
| `sender not verified` (Brevo/Postmark/SES) | Verify the From address in the provider's dashboard first |
| `SMTP server does not advertise STARTTLS support` | Server can't do TLS — remove `starttls=true` or fix the server |
| `Envelope contains internationalized addresses ... does not advertise SMTPUTF8` | Server lacks RFC 6531 support; use ASCII local parts or a modern provider |
| `Amazon SES does not support SMTPUTF8` | SES limitation — use ASCII local parts |
| `Header ... is managed by the serializer` | Structural headers (`To`, `Content-Type`, ...) are managed — use the dedicated Envelope methods |
| `Invalid envelope: ...` in the result | Envelope validation failed (missing From/recipients/body) — check the message |
