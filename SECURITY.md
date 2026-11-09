# Security policy

NONOX is an open-source (AGPL-3.0) bookkeeping, invoicing and payroll system for Swedish companies, developed by Stafe Development AB and run as a hosted service at https://nonox.app. It holds personal numbers, bank details, salaries and health-related absence data, so we take reports seriously.

## Reporting a vulnerability

**Do not open a public GitHub issue, pull request or discussion for a security problem.**

Send an e-mail to the security contact shown on [nonox.app/sakerhet](https://nonox.app/sakerhet) with "NONOX security" in the subject. You can also report it privately through GitHub: [Report a vulnerability](https://github.com/STAFE-GROUP-AB/nonox/security/advisories/new).

Please include:
- what the problem is and where (URL, route, file or commit),
- the steps to reproduce it, or a proof of concept,
- what an attacker could do with it, as you understand it,
- whether you tested against nonox.app or your own installation,
- how you would like to be credited, if at all.

Do not include real personal data in the report. If you came across any, tell us what kind of data it was, not the data itself.

## What to expect

| Step | Target |
|---|---|
| Acknowledgement of your report | Within 3 working days |
| First assessment (accepted, need more information, or not a vulnerability) | Within 10 working days |
| Fix | Depends on severity. We prioritise anything that exposes one company's data to another, bypasses authentication, or changes posted accounting records. |
| Disclosure | We agree on a date with you, normally after a fix is deployed on nonox.app and released in this repository |

These are targets for a small team, not guarantees. If we cannot meet one, we will tell you.

If a report shows that personal data on nonox.app may have been exposed, we handle it under our personal data breach procedure (`docs/gdpr/incidentrutin.md`). That includes notifying customers and, where required, the Swedish Authority for Privacy Protection (IMY).

## Scope

This policy covers the NONOX source code in this repository and the hosted service at https://nonox.app operated by Stafe Development AB. If you run NONOX yourself, you are responsible for operating it securely and for applying new releases; see [NONOX i egen drift](https://nonox.app/egen-drift).

In scope:
- the code in this repository,
- the hosted service at https://nonox.app and its subdomains operated by Stafe Development AB,
- in particular: cross-company data access (tenant isolation), authentication and sessions (including the passkey second step), authorisation and roles, the search (⌘K) and the firm workspace across companies, changes to posted vouchers, the audit log or the staff ledger, file access, the inbox's mail handling, the staff ledger kiosk, and the public API (NONOX API: `/api/v1`, the OAuth endpoints `/anslut` and `/oauth/token`, API keys, scopes, idempotency and rate limits).

Out of scope:
- installations of NONOX run by someone else (report to their operator; report the underlying code issue to us),
- third-party services we use (report to the vendor),
- denial-of-service and load testing, spam, social engineering and physical attacks,
- reports from automated scanners without a demonstrated impact,
- missing best-practice headers or settings without a concrete exploit,
- vulnerabilities that require a compromised device or browser.

## Safe harbour

We will not take legal action against, or report to the police, anyone who in good faith:
- reports a vulnerability to us as described here and gives us reasonable time to fix it before disclosing it,
- tests only against accounts and companies they created themselves, or against their own installation,
- does not access, change, delete or keep other people's data beyond the minimum needed to show the problem, and stops and tells us as soon as they do,
- does not degrade the service for others,
- does not demand payment.

This covers our own claims only. We cannot waive the rights of third parties or of public authorities. If you are unsure whether something is allowed, ask us first.

We do not run a paid bug bounty.

## Supported versions

NONOX is in public beta (version 0.9). Only the latest version is supported:

| Version | Supported |
|---|---|
| Hosted service at nonox.app | Yes, always the latest version |
| Latest commit on `main` | Yes |
| Earlier commits and forks | No. Update to the latest `main`. |

If you run NONOX yourself, install updates promptly. They may contain security fixes.

## Status page and security.txt

The hosted service shows its current state and recent incidents at [nonox.app/status](https://nonox.app/status). The reporting address is also published as [`/.well-known/security.txt`](https://nonox.app/.well-known/security.txt) (RFC 9116). A Swedish public version of this policy (`/sakerhetspolicy`) is switched off until it has been legally reviewed (`NONOX_SECURITY_POLICY_PUBLISHED`, ADR-078). If you run NONOX yourself, set your own contact in HQ → Inställningar (or `NONOX_SECURITY_EMAIL`); see ADR-078.

## Software bill of materials

Every [release](https://github.com/STAFE-GROUP-AB/nonox/releases) carries a software bill of materials (`nonox-<version>-sbom.cdx.json`, CycloneDX JSON) listing the PHP and JavaScript packages in `composer.lock` and `package-lock.json`. Use it to check your installation against new vulnerability advisories. Dependabot and `composer audit` / `npm audit` watch the same packages in this repository.

## Backups and restore tests

The hosted service restores its latest backup into a throwaway database every week and checks it: the hash chain, balance and numbering of every ledger, every attachment against its checksum, and the staff ledger chains. If you run NONOX yourself, `scripts/restore-test.sh` does the same for your backups. See [docs/gdpr/sakerhetskopiering.md](docs/gdpr/sakerhetskopiering.md).
