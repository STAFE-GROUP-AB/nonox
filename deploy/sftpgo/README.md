# SFTPGo for NONOX API (SFTP for SIE files)

This folder describes how to run [SFTPGo](https://github.com/drakkan/sftpgo)
as the SFTP server behind NONOX API's "SFTP för SIE-filer" (ADR-032,
section "SFTP"). **Nothing here is installed automatically.** Whoever runs
the installation decides whether to offer SFTP and does the steps below.
Until NONOX has the hook secret, the host and the users' directory, SFTP is
off: both hooks answer 404 and the panel says SFTP is not set up.

Written against **SFTPGo v2.7.6** (latest stable release on 2026-11-02),
from its official documentation (docs.sftpgo.com: external-auth,
custom-actions, config-file, env-vars) and the release's own
`openapi/openapi.yaml`, `sftpgo.json` and `init/sftpgo.service`. These files
have been checked against that documentation but **not run against a live
SFTPGo**; do the test procedure at the end before offering SFTP to anyone.

## How it fits together

```
 client (key) ──SSH──▶ SFTPGo :2022 ──POST /hooks/sftpgo/auth──▶ NONOX
                              │        (key → company → virtual user)
                              │ writes /srv/sftpgo/users/<user>/in/<file>
                              └──POST /hooks/sftpgo/upload (sync)──▶ NONOX
                                         queue job: read file → SIE import
                                         (pending confirmation) → delete file
```

- SFTPGo keeps no users of its own. Every login asks NONOX's external
  authentication hook, which finds the company from the generated user name
  (`nnx` + 13 random characters), checks the presented key against that
  company's active keys and the allowlist, and returns the virtual user:
  home directory, permissions (`/` list, `/in` list+upload, `/ut`
  list+download), allowed IPs, denied login methods (everything except
  public key), denied protocols (FTP, WebDAV, HTTP), allowed file patterns
  in `/in` (`*.se`, `*.si`, `*.sie`), max file size 50 MB, max 2 sessions,
  and `external_auth_cache_time` 0 so every login asks again.
- After every upload SFTPGo calls the upload hook **synchronously**. A
  non-200 answer makes SFTPGo delete the file and report an error to the
  client, so a refused file never stays on disk.
- NONOX reads the file from the shared directory in a queued job, takes it
  into the ordinary two-step SIE import (a person must confirm; nothing is
  booked automatically), and deletes it from the drop.
- SFTPGo's own quota is **not** used: NONOX deletes files outside SFTPGo,
  so SFTPGo's used-quota counter would only grow. NONOX enforces the limits
  itself (50 MB per file, 30 SIE files per hour per company, shared with
  browser uploads).

## Licence (AGPL-3.0 with section 7 terms)

SFTPGo is AGPL-3.0 with additional terms in its `NOTICE` (section 7.3(b)
and 13.1): a frontend that configures SFTPGo must credit the SFTPGo project
and its author(s), must not imply endorsement or affiliation, and must offer
remote users a link to the Corresponding Source of the version in use.
NONOX's SFTP panel does this; set `NONOX_SFTPGO_VERSION` to the release you
install so the link points to it. SFTPGo's web admin and web client are
switched off here; do not reuse their theme or branding anywhere.

## What must be done on the server

Run as root (or with sudo). Paths are the ones the example files use;
change them consistently if you choose others.

1. **Install SFTPGo v2.7.6** from the project's official packages or
   release binaries (see its README; verify the checksum of what you
   download). The package creates the `sftpgo` user and
   `/etc/sftpgo`.
2. **A shared group** for the users' files, with NONOX's PHP user (the
   one running php-fpm *and* the queue worker, `forge` on Forge) and
   `sftpgo` in it:

   ```bash
   groupadd --system nonox-sftp
   usermod -aG nonox-sftp sftpgo
   usermod -aG nonox-sftp forge
   ```

   Restart php-fpm and the queue worker afterwards so they get the group.
3. **The users' directory**, owned by `sftpgo`, group `nonox-sftp`,
   setgid so new folders keep the group, no access for others:

   ```bash
   install -d -o sftpgo -g nonox-sftp -m 2770 /srv/sftpgo/users
   install -d -o sftpgo -g sftpgo -m 0750 /var/lib/sftpgo
   ```

   Files in the drop are **not encrypted** (NONOX encrypts its own copy);
   put `/srv/sftpgo` on an encrypted disk if the server's disk is not.
   NONOX deletes every file from `/in` after reading it, and files in
   `/ut` after `NONOX_SFTP_EXPORT_DAYS` (default 7) through
   `php artisan connect:prune`, which already runs daily.
4. **Host keys** (once; never copy them between servers):

   ```bash
   ssh-keygen -t ed25519 -N '' -f /etc/sftpgo/ssh_host_ed25519_key
   ssh-keygen -t rsa -b 4096 -N '' -f /etc/sftpgo/ssh_host_rsa_key
   chown sftpgo:sftpgo /etc/sftpgo/ssh_host_*_key
   chmod 0600 /etc/sftpgo/ssh_host_*_key
   ssh-keygen -lf /etc/sftpgo/ssh_host_ed25519_key.pub
   ssh-keygen -lf /etc/sftpgo/ssh_host_rsa_key.pub
   ```

   Put the two `SHA256:…` fingerprints, comma-separated, in NONOX's
   `NONOX_SFTP_HOST_FINGERPRINTS`; the panel shows them so people can
   check the server on first connection.
