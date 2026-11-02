---
beskrivning: Granska och bokför fakturor, kvitton och dagsrapporter som mejlats till företagets egen adress – med varningar, karantän och mallar som lär sig.
ordning: 110
roller: [owner, admin, accountant, bookkeeper]
modul: inbox
rutter: [inbox.index, inbox.original, inbox.attachment]
sokord: [inkorg, e-post, mejla faktura, mejla kvitto, karantän, peppol, e-faktura, underlag via mejl, granska, avvisa]
---

# Inkorgen

Med inkorgen får företaget en egen e-postadress. Leverantörer skickar sina fakturor dit, du vidarebefordrar kvitton, och kassasystemet mejlar dagsrapporten. Allt landar under **Inkorg** i menyn, där NONOX har gjort ett förslag till bokföring som du granskar och godkänner. Originalmejlet sparas som underlag.

Inkorgen är en modul som en ägare eller administratör slår på under **Företagsinställningar → Moduler**. Hur du sätter upp adressen och listan med godkända avsändare beskrivs i [Inkorgens adress och godkända avsändare](/manual/leverantorer/inkorgens-installningar).

![Inkorgen med företagets egen adress och ett utkast som är klart att godkänna.](/bilder/manual/leverantorer/inkorgen.webp)

## Flikarna

| Flik | Vad som finns där |
|---|---|
| **Att granska** | Det som behöver dig: underlag i karantän och underlag NONOX inte kunde tolka färdigt. |
| **Utkast klara** | Förslag som är klara att godkänna. |
| **Bokfört** | Det som har bokförts eller sparats som utkast. |
| **Avvisat** | Det du har avvisat. |

Antalet som väntar visas vid **Inkorg** i menyn. Sök på avsändare eller ämne i fältet **Sök**.

## Så här granskar och bokför du ett underlag

1. Gå till **Inkorg** och klicka på ett underlag i **Utkast klara** eller **Att granska**.
2. Bilagan visas bredvid förslaget. Kontrollera varje uppgift mot den.
3. Finns det varningar, läs dem och kryssa i **Jag har kontrollerat varningarna**.
4. Klicka på **Godkänn och bokför** och bekräfta med **Ja, bokför**.

Vill du bara spara förslaget som ett utkast i bokföringen, klicka på **Spara som utkast** i stället. Det bokförs då inte förrän någon bokför utkastet.

Det som bokförs går genom NONOX vanliga funktioner, i ditt namn:

- En **leverantörsfaktura** registreras och får ett löpnummer. Den ska sedan [attesteras](/manual/leverantorer/attest) och betalas som vanligt.
- En **verifikation** – ett kvitto, en dagsrapport eller en SIE-fil – bokförs och får ett nummer.

Originalmejlet och alla bilagor blir underlag till varje verifikation det leder till. Efteråt finns länken **Öppna verifikationen** eller **Öppna leverantörsfakturan** på underlaget.

## När NONOX inte kunde tolka underlaget

Står det **Ingen tolkning finns. Hur ska underlaget bokföras?** väljer du själv: **Som verifikation**, **Som leverantörsfaktura** eller **Som dagsrapport**. Fyll i formuläret bredvid bilagan och godkänn.

Klicka på **Läs igen** om du vill att NONOX försöker tolka underlaget på nytt.

## Så tolkar NONOX underlagen

NONOX försöker i den här ordningen:

1. **SIE-filer** blir förslag till verifikationer.
2. **Peppol-fakturor** (e-fakturor i formatet BIS Billing 3.0) blir förslag till leverantörsfakturor.
3. **Dagsrapporter från kassasystem** läses med en mall som sparats för avsändaren. Läs [Dagsrapporter från kassasystemet](/manual/leverantorer/dagsrapporter).
4. **NONOX AI** läser resten, om företaget har slagit på det.
5. Annars hamnar underlaget i **Att granska**, där du fyller i själv.

