---
beskrivning: Dela upp bokföringen per restaurang, butik, avdelning eller uppdrag och se intäkter, kostnader och resultat för var och en.
ordning: 110
rutter: [accounting.costCenters, accounting.projects]
sokord: [kostnadsställe, projekt, avdelning, resultatenhet, dimension, objekt, kst, uppföljning, projektregister, projektledare, kontraktssumma]
---

# Kostnadsställen och projekt

Med kostnadsställen och projekt ser du hur det går för en del av företaget, inte bara för helheten. Ett [kostnadsställe](/manual/ordlista/ordlista#kostnadsstalle) är något som finns kvar, som en restaurang, en butik eller en avdelning. Ett projekt är ett uppdrag eller en satsning med ett slut, som en ombyggnad eller en kunds beställning. Du väljer dem per rad när du bokför, och sedan kan alla rapporter visa bara det kostnadsstället eller projektet.

Behöver du inte den uppdelningen kan du hoppa över det här helt. Kolumnerna syns inte i verifikationen förrän du har lagt upp något.

![Kostnadsställen med årets intäkter, kostnader och resultat.](/bilder/manual/bokforing/kostnadsstallen-och-projekt.webp)

## Så här lägger du till ett kostnadsställe

1. Gå till **Bokföring → Kostnadsställen**.
2. Under **Lägg till kostnadsställe**: skriv en **Kod**, till exempel `10` eller `MALMO`, och ett **Namn**, till exempel "Restaurang Malmö".
3. Välj **Lägg till**.

Projekt lägger du till på samma sätt under **Bokföring → Projekt**.

Koden får innehålla bokstäver utan å, ä och ö, siffror, punkt, bindestreck och understreck, högst 20 tecken. Den måste vara unik och går aldrig att ändra, eftersom gamla verifikationer pekar på den. Namnet kan du byta när du vill med **Byt namn**.

## Så här bokför du på ett kostnadsställe

1. Öppna en ny verifikation. Kolumnerna **Kostnadsställe** och **Projekt** finns nu mellan **Konto** och **Radtext**.
2. Skriv koden eller en del av namnet och välj i listan.
3. Fyll i det på de rader du vill följa upp – oftast intäkts- och kostnadsraderna. Raden mot banken behöver det sällan.

Du kan ha både ett kostnadsställe och ett projekt på samma rad.

## Se resultatet

På sidorna **Kostnadsställen** och **Projekt** visas **Intäkter**, **Kostnader** och **Resultat** för varje kostnadsställe och projekt under det räkenskapsår som pågår. Siffrorna bygger på bokförda rader på intäkts- och kostnadskonton. De syns för den som får se rapporterna.

Vill du se mer, välj kostnadsstället eller projektet som filter under **Bokföring → Rapporter**. Då visar resultatrapporten, huvudboken och de andra rapporterna bara de raderna. Se [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse).

För ett projekt väljer du **Projektresultat** på projektets rad. Då öppnas projektets sida med täckningsbidrag och marginal för hela projektet, jämfört med budgeten och med kontraktssumman och den beräknade kostnaden. Se [Projektresultat](/manual/rapporter/projektresultat).

## Projektregistret

Ett projekt kan ha fler uppgifter än kod och namn. Välj **Uppgifter** på projektets rad under **Bokföring → Projekt** och fyll i det du vill följa upp:

- **Status**: Planerat, Pågående, Avslutat eller Stängt. Statusen är till för dig och hindrar inte bokföringen. Vill du att projektet inte ska gå att välja längre väljer du **Inaktivera**.
- **Kund** och **Projektledare**. Projektledaren måste vara medlem i företaget. Lämnar personen företaget visas hen inte längre som projektledare.
- **Startdatum** och **Slutdatum**.
- **Prisform**: fast pris, löpande räkning eller internt projekt.
- **Kontraktssumma, kr** och **Beräknad total kostnad, kr**.
- **Kostnadsställe**, som föreslås på fakturarader med projektet.

Välj **Spara uppgifter**. Under projektets namn visas sedan status, kund, projektledare och de andra uppgifterna.

![Projektregistret med status, kund, projektledare och kontraktssumma.](/bilder/manual/bokforing/projektregistret.webp)

## Inaktivera

Välj **Inaktivera** när ett kostnadsställe stängs eller ett projekt är klart. Det går då inte att välja på nya verifikationer, men finns kvar på gamla och i rapporterna. **Aktivera** tar tillbaka det.

## Vem får göra vad

Alla som kan se verifikationerna kan se listorna. Ägare, administratörer och redovisningskonsulter kan lägga till, byta namn på och inaktivera, och fylla i projektens uppgifter. Bokförare och revisorer kan se projektregistret.

## Bra att veta

- Ingående balanser finns per konto, inte per kostnadsställe eller projekt. Filtrerar du en rapport på ett kostnadsställe räknas de ingående balanserna därför som noll.
- I SIE-filen blir kostnadsställen dimension 1 och projekt dimension 6, som andra program förväntar sig. Läser du in en SIE-fil med kostnadsställen eller projekt som inte finns här skapas de. Se [SIE-filer](/manual/bokforing/sie).
- På leverantörsfakturor kan du också välja kostnadsställe per rad.
- På fakturor, offerter och order väljer du kostnadsställe och projekt per rad, så att intäkterna hamnar på projektet. Se [Kostnadsställe och projekt på fakturor](/manual/fakturering/kostnadsstalle-och-projekt-pa-fakturor).

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Kostnadsställe och projekt på fakturor](/manual/fakturering/kostnadsstalle-och-projekt-pa-fakturor)
- [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse)
- [Resultatrapporten](/manual/rapporter/resultatrapport)