5. **Configuration**: copy `sftpgo.json` to `/etc/sftpgo/sftpgo.json`
   and replace `https://nonox.example.se` with NONOX's address in the two
   hook URLs. Copy `sftpgo.env.example` to `/etc/sftpgo/sftpgo.env`, set
   the secret (`openssl rand -hex 32`) and the URL prefix, then
   `chown root:sftpgo /etc/sftpgo/sftpgo.env && chmod 0640 /etc/sftpgo/sftpgo.env`.
6. **systemd**: install `sftpgo.service` as
   `/etc/systemd/system/sftpgo.service` (it replaces the package's unit),
   then `systemctl daemon-reload && systemctl enable --now sftpgo`.
7. **Firewall**: open TCP 2022 for SFTP (`ufw allow 2022/tcp`, or in the
   provider's firewall). Nothing else from SFTPGo is listening: FTP,
   WebDAV, the web admin, the REST API and telemetry are all off
   (port 0). If your machine is behind Cloudflare, SFTP must go to the
   server's own address (DNS-only record such as `sftp.example.se`), since
   Cloudflare's proxy does not carry SSH.
8. **NONOX's `.env`** (then `php artisan config:cache`):

   ```dotenv
   NONOX_SFTPGO_HOOK_SECRET=<the same secret as in sftpgo.env>
   NONOX_SFTPGO_HOOK_IPS=<the address SFTPGo's requests come from, see below>
   NONOX_SFTP_HOST=sftp.example.se
   NONOX_SFTP_PORT=2022
   NONOX_SFTP_HOST_FINGERPRINTS=SHA256:…,SHA256:…
   NONOX_SFTP_ROOT=/srv/sftpgo/users
   NONOX_SFTPGO_VERSION=v2.7.6
   ```

   `NONOX_SFTP_HOME_ROOT` is only needed when SFTPGo sees the directory
   under another path than NONOX (for example in a container).

### The hook URLs and where the calls come from

SFTPGo calls the hooks over HTTPS with Go's own certificate check. Two
working set-ups:

- **Through the public address** (`https://nonox.example.se/hooks/sftpgo/…`).
  Simple. Behind Cloudflare the request then arrives through Cloudflare, so
  `$request->ip()` is whatever NONOX's trusted-proxy setting makes of it;
  leave `NONOX_SFTPGO_HOOK_IPS` empty unless you know which address NONOX
  will see. The bearer secret is what protects the hooks either way.
- **Locally** (recommended on one machine): make NONOX's host name resolve
  to `127.0.0.1` on the server (`/etc/hosts`) so the call never leaves it,
  and set `NONOX_SFTPGO_HOOK_IPS=127.0.0.1`. If nginx serves a Cloudflare
  *origin* certificate, Go does not trust it by default: add Cloudflare's
  origin CA root to SFTPGo's `http.ca_certificates`. Never set
  `skip_tls_verify`.

The secret is only sent to URLs that start with the configured prefix, and
NONOX compares it in constant time and never logs it. Rotating it: change
both `.env` files, `php artisan config:cache`, `systemctl restart sftpgo`.

## Test procedure (before offering SFTP)

1. `systemctl status sftpgo` is active; `journalctl -u sftpgo` shows no
   configuration errors; `ss -ltnp | grep sftpgo` shows only port 2022.
2. The hooks refuse strangers:
   `curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' https://nonox.example.se/hooks/sftpgo/auth`
   answers `401` (or `404` when called from an address outside
   `NONOX_SFTPGO_HOOK_IPS`).
3. In a **test company** (Anslutningar → Testföretag för API:t), turn
   SFTP on, add an ed25519 key made for the test, and note the user name.
4. `sftp -i test_key -P 2022 <user>@sftp.example.se` logs in and the host
   key fingerprint matches the panel. `ls` shows `in` and `ut`.
5. Refusals: a password login, another key, a login from an address outside
   the allowlist (if you set one) and `ftp`/`https` to the server all fail.
6. `put` a small SIE 4 file to `/in`: within seconds the panel's upload log
   shows it, the file has disappeared from `/in`, Importera SIE lists a
   report waiting for confirmation, and the owner has a notice. Nothing is
   booked until someone confirms.
7. `put` a file named `x.txt`, and a file in `/ut`: both are refused.
8. Turn SFTP off in the panel: the next login fails. Remove the key: the
   next login with it fails.
9. "Lägg SIE-fil för innevarande år i /ut", then `get` it from `/ut`.
10. Check the audit log (Behandlingshistorik) for `connect.sftp.*` entries.

## Backups

SFTPGo's own database (`/var/lib/sftpgo`) holds nothing that cannot be
recreated: users come from NONOX at every login. `/srv/sftpgo/users` holds
only files on their way in or out and does not need a backup. What matters
(the SIE import and its encrypted copy, the upload log and the audit log)
is in NONOX's database and storage, covered by its ordinary backup.
