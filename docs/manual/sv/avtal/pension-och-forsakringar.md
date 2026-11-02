---
beskrivning: Avtalspension SAF-LO och ITP 1 och avtalsförsäkringarna (TFA, TGL, omställning) räknas och bokförs med lönen, och underlaget till Fora och Collectum tas fram per månad.
ordning: 54
roller: [owner, admin, accountant, payroll_admin]
modul: agreements
sokord: [avtalspension, SAF-LO, ITP 1, ITP, avtalsförsäkringar, Fora, Collectum, TFA, TGL, TSL, TRR, omställningsförsäkring, 7570, 2740, administrationsavgift hängavtal]
---

# Pension och avtalsförsäkringar

Ett kollektivavtal kräver att arbetsgivaren betalar tjänstepension och avtalsförsäkringar för de anställda. När en anställning är knuten till ett avtal i NONOX räknar lönekörningen premierna och bokför dem med lönen.

## Det här räknar NONOX

- **Tjänstepension enligt avtalet.** Arbetare: Avtalspension SAF-LO, från månaden den anställda fyller 22 år till och med månaden före 65. Tjänstemän: ITP 1 (ålderspension), från 25 år, med 30 % på lön över 7,5 inkomstbasbelopp upp till 30 inkomstbasbelopp. Har anställningen en egen pensionsplan under **Lön → Anställda** används den i stället.
- **Avtalsförsäkringar.** Arbetare: TFA på hela lönen, och TGL och omställningsstöd (TSL) på lön upp till 7,5 prisbasbelopp per månad. Tjänstemän: TFA och omställning (TRR). Är företaget bundet genom hängavtal används hängavtalets högre premie för omställning.
- **Administrationsavgift enligt hängavtal** när avtalet har en sådan, till exempel 0,51 % i Gröna Riks hängavtal.

Premierna är arbetsgivarens kostnad. De står på lönebeskedet för information men påverkar inte bruttolön, skatt eller nettolön.

## Var procentsatserna kommer ifrån

Premierna ändras varje 1 januari och delas av många avtal. De ligger därför som lönens regler, till exempel **pension.saf_lo** och **insurance.fora.arbetare**, under **Lön → Regler**. Där jämför du värdet med källan och markerar det som kontrollerat, precis som för skattesatser och arbetsgivaravgifter. Saknas en kontrollerad regel för utbetalningsdagen stoppas lönekörningen med ett meddelande om vilken regel det gäller.

## Det här händer i bokföringen

| Rad | Debet | Kredit |
|---|---|---|
| Tjänstepension | 7410 Pensionsförsäkringspremier | 2740 Avtalade sociala avgifter |
| Särskild löneskatt på pension | 7530 Särskild löneskatt | 2514 Beräknad särskild löneskatt |
| Avtalsförsäkringar | 7570 Premier för arbetsmarknadsförsäkringar | 2740 Avtalade sociala avgifter |

Kontona går att ändra per löneart. När fakturan från Fora eller Collectum betalas bokförs den mot 2740.

## Underlag till Fora och Collectum

Under **Lön → Avtal → Fora och Collectum** väljer du utbetalningsmånad. Sidan visar kontant bruttolön, tjänstepension och avtalsförsäkringar per anställd för lönekörningar som är attesterade.

1. Välj **Utbetalningsmånad**.
2. Klicka på **Ladda ner underlag (CSV)** under Fora eller Collectum.
3. Rapportera lönerna i Foras eller Collectums egna tjänster med hjälp av underlaget.

> [!IMPORTANT]
> NONOX skickar ingenting till Fora eller Collectum. Underlaget är inte Foras eller Collectums egna filformat. Filen innehåller personnummer, och varje nedladdning sparas i behandlingshistoriken. Bara den som får hantera avtal kan ladda ner den.

## Det här räknas inte ännu

- ITP 2 (förmånsbestämd), sjukpension och premiebefrielse i ITP 1, TGL för tjänstemän och Collectums administrationsavgift. De faktureras av Collectum eller Alecta.
- Frisörbranschens extra pension och den förstärkta pensionsavsättningen i handelns avtal.
- Kammarkollegiets återbetalning av omställningspremien via Fora.

## Läs också

- [Arbetsgivaravgifter och tjänstepension](/manual/lon/arbetsgivaravgifter-och-pension)
- [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)
