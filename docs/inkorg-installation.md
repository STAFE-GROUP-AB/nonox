# Kom igång med Inkorgen

Inkorgen ger företaget en egen e-postadress dit kassasystem, leverantörer och
personal skickar dagsrapporter, fakturor och kvitton. Originalet sparas som
underlag, och innehållet blir ett förslag som du godkänner. Tekniska beslut
finns i [ADR-023](adr/ADR-023-inbox.md).

## 1. Slå på modulen

1. Gå till **Företagsinställningar → Moduler** och slå på **Inkorg**.
2. Öppna inställningarna för Inkorg. Där finns företagets adress, en knapp
   för att kopiera den, listan över godkända avsändare, sparade mallar och
   inställningar för en egen brevlåda.

Modulen kan stängas av när som helst. Det som redan kommit in finns kvar.

## 2. Adresserna (NONOX CLOUD, nonox.app)

- **Företagets adress** ser ut som `exempelbolaget-k3x9p2qa@in.nonox.app`.
  Slutet är slumpat så att adressen inte går att gissa. Ägaren kan byta till
  en ny adress; den gamla slutar då ta emot direkt.
- **Tillägg** efter plustecknet säger vad som skickas:
  - `…+kassa@in.nonox.app` för dagsrapporter (Z-rapporter) från kassan,
  - `…+faktura@in.nonox.app` för leverantörsfakturor,
  - `…+kvitto@in.nonox.app` för kvitton.
- **Den gemensamma adressen** `mejl@in.nonox.app` är till för det man
  vidarebefordrar från sin egen e-post. Den tar bara emot post från en
  adress som någon i företaget loggar in med och som står på företagets
  lista. En domän (`@leverantor.se`) räcker inte där, eftersom vilket
  företag som helst kan skriva in vilken domän som helst. Leverantörer och
  kassasystem skickar till företagets egen adress. Är avsändaren med i
  flera företag läggs meddelandet åt sidan tills någon hämtar det till rätt
  företag under **Inkorg → Väntar på att ett företag hämtar dem**.

En byrå får en adress per kundföretag, eftersom varje företag har sin egen.

## 3. Godkänn avsändare

Bara e-post från adresser på listan **Godkända avsändare** tolkas. Lägg till

- en exakt adress, till exempel `rapport@kassasystemet.se`, eller
- en hel domän, till exempel `@kassasystemet.se`.

E-post från någon annan hamnar i karantän under **Att granska**, med knappen
**Godkänn avsändaren**. Ingenting tolkas eller bokförs innan dess.

Även en godkänd avsändare hamnar i karantän om avsändarens domän inte kan
bekräfta meddelandet (SPF, DKIM, DMARC), eftersom en avsändaradress lätt kan
förfalskas. Titta på meddelandet och välj **Släpp igenom efter granskning**
om det är äkta. Ett sådant meddelande bokförs aldrig automatiskt.

## 4. Ställ in kassasystemet

Be kassasystemet skicka dagsrapporten (Z-rapporten) med e-post till
`…+kassa@in.nonox.app`. CSV, Excel (XLSX), vanlig text och PDF med text
(inte en inskannad bild) fungerar.

- Första rapporten tolkas av NONOX AI om modulen NONOX AI är påslagen, annars
  kopplar du varje belopp till ett konto själv.
- Kontrollera förslaget och godkänn. Då sparas en **mall** för avsändaren och
  formatet.
- Nästa rapport i samma format tolkas med mallen, utan AI.
- Vill du att rapporterna bokförs utan att du godkänner varje gång, välj
  **Bokför automatiskt** på mallen. Det sker bara när rapporten stämmer
  exakt med mallen, verifikationen balanserar och inga varningar finns.

## 5. Arbeta i inkorgen

Under **Inkorg** finns flikarna **Att granska**, **Utkast klara**,
**Bokfört** och **Avvisat**. Bilagan visas till vänster och förslaget till
höger.

| Tangent | Gör |
|---|---|
| Ctrl/⌘ + Enter | Godkänn och bokför (tryck igen för att bekräfta) |
| j / k | Nästa / föregående underlag |
| g | Godkänn avsändaren |
| x | Avvisa (tryck igen för att bekräfta) |
| ? | Visa kortkommandona |

Du kan svara avsändaren direkt, till exempel "Kvittot saknas, kan du skicka
det?". Varningar om ändrat bankgiro, en avsändardomän som liknar en känd
leverantörs eller en bilaga som kommit förut måste du bekräfta innan du
godkänner.

En leverantörsfaktura kopplas till leverantören som redan finns i registret,
även en utländsk leverantör utan organisationsnummer (momsnummer, IBAN,
e-postdomän eller namn). Liknar namnet bara en befintlig leverantör frågar
NONOX **Menade du …?** och du väljer **Koppla** i stället för att lägga till
leverantören en gång till.

Gallring: avvisade meddelanden tas bort efter 7 dagar, och meddelanden i
karantän som ingen hanterat efter 90 dagar. Skräppost från avsändare som
inte är godkända sparas inte alls. Det som blivit bokföring sparas lika
länge som bokföringen.

## 6. Egen brevlåda (när ni kör NONOX själva)

Kör ni NONOX på egen server kan NONOX hämta e-post från en vanlig brevlåda,
gratis och utan NONOX CLOUD.

1. Skapa en brevlåda som bara används för underlag, till exempel
   `underlag@ertforetag.se`.
