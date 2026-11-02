---
beskrivning: När Skatteverket kommer på kontrollbesök – visa vem som är incheckad och liggaren, på kiosken eller i NONOX, och ladda ner den som PDF, CSV eller XML.
ordning: 80
roller: [owner, admin, staff_ledger_manager, accountant]
modul: staff_ledger
rutter: [staff-ledger.control-visit, staff-ledger.control-visit.export]
sokord: [Skatteverket är här, kontrollbesök, oannonserat besök, revision, exportera personalliggare, USB, XML, PDF, CSV]
---

# Kontrollbesök

Skatteverket kan komma på oannonserat kontrollbesök och be att få se personalliggaren. Då ska det gå fort. I NONOX finns knappen **Skatteverket är här** på två ställen: på kiosken vid entrén och överst på varje sida i personalliggaren. Båda visar vem som är incheckad just nu och liggaren, med fullständiga personnummer.

![Sidan för kontrollbesök: vem som är incheckad och liggaren att ladda ner.](/bilder/manual/personalliggare/kontrollbesok.webp)

## Så här gör du på kiosken

Det här fungerar även utan internet.

1. Tryck **Skatteverket är här** på kioskens startsida.
2. Skriv koden för en person som är markerad som **Chef** under **Personalliggare → Personal**.
3. Kiosken visar **Incheckade just nu** och **Liggaren** med namn, personnummer, arbetsgivare, början och slut. Sök med **Sök namn eller personnummer**.
4. Vill handläggaren ha en kopia: sätt i ett USB-minne eller anslut surfplattan, och tryck **Ladda ner till USB**.
5. Tryck **Stäng** när ni är klara.

Kiosken visar de senaste 31 dagarna. Är den utan internet visar den liggaren som finns sparad på surfplattan och säger det.

**Ladda ner till USB** sparar en ZIP-fil med liggaren som CSV, de registreringar som ännu inte skickats från kiosken och en fil som förklarar innehållet.

> [!TIP]
> Markera minst en person per lokal som **Chef**, och se till att den som brukar vara på plats kan sin kod. Fem fel koder i rad låser kontrollbesöket på kiosken i fem minuter.

## Så här gör du i NONOX

1. Välj **Skatteverket är här** överst på valfri sida i personalliggaren, eller **Personalliggare → Kontrollbesök** i menyn.
2. Överst ser du **Incheckade just nu** med namn, person- eller samordningsnummer, arbetsgivare, lokal och när personen checkade in.
3. Under **Liggaren** väljer du period med **Från** och **Till**. Förvalt är i dag. Välj en **Lokal** om ni har flera, och sök med **Sök namn eller personnummer**.
4. Välj **Ladda ner PDF**, **Ladda ner CSV** eller **Ladda ner XML**.

Tabellen visar ett arbetspass per rad med början och slut. Saknas en tid står det **saknas**, och är personen fortfarande på plats står det **incheckad**. Pass med en rättelse eller manuell registrering har anmärkningen **Rättad eller manuell**. Vill du se varje enskild registrering väljer du **Visa alla registreringar**. Exporterna innehåller alla registreringar under perioden, med rättelser.

Du kan välja en period på högst 13 månader åt gången.

## Elektronisk överföring

Knappen **Överför till Skatteverket** finns, men Skatteverket tar ännu inte emot personalliggare elektroniskt. Ladda ner XML-filen och lämna den till handläggaren i stället. XML-filen följer NONOX:s eget, preliminära format tills Skatteverket har publicerat sitt.

## Det här sparas

Varje gång kontrollbesöket öppnas, på kiosken eller i NONOX, och varje gång något laddas ner, sparas det i behandlingshistoriken med vem som gjorde det.

## Vem kan öppna kontrollbesöket

- På kiosken: den som är markerad som **Chef** och har en kod.
- I NONOX: ägare, administratörer, personalansvariga och redovisningskonsulter. De loggar in med passkey, eftersom vyn visar alla personnummer i sin helhet.

Kontrollbesöket går att öppna även efter att modulen har stängts av, så länge liggaren ska sparas.

## Läs också

- [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser)
- [Kiosken på surfplattan](/manual/personalliggare/kiosken)
- [Bevarande och personuppgifter](/manual/personalliggare/bevarande-och-personuppgifter)
