---
beskrivning: Gör ett fakturautkast av arbetsorderns tid och material, med ROT eller RUT bara på arbetet, arbetade timmar, a conto-planer och specificerat underlag.
ordning: 163
roller: [owner, admin, accountant, bookkeeper]
modul: work_orders
rutter: [workOrders.show, workOrders.specification]
sokord: [fakturaunderlag, fakturera arbetsorder, a conto, delbetalning, betalningsplan, slutfaktura, specificerat underlag, arbetade timmar, typ av arbete]
---

# Fakturaunderlag från arbetsorder

![En färdig arbetsorder med omvänd byggmoms vars fakturaunderlag är ett fakturautkast.](/bilder/manual/fakturering/fakturaunderlag-fran-arbetsorder.webp)

## Skapa fakturaunderlag

1. Öppna arbetsordern.
2. Välj **Till och med** under **Fakturaunderlag**.
3. Klicka på **Skapa fakturaunderlag** och bekräfta.

Du hamnar i ett fakturautkast. Granska det och skapa fakturan som vanligt. Ingenting bokförs innan dess.

- Raderna kommer i ordning: huvudarbetet först, sedan varje godkänt ÄTA med sitt nummer i radtexten.
- Tid blir en rad per typ av arbete och pris, material en rad per artikel och pris.
- Vid fast pris tas det som återstår av det fasta priset. Registrerade timmar hör till det fasta priset och faktureras inte en gång till.
- Tid och material på ÄTA som inte är godkända hålls tillbaka.
- Posterna reserveras för utkastet. Tas utkastet bort blir de lediga igen.

## ROT och RUT

Bara typer av arbete som är **Arbete** blir arbetskostnad. Resor, maskiner och material ger aldrig avdrag. Fakturan får **Arbetade timmar** och **Typ av arbete**, som Skatteverket frågar efter när du begär utbetalningen. Varje faktura från arbetsordern tar de timmar som är registrerade och inte redan står på en tidigare faktura från den, också en a conto-faktura. Har arbetsordern inga registrerade timmar alls säger NONOX nej: Skatteverket kräver antalet timmar även vid fast pris.

## A conto och betalningsplan

Vid fast pris kan du klicka på **Lägg till betalningsplan** och skriva delbetalningar med **Belopp exklusive moms** och ett datum eller delmoment. Med en konsument som kund bockar du i att planen är avtalad. **Fakturera** gör ett fakturautkast för delbetalningen med eget ROT- eller RUT-avdrag på sin andel av arbetet. Slutfakturan tar sedan resten av det fasta priset. A conto-planer finns bara med fakturametoden.

![Betalningsplanen för en altan till fast pris, med den första delen fakturerad.](/bilder/manual/fakturering/betalningsplan.webp)

Betalas en a conto-faktura med avdrag ett år, och arbetet inte är klart den 31 januari året efter, varnar arbetsordern.

## Specificerat underlag

En konsument kan begära en specificerad faktura. **Specificerat underlag (PDF)** visar tid per dag och typ av arbete med pris, material per rad och varje ÄTA.
