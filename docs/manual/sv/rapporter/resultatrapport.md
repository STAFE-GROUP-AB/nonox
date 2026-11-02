---
beskrivning: Resultatrapporten visar intäkter, kostnader och resultat för en period, grupperat som i en årsredovisning och jämfört med förra året om du vill.
ordning: 10
roller: [owner, admin, accountant, bookkeeper, auditor]
sokord: [resultatrapport, resultaträkning, vinst, förlust, rörelseresultat, intäkter, kostnader, RR]
---

# Resultatrapporten

Resultatrapporten svarar på frågan "går vi med vinst?". Den visar periodens intäkter och kostnader, konto för konto, och vad som blir kvar. Använd den varje månad för att följa hur det går, och jämför med samma period förra året för att se om det går åt rätt håll.

![Resultatrapporten för en butik.](/bilder/manual/rapporter/resultatrapport.webp)

## Så här tar du fram den

1. Gå till **Bokföring → Rapporter**.
2. Välj **Resultatrapport**.
3. Välj **Räkenskapsår**, och ändra **Från och med** och **Till och med** om du vill se en del av året, till exempel en månad.
4. Kryssa i **Jämför med samma period förra året** om du vill se förra årets siffror bredvid.

## Så läser du rapporten

Kontona är grupperade efter sina nummer, i den här ordningen:

| Grupp | Konton |
|---|---|
| Rörelsens intäkter | 3000–3999 |
| Varor, material och tjänster | 4000–4999 |
| Övriga externa kostnader | 5000–6999 |
| Personalkostnader | 7000–7699 |
| Avskrivningar | 7700–7899 |
| Övriga rörelsekostnader | 7900–7999 |
| **Rörelseresultat** | summan av grupperna ovan |
| Finansiella poster | 8000–8799 |
| **Resultat efter finansiella poster** | |
| Bokslutsdispositioner | 8800–8899 |
| **Resultat före skatt** | |
| Skatt och årets resultat | 8900–8999 |

Varje grupp har en rad med **Summa**, och längst ned står **Beräknat resultat**.

Intäkter visas som positiva belopp och kostnader som negativa, så att allt kan summeras rakt nedåt: ett positivt resultat är en vinst, ett negativt en förlust. Negativa belopp har ett minustecken och en egen färg.

Bara konton som har något bokfört under perioden – eller under jämförelseperioden – visas.

## Jämför med förra året

Med **Jämför med samma period förra året** får rapporten kolumnen **Förra året** bredvid **Perioden**. Jämförelsen gäller samma datum ett år tidigare, i räkenskapsåret före. Har du valt mars 2026 jämförs alltså med mars 2025.

Finns inget föregående räkenskapsår i NONOX står det **Det finns inget föregående räkenskapsår att jämföra med.** Har du flyttat hit kan du läsa in förra årets bokföring från en SIE-fil, så får du jämförelsen; se [SIE-filer](/manual/bokforing/sie).

## Följ upp en siffra

Välj ett konto i rapporten, så öppnas [kontoanalysen](/manual/rapporter/kontoanalys-och-huvudbok) för kontot med samma period och filter. Där ser du varje rad som bygger upp beloppet, och varje verifikation.

## Vanliga frågor

**Varför står Beräknat resultat på noll efter bokslutet?**
När årets resultat bokförs i bokslutet hamnar det på konto 8999 Årets resultat, som ingår i rapporten under **Skatt och årets resultat**. Raden för 8999 tar då ut resultatet, så att summan blir noll. Årets resultat är beloppet på 8999 med omvänt tecken, och det står också på eget kapital i [balansrapporten](/manual/rapporter/balansrapport).

**Rapporten för ett kostnadsställe visar konstiga summor.**
Kontrollera att intäkterna och kostnaderna verkligen har bokförts med kostnadsstället. Rader utan kostnadsställe kommer inte med när du filtrerar. Se [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse).

**Är det här den resultaträkning som ska in i årsredovisningen?**
Den bygger på samma bokföring, men årsredovisningen tar du fram under **Bokföring → Årsredovisning**. Se [Årsredovisning](/manual/bokslut/arsredovisning).

## Läs också

- [Balansrapporten](/manual/rapporter/balansrapport)
- [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse)
- [Skriv ut och exportera](/manual/rapporter/skriv-ut-och-exportera)
- [Översikten](/manual/kom-igang/oversikten)
