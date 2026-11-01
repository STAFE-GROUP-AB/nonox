# Webhooks

With webhooks your program does not have to ask for changes: NONOX sends a `POST` to your server when something happens. They are delivered since version 1.1.0 of the API.

## How they work

- An owner or administrator adds the webhook under **Anslutningar** in NONOX and confirms with a passkey: an `https` address and the events it should receive. The owner gets an e-mail about every new webhook.
- The address is checked when it is saved and before every attempt: private networks, loopback, link-local and other internal addresses are refused, the connection is pinned to the checked address and redirects are never followed.
- Every webhook has a secret of its own (`nnxwh_…`), shown once. It signs every delivery and can be rotated.
- The event is sent only after the bookkeeping is saved. Answer with `2xx` within 10 seconds (5 to connect). Anything else is retried after 1, 5 and 15 minutes and 1, 3, 6 and 13 hours. If the eighth attempt fails too, the webhook is disabled and the owner gets an e-mail.
- The same event may arrive more than once. `Nonox-Event-Id` is the same on every retry; keep the ids you have handled and skip duplicates. `Nonox-Event-Type` names the event.
- The body is short: ids, numbers, dates and amounts, never personal data. Fetch the full object from the API if you need more.
- Want to try without real bookkeeping? Create a [test company](kom-igang.md) under Anslutningar.

## The events

<!-- nonox:webhooks -->

## Verify the signature

Every delivery carries the header:

```http
Nonox-Signature: t=1772444713,v1=5f8e2a1c9b7d3e6f0a4c8b2d1e9f7a3c5b0d8e6f2a4c1b9d7e3f5a0c8b6d2e4f
```

`t` is the time in Unix seconds and `v1` is the HMAC-SHA256, hex, of `"<t>.<raw body>"` with the subscription's secret. To verify it:

1. Read the **raw** body, byte for byte, before anything parses the JSON.
2. Split the header on `,` and `=`. There may be several `v1` while a secret is being rotated; accept when any of them matches.
3. Reject when `t` is more than five minutes from your clock (replay protection).
4. Compute HMAC-SHA256 of `t + "." + body` and compare in **constant time**.

<!-- tabs -->
```php
/**
 * Verifies Nonox-Signature for a webhook. $payload is the raw body
 * ($request->getContent() in Laravel), never parsed and re-encoded JSON.
 */
function verifyNonoxSignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

        if ($key === 't' && ctype_digit($value)) {
            $timestamp = (int) $value;
        }

        if ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

// In a Laravel controller:
abort_unless(verifyNonoxSignature($request->getContent(), (string) $request->header('Nonox-Signature'), config('services.nonox.webhook_secret')), 400);
```

```js
import crypto from 'node:crypto';
import express from 'express';

export function verifyNonoxSignature(rawBody, header, secret, toleranceSeconds = 300) {
  let timestamp = null;
  const signatures = [];

  for (const part of String(header).split(',')) {
    const [key, value = ''] = part.trim().split('=', 2);
    if (key === 't' && /^\d+$/.test(value)) timestamp = Number(value);
    if (key === 'v1') signatures.push(value);
  }

  if (timestamp === null || Math.abs(Date.now() / 1000 - timestamp) > toleranceSeconds) {
    return false;
  }

  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.`).update(rawBody).digest();

  return signatures.some((signature) => {
    const given = Buffer.from(signature, 'hex');
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
  });
}

// Express: read the body raw, not as JSON.
const app = express();
app.post('/nonox/webhook', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifyNonoxSignature(req.body, req.get('Nonox-Signature'), process.env.NONOX_WEBHOOK_SECRET)) {
    return res.sendStatus(400);
  }
  const event = JSON.parse(req.body.toString('utf8'));
  res.sendStatus(204); // Answer fast, do the work in a queue.
});
```

```python
import hashlib
import hmac
import time


def verify_nonox_signature(payload: bytes, header: str, secret: str, tolerance: int = 300) -> bool:
    timestamp, signatures = None, []

    for part in header.split(","):
        key, _, value = part.strip().partition("=")
        if key == "t" and value.isdigit():
            timestamp = int(value)
        if key == "v1":
            signatures.append(value)

    if timestamp is None or abs(time.time() - timestamp) > tolerance:
        return False

    expected = hmac.new(secret.encode(), f"{timestamp}.".encode() + payload, hashlib.sha256).hexdigest()

    return any(hmac.compare_digest(expected, signature) for signature in signatures)


# Flask: request.get_data() is the raw body.
# if not verify_nonox_signature(request.get_data(), request.headers.get("Nonox-Signature", ""), os.environ["NONOX_WEBHOOK_SECRET"]):
#     abort(400)
```
<!-- /tabs -->

## Test your check

With the secret `test_secret`, the time `1772444713` and the body `{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}`, the signature is the HMAC-SHA256 of `1772444713.{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}`. Compute it yourself and compare:

```bash
printf '%s' '1772444713.{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}' \
  | openssl dgst -sha256 -hmac 'test_secret' -hex
```

Remember that your check rejects a time that old; test with the tolerance switched off or with a current time.
