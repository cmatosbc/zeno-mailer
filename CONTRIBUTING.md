# Contributing to ZenoMailer

## Development workflow

`main` is protected: force pushes and deletion are blocked, and the CI checks
(`PHP 8.2`, `PHP 8.3`) must pass on a commit before it can land on `main`.

The workflow for every change:

```bash
# 1. Commit locally, then push to a temporary CI branch
git push origin main:ci-<feature>

# 2. Wait for the checks to go green
gh run watch $(gh run list --limit 1 --json databaseId --jq '.[0].databaseId')

# 3. Land it on main (accepted now that the commit has passing checks)
git push origin main

# 4. Clean up
git push origin --delete ci-<feature>
```

Pull requests work too and are gated by the same checks.

## Local development

```bash
composer install        # dev dependencies (phpunit, phpstan)
composer test           # run the test suite
composer analyse        # PHPStan level 8 — must stay at zero errors
```

## Release checklist

1. Update `CHANGELOG.md` (Keep a Changelog format)
2. Full suite green locally: `composer test && composer analyse`
3. Push to `main` via the workflow above
4. Tag: `git tag -a vX.Y.Z -m "..." && git push origin vX.Y.Z`
5. Packagist syncs via webhook (or click "Update" on the package page)

## Security

See [SECURITY.md](SECURITY.md) — please report vulnerabilities privately via
GitHub's vulnerability reporting, never in public issues.
