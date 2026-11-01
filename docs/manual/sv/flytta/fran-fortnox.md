---
beskrivning: Flytta från Fortnox genom att logga in på Fortnox-kontot och låta NONOX hämta bokföringen år för år, registren och underlagen – eller gör det själv med SIE-filer.
ordning: 20
roller: [owner, admin, accountant]
rutter: [fortnox.move, fortnox.connect, fortnox.callback]
sokord: [Fortnox, flytta från Fortnox, byta från Fortnox, Fortnox export, Fortnox SIE, Fortnox API, hämta från Fortnox]
---

# Från Fortnox

Kommer du från Fortnox finns två sätt att flytta. Det snabbaste är att logga in på ditt Fortnox-konto inifrån NONOX och låta NONOX hämta allt. Det andra är att exportera SIE-filer ur Fortnox och läsa in dem, precis som från vilket program som helst.

NONOX läser bara från Fortnox. Ingenting ändras i ditt Fortnox-konto.

## Direkt från Fortnox

NONOX hämtar:

- bokföringen, ett räkenskapsår i taget som en SIE4-fil som du granskar innan den läses in,
- kunder, leverantörer och artiklar,
- underlagen (kvitton och fakturor) som hör till verifikationerna, kopplade till samma verifikation i NONOX,
- och jämför obetalda kund- och leverantörsfakturor med bokföringen.

> [!NOTE]
> Hämtningen kräver att installationen är registrerad hos Fortnox. På installationer där det inte är gjort står det **Hämtning från Fortnox är inte inställd på den här installationen**. Använd då SIE-filer i stället, se nedan.

### Så här gör du

1. Gå till **Bokföring → Läs in SIE-fil** och klicka på **Flytta från Fortnox** i rutan högst upp. Sidan **Flytta från Fortnox** har sex steg.
2. **1. Anslut till Fortnox:** klicka på **Anslut till Fortnox**, logga in hos Fortnox och godkänn att NONOX får läsa. Kontot måste tillhöra samma organisationsnummer som företaget i NONOX. När det är klart står det **Ansluten till** och företagets namn.
3. **2. Bokföringen, år för år:** klicka på **Visa räkenskapsåren i Fortnox**. Klicka på **Hämta** vid det år som ligger närmast det du redan har i NONOX, oftast det pågående. Klicka på **Hämtad, granska och läs in**, läs rapporten och läs in året. Fortsätt sedan bakåt ett år i taget. Se [Flera år och ingående balanser](/manual/flytta/flera-ar-och-ingaende-balanser).
4. **3. Kunder, leverantörer och artiklar:** klicka på **Hämta registren**. De som inte redan finns i NONOX läggs till. NONOX visar hur många som lades till, hur många som fanns och vad som inte gick att läsa.
5. **4. Underlag:** klicka på **Hämta underlag**. Läs in alla år i steg 2 först, eftersom varje underlag kopplas till verifikationen med samma serie och nummer. Du ser hur många som är hämtade; det som inte gick listas med verifikationens nummer.
6. **5. Stäm av obetalda fakturor:** klicka på **Jämför**. Obetalda kund- och leverantörsfakturor i Fortnox jämförs med kundfordringar (1510) och leverantörsskulder (2440) i det senaste året i NONOX. Det står **Stämmer** eller **Skiljer sig**.
7. **6. Klart:** när allt stämmer klickar du på **Avsluta flytten** och bekräftar. Anslutningen till Fortnox tas bort och nycklarna raderas.

> [!IMPORTANT]
> Inget år bokförs förrän du har läst rapporten och läst in det, precis som när du laddar upp en fil själv. Läs mer om rapporten i [SIE-filer](/manual/bokforing/sie).

### Bra att veta

- Det går att hämta igen. **Hämta igen** vid ett år eller vid registren gör om hämtningen, och **Hämta resten** fortsätter med underlag som inte har hämtats.
- Ett år som redan är inläst kan inte läsas in igen med samma verifikationer.
- Avbryter du flytten utan att avsluta den raderas nycklarna till Fortnox automatiskt efter 30 dagar utan aktivitet.
- Det är bara ägare, administratörer och redovisningskonsulter som kan flytta från Fortnox.
- Skiljer sig de obetalda fakturorna, kontrollera att det senaste året är inläst och att inga fakturor har betalats i Fortnox efter att du hämtade bokföringen.

## Med SIE-filer från Fortnox

Fungerar inte hämtningen, eller vill du hellre göra det själv:

1. Exportera en SIE-fil av typ 4 för **varje** räkenskapsår ur Fortnox, även de som är stängda. Hittar du inte exporten, sök på "SIE" i Fortnox egen hjälp.
2. Ladda ner underlagen ur Fortnox arkiv, gärna år för år så att verifikationsnumren syns i filnamnen eller mapparna, och spara dem.
3. Spara kund- och leverantörsfakturorna som PDF och ta en lista över de obetalda på flyttdagen.
4. Exportera kund-, leverantörs- och artikelregistret.
5. Läs in SIE-filerna under **Bokföring → Läs in SIE-fil**, närmast år först. Se [Från ett annat program](/manual/flytta/fran-andra-program#sa-har-laser-du-in-en-sie-fil).

## Lönen

Lönen i Fortnox flyttas med NONOX mallar, oavsett vilket sätt du flyttar bokföringen. Se [Flytta lönen](/manual/flytta/flytta-lonen).

## Vanliga frågor

**Måste jag ha kvar mitt Fortnox-abonnemang?**
Säg inte upp det förrän allt är exporterat och avstämt, och du har sparat underlagen och fakturorna. Räkenskapsinformationen ska sparas i sju år; se [Efter flytten](/manual/flytta/efter-flytten).

**Fortnox-kontot tillhör ett annat företag.**
NONOX kontrollerar att organisationsnumret i Fortnox är detsamma som företagets i NONOX. Logga in i Fortnox med rätt företag och anslut igen.

**Följer historiska kundfakturor med som fakturor?**
Nej. Bokföringen för dem följer med i SIE-filen, och obetalda fakturor finns kvar som saldo på kundfordringar. Spara fakturorna som PDF. Se [Kunder, leverantörer och obetalda fakturor](/manual/flytta/kunder-leverantorer-och-obetalda-fakturor).

## Läs också

- [Planera flytten](/manual/flytta/planera-flytten)
- [Flera år och ingående balanser](/manual/flytta/flera-ar-och-ingaende-balanser)
- [Stäm av](/manual/flytta/stam-av)
- [Flyttguiden på nonox.app](/flytta-hit)
