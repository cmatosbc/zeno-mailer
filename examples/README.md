# ZenoMailer examples

Runnable examples for ZenoMailer. All examples use `example.com` domains (reserved by IANA for documentation), so replace them with real addresses before running.

| File | What it shows |
|---|---|
| `modern-basic-usage.php` | SMTP transport with middleware (CSS inlining, dark mode guard) |
| `modern-brevo-api.php` | Brevo HTTP API transport |
| `modern-postmark-api.php` | Postmark HTTP API transport |
| `modern-ses-api.php` | Amazon SES transport |
| `modern-jmap.php` | JMAP transport |
| `modern-custom-middleware.php` | Writing your own middleware (signature, unsubscribe headers) |
| `modern-dkim.php` | DKIM signing (key generation, DNS setup, sending) |
| `modern-with-logging.php` | PSR-3 telemetry middleware |

## Running an example

```bash
composer install
php examples/modern-basic-usage.php
```

## Testing email sending safely

When developing email code, use a fake mail server so test messages never reach real recipients:

- [smtp4dev](https://github.com/rnwood/smtp4dev) - dummy SMTP server with a web UI
- [MailHog](https://github.com/mailhog/MailHog) - SMTP test server (Docker-friendly)
- [aboutmy.email](https://aboutmy.email) - evaluates SPF, DKIM, DMARC, list-unsubscribe and TLS setup
- [msglint](https://www.splitbrain.org/_static/msglint) - MIME structure analyser
