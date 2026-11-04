---
beskrivning: Ett formulär på er webbplats som skapar ett prospekt, en kontaktperson med samtycke och en uppgift att följa upp.
ordning: 48
roller: [owner, admin, accountant, bookkeeper]
modul: crm
rutter: [crm.forms]
sokord: [webbformulär, kontaktformulär, lead, intresseanmälan, iframe, samtycke, formulär]
---

# Webbformulär

Ett webbformulär lägger ni på er egen webbplats. När någon fyller i det skapar NONOX ett prospekt och en kontaktperson med samtycke, och en uppgift åt den som ska följa upp. Formuläret använder inga kakor och inga skript från andra.

![Webbformulär med ett formulär, antal anmälningar och koden för webbplatsen.](/bilder/manual/crm/webbformular.webp)

## Så här gör du ett formulär

1. Öppna **CRM → Webbformulär** och välj **Nytt formulär**.
2. Skriv en **Rubrik**. Namn, e-post och en ruta för samtycke finns alltid med. Välj för telefon, företag, organisationsnummer och meddelande om de ska vara **Visas inte**, **Frivilligt** eller **Obligatoriskt**.
3. Skriv **Samtyckestext**: vad ni gör med uppgifterna, till exempel om ni skickar nyhetsbrev.
4. Fyll i **Länk till er integritetspolicy** (adressen börjar med `https://`).
5. Välj vem som är **Ansvarig för nya leads** och vilka **Taggar på nya prospekt** de ska få.
6. Fyll i **Webbplatser som får visa formuläret**, en per rad, till exempel `https://www.exempel.se`.
7. Välj **Spara formuläret**.

## Lägg in formuläret på webbplatsen

Öppna **Lägg in på er webbplats** under formuläret och kopiera koden. Den består av en iframe och ett litet valfritt skript som anpassar höjden. Bara webbplatserna du har fyllt i kan visa formuläret. Formuläret har också en egen adress hos NONOX som du kan länka till.

## Det här händer när någon skickar in

- Är adressen ny skapas ett **prospekt** (ett företag om företagsnamnet är ifyllt, annars en privatperson) och en **kontaktperson** med rättslig grund **samtycke** och dagens datum.
- Samtyckestexten sparas precis som den visades, med version och länk till integritetspolicyn, så att ni kan visa vad personen godkände. Ändrar ni texten får den en ny version.
- Den ansvarige får en **uppgift** till nästa morgon med meddelandet, och ett mejl om att en ny intresseanmälan har kommit. Mejlet innehåller inga uppgifter om personen.
- Finns adressen redan i CRM skapas ingen dubblett, och personens rättsliga grund ändras inte, eftersom vem som helst kan skriva in vems adress som helst. Anmälan sparas med samtyckestexten, så att du kan ändra grunden för hand när du vet att det var personen själv. En avregistrering, invändning eller begränsning ändras aldrig av formuläret.

## Skräppost

Formuläret stoppar robotar utan skript från andra: ett dolt fält, en kontroll av hur snabbt formuläret skickas och en gräns för hur många anmälningar som tas emot. Det som stoppas får samma tack-sida men sparas inte.

## Bra att veta

- Ett formulär som har tagit emot anmälningar kan bara stängas, inte tas bort: samtyckena ska gå att visa.
- Organisationsnumret sparas bara om det är ett giltigt nummer för ett företag, aldrig ett personnummer.
- Ni som företag ansvarar för uppgifterna och för er integritetspolicy. NONOX behandlar dem för er räkning.

## Läs också

- [Utskick](/manual/crm/utskick)
- [Företag och personer](/manual/crm/foretag-och-personer)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