Ur en PDF kan NONOX utan AI bara läsa texten. En inskannad PDF utan textlager kräver NONOX AI eller en person.

När du godkänner ett förslag sparas hur det tolkades som en mall för avsändaren. Nästa underlag i samma format tolkas med mallen, utan AI.

## Karantän

Ett underlag hamnar i karantän och tolkas inte när:

- avsändaren inte finns på listan över godkända avsändare, eller
- mejlet inte klarar kontrollen av avsändaren (SPF, DKIM och DMARC) eller är märkt som skräppost – då hamnar det i karantän även från en godkänd avsändare.

Rutan säger **I karantän. Inget tolkas förrän du har tittat på det.** och varför. Du kan:

- **Godkänn avsändaren** – avsändaren läggs till på listan och dess väntande post släpps igenom.
- **Släpp igenom efter granskning** – för just det här mejlet. Ett mejl som underkändes i kontrollen av avsändaren bokförs då aldrig automatiskt och går inte att svara på.
- **Avvisa (x)** – med ett frivilligt skäl.

## Varningar

NONOX varnar när något ser misstänkt ut:

- bankgiro, plusgiro eller IBAN på fakturan skiljer sig från leverantörsregistret,
- avsändarens domän liknar en känd leverantörs,
- samma bilaga har kommit förut,
- en faktura med samma nummer, belopp och leverantör finns redan,
- mejlet nämner en bilaga som inte följde med.

> [!WARNING]
> Ett ändrat bankgiro på en faktura är ett vanligt bedrägeriförsök. Ring leverantören på ett nummer du redan har – inte ett från mejlet – innan du godkänner.

## Bilagor och säkerhet

Bilagor som kan innehålla program, skript eller makron spärras och visas med **spärrad:** och skälet. De sparas men lämnas aldrig ut. PDF och bilder visas i en skyddad ruta; allt annat laddas ned. Med **Ladda ner originalet (.eml)** får du hela mejlet som det kom.

## Svara avsändaren

Saknas något kan du svara direkt från underlaget under **Svara avsändaren**, till exempel "Kvittot saknas, kan du skicka det?", och klicka på **Skicka svar**.

## Post till den gemensamma adressen

Är du med i flera företag och vidarebefordrar till NONOX gemensamma adress kan NONOX inte veta vilket företag mejlet gäller. Det visas då under **Väntar på att ett företag hämtar dem**. Klicka på **Hämta till det här företaget** i rätt företag.

## Kortkommandon

Tryck på <kbd>?</kbd> i inkorgen för att se dem.

| Tangent | Gör |
|---|---|
| <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd> | Godkänn och bokför (tryck igen för att bekräfta) |
| <kbd>j</kbd> / <kbd>k</kbd> | Nästa / föregående underlag |
| <kbd>g</kbd> | Godkänn avsändaren |
| <kbd>x</kbd> | Avvisa (tryck igen för att bekräfta) |
| <kbd>Esc</kbd> | Stäng |

## Hur länge sparas posten?

- Underlag som lett till bokföring sparas lika länge som bokföringen och kan inte raderas.
- Avvisade underlag tas bort efter 7 dagar.
- Underlag i karantän som ingen har hanterat, och post till den gemensamma adressen som inget företag hämtat, tas bort efter 90 dagar.
- Skräppost från avsändare som inte är godkända sparas aldrig.

Ett underlag som inte är bokfört kan du radera själv med **Radera**. Det går inte att ångra.

Tar du bort ett utkast som kom från inkorgen finns mejlet kvar. Har du tagit bort alla utkast från ett mejl och inget är bokfört, hamnar mejlet under **Att granska** igen. Då kan du godkänna det på nytt eller radera det.

## Läs också

- [Inkorgens adress och godkända avsändare](/manual/leverantorer/inkorgens-installningar)
- [Dagsrapporter från kassasystemet](/manual/leverantorer/dagsrapporter)
- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [NONOX AI](/manual/installningar/nonox-ai)
