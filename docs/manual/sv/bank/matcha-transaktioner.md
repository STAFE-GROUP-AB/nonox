---
beskrivning: Bekräfta NONOX förslag på vilken verifikation varje banktransaktion hör till, och hitta det som saknas i bokföringen.
ordning: 20
roller: [owner, admin, accountant, bookkeeper]
modul: banking
sokord: [matcha, matchning, banktransaktioner, avprickning, bankhändelser, saknas i bokföringen, kontera banken]
---

# Matcha banktransaktioner

När banken är kopplad hämtar NONOX varje transaktion på kontot. För varje transaktion letar NONOX efter raden i bokföringen som hör ihop med den, och föreslår den. Du bekräftar. Det som blir kvar utan förslag är det som saknas i bokföringen.

Att matcha är inte att bokföra. Ingenting bokförs på den här sidan – du kopplar bara ihop det banken säger med det som redan är bokfört.

![Bankens transaktioner föreslås mot bokföringen.](/bilder/manual/bank/bankkoppling.webp)

## Så här matchar du

1. Välj **Bank** i menyn.
2. Under **Transaktioner** väljer du bankkontot överst, om företaget har flera.
3. Gå igenom kolumnen **I bokföringen**:
   - **Förslag: A12, 2026-10-03** – NONOX hittade en bokförd rad. Kontrollera och tryck på **Matcha**.
   - En länk till en verifikation – transaktionen är redan matchad.
   - **Inbetalning, förslag Faktura 1029** eller **Inbetalning att koppla** – en kundbetalning. Följ länken och bokför den under **Inbetalningar** (se nedan).
   - **Saknas i bokföringen** – inget bokfört motsvarar transaktionen.
4. Ser alla förslag på sidan rätt ut kan du trycka på **Matcha alla förslag på sidan**. Siffran inom parentes visar hur många.

Blev en matchning fel trycker du på **Ta bort matchning** bredvid verifikationen.

## Hur förslagen görs

NONOX föreslår en bokförd rad på det konto i bokföringen som bankkontot hör till, om

- beloppet är exakt detsamma, in eller ut, och
- verifikationen är daterad högst sju dagar före eller efter bankens datum.

Finns flera möjliga rader väljs den med närmast datum. Varje rad i bokföringen kan bara matchas mot en transaktion.

## Kundbetalningar

En insättning i kronor som betalar en obetald faktura, eller som har ett OCR-nummer, blir också en inbetalning under **Försäljning → Inbetalningar**, i listan **Från banken (NONOX Bank)**. Där bokför du den mot fakturan med **Bokför valda**, precis som med bankens fil. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

När inbetalningen är bokförd finns det en rad på bankkontot i bokföringen, och då föreslås den här under **Bank** så att du kan matcha den.

## Det som saknas i bokföringen

**Saknas i bokföringen** betyder att banken har en transaktion som ingen verifikation motsvarar: en bankavgift, en kortbetalning, en betalning till Skatteverket, en insättning utan OCR-nummer. Bokför den som vanligt med en verifikation, med kontoutdraget eller kvittot som underlag. Läs [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).

När verifikationen är bokförd dyker förslaget upp här, och du matchar den.

> [!TIP]
> Under **Att göra** på Översikt står *… banktransaktioner att matcha* så länge något är omatchat. Det är ett bra mått på om bokföringen är à jour.

## Vem får göra vad

För att matcha behöver du både se Bank och få bokföra verifikationer: ägare, administratör, redovisningskonsult eller bokförare. Revisorer kan se transaktionerna men inte matcha.

## Vanliga frågor

**Beloppet stämmer men inget förslag kommer.**
Verifikationen ligger mer än sju dagar från bankens datum, är bokförd på ett annat konto än det bankkontot hör till, eller är redan matchad mot en annan transaktion. Kontrollera kontot och datumet på verifikationen.

**Bankavgiften och leverantörsbetalningen kom som en transaktion, men jag har bokfört dem som två rader.**
Förslag görs bara på exakt samma belopp. Matchningen är en hjälp; det som räknas i slutändan är att bankavstämningen stämmer. Läs [Bankavstämning](/manual/bank/bankavstamning).

**Påverkar matchningen bokföringen?**
Nej. Den kopplar bara transaktionen till en rad, och används när bankens saldo fylls i bankavstämningen.

## Läs också

- [Bankkoppling](/manual/bank/bankkoppling)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
