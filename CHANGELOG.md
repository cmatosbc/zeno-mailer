# Changelog

All notable changes to ZenoMailer are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versioning follows
[Semantic Versioning](https://semver.org/) — while the major version is `0`,
the API may change between releases.

## [Unreleased]

## [0.1.0-beta.6] — 2026-10-19

### Added
- **Spool/outbox**: `withSpool($directory)` queues failed sends as JSON files instead of losing them; `Mailer::flushSpool()` re-sends them through the full transport chain (successes removed, failures kept with attempt counts). Attachments are stored by path. Verified live: outage → queue → a failed flush (IP changed mid-test) kept the messages queued → the next flush delivered everything
- `CONTRIBUTING.md` documenting the CI-before-main workflow

## [0.1.0-beta.5] — 2026-10-19

### Added
- **Streaming attachments**: messages with attachments are serialized to a `php://temp` stream and attachment content streams from disk to the SMTP socket through PHP's native base64 encoding filter, with on-the-fly dot-stuffing as a stream filter. Peak memory stays ~constant regardless of attachment size (measured: 9.3MB attachment → ~2MB RAM; the previous path peaked at ~7x the attachment size). DKIM-signed messages still materialize (signing requires the full message); HTTP API transports are unchanged (their JSON payloads require full content)

## [0.1.0-beta.4] — 2026-10-18

### Added
- **Delivery telemetry**: `enableTelemetry()` now wraps the transport instead of the middleware pipeline, so it logs the real delivery outcome — `Email delivered` (duration, message id, transport, retry attempts) or `Delivery failed` (error, retryable) — instead of "processed successfully" before the send. Recipients (PII) can be omitted via `logRecipients: false`
- **Batch contract**: `sendBatch()` is now part of `TransportInterface` — every transport and resilience decorator implements it (Brevo keeps its concurrent curl_multi version; retry/rate-limit/failover apply per envelope). New `Mailer::sendBatch()` runs every envelope through the middleware pipeline first, so batches and single sends have identical semantics
- `SECURITY.md` (private vulnerability reporting) and Dependabot (composer + GitHub Actions, weekly)

### Changed
- **PHP requirement raised to 8.2** (PHP 8.1 reached end of life in December 2025); claims and CI now match exactly

### Added (internal)
- `CHANGELOG.md` (this file)

## [0.1.0-beta.3] — 2026-10-18

### Fixed
- JMAP: the DSN now produces a scheme and port for the session URL (`jmap://host` previously built a scheme-less URL); added the `jmap+http://` scheme for plain-HTTP servers
- JMAP: session discovery follows redirects (Stalwart's `/.well-known/jmap` redirects to `/jmap/session`)
- JMAP: relative `apiUrl` values in the session (e.g. `/jmap/`) are resolved against the session host
- JMAP: the Drafts mailbox is looked up **by role** (and created on first use) instead of assuming a literal `"drafts"` mailbox id; the lookup is cached
- JMAP: `EmailSubmission/set` now declares the `urn:ietf:params:jmap:submission` capability and resolves the identity for the From address (creating one if needed); submissions use concrete ids instead of same-request backreferences
- Postmark: `track_links` values are normalized to Postmark's PascalCase (`HtmlAndText` etc.) — lowercase values were rejected by the API

### Verified live
- Postmark against the real API (official `POSTMARK_API_TEST` token): attachments, embedded images via ContentID, emoji content, tracking options
- JMAP against a local Stalwart server: full send loop with receipt verified by querying the mailbox back

## [0.1.0-beta.2] — 2026-10-18

### Added
- **Resilience package**:
  - `withRetry()` — transient failures retried with exponential backoff and full jitter
  - `withRateLimit()` — token-bucket throttling per transport (`withRateLimit(300, 'day')`)
  - `withFailover()` — ordered backup transport chain; each candidate gets the retry/rate-limit stack
  - Structured retryability: `TransportResult::isRetryable()` classifies SMTP 4xx replies, HTTP 429/408/5xx, SES throttling and network errors as transient; SMTP 5xx, HTTP 4xx and validation errors as permanent

### Fixed
- Brevo: empty recipient names are omitted (the API rejects `{"name": ""}` with "name is missing in to")

### Verified live
- Brevo against the real API: sync sends, concurrent async batch (~200ms/email), attachments, Reply-To, custom headers, unicode/emoji content, and three distinct failure modes (disabled key, IP whitelist, empty names)

## [0.1.0-beta.1] — 2026-10-18

### Added
- Initial public beta
- **Transports**: SMTP (STARTTLS/implicit TLS, AUTH PLAIN/LOGIN negotiated from EHLO capabilities, dot-stuffing, `BODY=8BITMIME`, connection reuse by default with NOOP validation), Brevo, Postmark, Amazon SES (SigV4 verified against AWS's official test suite), JMAP
- **DKIM signing** (RFC 6376, rsa-sha256, relaxed/relaxed) with a DNS-readiness guard (`verify_dns`) — an unverifiable signature is worse than none (Gmail rejects with `550 5.7.30`)
- **Middleware pipeline**: CSS inliner, dark mode guard, custom middleware
- **Internationalized addresses**: IDN domains punycode-converted, UTF-8 local parts via RFC 6531 SMTPUTF8 negotiation
- **RFC-compliant MIME**: RFC 2047 header encoding, header folding, dot-stuffing, automatic line-length protection (overlong bodies upgrade to quoted-printable), Bcc never emitted in headers
- Typed results (`TransportResult`) with per-send metadata
- 189 tests including fake SMTP/HTTP servers; PHPStan level 8 clean

[Unreleased]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.6...HEAD
[0.1.0-beta.6]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.5...v0.1.0-beta.6
[0.1.0-beta.5]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.4...v0.1.0-beta.5
[0.1.0-beta.4]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.3...v0.1.0-beta.4
[0.1.0-beta.3]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.2...v0.1.0-beta.3
[0.1.0-beta.2]: https://github.com/cmatosbc/zeno-mailer/compare/v0.1.0-beta.1...v0.1.0-beta.2
[0.1.0-beta.1]: https://github.com/cmatosbc/zeno-mailer/releases/tag/v0.1.0-beta.1
