---
beskrivning: Hur du bokför kortbetalningar som betalas ut med avgiften dragen, Swishbetalningar och bankens avgifter, så att kassan, banken och bokföringen stämmer.
ordning: 270
branscher: [cafe, restaurant, retail, hair_beauty]
rutter: [accounting.bankReconciliation, inbox.index]
sokord: [kortavgift, kortinlösen, inlösen, swish, swishavgift, bankavgift, utbetalning kort, avräkning, 6570, 1680, dagskassa, bankavstämning]
kallor:
  - titel: Skatteverket – Kassaregister
    url: https://www.skatteverket.se/foretag/drivaforetag/kassaregister.4.121b82f011a74172e5880005263.html
  - titel: Skatteverket – Så här använder du kassaregister
    url: https://www.skatteverket.se/foretag/drivaforetag/kassaregister/saharanvanderdukassaregistret.4.69ef368911e1304a625800013725.html
  - titel: Riksdagen – Mervärdesskattelag (2023:200)
    url: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/
  - titel: Bokföringsnämnden – Vägledning Årsredovisning i mindre företag (K2), konsoliderad 2025
    url: https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf
granskad: 2026-11-05
mansklig_granskning:
legal_verify:
  - Har avgiften från kortinlösaren eller banken moms? Det framgår av avräkningen eller fakturan. Om avgifter för betaltjänster är undantagna från moms är inte kontrollerat här.
  - Kontona i exemplen (1680 för kortfordran, 6570 för avgifter) är NONOX standardförslag och inte kontrollerade av en redovisningskonsult. BAS har 1580 för fordringar på kontokort, som inte finns i NONOX kontoplansmall.
---

# Kortavgifter och Swish

När en kund betalar med kort kommer pengarna inte direkt till banken. Kortinlösaren betalar ut dem en eller några dagar senare, ofta med avgiften redan dragen. Swish kommer in direkt, post för post, och banken tar betalt för tjänsten separat. Det gör att dagskassan och banken sällan visar samma belopp samma dag. Här ser du hur du bokför så att det går att stämma av.

## Det här säger reglerna

Enligt skatteförfarandelagen 39 kap. 4 § gäller kravet på kassaregister både kontant betalning och betalning med kort, och Skatteverket räknar Swish som kortbetalning. All sådan försäljning ska registreras i kassan, och kvittot ska visa hur kunden betalade. Läs [Kassaregister i praktiken](/foretagsguiden/branscher/kassaregister-i-praktiken).

Momsen beräknas på det kunden betalade, inte på det inlösaren betalar ut (mervärdesskattelagen 8 kap. 2 §). I årsredovisningen får intäkter och kostnader inte kvittas mot varandra (årsredovisningslagen 2 kap. 4 § första stycket 6, som Bokföringsnämnden beskriver i K2-vägledningen). Därför bokför du försäljningen med hela beloppet och avgiften som en egen kostnad redan från början.

## Ett exempel

Kafé Linden säljer för 1 000 kronor med kort på måndagen, varav 200 kronor är moms på 25 procent. På onsdagen betalar inlösaren ut 985 kronor och har dragit 15 kronor i avgift. Alla belopp är påhittade.

**Måndag, dagsrapporten:**

| Konto | Debet | Kredit |
|---|---:|---:|
| 1680 Andra kortfristiga fordringar (kortbetalningar på väg) | 1 000 | |
| 3001 Försäljning inom Sverige, 25 % moms | | 800 |
| 2611 Utgående moms på försäljning inom Sverige, 25 % | | 200 |

**Onsdag, utbetalningen:**

| Konto | Debet | Kredit |
|---|---:|---:|
| 1930 Företagskonto | 985 | |
| 6570 Bankkostnader | 15 | |
| 1680 Andra kortfristiga fordringar | | 1 000 |

När utbetalningen är bokförd är fordran på 1680 noll igen. Står det kvar ett belopp där har en utbetalning inte kommit, eller så stämmer inte dagsrapporten med inlösarens avräkning.

**Swish** kommer direkt till banken. Koppla Swishraden på dagsrapporten till 1930, eller till ett eget bankkonto om Swish går till ett annat konto. Avgiften som banken tar för Swish bokför du som en kostnad, till exempel på 6570, när den dras.

## Så här gör du

1. Låt kassan mejla dagsrapporten till inkorgen. Koppla raden för kort till ett fordringskonto och raden för Swish till bankkontot. Läs [Dagsrapporter från kassasystemet](/manual/leverantorer/dagsrapporter).
2. Spara inlösarens avräkning. Den visar vilka dagar en utbetalning gäller och hur stor avgiften är, och den är underlaget för verifikationen.
3. Bokför utbetalningen med avgiften på ett eget kostnadskonto, inte som minskad försäljning.
4. Stäm av bankkontot mot bokföringen varje månad. Läs [Bankavstämning](/manual/bank/bankavstamning).
5. Kontrollera ibland att fordringskontot för kort går ner till noll.

## Vanliga misstag

- **Att bokföra försäljningen med det utbetalda beloppet.** Då blir både försäljningen och momsen för låg.
- **Att bokföra kortförsäljningen direkt på banken.** Pengarna kommer en annan dag och med avgiften dragen, och då går banken inte att stämma av.
- **Att inte registrera Swish i kassan.** Swish ska slås in i kassan som annan försäljning.

## Bra att veta

Avgiften står ofta som en klumpsumma per månad i stället för per utbetalning. Bokför den då när den dras, med avräkningen eller fakturan som underlag.
