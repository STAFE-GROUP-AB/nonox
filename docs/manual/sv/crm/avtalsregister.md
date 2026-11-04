---
beskrivning: Håll ordning på kundernas avtal med period, uppsägningstid och förlängning, och få en uppgift innan det är dags att säga upp eller omförhandla.
ordning: 29
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.agreements, crm.agreements.create, crm.agreements.show]
sokord: [avtal, avtalsregister, kontrakt, uppsägning, uppsägningstid, förlängning, förnyelse, löptid, serviceavtal, supportavtal, ramavtal, abonnemang]
---

# Avtalsregister

I avtalsregistret har du kundernas avtal: vad avtalet gäller, när det började, när perioden slutar, hur lång uppsägningstiden är och om det förlängs automatiskt. NONOX ger den ansvarige en uppgift innan det är dags att säga upp eller omförhandla, så att inget avtal löper vidare av misstag.

![Avtal med period, sista dag att säga upp, förlängning, värde och status.](/bilder/manual/crm/avtal.webp)

Avtalsregistret kräver modulen CRM. Det är inte samma sak som **Avtalsfakturering**, som skickar fakturor varje månad eller kvartal. Ett avtal kan kopplas till sin avtalsfakturering. Se [Avtalsfakturering](/manual/fakturering/avtalsfakturering).

## Så här lägger du till ett avtal

1. Öppna **CRM → Avtal** och tryck på **Nytt avtal**, eller öppna kunden och fliken **Avtal** och tryck på **Nytt avtal** där.
2. Skriv **Rubrik**, välj **Kund eller prospekt** och **Typ**.
3. Fyll i **Startdatum** och **Slutdatum (tomt: tills vidare)**.
4. Skriv **Uppsägningstid, månader**.
5. Bocka i **Förlängs automatiskt om det inte sägs upp** om avtalet förnyas, och skriv **Förlängs med, månader**.
6. Fyll i **Värde exkl. moms, kr** och **Per** (månad, kvartal, år eller totalt), **Ansvarig** och, om det finns, **Avtalsfakturering**.
7. Skriv gärna **Avtalstext (frivilligt)** och **Interna anteckningar**.
8. Tryck på **Spara**.

![Avtalets sida med sista dag att säga upp, villkoren, PDF:en och signeringen.](/bilder/manual/crm/avtalets-kort.webp)

## Sista dag att säga upp

**Säg upp senast** är slutdatumet minus uppsägningstiden. Ett avtal som slutar 31 augusti med en månads uppsägningstid ska alltså sägas upp senast 31 juli. Saknar avtalet uppsägningstid är det slutdatumet som gäller.

## Påminnelser

Varje morgon tittar NONOX på avtalen. När det är 60 dagar eller mindre kvar till sista dag att säga upp får avtalets ansvarige en **uppgift** i CRM, med förfallodag den dagen. Saknas ansvarig får kundens ansvarige den, annars företagets ägare. Varje period ger en uppgift.

Hur många dagar i förväg ändrar du under **CRM → Inställningar**, **Påminn om avtal, dagar innan**. Avtal som närmar sig sista dagen syns också under **Att göra** på översikten.

## Förlängning och uppsägning

- Ett avtal som förlängs automatiskt får ett nytt slutdatum när perioden har gått ut, och du blir påmind igen inför nästa period.
- Bestämmer ni att avtalet ska upphöra, tryck på **Markera som uppsagt** på avtalets sida. Det slutar när perioden är slut och förlängs inte, och du får inga fler påminnelser. **Återta uppsägningen** ångrar det.

## Avtalets PDF och signering

Ladda upp avtalet som PDF under **Avtalets PDF**, eller låt bli: då blir avtalet en PDF med uppgifterna och avtalstexten när det skickas för signering. Skicka det till kunden under **Signering**. Se [Signera offerter och avtal](/manual/crm/signering).

När avtalet är signerat går villkoren inte längre att ändra: det signerade är det som gäller. Ansvarig, anteckningar, avtalsfakturering och uppsägning går fortfarande att ändra. Ändras villkoren gör du ett nytt avtal.

## Listan

Under **CRM → Avtal** väljer du **Löpande**, **Löper ut snart** (inom 30 till 365 dagar), **Avslutade** eller **Alla**, och söker på avtalet eller kunden. Filtren sparas i adressen.

## Bra att veta

- En **Säljare** kan lägga till, ändra och skicka avtal för signering. Avtalsfakturering visas bara för den som ser fakturorna.
- Ett prospekt med ett signerat avtal raderas inte och gallras inte, eftersom avtalet behövs som bevis.
- Ett avtal som har upphört raderas inte automatiskt. Radera de avtal som företaget inte längre behöver spara.

## Läs också

- [Signera offerter och avtal](/manual/crm/signering)
- [Avtalsfakturering](/manual/fakturering/avtalsfakturering)
- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
