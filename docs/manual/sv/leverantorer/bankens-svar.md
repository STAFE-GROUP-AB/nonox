---
beskrivning: Läs in bankens svar på en betalfil (pain.002, camt.054 eller camt.053). NONOX bokför det banken har betalat och visar det banken har avvisat, med orsaken.
ordning: 65
roller: [owner, admin, accountant, bookkeeper, approver, auditor]
sokord: [bankens svar, statusrapport, pain.002, camt.054, camt.053, debiteringsavisering, avvisad betalning, återredovisning, avstämning av betalfiler, betalfil avvisad]
---

# Bankens svar på betalfilen

När du har skickat en betalfil till banken svarar banken med filer. NONOX läser dem och gör resten:

- Det banken har **betalat** bokförs.
- Det banken har **avvisat** visas med bankens orsak, och fakturan kan betalas igen.

Du slipper alltså kontrollera kontoutdraget och klicka på **Banken har betalat** själv.

## Vilka filer banken skickar

Hämta filerna i internetbanken, där du hämtar andra filer i ISO 20022- eller XML-format. Tjänsten kan behöva beställas hos banken.

| Fil | Vad den säger |
| --- | --- |
| **Statusrapport (pain.002)** | Om banken tog emot betalningarna eller avvisade dem, och varför. Kommer strax efter att du läst in betalfilen, och ibland igen på betaldagen. |
| **Debiteringsavisering (camt.054)** | Vilka betalningar som har dragits från kontot. |
| **Kontoutdrag (camt.053)** | Alla rörelser på kontot. NONOX tar bara de som hör till dina betalfiler. |

Du behöver inte läsa in alla. Statusrapporten och debiteringsaviseringen räcker.

## Så här läser du in bankens svar

1. Gå till **Inköp → Betalfiler**.
2. Under **Läs in bankens svar**: dra hit filen, eller klicka på **välj fil**.
3. Läs resultatet under **Svar i** och filens namn.

Varje svar får en status:

| Status | Betyder |
| --- | --- |
| **Matchad automatiskt** | NONOX hittade betalningen och har gjort det som behövs. |
| **Behöver bekräftas** | NONOX har ett förslag. Kontrollera och klicka på **Bekräfta**. |
| **Okänd** | NONOX kunde inte koppla svaret till en betalning, eller svaret behöver hanteras av en person. Texten under säger varför. |
| **Redan hanterad** | Samma betalning var redan bokförd eller avvisad. Inget nytt har gjorts. |

## Så hittar NONOX betalningen

Varje betalning i betalfilen har en egen referens, och banken skickar tillbaka den i sina svar. Då vet NONOX exakt vilken faktura svaret gäller, och bokför direkt.

Saknas referensen letar NONOX efter en betalning med samma belopp, en betaldag nära bankens datum och samma bankgiro eller plusgiro. Passar exakt en betalning blir det ett förslag som du bekräftar. NONOX bokför aldrig ett sådant förslag själv.

## När banken har avvisat en betalning

Betalningen markeras som **Avvisad av banken** och orsaken visas på svenska, till exempel *Mottagarens konto är fel*, med bankens egen kod och text bredvid. Under **Att göra** på översikten står att en betalning har avvisats.

Fakturan har aldrig bokförts som betald. Den finns kvar under **Att betala**:

1. Rätta det som var fel, till exempel leverantörens bankgiro. Läs [Leverantörsregistret](/manual/leverantorer/leverantorsregistret).
2. Lägg fakturan i en ny betalfil, eller betala den i internetbanken.

> [!NOTE]
> Betalningsuppgifterna tas från leverantören som den var när fakturan registrerades. Har bankgirot ändrats sedan dess, registrera fakturan på nytt eller betala den i internetbanken.

Avvisade banken hela filen, till exempel för att den hade fel format, blir alla betalningar avvisade och filen makuleras.

## När banken har betalat

Betalningen bokförs på bankens bokföringsdag i serie E, precis som när du registrerar en betalning för hand: 2440 Leverantörsskulder i debet och 1930 Företagskonto i kredit (enligt kontantmetoden kostnaden och momsen i stället för 2440). Fakturan blir betald.

När alla betalningar i filen är betalda eller avvisade får filen status **Betald**. Avvisade banken alla, blir filen **Makulerad**.

NONOX bokför inte automatiskt när:

- betalfilen inte är godkänd i NONOX – godkänn den, och bekräfta sedan svaret,
- banken drog ett annat belopp än det som stod i filen,
- fakturan har betalats på annat sätt sedan filen skapades.

Då står svaret som **Okänd** eller **Behöver bekräftas**, med en förklaring.

## Om pengarna kommer tillbaka

Ibland går en betalning iväg men kommer tillbaka, till exempel för att mottagarens konto är avslutat. Svaret visas som **Återförd av banken**. NONOX rättar inte bokföringen själv. Gör en rättelse av betalningens verifikation och betala fakturan på nytt. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

## Samma svar två gånger

Du kan inte läsa in samma fil två gånger. Kommer samma betalning i två olika filer, till exempel både i debiteringsaviseringen och i kontoutdraget, bokförs den bara en gång. Det andra svaret blir **Redan hanterad**.

## Med bankkopplingen

Har ni modulen Bank hämtar NONOX transaktionerna från banken. En dragning som passar en betalning i en betalfil visas då som ett förslag här, utan att du hämtar någon fil. Du bekräftar förslaget. Läs [Bankkoppling](/manual/bank/bankkoppling).

## Avfärda ett svar

Ett svar som du har hanterat på annat sätt kan du avfärda. Klicka på **Avfärda** och **Ja, avfärda**. Inget bokförs.

## Vem får göra vad

Ägare, administratör, redovisningskonsult och bokförare kan läsa in svar, bekräfta och avfärda. Attestant och revisor ser svaren. Allt sparas i behandlingshistoriken.

## Läs också

- [Betalfiler till banken](/manual/leverantorer/betalfiler)
- [Registrera en betalning](/manual/leverantorer/registrera-en-betalning)
- [Bankfiler](/manual/bank/bankfiler)
