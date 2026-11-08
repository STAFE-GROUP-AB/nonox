---
beskrivning: Läs in kunder, leverantörer, artiklar, obetalda fakturor och underlag från ett annat program med en fil, i två steg där du ser en rapport innan något sparas.
ordning: 65
roller: [owner, admin, accountant]
rutter: [import.registers.index, import.registers.show]
sokord: [läs in register, importera kunder, importera leverantörer, importera artiklar, CSV, Excel, xlsx, kundregister, leverantörsregister, obetalda fakturor, öppna poster, 1510, 2440, underlag, ZIP, Mitt format, kolumner, flytta register]
---

# Läs in register

Under **Bokföring → Läs in register** läser du in kunder, leverantörer, artiklar, obetalda fakturor och underlag från ett annat program. Det fungerar med en fil från vilket program som helst, för du kopplar själv filens kolumner till fälten i NONOX. Du får alltid en rapport innan något sparas, och det går att köra samma fil igen utan att något hamnar dubbelt.

Läs först in bokföringen som SIE-fil; se [Från ett annat program](/manual/flytta/fran-andra-program). Registren och fakturorna hör ihop med den.

## Så här gör du

1. Kontrollera att du arbetar i rätt företag; namnet står högst upp i menyn.
2. Gå till **Bokföring → Läs in register**.
3. Under **Ladda upp en fil** väljer du vad filen innehåller under **Vad innehåller filen?**: **Kunder**, **Leverantörer**, **Artiklar**, **Obetalda kundfakturor**, **Obetalda leverantörsfakturor** eller **Underlag (ZIP)**.
4. Väljer du ett program under **Förval för kolumnerna** fylls kopplingen i åt dig. Annars väljer du **Egen fil eller NONOX-mall**. Under **Ladda ner en mall:** finns en tom fil med NONOX fält som rubriker.
5. Välj filen och klicka på **Ladda upp och granska**. Ingenting ändras än.
6. Under **Så läste vi filen** ser du hur NONOX läste filen: format, teckenkodning och avgränsare. Stämmer det inte kan du ändra det och klicka på **Granska igen**.
7. Under **Koppla kolumnerna** väljer du för varje fält vilken kolumn i filen som hör dit. Fält med stjärna måste kopplas. Kolumner du inte kopplar ignoreras.
8. Klicka på **Granska filen**. Du får en **Rapport** med hur många rader som **Läggs till**, **Finns redan, hoppas över**, är **Dubblett, hoppas över** eller är **Fel, hoppas över**, och vad som behöver ses över per rad.
9. Är något fel rättar du filen och laddar upp den igen. Poster som redan lästs in hoppas över. Vill du bara slippa en rad kryssar du i **Utesluta** på raden och klickar på **Granska igen**.
10. Står allt rätt klickar du på **Läs in filen** och sedan **Ja, läs in** och antalet rader. Då visas **Resultat**.

## Kolumnerna och förvalen

Ett förval är bara en förifylld koppling, och du kan alltid ändra den. NONOX har förval för de program vars egen dokumentation beskriver filerna. För övriga program börjar du med en tom koppling.

Vill du använda kopplingen igen skriver du ett namn under **Spara kopplingen som Mitt format** och granskar filen. Nästa gång finns den under **Förval för kolumnerna**. Bara rubrikerna sparas, inga uppgifter.

Vissa fält är koder i det gamla programmet, till exempel betalningsvillkor. Förstår NONOX inte en kod listas den under **Koder som behöver förklaras**. Skriv vad koden betyder, betalningsvillkor som antal dagar, och granska igen.

## Det här kontrollerar NONOX

- Organisationsnummer och personnummer, med kontrollsiffran. Ett personnummer visas bara med de sista fyra siffrorna i rapporten.
- Momsregistreringsnummer, e-postadress, landskod och valuta.
- Bankgiro, plusgiro, IBAN och BIC hos leverantörer.
- Belopp med decimalkomma eller decimalpunkt och mellanslag som tusentalsavgränsare. Ett belopp som kan läsas på två sätt, till exempel 1,234, avvisas.
- Att samma post inte finns två gånger i filen.

