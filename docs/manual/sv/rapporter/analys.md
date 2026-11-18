---
beskrivning: Välj uppgifter som bokföringsrader, budget, fakturor eller lön, gruppera dem i rader och kolumner, jämför med förra året och klicka dig ner till posterna bakom ett belopp.
ordning: 63
roller: [owner, admin, accountant, bookkeeper, auditor, unit_manager]
rutter: [analysis.index]
sokord: [analys, pivot, pivottabell, rapportbyggare, gruppera, jämför, drill-down, kostnadsställe, projekt, månad, lönesammanställning]
---

# Analys

Analys låter dig ställa egna frågor till företagets siffror utan att skriva något: Vad kostade varje kostnadsställe per månad? Vilka kunder står för mest försäljning? Hur ser fakturorna från varje leverantör ut? Du väljer uppgifter, grupperar dem i rader och kolumner och klickar dig ner till posterna bakom ett belopp. Inget bokförs eller ändras här; sidan läser bara.

![Analys av bokföringsraderna per konto och månad.](/bilder/manual/rapporter/analys.webp)

## Så här gör du en analys

1. Gå till **Bokföring → Analys**.
2. Välj **Uppgifter**, till exempel **Bokföringsrader**.
3. Välj **Mått**, till exempel **Debet minus kredit**.
4. Välj **Rader**, till exempel **Konto**. Under **Rader, nivå två** kan du lägga till en grupp till, till exempel **Kostnadsställe**.
5. Välj **Kolumner** om du vill ha en tabell med två dimensioner, till exempel **Månad**. Då visas också en **Summa** för varje rad och längst ner.
6. Ändra perioden med **Från månad** och **Till månad**. Perioden räknas i hela månader, högst 60 månader.

Valen sparas i adressen, så du kan lägga ett bokmärke eller skicka länken till en kollega som har samma behörighet.

## Uppgifterna du kan välja

| Uppgifter | Vad det är |
|---|---|
| **Bokföringsrader** | De bokförda raderna: konto, kontogrupp, kontoklass, månad, verifikationsserie, kostnadsställe och projekt. Alla bokförda verifikationer räknas med, också bokslutsverifikationer som avskrivningar och periodiseringar, precis som i resultatrapporten. |
| **Budgetrader** | Raderna i varje räkenskapsårs aktiva budget. Prognoser räknas inte med. |
| **Kundfakturor** | En post per faktura eller kreditfaktura, utan utkast: kund, månad, status och sort. Netto, moms, totalt och obetalt. |
| **Fakturarader** | Raderna på kundfakturorna: artikel, intäktskonto, kund och månad. |
| **Leverantörsfakturor** | Raderna på registrerade leverantörsfakturor, utan moms: leverantör, konto, månad, status, kostnadsställe och projekt. |
| **Lön, sammanräknad** | Bruttolön per lönekod, kostnadsställe och månad. Se nedan. |

Du ser bara de uppgifter din roll har rätt till. Kundfakturorna kräver samma behörighet som fakturorna, budgeten som budgeten och lönen som lönen.

## Filter, sortering och jämförelse

- Med **Filter 1** och **Filter 2** väljer du en dimension, till exempel **Kostnadsställe**, och sedan ett eller flera värden. Håll ned Ctrl eller Cmd för att välja flera.
- Med **Sortera** väljer du **Efter namn** eller **Störst summa först**.
- Kryssrutan **Jämför med samma månader året innan** visar under varje belopp förra årets belopp och skillnaden. Jämförelsen går inte att kombinera med **Månad** som rad eller kolumn.

## Posterna bakom ett belopp

Klicka på ett belopp för att se posterna bakom det: för bokföringsrader verifikation, konto, text, kostnadsställe och projekt, och en länk till verifikationen. Klicka på **Öppna listan** för att komma till sidan där posterna hör hemma, eller på **Stäng** för att dölja dem. Högst 200 poster visas; välj ett smalare val för att se fler.

## Tabellen kan inte bli för stor

En tabell får högst 10 000 celler. Blir den större visas **För många celler**; välj färre rader eller kolumner, eller lägg på ett filter.

## Lön visas aldrig per person

Uppgifterna **Lön, sammanräknad** finns bara för den som har behörighet till lön. De visar bruttolön per lönekod, kostnadsställe och månad, aldrig per person och utan drill-down.

Ett delbelopp per lönekod, kostnadsställe och månad visas inte när färre än tre personer har lön i det, eller när en person står för mer än 80 procent av det: beloppet ersätts av **Dold**. Det gäller också varje summa och varje ny gruppering som innehåller en dold del, så en dold grupps lön går inte att räkna fram genom att jämföra en summa med en annan.

Det här skyddar inte mot allt. Jämför du perioder eller kostnadsställen kan en enskild persons förändring fortfarande synas i en grupp på tre eller fler, och **Dold** visar att en lönekod förekommit. Uppgifterna visar inget som den som har behörighet till lön inte redan ser per person.

## För dig som är begränsad till enheter

En **Enhetschef**, eller en bokförare som är begränsad till kostnadsställen och projekt, ser [bara](/manual/installningar/behorighet-per-enhet):

- bokföringsrader, budgetrader och leverantörsfakturor, och bara de rader som hör till personens egna kostnadsställen och projekt;
- inte lönens verifikationer och inte personalkostnaderna på konto 7000–7699, hur lönen än är bokförd;
- andra enheter bara som **Annan enhet**, utan namn eller nummer;
- ingen sammanräknad lön och inga kundfakturor.

Personen kan inte räkna fram något utanför sina enheter genom att välja andra rader, kolumner, filter eller perioder: siffrorna är redan avgränsade när de grupperas.

## Vanliga frågor

**Varför är ett belopp negativt?** Måttet **Debet minus kredit** följer bokföringen: intäkter, som krediteras, blir negativa och kostnader positiva. Välj **Kredit** eller **Debet** om du vill se en sida i taget.

**Varför stämmer inte en summa med resultatrapporten?** Resultatkontona (3000–8999) ger samma belopp som resultatrapporten för samma period. Skillnaden är att analysen också räknar balanskonton, och att den läser rader, inte ingående balanser: ett balanskonto visar periodens rörelser, inte saldot. Använd [saldobalansen](/manual/rapporter/saldobalans) för saldon.
