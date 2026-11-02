# SFTP for SIE files

Some programs would rather deliver a file than call an API: a cash register system that writes a SIE file every night, or an accounting firm's tool that produces a client's bookkeeping. For them, every company in NONOX can have its own SFTP drop. A file delivered there is checked like an ordinary SIE import and then waits for a person in the company to read the report and confirm it. Nothing is booked automatically. Available since version 1.3.0 of the API.

## Getting started

1. An owner or administrator of the company turns **SFTP** on under **Anslutningar** (Connections) in NONOX. Whoever turns it on becomes responsible for the files that arrive and must be allowed to import SIE files themselves.
2. Create a key pair for your program. Use ed25519:

   ```bash
   ssh-keygen -t ed25519 -f nonox_sftp -C "cash-register"
   ```

   RSA works too, with at least 3072 bits (`ssh-keygen -t rsa -b 4096`). DSA, ECDSA, certificates and security-key types (`sk-…`) are not accepted.
3. Send **only the public key** (`nonox_sftp.pub`) to the person in the company. The private key never leaves your program.
4. They add the key under **Anslutningar → SFTP för SIE-filer** and confirm with a passkey. NONOX shows the key's fingerprint (`SHA256:…`); compare it with your own:

   ```bash
   ssh-keygen -lf nonox_sftp.pub
   ```

5. The same page shows the server, port and user name. The user name starts with `nnx` and is random; it says nothing about the company.

## Connecting

```bash
sftp -i nonox_sftp -P 2022 nnxabc123def456gh@sftp.example.se
sftp> cd /in
sftp> put SIE4_20260601.se
```

Check the server's fingerprint the first time you connect. If the NONOX page shows one, it must match; otherwise ask whoever runs the installation.

Only key login works. Passwords, keyboard-interactive login, FTP, WebDAV and the web client are turned off for these users. If the company has entered allowed IP addresses, only those get in.

## Folders

| Folder | What you may do |
|---|---|
| `/` | List |
| `/in` | List and upload `.se`, `.si` and `.sie`. No overwrite, rename or delete. |
| `/ut` | List and download files NONOX has put there |

Upload under a new name every time, for example with the date and time in it. A file cannot be overwritten.

## Limits

- At most 50 MB per file, the same as in the browser.
- At most 30 SIE files per hour per company, together with uploads in the browser. File 31 is refused and you get an error.
- At most two concurrent connections per user.

## What happens to the file

1. When the upload finishes, the SFTP server asks NONOX whether to accept it. If it is in the wrong place, has the wrong ending, is too large, or the company has turned SFTP off, NONOX says no; the server removes the file and your program gets an error.
2. NONOX reads the file in the background. It must start with `#FLAGGA` and be a text file. It is then checked exactly as when someone uploads it in the browser: SIE type 4, the right organisation number, the checksum, balanced vouchers, an existing fiscal year, no locked period, and not already imported.
3. The file is always removed from `/in` afterwards. An encrypted copy is kept with the SIE import.
4. The responsible person and the owner get a notice. Under **Importera SIE** (Import SIE) they find the report; only when a person confirms are the vouchers read in.

## The `sie.uploaded` webhook

If your program wants to know how it went, the company can add a [webhook](webhooks.md) for `sie.uploaded`. It is sent when the file has been checked:

```json
{
  "type": "sie.uploaded",
  "data": {
    "upload_id": "9b2f4c6e-8a1d-4e3f-b5c7-d9e1f3a5b7c9",
    "status": "analysed",
    "size": 18432,
    "sha256": "3a7bd3e2…",
    "sie_import_id": 118,
    "can_be_confirmed": true,
    "vouchers": 31,
    "blockers": 0
  }
}
```

`status` is `analysed` (waiting for confirmation) or `rejected` (not accepted). `can_be_confirmed` is `false` when the report lists problems that must be fixed first. The file name is not included; compare `sha256` with your own file to tell which one it was.

## Downloading from `/ut`

An owner or administrator can put a SIE4 file for the current fiscal year in `/ut` with a button under Connections. Fetch it with `get`. The file is removed after seven days.
