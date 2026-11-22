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

## Vitec Pyramid (tillagd 2026-11-19)

Vitec Pyramid lades till 2026-11-19. Vitec har två webbplatser: vitec-pyramid.com (Business Studio, som är SaaS, och Pyramid Hybrid Cloud, som installeras på kundens egen hårdvara; båda har samma modulkatalog) och novaoffice.se (Pyramid Nova Office, småföretagsprodukten). Bara Nova Office har ett publicerat pris, så det är paketet som jämförs i `jamforelse.json`. Moduler som Vitec bara säljer i Business Studio eller Hybrid Cloud, och som inte står på Novas egna sidor, visas som "Större paket" (pris på offert) när Vitecs egna sidor nämner funktionen. Förkortningar: **BS** = `https://www.vitec-pyramid.com/vara-paket-och-moduler-start/vara-moduler-business-studio`, med undersidorna `/baspaket/<modul>/`, `/foretagspaket/<paket>/` och `/tillvalsmoduler/<modul>/`; **NO** = `https://novaoffice.se/om-pyramid-nova-office/` (även `/basfunktioner/` och `/tillvalsfunktioner/`); **ORDER** = `https://portal.novaoffice.se/Order`. Alla sidor lästes 2026-11-19.

### Pris

| Paket | Källa | Värde |
|---|---|---|
| Nova Office | ORDER och novaoffice.se | "399 kr/användare", "Pris exkl. moms"; "399 kr/månad", "Ingen bindningstid", "Fri support". Fler än fem användare: "Begär offert av våra samarbetspartners" |
| Business Studio | vitec-pyramid.com/business-studio/ och BS | Inget pris: bara "Bli kontaktad", "Skicka in en förfrågan" och "betalar bara för det du behöver" |
| Pyramid Hybrid Cloud | vitec-pyramid.com/pyramid-hybrid-cloud/ | Inget pris: bara "Bli kontaktad" |

Inget uppskattat pris för Business Studio publiceras någonstans (beslut av ägaren). Priset jämförs inte med scenariot (`scenario_price: false` i `jamforelse.json`), eftersom scenariot kräver lön och Pyramid inte har någon lönemodul, och eftersom Pyramid prissätter per användare och inte per anställd.

### Raderna i tabellen (paketet Nova Office)

