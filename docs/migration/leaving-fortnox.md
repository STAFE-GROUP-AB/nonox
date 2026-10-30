# Flytta från Fortnox till NONOX

Den här guiden beskriver hur ett företag flyttar sin bokföring och sin lön
från Fortnox till NONOX Ekonomisystem utan att något går förlorat. Fortnox
nämns bara som källa för uppgifterna; NONOX har ingen koppling till Fortnox.

> Bokföringslagen kräver att räkenskapsinformation sparas i sju år efter
> utgången av det kalenderår då räkenskapsåret avslutades. Säg inte upp det
> gamla abonnemanget förrän allt är exporterat och kontrollerat, eller förrän
> lagringstiden har gått ut.

## 1. Välj dag för flytten

Flytta helst direkt efter att en momsperiod och en arbetsgivardeklaration är
inlämnade, och innan nästa lön räknas. Då hamnar varje deklaration helt i ett
av systemen.

## 2. Bokföringen

1. Exportera en SIE4-fil för **varje** räkenskapsår från Fortnox.
2. Läs in filerna i NONOX under Bokföring → Läs in SIE-fil. Inläsningen visar en
   avstämning innan något sparas.
3. Ladda ner alla underlag (kvitton, fakturor) och fakturor som PDF från
   Fortnox och spara dem. De ingår i räkenskapsinformationen.
4. Exportera kund-, leverantörs- och artikelregister och de öppna kund- och
   leverantörsfakturorna.

## 3. Lönen

Läs in lönedata innan den första lönen räknas i NONOX. Under tiden kan ingen
lönekörning räknas. Allt görs under **Lön → Flytt av lönedata**, där det finns
en tom mall (CSV) för varje steg.

1. **Starta flytten** och ange året som beloppen gäller.
2. **Anställda** (`anstallda.csv`): ta fram en personallista ur Fortnox Lön
   med anställningsnummer, namn, personnummer (tolv siffror), anställd från,
   anställningsform, lönform (månadslön eller timlön), lön, skattetabell och
   kolumn, och gärna e-post, clearingnummer och kontonummer. Skriv in dem i
   mallen. Anställningsnumret används av de andra filerna.
3. **Hittills i år** (`hittills-i-ar.csv`): för varje anställd bruttolön,
   skatt, avgiftsunderlag och arbetsgivaravgifter för året till och med den
   sista lönen i Fortnox, och förmåner och engångsbelopp om sådana finns.
   Uppgifterna finns i de årssummor eller ackumulatorer Fortnox Lön visar per
   anställd.
4. **Semester** (`semester.csv`): saldot för det semesterår som pågår, med
   semesterårets första dag, intjänade, uttagna och sparade dagar, och den
   semesterlönegrundande lönen för den som har procentregeln.
5. **Gamla lönebesked**: spara lönebeskeden som PDF och döp dem till
   `anställningsnummer_ÅÅÅÅ-MM.pdf`, till exempel `17_2026-03.pdf`. De hamnar
   i Lönearkivet och på den anställdes Min sida.
6. **Avstämning**: när skatt och arbetsgivaravgifter hittills i år stämmer med
   bokföringen för året (konto 2710 och 7510 från SIE-filen) kan flytten
   markeras som klar. Stämmer de inte, kontrollera att bokföringen för hela
   året är inläst och att filen med beloppen är rätt.

Arbetsgivardeklarationer för månader som betalades ut i Fortnox rättas i
Fortnox eller direkt hos Skatteverket; NONOX deklarerar från den första lönen
som betalas ut här.

## 4. Kontrollera

- Saldobalansen per räkenskapsår i NONOX är densamma som i Fortnox.
- Antalet verifikationer per serie och år stämmer.
- Öppna kund- och leverantörsfakturor har samma summor.
- Flytten av lönedata är markerad som klar och semestersaldona stämmer.

## 5. Efter flytten

- Uppdatera betalningsuppgifter (bankgiro, OCR) på fakturorna och berätta för
  kunderna om något har ändrats.
- Behåll exporterna och det gamla systemet läsbart, eller ett kontrollerat
  arkiv, tills lagringstiden har gått ut. Först då kan abonnemanget sägas upp.

## Vanliga frågor

**Kan vi byta mitt i året?** Ja. Bokföringen läses in för hela året och lönen
fortsätter med beloppen hittills i år, så skatt på engångsbelopp och
arbetsgivaravgifter räknas rätt.

**Vad händer med en momsperiod som pågår?** Byt helst efter att den är
inlämnad. Annars deklareras perioden i NONOX från den inlästa bokföringen.

**Lönehistorik från tidigare år?** Spara lönebeskeden som PDF. Årets belopp
läses in som summor; tidigare år behövs inte för att räkna lön.

TODO(legal-verify): Fortnox egna exportformat för lön (till exempel PAXml) och
deras API, för att läsa uppgifterna direkt utan CSV-steget, och villkoren för
att använda API:et.
