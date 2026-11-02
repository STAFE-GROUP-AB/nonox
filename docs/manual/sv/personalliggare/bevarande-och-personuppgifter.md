---
beskrivning: Hur länge personalliggaren sparas, vilka personuppgifter NONOX och kiosken lagrar, och vad som händer när du stänger av modulen.
ordning: 100
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
sokord: [bevarandetid, lagringstid, gallring, GDPR, personuppgifter, samtycke, foto, stänga av personalliggare, arkiv]
---

# Bevarande och personuppgifter

Personalliggaren innehåller personnummer, samordningsnummer eller utländska ID-nummer för alla som arbetar hos er, även inhyrd personal. Här är vad NONOX sparar, var och hur länge.

## Hur länge liggaren sparas

En post i liggaren sparas i två år efter utgången av det kalenderår då företagets räkenskapsår slutade. Det är räkenskapsåret som posten hör till som räknas, inte kalenderåret då posten skrevs. Sedan tas de äldsta posterna bort automatiskt, lokal för lokal, och det skrivs i behandlingshistoriken. Kontrollen av liggaren fungerar ändå för de poster som finns kvar.

| Räkenskapsår | Post från | Sparas till och med |
|---|---|---|
| Kalenderår (januari–december) | 15 juni 2025 | 31 december 2027 |
| Brutet, maj–april (2025-05-01–2026-04-30) | 15 juni 2025 | 31 december 2028 |
| Förlängt första år (2025-09-01–2026-12-31) | 1 oktober 2025 | 31 december 2028 |

Med brutet eller förlängt räkenskapsår kan liggaren alltså sparas i mer än tre år. NONOX använder räkenskapsåren under **Bokföring → Räkenskapsår**. Finns inget räkenskapsår för en post räknar NONOX med företagets första månad i räkenskapsåret. Går det inte att avgöra vilket räkenskapsår posten hör till sparas den.

> [!IMPORTANT]
> Bevarandetiden följer 9 kap. 5 § skatteförfarandeförordningen. Bedömningen bygger på en AI-utredning 2026-11-02 och är inte granskad av jurist. Kontrollera med din redovisningskonsult eller Skatteverket vad som gäller för er.

Personer som du har arkiverat tas bort när de inte längre finns med i någon kvarvarande post, tillsammans med sitt foto.

## Vad NONOX sparar

| Uppgift | Varför | Hur länge |
|---|---|---|
| In- och utcheckningar med namn, person-, samordnings- eller utländskt ID-nummer och arbetsgivare | Liggaren och kontrollbesök | Två år efter utgången av det kalenderår då räkenskapsåret slutade, se ovan |
| Personen: namn, person-, samordnings- eller utländskt ID-nummer med land, e-post, kontrollvärde för koden och QR-nyckel | För att kunna checka in | Så länge personen finns i registret |
| Foto | Bara om personen har samtyckt | Tills samtycket återkallas eller personen tas bort |
| Avståndet till lokalen vid mobil incheckning | Platskontrollen | Som posten. Positionen sparas aldrig |

Koden sparas aldrig, bara ett kontrollvärde. Ingen kan läsa ut en persons kod.

## Vad kiosken sparar

För att fungera utan internet har kiosken en kopia av personalen: namn, foto om personen har samtyckt och kontrollvärden för koderna. Personnummer och de senaste 31 dagarnas pass sparas krypterade och kan bara öppnas med en chefs kod. Kopian ersätts varje gång kiosken hämtar personalen på nytt. Spärra en kiosk som kommer bort, se [Kiosken på surfplattan](/manual/personalliggare/kiosken).

## Vem ser personnumren

Personnummer visas med de fyra sista siffrorna dolda. I sin helhet ser du dem bara om du har behörighet och väljer **Visa**, eller på kontrollbesöket. Båda sparas i behandlingshistoriken, och den som har behörigheten loggar in med passkey.

## Om du stänger av modulen

Du stänger av personalliggaren under **Inställningar för företaget → Moduler**. NONOX varnar först och ber dig skriva modulens namn för att bekräfta. Då händer det här:

- Kioskerna slutar ta emot incheckningar.
- Liggaren finns kvar. I menyn heter den **Personalliggare (arkiv)**, och den kan visas och exporteras vid kontrollbesök under hela bevarandetiden.
- Ingenting tas bort.

> [!WARNING]
> Ett företag i en bransch med krav på personalliggare måste föra liggaren även om modulen stängs av i NONOX.

## Läs också

- [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser)
- [Kontrollbesök](/manual/personalliggare/kontrollbesok)
- [Moduler](/manual/installningar/moduler)
- [Dina uppgifter och vem som ser dem](/manual/personal/dina-uppgifter) – det din personal läser
