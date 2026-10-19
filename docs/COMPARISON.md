# PHPMailer vs ZenoMailer — Honest Comparison

A feature-by-feature comparison, including what was verified empirically (marked **[verified]** with real sends and measurements from testing against live Gmail, Postmark and Brevo APIs) versus what exists only as code.

---

## TL;DR

| | PHPMailer | ZenoMailer |
|---|---|---|
| Best for | Maximum maturity, PHP 7.x, S/MIME, `mail()`/sendmail | Modern PHP 8.2+, API transports, batching, extensibility |
| License | LGPL-2.1 | MIT |
| Age | ~20 years, billions of sends | new, verified against live receivers |
| Verdict | The safe, proven choice | The modern choice — ahead on transports and DX, behind on accumulated hardening |

---

## Feature Matrix

| Feature | PHPMailer | ZenoMailer |
|---|---|---|
| SMTP with STARTTLS / implicit TLS | ✅ | ✅ **[verified vs Gmail]** |
| SMTP AUTH (PLAIN / LOGIN) | ✅ (+ CRAM-MD5, XOAUTH2) | ✅ PLAIN + LOGIN, mechanism negotiated from EHLO |
| Connection reuse across sends | ✅ opt-in (`SMTPKeepAlive`), manual close | ✅ **default on**, NOOP-validated, auto-reconnect **[verified: 3.9s → 1.7s/email vs Gmail]** |
| DKIM signing | ✅ simple/relaxed, mature | ✅ rsa-sha256 relaxed/relaxed; self-verified cryptographically in tests; **DNS-readiness guard** (refuses to sign with an unpublished key) |
| S/MIME signing/encryption | ✅ | ❌ |
| XOAUTH2 / Google OAuth | ✅ | ❌ |
| Internationalized domains (IDN → punycode) | ✅ | ✅ **[verified vs Gmail]** |
| UTF-8 local parts (SMTPUTF8, RFC 6531) | ✅ | ✅ negotiated per EHLO capabilities, fails clearly when unsupported **[verified vs Gmail]** |
| Attachments | ✅ | ✅ **[verified vs Gmail]** |
| Embedded images (CID) | ✅ | ✅ full `mixed[related[alternative, images], attachments]` nesting **[verified vs Gmail]** |
| `msgHTML()` auto-embedding | ✅ | ❌ (manual `addEmbeddedImage()`) |
| Word wrap for long unencoded lines | ✅ | ❌ (long lines are the sender's responsibility) |
| iCal event attachments | ✅ | ❌ |
| POP-before-SMTP | ✅ | ❌ |
| `mail()` / sendmail transports | ✅ | ❌ |
| HTTP API transports | ❌ | ✅ Brevo, Postmark, SES, JMAP — one identical API |
| Concurrent batch sending | ❌ | ✅ `sendBatch()` on every transport; Brevo delivers concurrently via curl_multi, measured **5x** (402ms vs 2007ms for 5 delayed requests) |
| Structured send results | ❌ (bool + `ErrorInfo`) | ✅ `TransportResult`: success, message, message ID, metadata |
| Auto-retry with backoff + jitter | ❌ | ✅ transient failures (SMTP 4xx, HTTP 429/5xx) retried automatically |
| Rate limiting | ❌ | ✅ token bucket per transport **[verified live: paces real API calls]** |
| Failover transport chain | ❌ | ✅ ordered backup transports **[verified live: dead SMTP primary rescued by Brevo]** |
| Middleware / extensibility | subclassing | ✅ composable pipeline (CSS inliner, dark mode guard, PSR-3 telemetry, custom) |
| DSN configuration | ✅ (`DSNConfigurator`) | ✅ all transports |
| Header injection defense | ✅ mature | ✅ validated/encoded at the boundary; CR/LF rejected |
| Bcc handling | ✅ | ✅ never in headers; explicit `Destinations` for SES |
| Language files (localized errors) | ✅ 50+ languages | ❌ (English messages) |
| Debug output levels | ✅ (`SMTPDebug`) | PSR-3 telemetry |
| PHP version support | 5.5 – 8.x | 8.2+ |

---

## Performance (measured, not claimed)

| Scenario | Result |
|---|---|
| SMTP to Gmail, fresh connection per email | ~3.9s/email |
| SMTP to Gmail, connection reuse (ZenoMailer default) | ~1.7s/email (**2.3x faster**) |
| Brevo API, sequential | ~0.75s/email |
| Brevo API, concurrent batch (`async=true`) | 5 requests × 400ms server delay: **402ms total** vs 2007ms sequential (**5x**) |

Notes: HTTP API transports avoid the per-message SMTP round trips entirely; the concurrency comes from curl_multi (no Fiber/event-loop dependency). SMTP latency floor (~1.7s to Gmail) is the protocol's round-trip cost, not library overhead.

---

## Architecture & Code Quality

| Aspect | PHPMailer | ZenoMailer |
|---|---|---|
| Structure | 5,500-line god class: message building, MIME, SMTP client, DKIM, validation, error state in one mutable object | Envelope (data) / Middleware (processing) / Transport (delivery), each independently testable |
| Typing | PHP 5.5-era patterns, broad mutable public surface | `strict_types`, `final` classes, `readonly`, `match`, constructor promotion |
| Static analysis | (its own tooling) | PHPStan level 8: zero errors |
| Test suite | extensive, mature | 220 tests / 7107 assertions, including **fake SMTP and HTTP servers** that verify full protocol transcripts, dot-stuffing round-trips, and a cryptographically independent DKIM verifier |
| Known-answer crypto tests | — | SigV4 verified byte-for-byte against AWS's official test suite |

---

## Real-World Verification Status

| Transport | Verified against |
|---|---|
| SMTP | Live Gmail (smtp.gmail.com:587, STARTTLS, AUTH PLAIN): basic sends, connection reuse, all middlewares, embedded images, attachments, RFC 2047/QP internationalization, SMTPUTF8 negotiation |
| Postmark | Live API via the official `POSTMARK_API_TEST` token: attachments, embedded images (ContentID), emoji content, tracking options (caught and fixed the PascalCase `TrackLinks` requirement); error paths with real API error messages |
| Brevo | Live API: request/response/error handling exercised (test key disabled — the transport surfaced the provider's exact error) |
| SES | SigV4 proven against AWS's official test-suite vector; payload format per AWS docs; live send pending credentials |
| JMAP | **Live against a local Stalwart server**: full send loop (session discovery, Drafts mailbox lookup by role, identity resolution, draft creation, submission) with receipt verified by querying the mailbox back; live testing caught and fixed five real bugs (DSN scheme/port, redirect following, relative apiUrl, server-assigned mailbox ids, submission capability/identity) |

One production lesson was learned the hard way and turned into a feature: a DKIM signature whose DNS record doesn't exist is **worse than no signature** — Gmail hard-rejects with `550 5.7.30`. ZenoMailer's `verify_dns` option refuses to sign until the key is published; PHPMailer has no equivalent guard.

---

## Where PHPMailer Still Wins

1. **Battle-testing** — two decades of edge cases (broken servers, exotic line endings, odd auth flows) already survived. ZenoMailer has been verified against exactly one live receiver (Gmail) plus two API error paths.
2. **Features**: S/MIME, XOAUTH2, `mail()`/sendmail, word wrap, iCal, POP-before-SMTP, `msgHTML`, localized error messages.
3. **Ecosystem**: documentation depth, community answers, dependents.
4. **Compatibility**: runs on PHP 5.5+; ZenoMailer requires 8.2+ (8.1 is EOL).

---

## Recommendation

- **Choose ZenoMailer** for new PHP 8.2+ projects — especially when you want API transports (Brevo/Postmark/SES), batch throughput, middleware-driven processing, or typed results. It does things PHPMailer structurally cannot.
- **Choose PHPMailer** when you need S/MIME, XOAUTH2, PHP 7.x, `mail()`/sendmail, or simply the accumulated confidence of twenty years in production.
- **Migrating?** See the [Migration Guide](../MIGRATION.md) for task-by-task translations.
