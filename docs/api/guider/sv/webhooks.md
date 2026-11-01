# Webhooks

Med webhooks slipper ditt program fråga efter ändringar: NONOX skickar en `POST` till din server när något händer. De skickas sedan version 1.1.0 av API:t.

## Så fungerar de

- En ägare eller administratör lägger till webhooken under **Anslutningar** i NONOX och bekräftar med passkey: en `https`-adress och vilka händelser den ska få. Ägaren får ett mejl om varje ny webhook.
- Adressen kontrolleras när den sparas och före varje försök: privata nät, loopback, link-local och andra interna adresser avvisas, anslutningen låses till den kontrollerade adressen och omdirigeringar följs aldrig.
- Varje webhook får en egen hemlighet (`nnxwh_…`) som visas en gång. Den används för att signera varje leverans och kan bytas.
- Händelsen skickas först när bokföringen är sparad. Svara med `2xx` inom 10 sekunder (5 för att ansluta). Allt annat försöks igen efter 1, 5 och 15 minuter och 1, 3, 6 och 13 timmar. Misslyckas även det åttonde försöket stängs webhooken av och ägaren får ett mejl.
- Samma händelse kan komma mer än en gång. `Nonox-Event-Id` är samma vid varje nytt försök; spara de id:n du har behandlat och hoppa över dubbletter. `Nonox-Event-Type` säger vilken händelse det är.
- Innehållet är kort: id, nummer, datum och belopp, aldrig personuppgifter. Hämta det fullständiga objektet från API:t om du behöver mer.
- Vill du prova utan riktig bokföring? Skapa ett [testföretag](kom-igang.md) under Anslutningar.

## Händelserna

<!-- nonox:webhooks -->

## Kontrollera signaturen

Varje leverans har huvudet:

```http
Nonox-Signature: t=1772444713,v1=5f8e2a1c9b7d3e6f0a4c8b2d1e9f7a3c5b0d8e6f2a4c1b9d7e3f5a0c8b6d2e4f
```

`t` är tiden i Unix-sekunder och `v1` är HMAC-SHA256, hex, av `"<t>.<rå kropp>"` med prenumerationens hemlighet. Så här kontrollerar du den:

1. Läs den **råa** kroppen, byte för byte, innan något tolkar JSON.
2. Dela upp huvudet på `,` och `=`. Det kan finnas flera `v1` när en hemlighet byts; godta om någon stämmer.
3. Avvisa om `t` är mer än fem minuter från din klocka (skydd mot uppspelning).
4. Räkna ut HMAC-SHA256 av `t + "." + kropp` och jämför med **konstant tid**.

<!-- tabs -->
```php
/**
 * Kontrollerar Nonox-Signature för en webhook. $payload är den råa kroppen
 * ($request->getContent() i Laravel), aldrig en tolkad och omkodad JSON.
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

// I en Laravel-controller:
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

// Express: läs kroppen rå, inte som JSON.
const app = express();
app.post('/nonox/webhook', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifyNonoxSignature(req.body, req.get('Nonox-Signature'), process.env.NONOX_WEBHOOK_SECRET)) {
    return res.sendStatus(400);
  }
  const event = JSON.parse(req.body.toString('utf8'));
  res.sendStatus(204); // Svara snabbt, gör jobbet i en kö.
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


# Flask: request.get_data() är den råa kroppen.
# if not verify_nonox_signature(request.get_data(), request.headers.get("Nonox-Signature", ""), os.environ["NONOX_WEBHOOK_SECRET"]):
#     abort(400)
```
<!-- /tabs -->

## Testa din kontroll

Med hemligheten `test_secret`, tiden `1772444713` och kroppen `{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}` ska signaturen bli HMAC-SHA256 av `1772444713.{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}`. Räkna ut den själv och jämför:

```bash
printf '%s' '1772444713.{"id":"c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f","type":"voucher.posted"}' \
  | openssl dgst -sha256 -hmac 'test_secret' -hex
```

Kom ihåg att din kontroll avvisar en så gammal tid; testa med `tolerance` avstängd eller med en aktuell tid.
