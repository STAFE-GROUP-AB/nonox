<!-- UTKAST, publiceras inte. Sidan hör inte till LegalPageController::DOCUMENTS och visas inte på webbplatsen. -->
# Utkast: driftbolaget i de publika texterna (ADR-044)

Status: **utkast för granskning, inte publicerat.** Skrivet 2026-11-03.

Tjänsten drivs av ett av företagen i installationen, **driftbolaget**, som
väljs i HQ → Inställningar och bara står i databasen. När driftbolaget är
valt för den driftade tjänsten ska de publika texterna säga vem det är och
vad det gör med uppgifterna. Det här utkastet beskriver exakt vilka texter
som ändras och hur. Platshållarna `[DRIFTBOLAGETS NAMN]` och `[ORG.NR]`
fylls i av ägaren; i de publicerade texterna blir de i stället
platshållarna `{foretag}` och `{orgnr}`, som hämtas ur konfigurationen
(`app.landing.company_name`, `app.landing.org_number`). Inga namn skrivs i
koden eller i det här repot.

Redan publicerat (version 1.3, gäller från 2026-11-03), eftersom det
beskriver hur tjänsten redan fungerar: att köanmälningarna också finns i
tjänstens kundregister och CRM, att uppgifterna där raderas med anmälan
och att företaget och ägaren finns i kundregistret. Det står utan namn på
driftbolaget.

## 1. Konfigurationen

Innan texterna nedan publiceras:

- `app.landing.company_name` = `[DRIFTBOLAGETS NAMN]`
- `app.landing.org_number` = `[ORG.NR]`
- adress och e-post för dataskyddsfrågor (`app.landing.company_address`,
  `app.landing.privacy_email`) = driftbolagets.

TODO(legal-verify): att den som står som part i villkoren, som
personuppgiftsansvarig i integritetspolicyn och som biträde i
personuppgiftsbiträdesavtalet är samma juridiska person som HQ pekar ut som
driftbolag, och att den som fakturerar tjänsten är densamma.

## 2. Integritetspolicyn (`docs/juridik/integritetspolicy.md`)

**Avsnitt 1, Två roller, första punkten.** Nu:

> **Personuppgiftsansvarig:** för dig som besöker webbplatsen, ställer dig i
> kö, har ett konto och loggar in, och för vår egen kundrelation och
> fakturering är {foretag} personuppgiftsansvarig.

Ändras till:

> **Personuppgiftsansvarig:** för dig som besöker webbplatsen, ställer dig i
> kö, har ett konto och loggar in, och för vår egen kundrelation och
> fakturering är [DRIFTBOLAGETS NAMN] ([ORG.NR]) personuppgiftsansvarig.
> [DRIFTBOLAGETS NAMN] använder själv NONOX och har företagen som använder
> tjänsten i sitt kundregister och dem som står i kö i sitt CRM i NONOX.

**Avsnitt 2, tabellen, raden om kön.** Kolumnen Ändamål, nu "… finns därför
också i tjänstens kundregister och CRM, där vi väljer ut och kontaktar dem
som står i kö". Ändras till "… finns därför också i [DRIFTBOLAGETS NAMN]s
kundregister och CRM i NONOX, där vi väljer ut och kontaktar dem som står i
kö". Rättslig grund och lagringstid oförändrade.

**Avsnitt 2, tabellen, raden om företaget du företräder.** "… finns i
tjänstens kundregister …" ändras till "… finns i [DRIFTBOLAGETS NAMN]s
kundregister i NONOX …". Ny mening i samma cell: "Ägaren läggs in som
kontaktperson med avtalet som grund."

**Avsnitt 4, Vilka som får del av uppgifterna.** Ny första mening:

> Inom [DRIFTBOLAGETS NAMN] ser de som arbetar med kunder och support
> uppgifterna i kundregistret och CRM. Inga andra företag i tjänsten ser
> dem.

**Avsnitt 5, dina rättigheter, stycket om kön.** Oförändrat i sak; "vårt
kundregister och CRM" står redan.

**Versionsrad.** Ny version (1.4) och nytt datum. `LegalPageController::VERSION`
och `EFFECTIVE` ändras samtidigt.

## 3. Allmänna villkor (`docs/juridik/villkor.md`)

**Första stycket.** "… tjänsten NONOX som {foretag} ({orgnr}) driftar på
{webbplats}" blir oförändrat i form, men {foretag} och {orgnr} är nu
driftbolaget (avsnitt 1 ovan).

**Nytt stycke under betalning och fakturering** (eller där villkoren
beskriver fakturering):

> Kunden läggs in som kund i [DRIFTBOLAGETS NAMN]s kundregister i NONOX med
> företagets namn, organisationsnummer, adress och e-post, och ändringar
> Kunden gör av dessa uppgifter i tjänsten förs över dit. För en enskild
> firma sparas ägarens personnummer krypterat. Uppgifterna används för
> avtalet och faktureringen.

TODO(legal-verify): om överföringen av Kundens företagsuppgifter till
driftbolagets kundregister behöver stå i villkoren eller räcker i
integritetspolicyn, och om ändringar i villkoren kräver att befintliga
kunder godkänner på nytt (`agreement_version`).

## 4. Personuppgiftsbiträdesavtalet (`docs/juridik/personuppgiftsbitradesavtal.md`)

**Första stycket.** {foretag} ({orgnr}) som biträde är driftbolaget
(avsnitt 1). Ingen ändring i texten.

**Nytt stycke om gränsen mellan rollerna:**

> [DRIFTBOLAGETS NAMN] använder själv NONOX för sin egen kundrelation. Det
> sker som personuppgiftsansvarig för [DRIFTBOLAGETS NAMN]s egna uppgifter
> och omfattar bara uppgifter om den personuppgiftsansvarige som kund
> (företagets namn, nummer, adress, kontaktperson). Biträdet hämtar aldrig
> uppgifter ur den personuppgiftsansvariges bokföring, kundregister, CRM
> eller lön till sitt eget företag.

TODO(legal-verify): att den gränsen är rätt beskriven, och att översikten i
HQ (ADR-038 §6) och överföringen till kundregistret (ADR-044) behandlas som
driftbolagets egen behandling och inte som biträdesbehandling.

## 5. Registerförteckningen (`docs/gdpr/registerforteckning.md`)

Avsnitt A har redan raderna om driftbolagets kundregister och köns prospekt
utan namn. När driftbolaget är bestämt: förteckningens första rad (vem som
för den) ska vara [DRIFTBOLAGETS NAMN] ([ORG.NR]), och mottagarna "HQ-
administratörerna och driftbolagets användare" kan skrivas med namn.

## 6. Underbiträden (`docs/juridik/underbitraden.md`)

Ingen ändring: driftbolaget är inget underbiträde, och synken skickar inga
uppgifter utanför installationen.

## Frågor till jurist

1. TODO(legal-verify): samtycke som grund för köns prospekt i CRM, eller
   åtgärder före avtal (art. 6.1 b) ensamt; och om informationen i formuläret
   räcker som information enligt artikel 13 för CRM-behandlingen.
2. TODO(legal-verify): om ett prospekt som driftbolaget har arbetat med
   (aktivitet eller affär) får finnas kvar när den sökande begär radering av
   anmälan, eller ska raderas direkt (ADR-044 §4).
3. TODO(legal-verify): om ägarens e-post får användas som kundens e-post när
   företaget inte har angett någon.
4. TODO(legal-verify): om kontaktpersonen för en användare som raderar sitt
   konto ska raderas direkt ur driftbolagets CRM.
