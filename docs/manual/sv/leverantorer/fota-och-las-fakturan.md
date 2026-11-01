---
beskrivning: Fotografera pappersfakturan med mobilen och låt NONOX AI fylla i leverantör, belopp, moms och konton åt dig. Du kontrollerar och sparar.
ordning: 30
roller: [owner, admin, accountant, bookkeeper]
modul: ai
sokord: [fota faktura, kamera, mobil, ai, tolka faktura, läsa faktura, ocr-tolkning, skanna]
---

# Fota fakturan och låt NONOX AI läsa den

Har du fakturan på papper behöver du ingen skanner. Öppna NONOX i mobilen, fota fakturan och registrera den direkt. Har företaget modulen NONOX AI påslagen läser AI:n dessutom fakturan och fyller i formuläret, så att du bara behöver kontrollera.

## Så här fotar du fakturan

1. Öppna **Inköp → Leverantörsfakturor** i mobilen och tryck på **Ny leverantörsfaktura**.
2. Tryck på **Fota fakturan**. Mobilens kamera öppnas.
3. Fota fakturan rakt ovanifrån, med hela sidan i bild och bra ljus.
4. Bilden laddas upp och visas överst i formuläret.

Fotot blir fakturans fil på samma sätt som en PDF: det sparas och blir underlag till verifikationen när fakturan bokförs. Det fungerar lika bra utan NONOX AI – då fyller du i fälten själv.

> [!TIP]
> Du kan installera NONOX som app på hemskärmen i mobilen direkt från webbläsaren. Då är kameran ett tryck bort.

## Så här läser NONOX AI fakturan

Med NONOX AI påslaget visas rutan **NONOX AI** under fakturan.

1. Ladda upp eller fota fakturan. AI:n börjar läsa så fort filen är uppladdad – det brukar ta högst 20 sekunder.
2. Vill du läsa om fakturan, klicka på **Analysera**.
3. När det står **Förslaget är ifyllt. Kontrollera varje uppgift mot underlaget innan du sparar.** är leverantör, fakturanummer, OCR, datum, belopp, moms och kontering ifyllda.
4. Gå igenom varje fält mot fakturan och ändra det som inte stämmer.
5. Spara eller registrera fakturan som vanligt.

Rutan visar också hur säker AI:n är, till exempel **Säkerhet: 92 %**.

> [!IMPORTANT]
> AI:n sparar aldrig något själv. Det är du som kontrollerar och registrerar, och det är ditt namn som står på registreringen.

## När leverantören inte finns i registret

Känner NONOX inte igen leverantören visas en ruta med det AI:n läste: namn, organisationsnummer, bankgiro, plusgiro och ort. Kryssa i **Skapa leverantören med de här uppgifterna och koppla fakturan till den när du sparar**, så läggs leverantören till i registret när du sparar.

NONOX letar först efter en leverantör som redan finns, på organisationsnummer, momsnummer, bankgiro, plusgiro, IBAN, e-postdomän och namn. Finns en liknande leverantör föreslås den, så att samma leverantör inte läggs in två gånger.

## Vad som krävs

- Modulen **NONOX AI** ska vara påslagen för företaget, med företagets egen nyckel till en AI-leverantör. Läs [NONOX AI](/manual/installningar/nonox-ai).
- Du behöver rätt att använda AI:n i företaget.

Syns inte rutan **NONOX AI** är modulen avstängd eller saknar du behörigheten. Knappen **Fota fakturan** finns ändå.

## Vanliga frågor

**Skickas fakturan någonstans?**
Ja, när AI:n läser den skickas filen till den AI-leverantör företaget har valt, med företagets egen nyckel. Utan NONOX AI stannar fakturan i NONOX.

**AI:n valde ett konto jag inte använder.**
AI:n föreslår bara konton som finns i er kontoplan, men den kan välja fel. Ändra kontot. Har leverantören ett förvalt konto i registret är det ofta enklast att utgå från det.

**Kan AI:n läsa ett kvitto som redan är betalt?**
Ja, men ett betalt kvitto bokförs som en verifikation, inte som en leverantörsfaktura. Läser du ett underlag på verifikationssidan och AI:n ser att det är en obetald faktura, föreslår den **Registrera som leverantörsfaktura** i stället.

## Läs också

- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [Leverantörsregistret](/manual/leverantorer/leverantorsregistret)
- [Inkorgen](/manual/leverantorer/inkorgen)
