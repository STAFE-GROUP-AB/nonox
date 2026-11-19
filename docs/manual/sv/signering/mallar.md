---
beskrivning: Spara en signering som mall med roller, steg, kontroller, fält och inställningar, och skicka nästa dokument genom att bara ange vem som tar varje roll. Det finns också mallar för offerter och avtal.
ordning: 20
roller: [owner, admin, accountant, bookkeeper, sales]
modul: signing
rutter: [signing.templates, signing.template, signing.template.new]
sokord: [mall, mallar, roller, roll, kund, säljare, spara som mall, använd mall, arkivera mall, offertmall, avtalsmall, återanvända, signeringsmall]
---

# Mallar för signering

En mall sparar det du gör om och om igen: dokumentet, **rollerna** (till exempel Kund och Säljare) med steg och kontroll, fälten för varje roll och inställningarna för sista dag, påminnelser, kopior och språk. När du använder mallen anger du bara vem som tar varje roll. En mall innehåller aldrig någon undertecknares e-postadress.

![Listan Mallar för signering.](/bilder/manual/signering/mallar.webp)

## Spara en mall

1. Gå igenom guiden **Skicka dokument** som vanligt. Skriv under **Undertecknare** en **Roll (frivilligt)** för var och en, till exempel Kund.
2. Placera fälten under **Fält i dokumentet** om du vill. Varje fält hör till en roll.
3. På sista steget, **Granska och skicka**, finns **Spara som mall**. Skriv **Mallens namn** och tryck på **Spara som ny mall**.

Mallen sparar en egen kopia av PDF-filen. Du kan skicka dokumentet nu eller lämna guiden.

## Använd en mall

- **Eget dokument:** öppna **Signering → Mallar** och tryck på **Använd**, eller välj **Använd mallen** under **Börja från en mall** i första steget av guiden. Du kommer till **Undertecknare** med en rad per roll, rollernas steg, kontroller och fält och mallens inställningar färdiga. Skriv **E-post** för varje roll.
- **Offert eller avtal:** öppna guiden med **Fler undertecknare** och välj mallen under **Använd en mall**. Fälten placeras inte i offerter och avtal, bara rollerna och inställningarna används.
- Är en roll markerad som företagets egen kan du välja **Föreslå en medlem** så fylls medlemmens adress i.

![Första steget i guiden med mallar att börja från.](/bilder/manual/signering/skicka-fran-mall.webp)

Ett dokument som du laddat upp själv får mallens roller och inställningar, men aldrig mallens fält: fälten passar bara mallens egen PDF. Du kan ändra allt i guiden innan du skickar. Signeringen kommer ihåg vilken mall den kom från.

## Mallar för offerter och avtal

Tryck på **Ny mall för offert eller avtal** under **Signering → Mallar**. Välj **Mallen är för**, skriv **Mallens namn** och ange **Roller**: namn, **Steg**, **Kontroll av undertecknaren** och om rollen är **Företagets egen roll**. Under **Inställningar** väljer du dagar att signera, påminnelser, språk och kopiemottagare.

![Formuläret för en mall.](/bilder/manual/signering/mall-roller.webp)

## Ändra, arkivera och ta bort

- **Ändra** öppnar mallen. Du kan byta namn, roller och inställningar. Byter du namn på en roll behåller den sina fält, och tar du bort en roll försvinner dess fält. PDF-filen ändras aldrig: behöver du en annan fil, spara en ny mall och arkivera den gamla. Fälten ändras med **Ändra fält**, som öppnar guiden, och sedan **Uppdatera mallen** på sista steget.
- **Arkivera** tar bort mallen ur valen. Signeringar som redan gjorts med den ändras inte. Under **Arkiverade** kan du **Återställa** den.
- **Ta bort** går bara för en mall som aldrig har använts. En använd mall kan bara arkiveras.

## Vem får göra vad

Alla med behörigheten att skicka signeringar kan se och använda mallarna och skapa egna. Du ändrar, arkiverar och tar bort mallar du själv skapat. Den som får hantera signering ändrar allas.
