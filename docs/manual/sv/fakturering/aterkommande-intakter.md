---
beskrivning: NONOX SaaS – MRR, ARR, rörelser per månad, churn, kvarhållen intäkt och kohorter ur dina avtal och din avtalsfakturering, med varje belopp spårbart till kunden.
ordning: 170
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: saas
rutter: [saas.overview, saas.movements, saas.movements.show, saas.customers, saas.customers.show, saas.cohorts, saas.scheduled, saas.sources]
sokord: [MRR, ARR, churn, återkommande intäkter, abonnemang, prenumeration, SaaS, NRR, GRR, kohorter, ARPA, kundtapp, intäktstapp, månadsintäkt]
---

# Återkommande intäkter

Säljer du abonnemang, licenser eller serviceavtal vill du veta hur den återkommande intäkten rör sig: hur mycket som kommer in varje månad, vilka kunder som har tillkommit, ökat, minskat eller slutat, och hur mycket av förra årets intäkt som finns kvar. Modulen **NONOX SaaS** räknar fram det ur dina avtal i avtalsfaktureringen och, om CRM är påslaget, ur avtalsregistret. Varje belopp går att öppna ända ner till kunden och avtalet bakom det.

![Översikten under Återkommande intäkter med MRR, ARR, kunder, churn och bryggan från förra månaden.](/bilder/manual/fakturering/aterkommande-intakter.webp)

> [!NOTE]
> Siffrorna är nyckeltal för styrning, inte bokföring. Modulen bokför ingenting, och siffrorna används inte i momsdeklarationen, årsredovisningen eller inkomstdeklarationen.

## Så här slår du på modulen

1. Gå till **Inställningar för företaget → Moduler**.
2. Slå på **NONOX SaaS**.
3. Gå till **Återkommande intäkter** i menyn. De avtal som är aktiva eller pausade läses in direkt.

Siffrorna är kompletta från den månad du slår på modulen. Månaderna innan finns bara som en uppskattning ur de fakturor som avtalsfaktureringen redan har ställt ut, och de märks **Uppskattad**.

## Vad siffrorna betyder

- **MRR** är det avtalade återkommande beloppet utan moms vid månadens sista dag, omräknat till en månad. Ett kvartalsavtal på 900 kr blir 300 kr i månaden och ett årsavtal på 12 000 kr blir 1 000 kr. Rabatter på raderna är med.
- **ARR (årstakt)** är MRR gånger 12. Det är ingen prognos.
- **Kunder** är kunderna med MRR vid månadens slut. Flera avtal med samma kund räknas som en kund.
- **ARPA** är MRR per kund.
- **Kundchurn** är andelen av förra månadens kunder som slutade under månaden.
- **Brutto intäktschurn** är minskningar och churn delat med förra månadens MRR. **Netto intäktschurn** drar också av utökningarna; blir den negativ har du *negativ churn*.
- **NRR (12 mån)** och **GRR (12 mån)** visar hur mycket av MRR:en från kunderna för tolv månader sedan som finns kvar i dag, med och utan utökningar. De räknas med samma valutakurser för båda åren.

Engångsbelopp, fakturor som inte kommer från ett avtal och kreditfakturor ändrar inte MRR. Det gör bara en ändring av själva avtalet.

## Bryggan från förra månaden

Kortet **Från förra månadens MRR till denna** visar hur förra månadens MRR blev denna månads, kund för kund:

| Rad | Betyder |
|---|---|
| **Ny** | En kund som aldrig har haft MRR förut. |
| **Återaktivering** | En kund som har haft MRR, slutade och kom tillbaka. Den räknas aldrig som ny och stannar i sin första kohort. |
| **Återupptagen** | Ett pausat avtal som är igång igen. |
| **Utökning** och **Minskning** | En befintlig kund som betalar mer eller mindre. |
| **Paus** | Alla kundens avtal är pausade. En paus är ingen churn, men en paus som varar längre än tolv månader räknas som churn i den trettonde månaden. |
| **Churn** | En kund som inte har någon MRR kvar. |
| **Valutaeffekt** | Avtal i andra valutor, omräknade med Riksbankens kurs vid månadens slut. Kursändringen syns här och aldrig som minskning eller utökning. |
| **Rättelse** | Det som blev känt efter att förra månaden stängdes. Läs [Stäng en månad i Återkommande intäkter](/manual/fakturering/stang-en-manad-i-aterkommande-intakter). |

