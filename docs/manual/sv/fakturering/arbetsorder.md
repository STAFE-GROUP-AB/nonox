---
beskrivning: NONOX Arbetsorder – jobb hos kunder med tid och material, ROT eller RUT, omvänd byggmoms, fast eller löpande pris och rätt utförare.
ordning: 160
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: work_orders
rutter: [workOrders.index, workOrders.create, workOrders.edit, workOrders.show, workOrders.settings]
sokord: [arbetsorder, hantverkare, jobb, tid och material, löpande räkning, fast pris, ungefärligt pris, utförare, projekt, portkod, byggtjänst]
---

# Arbetsorder

En arbetsorder är ett jobb hos en kund: badrummet hos familjen, elen i trapphuset eller väggen åt huvudentreprenören. Utförarna registrerar tid och material på arbetsordern i mobilen, och när det är dags att fakturera blir arbetsordern ett fakturautkast. En arbetsorder bokför ingenting själv.

Arbetsorder är en modul. Ägaren slår på den under **Inställningar → Moduler**. Då läggs typerna av arbete **Arbete** och **Resa** upp, och rollen **Utförare** går att ge till dem som jobbar ute hos kunderna.

## Skapa en arbetsorder

1. Gå till **Arbetsorder** och klicka på **Ny arbetsorder**.
2. Välj **Kund** och skriv en **Rubrik**, till exempel Badrumsrenovering. Rubriken blir typ av arbete på fakturan.
3. Under **Platsen** skriver du adressen och, om det behövs, **Portkod och nycklar**. De sparas krypterat och tas bort automatiskt när arbetsordern avslutas.
4. Välj **Prisform** under **Pris**:
   - **Löpande räkning** – timmar till priset för varje typ av arbete, och material till försäljningspriset.
   - **Fast pris** – skriv **Fast pris exklusive moms** och, för ROT eller RUT, **Varav arbete exklusive moms**.
   - **Löpande räkning med ungefärligt pris** – skriv **Ungefärligt pris inklusive moms**. NONOX varnar när huvudarbetet går över 15 procent av det, eller över **Annan avtalad gräns, procent** om ni har kommit överens om en annan.
5. Bocka i vilka som är **Utförare** på jobbet och klicka på **Spara arbetsordern**.

Ett projekt skapas för varje arbetsorder med koden AO och arbetsorderns nummer, så att leverantörsfakturor, tidrapporter i lönen och verifikationer kan kopplas till jobbet. Det går att stänga av under **Arbetsorder → Inställningar**.

## ROT, RUT och omvänd byggmoms

- **Omvänd betalningsskyldighet** följer kunden. Är kunden markerad för omvänd betalningsskyldighet för byggtjänster blir hela arbetsordern det, ÄTA inräknade, och det går inte att välja ROT eller RUT.
- **ROT och RUT** gäller privatpersoner med personnummer. ROT kräver **Fastighetsbeteckning**, eller bostadsrättsföreningens organisationsnummer och **Lägenhetsnummer**.
- Delar flera köpare på avdraget, till exempel makar, klickar du på **Lägg till köpare** och skriver varje köpares **Andel, procent**. Varje köpare har sitt eget tak för året.

Läs mer om avdragen i [ROT- och RUT-avdrag](/manual/fakturering/rot-och-rut) och om byggmomsen i [Omvänd betalningsskyldighet i byggsektorn](/manual/fakturering/omvand-betalningsskyldighet-bygg).

## Status

En arbetsorder går från **Utkast** till **Planerad**, **Pågår**, **Klar** och **Avslutad**. Tid och material registreras när den är planerad eller pågår. En arbetsorder kan bara avslutas när allt som går att debitera är fakturerat. En arbetsorder utan tid och material kan **Makuleras**.

## Varningar på arbetsordern

- **Ungefärligt pris**: när huvudarbetet går över gränsen. Godkända ÄTA räknas inte in.
- **Sista fakturadag för byggtjänster**: ofakturerat arbete ska faktureras senast vid utgången av andra månaden efter den månad arbetet gjordes. Varningen syns från första dagen i den månaden.
- **Kan kräva personalliggare**: när kunden är ett företag och det förväntade värdet är högre än fyra prisbasbelopp. Personalliggaren för byggarbetsplatser finns inte i NONOX än.

## Inställningar

Under **Arbetsorder → Inställningar** finns **Skapa ett projekt för varje arbetsorder**, **Påslag på material, procent**, **Metod för fastprisuppdrag vid bokslut** och **Typer av arbete**. Bara typer som är **Arbete** kan ge ROT- eller RUT-avdrag. En **Resa** räknas per resa eller per kilometer och ger aldrig avdrag.
