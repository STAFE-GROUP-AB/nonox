# Källor för funktionsraderna i jämförelsen

Raderna nedan lades till 2026-11-03 och kontrollerades samma dag kl. 10:22–10:27 (svensk tid) mot leverantörernas egna sidor (ADR-020). Värdet gäller det jämförda paketet i `jamforelse.json`. Står funktionen inte på leverantörens sida blir värdet "Anges ej", även om den kan finnas.

| Rad | Fortnox | Spiris (Visma) | Bokio | Björn Lundén | Kleer |
|---|---|---|---|---|---|
| Lager med lagerställen | Tillval, "Lager 369 kr/mån" (fortnox.se/produkt/prislista) | Tillval, "Lager 249 kr/mån" (spiris.se/priser) | Anges ej | Anges ej | Anges ej |
| Streckkodsskanning i mobilen, även utan nät | Anges ej (skanning via partner) | Anges ej | Anges ej | Anges ej | Anges ej |
| CRM med säljtratt och nyckeltal ur bokföringen | Anges ej (CRM via partner) | Anges ej | Anges ej (CRM via partner) | Anges ej | Anges ej |
| Öppet API | Ingår i Mellan+, "Integration" (fortnox.se/paket); annars 189 kr/mån | Ingår i Växa, "Integration … Obegränsat" (spiris.se/priser) | Ingår, privata integrationer utan kostnad i Business (bokio.se/priser) | Anges ej (ingen publik API-dokumentation hittad) | Ingår, "Öppet API med tydlig dokumentation" (kleer.se/ekonomisystem/integrationer) |
| Bankkoppling | Ingår med Bokföring (fortnox.se, automatisk bokföring från kontoutdrag); banken kan ta ut avgift | Ingår, "Bankkopplingen ingår i Spiris" (spiris.se/priser) | Ingår i Business (bokio.se/priser) | Ingår i Total, "Bank & Betalning" (bjornlunden.com) | Ingår, "Integration med bank" (kleer.se/pris-ekonomisystem) |
| Schema och personalplanering | Anges ej (via partner) | Anges ej | Anges ej | Anges ej | Anges ej |
| Inkorg för leverantörsfakturor och kvitton | Tillval, e-post kopplad till Fakturatolkning 4,90 kr/st | Ingår, unik e-postadress för leverantörsfakturor (support.spiris.se) | Ingår, "Skicka in kvitton och fakturor med e-post" (bokio.se/priser) | Ingår i Total, "E-postportal (obegränsad)" (bjornlunden.com) | Ingår, digital fakturaadress (kleer.se/ekonomisystem/leverantorsfakturor) |
| Tolkning av kvitton och fakturor | Tillval, "Fakturatolkning digital: 4,90 kr/st" (fortnox.se/produkt/prislista) | Ingår i Växa, "Tolkning & bokföringshjälp … Obegränsat" | Ingår, "Automatisk avläsning av kvitton och fakturor" | Ingår i Total, "Tolkning av leverantörsfakturor och kvitton (obegränsad)" | Ingår, "Belopp, moms och leverantör läses av automatiskt" |
| NE-bilaga för enskild firma (SRU) | Anges ej | Anges ej | Ingår i Premium, Business och Plus, tillval 99 kr i Basic; NE-bilagan tas fram som underlag i sista steget av bokslutet (bokio.se/hjalp/bokslut/arsredovisning-och-deklaration/hur-fungerar-ne-bilagan/) | Anges ej | Anges ej |
| Inkomstdeklaration 4 för handelsbolag (SRU) | Anges ej | Anges ej | Nej, "I Bokio kan du i dagsläget inte få ut någon deklaration eller årsredovisning för handelsbolag" (bokio.se/hjalp/bokslut/arsredovisning-och-deklaration/deklaration-i-handelsbolag-kommanditbolag-och-ekonomisk-forening/) | Anges ej | Anges ej |

Raderna för NE-bilagan och Inkomstdeklaration 4 lades till 2026-11-05 och Bokios två hjälpartiklar lästes samma dag. För de andra leverantörerna hittades ingen egen sida som anger funktionen, så värdet är "Anges ej".

NONOX: tolkningen kräver att företaget lägger in en egen nyckel hos en AI-leverantör, som kostar enligt den leverantörens pris; tabellen visar det som "egen AI-nyckel". Bankkopplingen är byggd men kräver ett avtal med en leverantör av öppen bankdata och erbjuds inte på nonox.app än; tabellen visar det som "egen drift", och raden räknas inte som en funktion som är i drift.

Björn Lundéns egen prissida (bjornlunden.com/se/priser/) lästes 2026-11-06: "Total" 349:-/mån, "Lundify Payroll" "från 39:-/lönebesked" och "Moms tillkommer på alla priser." Prisraden bygger nu på den egna sidan och är verifierad. Samma sida listar "Bank & betalning" 49:-/mån bland tilläggstjänsterna utan att säga vilka paket de gäller; raden Bankkoppling står kvar som "Ingår i Total" enligt produktsidan och kontrolleras igen vid nästa genomgång.

