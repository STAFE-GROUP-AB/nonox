---
beskrivning: Skicka kundfakturor som e-faktura via Peppol, till exempel till kommuner, regioner och myndigheter. Så lägger du in kundens Peppol-ID och följer leveransen.
ordning: 45
roller: [owner, admin, accountant, bookkeeper]
modul: peppol
sokord: [peppol, e-faktura, elektronisk faktura, Svefaktura, offentlig sektor, kommun, myndighet, region, Peppol-ID, EN 16931, BIS Billing]
---

# Skicka e-faktura via Peppol

Peppol är ett nätverk för e-fakturor. Fakturan går som en fil som kundens ekonomisystem läser in direkt, i stället för en PDF som någon måste skriva av. Kommuner, regioner och myndigheter ska ta emot e-fakturor, och många större företag vill ha dem.

NONOX skickar fakturan enligt Peppol BIS Billing 3.0, med PDF:en bifogad, genom den Peppol-operatör som den som driftar NONOX har avtal med. I den driftade tjänsten på nonox.app är modulen inte påslagen än. Fakturan bokförs precis som när du mejlar den: den bokfördes redan när du skapade den, och att skicka den ändrar ingenting i bokföringen.

> [!NOTE]
> Peppol e-faktura är en modul. Den kan bara slås på när den som driftar NONOX har ett avtal med en Peppol-operatör. Saknas det står det i modullistan under **Inställningar för företaget → Moduler**.

## Så här kommer du igång

1. Slå på **Peppol e-faktura** under **Inställningar för företaget → Moduler**.
2. Kontrollera **Företagets Peppol-ID** i modulens inställningar. Det är organisationsnumret med 0007 framför, till exempel 0007:5561234567. En enskild firma kan använda ett GLN (0088) i stället för personnumret.
3. Tryck på **Spara**.

Innan första fakturan kan gå iväg kopplar den som driftar NONOX företaget till ett konto hos Peppol-operatören. Tills det är gjort står det *Inte kopplat än* under **Konto hos Peppol-operatören**.

## Så här lägger du in kundens Peppol-ID

1. Öppna kunden under **Register → Kunder**.
2. Välj **Fakturera via Peppol (e-faktura)** under **Skicka fakturor**.
3. Skriv **Kundens Peppol-ID**, eller lämna fältet tomt för en svensk kund, så används kundens organisationsnummer.
4. Tryck på **Spara**.

Kommuner och myndigheter har ofta ett eget Peppol-ID och vill ha en referenskod på fakturan. Fråga kunden och skriv koden i **Er referens** på fakturan.

## Så här skickar du fakturan

1. Skapa fakturan som vanligt och öppna den.
2. Under **Skicka som e-faktura (Peppol)** trycker du på **Kontrollera mottagaren**. NONOX frågar Peppol-nätverket om kunden tar emot fakturor.
3. Tryck på **Skicka via Peppol**.

Du ser *Faktura 1029 är på väg till kunden via Peppol.* Statusen står i samma ruta:

| Status | Betyder |
|---|---|
| **I kö** | Fakturan väntar på att lämnas till Peppol-operatören. |
| **Skickad till Peppol** | Operatören har tagit emot fakturan och skickar den vidare. |
| **Levererad** | Kundens Peppol-operatör har bekräftat att den tog emot fakturan. |
| **Misslyckades** | Fakturan kom inte fram. Felet står under statusen. Rätta det och tryck på **Skicka igen via Peppol**. |

En kreditfaktura skickas på samma sätt och blir en kreditnota som hänvisar till fakturan den krediterar.

Avtalsfakturor till en kund som är inställd på Peppol skickas via Peppol av sig själva när avtalet skickar fakturorna automatiskt.

## Det här skickas inte via Peppol

- Utkast. Fakturan måste vara skapad.
- Fakturor med ROT- eller RUT-avdrag och fakturor till privatpersoner i andra EU-länder (OSS). De går till privatpersoner, som inte finns i Peppol.
- Fakturor från ett testföretag.
- En faktura med moms om företaget saknar momsregistreringsnummer.

## Bra att veta

- NONOX kontrollerar varje e-faktura mot reglerna i Peppol BIS Billing 3.0 innan den skickas. En faktura som inte följer reglerna skickas inte, och du ser vad som är fel.
- E-fakturan sparas precis som den skickades, som en del av räkenskapsinformationen.
- Moms, omvänd betalningsskyldighet, försäljning till andra EU-länder, export, öresavrundning och utländsk valuta står i e-fakturan på samma sätt som på PDF:en.
- Bankgiro, plusgiro och IBAN hämtas från **Inställningar för företaget**, och OCR-numret följer med som betalningsreferens.

## Vanliga frågor

**Kunden säger att den inte finns i Peppol.**
Tryck på **Kontrollera mottagaren**. Står det att kunden inte finns i Peppol har kunden ett annat Peppol-ID, eller tar inte emot e-fakturor. Fråga kunden.

**Måste vi registrera oss för att skicka?**
Nej. Registreringen behövs bara för att ta emot e-fakturor. Läs [Ta emot e-fakturor via Peppol](/manual/leverantorer/ta-emot-e-faktura).

## Läs också

- [Skicka fakturan och öppna PDF:en](/manual/fakturering/skicka-en-faktura)
- [Kunder](/manual/fakturering/kunder)
- [Kreditera en faktura](/manual/fakturering/kreditfaktura)
