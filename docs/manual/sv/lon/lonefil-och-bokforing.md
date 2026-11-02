---
beskrivning: Hämta lönefilen till banken, markera lönen som utbetald, bokför den i serie L och bokför skatt och avgifter mot skattekontot.
ordning: 50
roller: [owner, accountant, payroll_admin]
modul: payroll
rutter: [payroll.runs.payment-file]
sokord: [lönefil, betalfil lön, pain.001, utbetalning lön, bokföra lön, lönverifikation, skattekonto, personalskatt]
---

# Lönefil till banken och bokföring av lönen

När lönekörningen är attesterad återstår tre saker: betala ut nettolönerna, bokföra lönen och, när Skatteverket har dragit skatten och avgifterna, bokföra den dragningen. Allt görs från lönekörningens sida under **Lön → Lönekörningar**.

![Lönekörningen med verifikationen i serie L och bokföringen mot skattekontot. Beloppen är demodata.](/bilder/manual/lon/lonekorning.webp)

## Innan du börjar: bankuppgifterna

Lönefilen använder samma bankuppgifter som betalfilerna för leverantörsfakturor. Har du inte gjort det än: välj bank och fyll i IBAN och avtals-id under **Leverantörer → Betalningar**. Se [Betalfiler](/manual/leverantorer/betalfiler).

## Så här betalar du ut lönen

1. Öppna den attesterade lönekörningen.
2. Klicka på **Hämta lönefil till banken**.
3. Logga in i internetbanken och läs in filen som en betalfil. Kontrollera antal och summa och godkänn betalningen i banken.
4. När banken har betalat: klicka på **Markera som utbetald** i NONOX.

Lönefilen är en ISO 20022-fil (pain.001) med en betalning per anställd till personens clearing- och kontonummer, märkt som lön. Den skapas bara för en attesterad lönekörning.

Filen kan inte skapas om:

- en anställd saknar bankkonto. Fyll i **Clearingnummer** och **Kontonummer för lön** under **Lön → Anställda**.
- en anställds bankkonto är ändrat men inte bekräftat av en annan person. Se [Anställda](/manual/lon/anstallda).
- ingen i lönekörningen har något att få utbetalt.

> [!NOTE]
> NONOX markerar inte lönen som utbetald av sig själv. Det gör du när du ser att banken har betalat. Samtidigt markeras utlägg som betalades med lönen som utbetalda.

Vill du betala lönerna på annat sätt, till exempel en och en i internetbanken, kan du hoppa över lönefilen och bara klicka på **Markera som utbetald**.

## Så här bokför du lönen

1. Öppna lönekörningen med status **Utbetald**.
2. Klicka på **Bokför lönen**.

Lönen bokförs som en [verifikation](/manual/ordlista/ordlista#verifikation) i serie **L** med utbetalningsdagen som datum. En länk till verifikationen visas sedan på lönekörningen. Det är ägaren, redovisningskonsulten och löneadministratören som kan bokföra lönen.

Saknar kontoplanen något av kontona visar sidan vilka och knappen **Lägg till kontona**.

## Det här händer i bokföringen

Lönens verifikation i serie L innehåller:

| Vad | Konto | Debet / kredit |
|---|---|---|
| Varje lönerad på sin lönearts konto, till exempel löner 7010, sjuklön 7081, semesterlön 7082 | 70xx | Debet |
| Skattefria traktamenten och milersättning | 7321, 7331 | Debet |
| Arbetsgivaravgifter | 7510 | Debet |
| Avdragen preliminärskatt (personalskatt) | 2710 | Kredit |
| Arbetsgivaravgifter att betala | 2730 | Kredit |
| Tjänstepension och särskild löneskatt, om anställningen har en pensionsplan | 7410 mot 2740, 7530 mot 2514 | Debet / kredit |
| Utlägg som betalas tillbaka | 2890 | Debet |
| Utbetalda nettolöner | 1930 | Kredit |

Verifikationen balanserar alltid: bruttolön minus skatt, plus och minus poster efter skatt, är lika med nettolönen. Är **Kostnadsställe och projekt från anställningen** på i löneinställningarna bokförs lönekostnaden på anställningens kostnadsställe.

Bokför företaget semesterlöneskulden **Varje månad** får samma verifikation också rader som för skulden till rätt nivå vid periodens slut: 7090 mot 2920 för semesterlönerna och 7519 mot 2940 för de sociala avgifterna på skulden. Se [Semester](/manual/lon/semester).

Lönearternas konton följer BAS för kollektivanställda (70xx), även för tjänstemän. Kontona går inte att ändra i appen ännu. Vill ni redovisa tjänstemännens löner på 72xx kan er redovisningskonsult göra en omföring.

## Så här bokför du skatt och avgifter mot skattekontot

Skatteverket drar den avdragna skatten och arbetsgivaravgifterna från företagets skattekonto månaden efter utbetalningen. När lönen är bokförd visas en ruta för det på lönekörningen.

1. Välj **Dag**, den dag dragningen görs. NONOX föreslår den 12:e månaden efter utbetalningen; ändra om ert datum är ett annat.
2. Klicka på **Bokför mot skattekontot**.

Det ger en verifikation i serie L där 2710 och 2730 debiteras och skattekontot 1630 krediteras. Det görs en gång per lönekörning. Särskild löneskatt på pension betalas inte via arbetsgivardeklarationen utan med företagets egen preliminärskatt, och ingår därför inte här.

> [!TIP]
> Kontrollera mot skattekontots kontoutdrag hos Skatteverket att beloppet stämmer med summan av personalskatt och arbetsgivaravgifter på arbetsgivardeklarationen.

## Vanliga frågor

**Kan jag ändra lönen efter att den är bokförd?**
Nej. Den bokförda verifikationen ändras aldrig. Fel rättas i nästa lönekörning, som bokförs med sin egen verifikation.

**Banken vägrar filen. Vad gör jag?**
Kontrollera bankuppgifterna under **Leverantörer → Betalningar** och att avtalet med banken omfattar betalfiler. Varje bank har sina egna villkor för filöverföring.

**Måste lönen vara bokförd innan jag skapar arbetsgivardeklarationen?**
Nej, arbetsgivardeklarationen kan skapas så fort lönekörningen är attesterad. Men lönemodulen kan inte stängas av, och löneåret kan inte avslutas, så länge en attesterad lönekörning inte är bokförd.

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration)
- [Betalfiler](/manual/leverantorer/betalfiler)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
