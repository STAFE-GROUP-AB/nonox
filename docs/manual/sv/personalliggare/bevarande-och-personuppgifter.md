---
beskrivning: Hur länge personalliggaren sparas, vilka personuppgifter NONOX och kiosken lagrar, och vad som händer när du stänger av modulen.
ordning: 100
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
sokord: [bevarandetid, lagringstid, gallring, GDPR, personuppgifter, samtycke, foto, stänga av personalliggare, arkiv]
---

# Bevarande och personuppgifter

Personalliggaren innehåller personnummer för alla som arbetar hos er, även inhyrd personal. Här är vad NONOX sparar, var och hur länge.

## Hur länge liggaren sparas

NONOX sparar liggaren i två år efter utgången av det kalenderår då den fördes. Sedan tas de äldsta posterna bort automatiskt, lokal för lokal, och det skrivs i behandlingshistoriken. Kontrollen av liggaren fungerar ändå för de poster som finns kvar.

> [!IMPORTANT]
> Bevarandetiden bygger på 39 kap. skatteförfarandelagen och är inte slutligt kontrollerad i NONOX. Kontrollera med din redovisningskonsult eller Skatteverket vad som gäller för er.

Personer som du har arkiverat tas bort när de inte längre finns med i någon kvarvarande post, tillsammans med sitt foto.

## Vad NONOX sparar

| Uppgift | Varför | Hur länge |
|---|---|---|
| In- och utcheckningar med namn, personnummer och arbetsgivare | Liggaren och kontrollbesök | Två år efter kalenderårets slut, se ovan |
| Personen: namn, personnummer, e-post, kontrollvärde för koden och QR-nyckel | För att kunna checka in | Så länge personen finns i registret |
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