Raderna summerar till öret. Tryck på en rad så ser du kunderna bakom beloppet och avtalen bakom varje kund, och **Ladda ner CSV** ger samma lista som en fil.

## När en kund säger upp sig

En uppsägning är inte churn. Kunden betalar perioden ut, så intäkten räknas som churn först den månad då avtalet har upphört. Fram till dess står avtalet under **Uppsagda** med beloppet och månaden det upphör.

I avtalsfaktureringen räknas ett avtal med **Sista dag (frivilligt)** fram till nästa fakturadatum efter den dagen, det vill säga till slutet av den sista betalda perioden. Ett avtal som du avslutar räknas fram till det datum du anger i **Gäller från**. Avslutar du ett pausat avtal räknas det pausade beloppet som churn den månad avtalet upphör.

## Gäller från

När NONOX SaaS är påslaget har avtalet i avtalsfaktureringen fältet **Gäller från**. Där anger du från vilket datum en prisändring, en paus eller en avslutning gäller. Tomt betyder i dag. Fyll i datumet innan du trycker på **Spara avtalet**, **Pausa**, **Aktivera** eller **Ja, avsluta**.

## Sidorna

- **Översikt** – nyckeltalen för en månad, bryggan och MRR per månad. Välj månad uppe till höger.
- **Rörelser** – en rad per månad och en kolumn per rad i bryggan.
- **Kunder** – varje kund med MRR, förändringen under månaden, kohort, källa och status. Kundens egen sida visar MRR månad för månad, varje version av avtalen med när den gäller och när den registrerades, och fakturorna från avtalen.
- **Kohorter** – kunderna grupperade efter månaden då de fick sin första MRR, med andelen kunder eller intäkt som finns kvar månad för månad.
- **Uppsagda** – avtal som är uppsagda men fortfarande löper.
- **Rättelser** och **Månader** – se [Stäng en månad i Återkommande intäkter](/manual/fakturering/stang-en-manad-i-aterkommande-intakter).
- **Källor** – det som inte kan räknas med och varför, till exempel ett avtal i avtalsregistret vars kundkonto inte är kopplat till en kund.

![Rörelser per månad med en kolumn per rad i bryggan.](/bilder/manual/fakturering/aterkommande-intakter-rorelser.webp)

## Bra att veta

- Ett avtal i avtalsregistret som är kopplat till en avtalsfakturering räknas bara en gång, i avtalsfaktureringen. Har en kund både ett avtal i registret och en avtalsfakturering som inte är kopplade visas de under **Källor** som **Möjlig dubbelräkning**, så att du kan koppla dem.
- Ett nytt avtal i avtalsfaktureringen räknas från sitt första fakturadatum, om du inte anger något annat i **Gäller från**.
- Saknar Riksbanken en kurs vid månadens slut används den senaste kända kursen, och månaden kan inte stängas förrän kursen finns.
- Ett avtal i avtalsregistret med ett totalvärde räknas om det har både start- och slutdatum. Värdet delas då med antalet månader.
- Ett ramavtal har inget bestämt belopp och räknas inte.
- Bokföraren och revisorn kan läsa sidorna. Ägaren, administratören och redovisningskonsulten kan också stänga månader.

## Läs också

- [Stäng en månad i Återkommande intäkter](/manual/fakturering/stang-en-manad-i-aterkommande-intakter)
- [Avtalsfakturering](/manual/fakturering/avtalsfakturering)
- [Kunder](/manual/fakturering/kunder)