| Rad | Värde | Källa och citat |
|---|---|---|
| Bokföring | Ingår | BS/baspaket/redovisning; NO/basfunktioner "Redovisning", "Verifikationer", "Momshantering" |
| Fakturering | Ingår | BS/baspaket/fakturering; NO/basfunktioner "Fakturering", "Offerter" finns i CRM/Säljstöd (BS/baspaket/crm-saljstod, fliken "Offerter") |
| Lön för fem anställda | Finns inte | Ingen lönemodul på någon av Vitecs sidor (baspaket, företagspaket, tillval, Novas bas och tillval). Vitecs egen text pekar på externa löneprogram: "Pyramid kan dessutom läsa in verifikat från de vanligaste löneprogrammen." (BS/baspaket/redovisning); "Löne- och faktureringsunderlag tas fram via Pyramid Tidrapportering" (BS/tillvalsmoduler/tidrapportering); "Tidrapportering – för att koppla samman mobil arbetsorder med löneprogram." (BS/tillvalsmoduler/mobil-arbetsorder) |
| Leverantörsfakturor med attest | Ingår | NO/basfunktioner "Attesthantering", "Fakturaattest"; BS/tillvalsmoduler/e-faktura-leverantor (ingår i baspaketet) |
| ROT- och RUT-avdrag | Ingår | BS/baspaket/fakturering "Rot/rut/grön teknik"; NO/basfunktioner "Rot/rut" |
| Lager med lagerställen | Tillval | "Flerlager" +99 kr och "Lagerplats/Batch" +99 kr (ORDER, portal.novaoffice.se/Order) |
| Inköpsorder och inköpsförslag | Tillval | "Handelspaketet" +99 kr per användare (ORDER, portal.novaoffice.se/Order); BS/foretagspaket/handelspaket-business-studio nämner "inköpsanmodan" och "behovsanalys" |
| Bankkoppling | Anges ej | NO/basfunktioner "Utbetalning - Bankkoppling". Gäller bara leverantörsbetalningar: BS/tillvalsmoduler/bankkoppling beskriver den som "leverantörsbetalningar via en direktintegration till banken" och "Automatisk och effektiv hantering av leverantörsbetalningar". Ingen sida nämner inläsning av kontotransaktioner. Raden i tabellen betyder bank-koppling med kontoinläsning hos de andra programmen, så Vitecs leverantörsbetalningar räknas inte som samma sak och värdet är "Anges ej" |
| Mobilapp | Ingår | NO/basfunktioner "App för telefon"; BS/tillvalsmoduler/phone-edition (appar för iOS och Android, "Pyramid Phone") |
| Alla moduler ingår i priset | Finns inte | BS: "betalar bara för det du behöver"; Nova Office har betalda tillval (ORDER) |
| Öppen källkod | Finns inte | novaoffice.se: "Copyright © 2026 Vitec Pyramid ERP AB. Alla rättigheter reserverade." |
| Arbetsorder med tid, material och ÄTA | Större paket | Business Studios Entreprenadpaket (BS/foretagspaket/entreprenadpaket-business-studio): "Hantera anbudsprojekt, ändrings- och tilläggsarbeten (ÄTA)" och "Mobil arbetsorder". Novas del: "Arbetsorder" +79 kr per användare (ORDER, portal.novaoffice.se/Order); ÄTA nämns inte för Nova |
| Tillverkning, Öppet API, Tolkning av kvitton och fakturor, Kör på egen server | Större paket | Finns i Business Studio eller Hybrid Cloud, där priset är offert, men inte på Novas sidor (BS/foretagspaket/tillverkningspaket-business-studio, BS/tillvalsmoduler/api-connector, BS/tillvalsmoduler/automatiserad-fakturahantering "AI-tolkning av PDF-fakturor", och vitec-pyramid.com/pyramid-hybrid-cloud/ "Med systemet installerat på egen hårdvara" för egen server) |
| CRM med säljtratt och nyckeltal ur bokföringen | Anges ej | BS/baspaket/crm-saljstod ("Kundvård, säljstöd och verksamhetsstöd") nämner offerter, aktiviteter och kundcentral med "offerter, order/projekt, reskontra, köphistorik", men ingen säljtratt och inga nyckeltal ur bokföringen |
| Inkorg för leverantörsfakturor och kvitton | Anges ej | BS/tillvalsmoduler/e-faktura-leverantor: "Fakturor kan skannas till Pyramids inkorg". Kvitton och inläsning via e-post anges inte |
| Kör på egen server | Större paket | Business Studio är SaaS och Nova är molnbaserat ("Molnbaserat" i NO/basfunktioner), men Pyramid Hybrid Cloud installeras på kundens egen hårdvara ("Med systemet installerat på egen hårdvara", vitec-pyramid.com/pyramid-hybrid-cloud/). Det är licensierad programvara, inte fri egen drift, och finns bara i Hybrid Cloud (pris på offert), därför "Större paket" |
| Alla övriga rader (Personalliggare, bokslut och årsredovisning, SRU-deklarationerna, streckkodsskanning utan nät, recept och svinn, körjournal, K3, MRR/ARR, schema och personalplanering, personalliggare i surfplatteapp, aktiebok, flera bolag under en inloggning) | Anges ej | Står inte på Vitecs sidor. Bokslut: bara "bokslutssimulering" med preliminära verifikationer nämns (BS/baspaket/redovisning). Flera bolag: BS/tillvalsmoduler/koncernredovisning ("obegränsade antalet företag") nämner inte en gemensam inloggning |

### Nya rader

| Rad | Pyramid | Källa |
|---|---|---|
| Batch- och serienummerspårning | Tillval | ORDER "Lagerplats/Batch" +99 kr och "Serienummer/Serviceorder" +199 kr; BS/tillvalsmoduler/lagerplatser-batch, serienummer-serviceorder |
| Flytt mellan lager | Tillval | ORDER "Flerlager" +99 kr; BS/tillvalsmoduler/flerlager ("lageromföringar") |
| Behovsplanering (MRP) vecka för vecka | Större paket | Materialplanering och MPS finns i Business Studios Tillverkningspaket (BS/foretagspaket/tillverkningspaket-business-studio, BS/tillvalsmoduler/mps), där priset är offert; Nova erbjuder inget tillverkningspaket |
| Koncernsammanställning (internt underlag) | Större paket | BS/tillvalsmoduler/koncernredovisning finns i Business Studio (offert) men inte på Novas sidor. Fortnox och Kleer: se avsnittet "Rader med kontroll per leverantör" nedan |
| Anläggningsregister med avskrivningar | Tillval | ORDER "Inventarie" +99 kr (portal.novaoffice.se/Order). Avskrivningar nämns bara på BS/tillvalsmoduler/inventarie, inte på Novas sidor, så "med avskrivningar" är sourcat bara för Business Studio |
| Projekt med resultat och uppföljning | Tillval | ORDER "Projektpaketet" +99 kr per användare; BS/foretagspaket/projektpaket-business-studio |
| Tidrapportering | Tillval | ORDER "Tidrapportering" +79 kr, plus 19 kr per extra användare (portal.novaoffice.se/Order); BS/tillvalsmoduler/tidrapportering |
| Fakturering i utländsk valuta | Tillval | ORDER "Valutaorder" +79 kr; BS/tillvalsmoduler/valutaorder (även Intrastat vid export, som NONOX inte har) |
| Elektronisk signering (enkel elektronisk underskrift) | Större paket | BS/tillvalsmoduler/e-signering-scrive (tjänsten levereras av Scrive) finns i Business Studio (offert) men inte på Novas sidor. NONOX signering är en enkel elektronisk underskrift, inte BankID |
| Variantartiklar | Tillval (NONOX: Finns inte) | ORDER "Variantartiklar" +99 kr; BS/tillvalsmoduler/variantartiklar |
| Prislistor och kundavtal | Ingår | NO/basfunktioner "Prislistor", "Kundavtal", "Prishantering - flera nivåer". Motsägelse: BS/foretagspaket/handelspaket-business-studio (och Novas Handelspaket, +99 kr) nämner också prislistor, så det är oklart om Nova bara har dem i bas eller i Handelspaketet; raden visar bara det Novas egen baslista säger |
| Kassasystem | Större paket (NONOX: Finns inte) | BS/tillvalsmoduler/kassa-och-betalkort och BS/foretagspaket/butikspaket-business-studio finns i Business Studio men inte på Novas sidor. NONOX har ingen egen kassa; kopplingarna till Zettle och Onslip är byggda men inte i drift |
| EDI-order | Större paket (NONOX: Finns inte) | BS/tillvalsmoduler/edi-order (offert), inte på Novas sidor |
| E-handel | Större paket (NONOX: Finns inte) | BS/tillvalsmoduler/e-handel (offert), inte på Novas sidor. NONOX har bara API:t |