2. Under inställningarna för Inkorg, slå på **Hämta e-post från en egen
   brevlåda** och fyll i server (till exempel `imap.ertforetag.se`), port
   (993 med SSL/TLS eller 143 med STARTTLS), användarnamn och lösenord.
   Lösenordet sparas krypterat. NONOX hämtar aldrig e-post okrypterat och
   kräver ett giltigt certifikat för serverns namn.
   - NONOX ansluter bara till servrar med en **offentlig adress** på
     internet. Ett namn som pekar på en intern adress (127.0.0.1, 10.x,
     172.16–31.x, 192.168.x, 169.254.x, IPv6 som `::1`, `fc00::/7`,
     `fe80::/10` med flera) nekas, så att inställningarna inte kan användas
     för att nå tjänster inne i ert nät. Ligger er e-postserver på ett eget,
     privat nät, sätt `NONOX_INBOX_IMAP_ALLOW_PRIVATE=true` i `.env`. Gör det
     bara om alla som kan ändra inkorgens inställningar får nå det nätet.
   - Misslyckas en hämtning visar inställningarna ett allmänt skäl.
     Detaljerna skrivs i NONOX logg (`storage/logs`).
   - Ett meddelande som är större än `NONOX_INBOX_MAX_MESSAGE_KB` (25 MB)
     hämtas inte; det hoppas över och syns i behandlingshistoriken.
3. Se till att schemaläggaren körs (`php artisan schedule:run` varje minut).
   NONOX hämtar ny e-post var femte minut med `php artisan inbox:fetch`.
   Ingenting på e-postservern ändras.
4. Om er e-postserver lägger till rubriken `Authentication-Results`, sätt
   `NONOX_INBOX_IMAP_AUTHSERV_ID` till serverns namn i rubriken, så litar
   NONOX bara på den.
5. Rekommenderat: installera ClamAV och sätt `NONOX_INBOX_CLAMD_SOCKET`,
   till exempel `/var/run/clamav/clamd.ctl`. Utan den spärras farliga
   filtyper men ingen virussökning görs.

## 7. För den som driftar NONOX CLOUD

1. **DNS** för `in.nonox.app`: MX-poster till Mailgun EU
   (`mxa.eu.mailgun.org` och `mxb.eu.mailgun.org`, prioritet 10), plus de
   TXT-poster Mailgun anger när domänen läggs till.
2. **Mailgun:** lägg till domänen `in.nonox.app` i EU-regionen och skapa en
   inkommande route: *Match Recipient* `.*@in.nonox.app`, åtgärd
   *Forward* till `https://nonox.app/webhooks/inkorg/mailgun/mime`
   (slutet `/mime` gör att Mailgun skickar hela meddelandet) och *Stop*.
3. **Miljövariabler:**
   - `MAILGUN_WEBHOOK_SIGNING_KEY` = HTTP webhook signing key från Mailgun,
   - `NONOX_INBOX_DOMAIN=in.nonox.app`,
   - `NONOX_INBOX_SHARED_LOCAL=mejl`,
   - `NONOX_INBOX_CLAMD_SOCKET=/var/run/clamav/clamd.ctl` när ClamAV är
     installerat (rekommenderas),
   - valfritt `NONOX_INBOX_MAX_MESSAGE_KB` (förval 25600).
4. Kör `php artisan migrate` och se till att kön och schemaläggaren körs
   (`inbox:fetch`, `inbox:daily-summary`, `inbox:prune`).

## 8. CRM: loggade mejl och utskick (ADR-048)

- **Loggning med +crm:** routen ovan (`.*@in.nonox.app`) tar redan emot
  `{slug}+crm@in.nonox.app`; NONOX skickar dem till CRM i stället för
  inkorgen. Inget mer behövs i Mailgun. Själv-driftat med IMAP: e-postservern
  måste leverera plusadresser (`brevlada+crm@doman`) till brevlådan och sätta
  `Delivered-To` eller `X-Original-To`.
- **Egen avsändardomän för CRM:** lägg till en domän i Mailgun EU, till
  exempel `utskick.nonox.app`, med de SPF- och DKIM-poster Mailgun anger och
  en DMARC-post (`_dmarc.utskick.nonox.app`). Skapa SMTP-uppgifter för
  domänen och sätt `NONOX_CRM_SMTP_HOST=smtp.eu.mailgun.org`,
  `NONOX_CRM_SMTP_PORT=587`, `NONOX_CRM_SMTP_USERNAME`,
  `NONOX_CRM_SMTP_PASSWORD`, `NONOX_CRM_SMTP_SCHEME=smtp` och
  `NONOX_CRM_MAIL_FROM=utskick@utskick.nonox.app`. Utan dem är utskick och
  mejl med mall av i molntjänsten (`NONOX_HOSTED=true`); själv-driftat
  används standardmejlaren.
- **Händelser för utskick:** Mailgun → Sending → Webhooks för den domänen:
  lägg till `https://nonox.app/webhooks/utskick/mailgun` för *Delivered*,
  *Permanent failure* och *Spam complaints*. Samma
  `MAILGUN_WEBHOOK_SIGNING_KEY` används.
- **Spårning av:** stäng av open och click tracking för domänen (varje
  utskick skickar också `X-Mailgun-Track: no`). NONOX skickar över SMTP, där
  Mailgun läser `X-Mailgun-*`-rubrikerna; med Mailguns API skulle de vara
  API-parametrar i stället.
- **IMAP (själv-driftat):** e-postservern måste lägga till en rubrik
  Authentication-Results (eller sätt `NONOX_INBOX_IMAP_AUTHSERV_ID`), annars
  tas mejl till `+crm` inte emot.
- **Schemaläggare och kö:** `crm:send-mailings` varje minut och
  `crm:prune-mail` varje natt; utskicken skickas i omgångar av köarbetaren.
- Valfritt: `NONOX_CRM_MAILING_HOURLY_CAP` (förval 500) och
  `NONOX_CRM_MAILING_DAILY_CAP` (förval 2000) per företag.
