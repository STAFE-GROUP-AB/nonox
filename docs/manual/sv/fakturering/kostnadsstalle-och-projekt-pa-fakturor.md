---
beskrivning: Välj kostnadsställe och projekt på raderna i fakturor, offerter och order, så att intäkterna hamnar på rätt projekt i bokföringen.
ordning: 35
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.invoices.create, invoicing.invoices.edit]
sokord: [projekt, kostnadsställe, intäkt per projekt, projektredovisning, faktura projekt, offert projekt, förval]
---

# Kostnadsställe och projekt på fakturor

När du fakturerar ett uppdrag vill du att intäkten syns på uppdragets projekt, inte bara på intäktskontot. Därför kan varje rad på en faktura, en offert och en order ha ett [kostnadsställe](/manual/ordlista/ordlista#kostnadsstalle) och ett projekt. När fakturan bokförs hamnar radens intäkt på just det kostnadsstället och projektet.

Valen för kostnadsställe och projekt finns under **Benämning** på varje rad, och de syns bara när företaget har lagt upp kostnadsställen eller projekt. Se [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt).

![En faktura med kostnadsställe och projekt på raderna.](/bilder/manual/fakturering/kostnadsstalle-och-projekt-pa-fakturor.webp)

## Så här väljer du på en rad

1. Öppna en ny faktura, offert eller order.
2. Välj kund och fyll i raderna som vanligt.
3. Under benämningen väljer du kostnadsställe och projekt på de rader du vill följa upp. **Kostnadsställe: Inget** och **Projekt: Inget** betyder att raden inte hör till något.
4. Spara. När fakturan skapas bokförs intäkten på det du valt.

En offert som blir order, och en order som blir faktura, behåller kostnadsställe och projekt på varje rad. En [kreditfaktura](/manual/fakturering/kreditfaktura) får samma val som fakturan den krediterar, så att krediten tar bort intäkten från samma projekt.

## Förval från artikeln, kunden och projektet

Du slipper välja samma sak varje gång:

- **Artikeln.** Under **Register → Artiklar** kan en artikel ha **Kostnadsställe på nya rader** och **Projekt på nya rader**. När du hämtar artikeln till en rad fylls de i.
- **Kunden.** Under **Register → Kunder** kan en kund ha samma två förval. Nya rader på fakturor, offerter och order till kunden får dem. Artikelns förval går före kundens, ett fält i taget: har artikeln ett projekt men inget kostnadsställe får raden artikelns projekt och kundens kostnadsställe.
- **Projektet.** Har projektet ett kostnadsställe i projektregistret fylls det i när raden får projektet men inget kostnadsställe. Det gäller också fakturor som skapas via API:t och avtalsfakturering.

Du kan alltid ändra på raden. Det du väljer på raden gäller.

## Så bokförs det

- **Fakturametoden.** Fakturans verifikation i serie B har en intäktsrad per konto, kostnadsställe och projekt. Momsen, kundfordran på 1510 och öresavrundningen har inget kostnadsställe eller projekt.
- **Kontantmetoden.** Intäkten bokförs när kunden betalar. En delbetalning delar sin del av intäkten på raderna i samma proportion som de har på fakturan, i hela ören. Betalningen som gör fakturan betald bokför det som återstår, så att projektet till sist har exakt radernas intäkt.
- **Bokslutet med kontantmetoden.** Obetalda kundfakturor som bokförs vid årets slut delas på kostnadsställen och projekt på samma sätt, så att ett konto som kräver projekt kan bokföras. Se [Kontantmetoden vid årets slut](/manual/bokslut/kontantmetoden-vid-arets-slut).
- **Kundförlust.** Skriver du bort en faktura delas förlusten på de kostnadsställen och projekt som intäkten hade. Kommer pengarna in senare delas återvinningen likadant.
- En faktura utan kostnadsställe och projekt bokförs precis som förut.
- När fakturan är skapad går raderna inte att ändra, och inte heller deras kostnadsställe och projekt. Rättar du något gör du det med en kreditfaktura.

Rapporterna och listorna under **Bokföring → Projekt** och **Bokföring → Kostnadsställen** visar sedan intäkterna per projekt och kostnadsställe.

## Bra att veta

- Fakturor från avtalsfakturering får artikelns och kundens förval. Avtalets egna rader har inget kostnadsställe eller projekt.
- Ett inaktiverat kostnadsställe eller projekt går inte att välja på nya rader, men finns kvar på raderna som redan har det.

## Läs också

- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden)