En post som redan finns, räknad på organisationsnummer eller personnummer, sedan på **Nummer i tidigare program** och sist på namn och postnummer, hoppas över och skrivs aldrig över. Kryssar du i **Fyll i tomma fält på poster som redan finns** fylls bara tomma fält i på kunder och leverantörer.

Posterna får nästa nummer i NONOX egen nummerserie. Numret från det gamla programmet sparas och visas som **Nummer i tidigare program** på kunden, leverantören och artikeln.

Filer kan vara CSV, TSV, text eller Excel (.xlsx), högst 20 MB och 100 000 rader. Celler som börjar med =, +, - eller @ läses som text och räknas aldrig ut.

## Obetalda fakturor

Obetalda kund- och leverantörsfakturor kan läsas in för företag som redovisar enligt fakturametoden. Läs in kunderna och leverantörerna först, så att fakturorna kan kopplas dit.

Filen ska ha en rad per faktura med kund eller leverantör, fakturanummer, fakturadatum, förfallodatum, fakturabelopp och obetalt belopp. Fakturor i annan valuta än kronor läses inte in här.

Fakturorna skapas **utan verifikation**. Saldot finns redan i bokföringen från SIE-filen, på 1510 för kunder och 2440 för leverantörer. Därför måste summan av **Obetalt enligt filen** vara lika med saldot, och under **Avstämning mot** ser du de två summorna och skillnaden. Skiljer de sig åt går det inte att läsa in, och du rättar filen. Stämningen görs om när du bekräftar, ifall bokföringen har ändrats.

När en inläst faktura betalas bokförs betalningen mot 1510 respektive 2440. Fakturorna kan inte ändras eller tas bort, bara betalas, krediteras eller skrivas av. Fakturanumret från det gamla programmet visas som **Nummer i tidigare program**, och fakturan får nästa nummer i NONOX serie.

> [!IMPORTANT]
> Använder företaget kontantmetoden kan obetalda fakturor inte läsas in här ännu. Hur de ska bokföras behöver en redovisningskonsult bedöma. Se [Kunder, leverantörer och obetalda fakturor](/manual/flytta/kunder-leverantorer-och-obetalda-fakturor).

## Underlag i en ZIP-fil

Välj **Underlag (ZIP)** för kvitton och andra underlag som hör till verifikationer du redan har läst in från en SIE-fil. Filerna kopplas till rätt verifikation på ett av två sätt:

- En fil som heter lank.csv i ZIP-filen, med kolumnerna filnamn;serie;nummer;ar.
- Mappar enligt ÅÅÅÅ/SERIE-NUMMER.pdf, till exempel 2026/A-17.pdf.

Filer som inte går att koppla räknas upp i rapporten med skälet, och gissas aldrig. Bara PDF, JPG, PNG och WebP tas emot, högst 10 MB per fil och 100 MB för hela ZIP-filen. Filer med namn som pekar utanför arkivet avvisas. Samma fil läggs aldrig två gånger på samma verifikation. Underlag på bokförda verifikationer kan läggas till men inte tas bort.

## Källfilerna sparas inte länge

Filen du laddar upp sparas krypterad. Den raderas automatiskt 30 dagar efter att den lästs in, eller efter 7 dagar om du aldrig läser in den. Importen med antal och kontrollsumma finns kvar i historiken.

## Vanliga frågor

**Jag ser inte Läs in register.**
Du behöver vara ägare, administratör eller redovisningskonsult i företaget.

**Siffrorna i filen stämmer inte med 1510 eller 2440.**
Läs rapporten: vilka rader har fel, och gäller bokföringen samma datum som listan? Rätta filen och ladda upp den igen.

**Filen har å, ä och ö som tecken som ser fel ut.**
Ändra **Teckenkodning** under **Så läste vi filen** och granska igen.

## Läs också

- [Från ett annat program](/manual/flytta/fran-andra-program)
- [Kunder, leverantörer och obetalda fakturor](/manual/flytta/kunder-leverantorer-och-obetalda-fakturor)
- [Stäm av](/manual/flytta/stam-av)
- [Kunder](/manual/fakturering/kunder)
