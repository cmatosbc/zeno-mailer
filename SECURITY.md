# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x (beta) | ✅ security fixes |
| < 0.1.0 | ❌ |

## Reporting a vulnerability

**Please do not report security issues in public issues or discussions.**

Use GitHub's private vulnerability reporting:
[Security → Report a vulnerability](https://github.com/cmatosbc/zeno-mailer/security/advisories/new)

Include:

- A description of the issue and its impact
- Steps to reproduce (a minimal script is ideal)
- The affected version(s)

You will receive a response within 72 hours. Fixes are released as soon as
possible; reporters are credited in the changelog unless they prefer otherwise.

## Scope

In scope: anything in the `src/` library code — header injection, MIME
parsing, credential exposure, TLS handling, DKIM signing, transport request
forgery.

Out of scope: misconfiguration of third-party providers (e.g. sending with an
unpublished DKIM key), vulnerabilities in dependencies (report upstream).

## Known design notes

- All header input (subjects, names, custom headers, filenames) is validated
  and encoded at the boundary; CR/LF injection is rejected
- Bcc recipients never appear in message headers
- TLS peer verification is enabled by default for SMTP and HTTP transports
- DKIM private keys are never logged; telemetry logs recipients only when
  explicitly enabled (`logRecipients` — recipients are PII)
