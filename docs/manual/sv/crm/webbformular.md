---
beskrivning: Ett formulär på er webbplats som skapar ett prospekt, en kontaktperson med samtycke och en uppgift att följa upp.
ordning: 48
roller: [owner, admin, accountant, bookkeeper]
modul: crm
rutter: [crm.forms]
sokord: [webbformulär, kontaktformulär, lead, intresseanmälan, iframe, samtycke, formulär]
---

# Webbformulär

Ett webbformulär lägger ni på er egen webbplats. När någon fyller i det skapar NONOX ett prospekt och en kontaktperson, och en uppgift åt den som ska följa upp. Formuläret använder inga kakor och inga skript från andra.

![Webbformulär med ett formulär, antal anmälningar och koden för webbplatsen.](/bilder/manual/crm/webbformular.webp)

## Så här gör du ett formulär

1. Öppna **CRM → Webbformulär** och välj **Nytt formulär**.
2. Skriv en **Rubrik**. Namn och e-post finns alltid med. Välj för telefon, företag, organisationsnummer och meddelande om de ska vara **Visas inte**, **Frivilligt** eller **Obligatoriskt**.
3. Ändra vid behov **Text vid rutan för nyhetsbrev**. Rutan är frivillig och inte ikryssad.
4. Kontrollera att **Länk till er integritetspolicy** är ifylld under **CRM → Inställningar** (adressen börjar med `https://`). Utan den går formuläret inte att spara.
5. Välj vem som är **Ansvarig för nya leads** och vilka **Taggar på nya prospekt** de ska få.
6. Fyll i **Webbplatser som får visa formuläret**, en per rad, till exempel `https://www.exempel.se`.
7. Välj **Spara formuläret**.

## Lägg in formuläret på webbplatsen

Öppna **Lägg in på er webbplats** under formuläret och kopiera koden. Den består av en iframe och ett litet valfritt skript som anpassar höjden. Bara webbplatserna du har fyllt i kan visa formuläret. Formuläret har också en egen adress hos NONOX som du kan länka till.

## Det här händer när någon skickar in

- Formuläret säger att ert företag använder uppgifterna för att svara på förfrågan och länkar till er integritetspolicy. Någon ruta för samtycke behövs inte för det.
- Är adressen ny skapas ett **prospekt** (ett företag om företagsnamnet är ifyllt, annars en privatperson) och en **kontaktperson** med rättslig grund **berättigat intresse** ("Kontaktförfrågan via webbformuläret").
- Kryssar personen i rutan för nyhetsbrev blir grunden **samtycke** med dagens datum, och personen får **Samtycke till e-postreklam**, som behövs för utskick till privatpersoner.
- Texten vid rutan sparas precis som den visades, med version, om rutan var ikryssad och länken till integritetspolicyn. Ändrar ni texten får den en ny version.
- Den ansvarige får en **uppgift** till nästa morgon med meddelandet, och ett mejl om att en ny intresseanmälan har kommit. Mejlet innehåller inga uppgifter om personen.
- Finns adressen redan i CRM skapas ingen dubblett, och personens rättsliga grund ändras inte, eftersom vem som helst kan skriva in vems adress som helst. Kryssar personen i rutan för nyhetsbrev visar personens sida **Anmälde sig till nyhetsbrev via formulär** med datumet. Välj **Bekräfta** när du vet att det var personen själv; först då får personen samtycke till e-postreklam. En avregistrering, invändning eller begränsning ändras aldrig av formuläret. Är personen märkt **Kontakta inte** sparas bara anmälan: ingen uppgift och inget i historiken.

## Skräppost

Formuläret stoppar robotar utan skript från andra: ett dolt fält, en kontroll av hur snabbt formuläret skickas och en gräns för hur många anmälningar som tas emot från samma uppkoppling. Det som stoppas får samma tack-sida men sparas inte.

## Anmälningar som väntar på granskning

Formuläret har också en gräns för hur många anmälningar det tar emot per timme, och ert företag har en gemensam gräns för alla formulär tillsammans. Kommer det fler än så, till exempel när någon fyller ett formulär med skräp, stoppas inte de riktiga anmälningarna. De väntar i stället på granskning:

- Inget prospekt, ingen kontaktperson och ingen uppgift skapas, och den ansvarige får inget mejl.
- Den som får ändra CRM-inställningarna får en notis och en rad under **Att göra** på översikten, högst en notis per dag.
- Överst på **CRM → Webbformulär** visas **Anmälningar att granska** med namn, e-post, formulär, tid och meddelande.
- Välj **Ta emot** för en riktig anmälan. Då skapas prospekt, kontaktperson och uppgift som vanligt, med samtycket, texten och formulärets namn från när formuläret skickades.
- Välj **Ta bort** för skräp. Det som skrevs raderas.
- Det som ingen har granskat tas bort automatiskt efter 14 dagar.
- Väntar 500 anmälningar redan sparas inga fler, och ni får en notis om det.

Den som får ändra CRM-inställningarna kan granska.

## Bra att veta

- Ett formulär som har tagit emot anmälningar kan bara stängas, inte tas bort: samtyckena ska gå att visa.
- Organisationsnumret sparas bara om det är ett giltigt nummer för ett företag, aldrig ett personnummer.
- Ni som företag ansvarar för uppgifterna och för er integritetspolicy. NONOX behandlar dem för er räkning.

## Läs också

- [Utskick](/manual/crm/utskick)
- [Företag och personer](/manual/crm/foretag-och-personer)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
