---
beskrivning: NONOX skriver ner allt som görs i bokföringen – vem, när och vad. Så fungerar behandlingshistoriken och var du hittar den.
ordning: 160
sokord: [behandlingshistorik, logg, ändringslogg, audit log, spårbarhet, vem gjorde vad, historik, BFNAR 2013:2]
---

# Behandlingshistorik

[Behandlingshistoriken](/manual/ordlista/ordlista#behandlingshistorik) är företagets logg över allt som görs i bokföringen: vem som bokförde, rättade, lade till ett konto, låste en period eller läste in en SIE-fil, och när. Den skrivs automatiskt – du behöver aldrig göra något för att den ska bli komplett – och den kan aldrig ändras i efterhand.

## Vad som skrivs ner

Varje händelse sparas med vem som gjorde den, tidpunkt, vad det gällde och, när något ändrades, värdena före och efter. Dessutom sparas IP-adress och webbläsare. Bland annat skrivs det här:

- att en verifikation bokförs, med nummer, datum, belopp och kontrollsumma,
- att en verifikation rättas, med anledningen,
- att underlag läggs till på en verifikation, eller tas bort från ett utkast,
- nya och ändrade konton i kontoplanen,
- nya räkenskapsår, ändrade datum och balanser som förs över,
- att en period låses, med datumet före och efter,
- varje [kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen), med resultatet,
- varje granskad och inläst SIE-fil och varje nedladdad SIE-fil,
- ändringar av företagets uppgifter, inbjudningar och medlemmar och deras roller,
- in- och utloggningar,
- när en ny version av NONOX tas i bruk, med versionsnummer och datum. Den raden gäller alla företag i installationen och följer med i varje företags [arkivpaket](/manual/bokslut/arkivpaket).

Det mesta som bokförs automatiskt – fakturor, betalningar, lön, moms – skrivs också, eftersom det bokförs på samma sätt som det du bokför för hand.

## Den kan inte ändras

Historiken går bara att lägga till i. Det finns ingen funktion för att ändra eller ta bort en rad, och databasen själv vägrar om någon försöker gå runt programmet. Raderar en användare sitt konto står det "Raderad användare" i stället för namnet, men händelserna finns kvar, eftersom bokföringslagen kräver att räkenskapsinformationen sparas.

## Var du hittar den

- **I arkivpaketet.** Varje arkivpaket innehåller mappen `behandlingshistorik` med allt som gjorts i företaget från räkenskapsårets första dag tills paketet skapades, både som CSV-fil som går att öppna i Excel och som JSON. Se [Arkivpaket](/manual/bokslut/arkivpaket).
- **På verifikationen.** Varje bokförd verifikation visar vem som bokförde den och när, och om den är rättad och med vad.
- **Dina egna händelser.** Under din profil kan du välja **Ladda ner mina uppgifter**. Utdraget innehåller bland annat din egen behandlingshistorik.
- **Om du har en redovisningsbyrå.** Är företaget kopplat till en byrå visar **Översikt** vad byrån har gjort i bokföringen de senaste 60 dagarna, hämtat ur behandlingshistoriken. Se [För redovisningsbyråer](/manual/byra).

> [!NOTE]
> Det finns ännu ingen sida i appen där hela behandlingshistoriken kan bläddras och sökas. Behöver du den, till exempel inför en revision, skapar du ett arkivpaket för året.

## Vem får se den

I arkivpaketet ser den som får skapa arkivpaket hela historiken: ägare, administratörer, redovisningskonsulter och revisorer.

## Varför den finns

Bokföringsnämndens allmänna råd om bokföring (BFNAR 2013:2) beskriver krav på systemdokumentation och behandlingshistorik. Hur NONOX uppfyller dem beskrivs i systemdokumentationen, som också följer med i arkivpaketet. Vill du veta vad just ert företag behöver spara, fråga din redovisningskonsult eller revisor.

## Läs också

- [Kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Arkivpaket](/manual/bokslut/arkivpaket)
- [Inställningar och säkerhet](/manual/installningar)