## Flera bolag under en inloggning (kontrollerad 2026-11-06)

| Leverantör | Värde | Källa |
|---|---|---|
| Fortnox | Tillval: "Du samlar alla bolag under en och samma inloggning" med Fortnox Bolagshanterare, som "skräddarsys" efter antalet bolag och har inget publicerat pris | fortnox.se/produkt/fortnox-bolagshanterare |
| Spiris (Visma) | Anges ej: hittades bara i hjälpen för produkten Resor & Utlägg, inte för det jämförda paketet | support.spiris.se |
| Bokio | Ingår: "I Bokio kan du skapa flera företag under samma konto (användare)", med en prisplan per företag | bokio.se/hjalp/komma-igang/skapa-ett-konto/skapa-flera-foretag/ |
| Björn Lundén | Anges ej: produktsidan anger "Pris för 1 användare och 1 företag" | bjornlunden.com/se/redovisning/bokforingsprogram/ |
| Kleer | Anges ej: "Kleer samlar alla bolag i koncernen i samma system" gäller koncernkonsolideringen, som är ett tillägg, och säger inget om inloggningen | kleer.se/ekonomisystem/koncerngemensamma-projekt |
| Accounted | Anges ej: planen Bolag nämner "Koncerner och många bolag i samma avtal", men inte inloggningen | accounted.se/priser |

## Accounted (tillagd 2026-11-06)

Accounted lades till 2026-11-06. Värdena gäller planen Auto och kommer från leverantörens egna sidor, lästa samma dag: prissidan (accounted.se/priser), vanliga frågor (accounted.se/faq), sidan för byråer (accounted.se/byraer), prisjämförelsen (accounted.se/priskalkylator) och leverantörens eget kodförråd (github.com/erp-mafia/accounted).

| Rad | Accounted (Auto) |
|---|---|
| Pris | "199kr/mån" "debiteras årligen", eller "249 kr/mån debiteras månadsvis"; priser exklusive moms. Gratisplanen Open (0 kr) "För dig som bokför manuellt" saknar bankkoppling och inkorg, och momsdeklarationen är där "beräknad, du lämnar in" (accounted.se/priser); den jämförs därför inte |
| Bokföring | Ingår, dubbel bokföring på BAS 2026 |
| Fakturering | Ingår, offerter ingår |
| Lön för fem anställda | Ingår i både Open och Auto enligt prissidans rad "Lönekörningar": "Bruttolön, skatteavdrag, arbetsgivaravgift, lönebesked" (accounted.se/priser) |
| Leverantörsfakturor med attest | Ingår: "Fakturan godkänns innan den kan markeras som betald." |
| ROT- och RUT-avdrag | Ingår: "Avdraget uträknat, begäran om utbetalning genereras som HUS-fil." |
| Bokslut och årsredovisning | Ingår: årsredovisning K2/K3 med "Noter, fastställelseintyg och signering"; inlämning till Bolagsverket är "På väg" |
| Inkomstdeklaration (SRU) och NE-bilaga | Ingår: INK2 och NE-bilaga |
| Inkomstdeklaration 4 | Anges ej |
| Öppet API | Ingår: "280+ endpoints, 28 webhook-händelser, OAuth 2.1." |
| Bankkoppling | Ingår: livekoppling via PSD2 med "2 konton", fler konton "20 kr per styck och månad" |
| Inkorg | Ingår: "Mejla in underlagen, AI:n läser av dem." |
| Tolkning av kvitton och fakturor | Ingår: underlag läses av med AI, "25 i månaden", sedan "1 kr per underlag" |
| Alla moduler ingår i priset | Anges ej. Leverantörens prisjämförelse säger "Ett pris per företag. Allt ingår." (accounted.se/priskalkylator), men prissidan (accounted.se/priser) anger gränser och styckpriser, till exempel två bankkonton och 20 kr per extra konto, 25 AI-tolkningar i månaden och 1 kr per extra tolkning. Uppgifterna går isär, så raden visar "Anges ej" |
| Öppen källkod | Ja: licensen "AGPL-3.0-or-later" enligt README i github.com/erp-mafia/accounted |
| Kör på egen server | Ja: "fully self-hostable with Docker and Supabase" enligt samma README |
| Lager, streckkodsskanning, CRM, schema, personalliggare, mobilapp, flera bolag under en inloggning | Anges ej |

Accounted skriver om deklarationerna i sina vanliga frågor: "Deklarationen byggs ur bokföringen och signeras med BankID i appen." (accounted.se/faq). NONOX lämnar i dag momsdeklarationen som fil; att lämna den direkt till Skatteverket är byggt men provas bara av pilotanvändare i Skatteverkets testtjänst. Därför finns ingen rad som påstår att NONOX gör samma sak.