Signering (ADR-046 S21): raden Elektronisk signering omfattar nu också fält i PDF:en per undertecknare och mallar med roller och massutskick (nycklarna `signing_fields` och `signing_templates`). Inga leverantörsuppgifter har ändrats: Pyramids värde (Större paket) bygger fortfarande på källan ovan, och övriga leverantörer står kvar som `unknown` eftersom ingen av deras egna sidor har lästs för raden. Försegling, kontrollsida och underskrift hos extern tjänst är avstängda i den driftade tjänsten och ingår inte i jämförelsen.

De nya raderna har fältet `checked_for` i `jamforelse.json`: en lista med de leverantörer vars sidor faktiskt lästes för raden. En rad med `checked_for` visas bara på sidan "Alternativ till <leverantör>" för en leverantör i listan, aldrig på startsidan eller branschsidorna, eftersom övriga leverantörers sidor inte lästs för raderna. Värdet "Större paket" betyder att funktionen finns i leverantörens större paket (Business Studio eller Hybrid Cloud) med pris på offert.

### Rader med kontroll per leverantör (lästa 2026-11-19)

Fortnox prislista (https://www.fortnox.se/produkt/prislista) lästes 2026-11-19. Orden nedan är sidans, ordagrant:

| Rad | Fortnox | Citat |
|---|---|---|
| Anläggningsregister med avskrivningar | Tillval | "Anläggningsregister 129 kr / mån". Prislistan nämner inte avskrivningar, så "med avskrivningar" är en slutsats av namnet |
| Tidrapportering | Tillval | "Tid 89 kr / mån" (Fortnox tidprodukt heter "Tid") |
| Koncernsammanställning (internt underlag) | Tillval | "Fortnox Koncern": "Koncern liten 499 kr / mån Koncern mellan 699 kr / mån Koncern stor 899 kr / mån", "Listade priser avser per bolag". Produkten är en egen prislistepost; sidan säger inget om att den motsvarar ett internt konsolideringsunderlag, så raden gäller koncernprodukten som sådan |

Kleers sida om koncernkonsolidering (https://www.kleer.se/ekonomisystem/koncerngemensamma-projekt) lästes 2026-11-19: "Tillägg till Kleer Finance", "Automatiserad koncernkonsolidering", "Koncerninterna transaktioner kan elimineras automatiskt". Därför Tillval hos Kleer, och Kleer står i `checked_for` för raden.

Alla andra leverantörers sidor lästes inte för de nya raderna. Därför visas raderna inte för dem.

Bankkopplingens omfattning: för Pyramid gäller bara utbetalningar till leverantörer. Vitec skriver inte att kontotransaktioner läses in.

Leverantörsbetalningar är det enda Vitec anger för bankkopplingen, och raden Bankkoppling visar därför "Anges ej" för Vitec Pyramid, med Vitecs egna ord ovan som källa.

### Prislistor och kundavtal (kontrollerat 2026-11-22)

NONOX har raden som Finns (prislistor, kampanjer, kundgrupper och prisavtal, ADR-082). Fortnox, Visma Spiris, Bokio, Björn Lundén, Kleer och Accounted står kvar som Anges ej. Fortnox faktureringssida (fortnox.se/produkt/fakturering) lästes 2026-11-22 och beskriver inte prislistor eller kundpriser som en funktion; ordet prislista förekommer bara som länk till Fortnox egna priser. De övriga leverantörernas sidor har inte lästs för den här raden, och raden visas därför bara på sidan Alternativ till Pyramid (checked_for). Pyramid-värdet är oförändrat (se tabellen ovan).
