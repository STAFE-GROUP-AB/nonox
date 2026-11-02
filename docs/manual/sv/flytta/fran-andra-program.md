---
beskrivning: Flytta från Visma, Spiris, Bokio, Björn Lundén eller vilket program som helst som kan exportera SIE4 – vad du exporterar och hur du läser in det i NONOX.
ordning: 30
roller: [owner, admin, accountant]
sokord: [SIE4, SIE-fil, importera, Visma, Spiris, eEkonomi, Visma Administration, Bokio, Björn Lundén, PE Accounting, Wint, byråprogram, annat program]
---

# Från ett annat program

Nästan alla svenska bokföringsprogram kan exportera **SIE typ 4**, det svenska standardformatet för bokföring. Med en SIE4-fil per räkenskapsår får du med kontoplanen, de ingående balanserna, alla verifikationer och kostnadsställen och projekt. Det är den vägen du flyttar från alla program utom Fortnox, där NONOX också kan hämta direkt; se [Från Fortnox](/manual/flytta/fran-fortnox).

![Läs in SIE-fil: så tar du med bokföringen från ditt gamla program.](/bilder/manual/flytta/fran-andra-program.webp)

## Vad du exporterar

Gör en SIE4-fil för **varje** räkenskapsår, även de som är stängda. Står det bara "SIE" utan typ, välj den som innehåller verifikationer (typ 4, ibland kallad 4E eller 4I). Spara också underlag, kundfakturor som PDF, registren och listan över obetalda fakturor; se [Planera flytten](/manual/flytta/planera-flytten#ta-ut-allt-ur-det-gamla-programmet).

Några tips per program. Menyerna ändras ibland, så sök på "SIE" eller "export" i programmets egen hjälp om du inte hittar det.

**Visma och Spiris.** Visma eEkonomi heter numera Spiris. Visma Administration och Visma Lön installeras på datorn. Alla kan exportera SIE. I de installerade programmen ligger exporten oftast under Arkiv eller Export; i Spiris bland bokföringens inställningar. I Spiris är kvitton och fakturor kopplade till verifikationerna; ladda ner dem. Har du ett installerat program, behåll en säkerhetskopia av hela programmets datamapp. Den är ditt arkiv om du behöver visa originalet för en revisor eller Skatteverket.

**Bokio.** SIE-exporten finns bland bokföringens inställningar eller rapporter. Ladda ner kvitton och fakturor som du har laddat upp och spara dem sorterade per år. Har du använt Bokios bank- eller kortkonto behöver du kontoutdragen för hela perioden, så att bankavstämningen i NONOX stämmer.

**Björn Lundén, PE Accounting, Wint och byråprogram.** De kan exportera SIE4. Välj typen som innehåller verifikationer.

## Så här läser du in en SIE-fil

1. Kontrollera att du arbetar i rätt företag; namnet står högst upp i menyn.
2. Gå till **Bokföring → Läs in SIE-fil**.
3. Under **Ladda upp en fil** väljer du filen och klickar på **Granska filen**. Filen får vara högst 50 MB. Ingenting ändras än.
4. Läs **Rapport över SIE-filen**: vilket räkenskapsår filen gäller, vilka konton, kostnadsställen och projekt som läggs till, hur verifikationsserierna kopplas och om något behöver rättas.
5. Står det **Filen kan inte läsas in som den är**, läs vad som behöver rättas, rätta det och ladda upp filen igen.
6. Står allt rätt klickar du på **Läs in filen** och sedan **Ja, bokför** och antalet verifikationer.
7. När filen är inläst står det **Filen är inläst**, och att varje konto stämmer med filen.

Har du flera år läser du in nästa fil på samma sätt, närmast år först. Se [Flera år och ingående balanser](/manual/flytta/flera-ar-och-ingaende-balanser).

> [!WARNING]
> Verifikationerna blir bokförda när filen läses in. De kan inte ändras eller tas bort efteråt, bara rättas, precis som allt annat som är bokfört. Läs rapporten noga innan du bekräftar.

## Det här kontrollerar NONOX

Innan något sparas kontrollerar NONOX bland annat att:

- filen gäller företagets organisationsnummer,
- filens kontrollsumma stämmer, om den har en,
- varje verifikation balanserar och ligger inom räkenskapsåret,
- inga verifikationer redan är inlästa,
- räkenskapsåret inte är stängt eller låst här.

Efter inläsningen jämförs varje kontos utgående balans och resultat med filen, och antalet verifikationer. Stämmer något inte läses ingenting in. Hela inläsningen är allt eller inget.

## Vad som följer med

| Följer med | Hur |
|---|---|
| Verifikationer | Som bokförda verifikationer, med filens datum, texter och rader. |
| Kontoplan | De konton filen använder läggs till med filens namn. Konton som finns i filen men inte används läggs inte till. Finns kontot redan behålls NONOX namn. |
| Ingående balanser | Från filens ingående balanser för året. |
| Kostnadsställen och projekt | Läggs till om de saknas. |
| Verifikationsserier | Saknade serier skapas. Verifikationerna behåller sina nummer om serien är tom här och filens nummer börjar på 1 utan luckor; annars får de nästa nummer här och filens nummer sparas. |
| Ändrade rader | Rader som har lagts till eller tagits bort i efterhand i det gamla programmet sparas som historik med verifikationen. |

Underlag följer inte med i en SIE-fil. Spara dem i ditt arkiv. Kunder, leverantörer och artiklar lägger du in i NONOX; se [Kunder, leverantörer och obetalda fakturor](/manual/flytta/kunder-leverantorer-och-obetalda-fakturor).

## Vanliga frågor

**Filen gäller ett annat företag.**
Har du flera företag i NONOX erbjuder rapporten att byta till rätt företag och granska filen där. Finns företaget inte ännu kan du skapa det med uppgifterna från filen.

**Det står att filen saknar kontrollsumma.**
Det är en varning, inte ett fel. Filen kan läsas in, men det går inte att se om den har ändrats på vägen. Stäm av extra noga efteråt.

**Tecknen å, ä och ö ser konstiga ut.**
NONOX läser både SIE-filernas vanliga teckenkodning och UTF-8. Ser texterna ändå fel ut i rapporten, exportera filen igen ur det gamla programmet.

**Kan jag läsa in en fil för ett år som jag redan har börjat bokföra i här?**
Ja, men verifikationerna i filen läggs då till efter dem som redan finns, och rapporten varnar. Kontrollera att samma affärer inte blir bokförda två gånger. Bäst är att läsa in året innan du börjar bokföra i det här.

## Läs också

- [SIE-filer](/manual/bokforing/sie)
- [Flera år och ingående balanser](/manual/flytta/flera-ar-och-ingaende-balanser)
- [Stäm av](/manual/flytta/stam-av)
- [Flyttguiden på nonox.app](/flytta-hit)
