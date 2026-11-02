# Konsekvensbedömning: personalliggare (mall, artikel 35)

Senast uppdaterad 2026-11-01. Version 0.1 (utkast).

**Det här är en mall.** Företaget som för personalliggaren är personuppgiftsansvarigt och ansvarar för konsekvensbedömningen (artikel 35.1). Stafe Development AB är personuppgiftsbiträde för den driftade tjänsten på nonox.app och bistår med den information som behövs (artikel 28.3 f). Mallen beskriver vad NONOX gör. Fält inom hakparentes, till exempel **[fylls i av företaget]**, beskriver företagets egen verksamhet och måste fyllas i av företaget. Den som kör NONOX på en egen server är själv ansvarig även för drift och säkerhetskopior.

TODO(legal-verify): om personalliggaren kräver en konsekvensbedömning för varje företag. Den kan omfattas eftersom anställda övervakas systematiskt och anställda är i underläge mot arbetsgivaren (artikel 35.1 och 35.3 samt IMY:s förteckning enligt artikel 35.4). Mot det talar att behandlingen är föreskriven i lag. Mallen bör ses över av jurist innan den lämnas till kunder.

## 1. Behandlingen

| | |
|---|---|
| **Personuppgiftsansvarig** | [Företagets namn och organisationsnummer] |
| **Kontaktperson för dataskydd** | [Namn, e-post] |
| **Lokaler och bransch** | [Lokaler där liggare förs, bransch enligt 39 kap. skatteförfarandelagen] |
| **Biträde** | Stafe Development AB (NONOX på nonox.app), eller ingen om företaget kör NONOX själv |
| **Ändamål** | Att föra den elektroniska personalliggare som skatteförfarandelagen kräver och visa den vid Skatteverkets kontrollbesök. Om företaget väljer det: att föreslå tidrapporter till lönen ur liggarens pass (se avsnitt 6). |
| **Rättslig grund** | Rättslig förpliktelse (artikel 6.1 c) enligt 39 kap. skatteförfarandelagen (2011:1244), med de ändringar som gäller från den 1 januari 2027 (prop. 2025/26:282). För foto: samtycke (artikel 6.1 a), se avsnitt 4. |
| **Personnummer** | Får behandlas utan samtycke när det är klart motiverat med hänsyn till ändamålet, vikten av en säker identifiering eller något annat beaktansvärt skäl (3 kap. 10 § lagen (2018:218) med kompletterande bestämmelser till EU:s dataskyddsförordning). Liggaren ska identifiera varje person, och personnumret är den identitetsuppgift som lagen förutsätter. |

TODO(legal-verify): vilka paragrafer i 39 kap. skatteförfarandelagen och vilka bestämmelser i skatteförfarandeförordningen och Skatteverkets föreskrifter som anger skyldigheten, uppgifterna och bevarandetiden efter ändringarna 2027. Mallen anger kapitlet men inte exakta paragrafer.

## 2. Uppgifter och registrerade

**Registrerade:** alla som arbetar i lokalen: egna anställda, inhyrd personal, personal hos andra arbetsgivare och företagets ägare när de arbetar där. [Antal personer och lokaler.]

| Uppgift | Varför | Var den finns |
|---|---|---|
| Namn | Liggaren ska visa vem som arbetar | Registret och varje post |
| Person- eller samordningsnummer, eller utländskt ID-nummer med land för den som saknar svenskt nummer | Identifiering enligt 9 kap. 5 § skatteförfarandeförordningen ("motsvarande utländska nummer") | Krypterat i databasen. Maskerat i alla listor (ett utländskt nummer döljs helt). |
| Arbetsgivarens namn och organisationsnummer, eller utländskt nummer med land | Inhyrd personal och personal hos andra arbetsgivare | Registret och varje post |
| Tid för in- och utcheckning per lokal | Liggarens innehåll | Varje post, med kioskens tid och serverns mottagningstid |
| Rättelser och manuella registreringar med skäl, vem och när | Spårbarhet, liggaren får inte skrivas om | Egna poster som pekar på originalet |
| PIN-kod | Identifiering på kiosken | Lagras aldrig. Servern sparar bara ett salt och ett kontrollvärde (PBKDF2). |
| Personlig QR-kod | Identifiering på kiosken | Signerad med applikationsnyckeln. Kiosken får bara en hash. |
| E-postadress (frivillig) | Påminnelse om glömd utcheckning | Registret |
| Foto (frivilligt) | Att personen känner igen sig själv på kiosken | Krypterad fil. Sparas bara med samtycke. |
| Avstånd till lokalen i meter | Platskontroll vid mobil incheckning | Bara avståndet sparas, aldrig positionen |
| Kioskens enhet och sekvensnummer, flaggor (klockavvikelse, sen överföring, lucka) | Visa om en registrering kan ha manipulerats | Varje post |

## 3. Nödvändighet och proportionalitet

- Uppgifterna i liggaren följer av lagen. NONOX samlar inte in mer än liggaren, påminnelsen och den frivilliga platskontrollen kräver. TODO(legal-verify): exakt vilka uppgifter Skatteverket kräver från 2027 (`app/Modules/StaffLedger/Actions/SavePerson.php`).
- Liggaren används inte för arbetstidsuppföljning eller prestationsmätning. [Företaget intygar hur liggaren används. Om liggaren också används för tidrapporter till lönen är det ett nytt ändamål som ska bedömas här (artikel 6.4).]
- Bevarandetid: posterna gallras automatiskt två år efter utgången av det kalenderår då företagets beskattningsår (räkenskapsår) som posten hör till gick ut, enligt 9 kap. 5 § tredje stycket skatteförfarandeförordningen (2011:1261). Med brutet eller förlängt räkenskapsår blir det mer än tre år. Enligt AI-utredning 2026-11-02, juristgranskning återstår (se [lagringstider](lagringstider.md)).
- Information till de registrerade (artikel 13): [Hur företaget informerar personalen, till exempel anslag vid kiosken och skriftlig information vid anställning.]

## 4. Risker

| Risk | Sannolikhet och allvar (fylls i av företaget) |
|---|---|
| **Systematisk övervakning av anställda.** Liggaren visar exakt när varje person kommer och går. Den kan användas för att kontrollera personal utöver lagens ändamål, till exempel för disciplinära åtgärder. | [ ] |
| **Plats.** Mobil incheckning med platskontroll använder mobilens position. Det kan upplevas som spårning. | [ ] |
| **Foto.** Ett foto identifierar personen och kan spridas. Samtycke i en anställning är sällan frivilligt (skäl 43, artikel 7.4). | [ ] |
| **Personnummer röjs**, till exempel för kollegor vid kiosken eller vid stöld av en kiosk. | [ ] |
| **En kiosk kopieras.** En kiosk som används utan nät lagrar PIN-kontrollvärden, foton och, förseglat, kontrollbesökets uppgifter. Fyrsiffriga PIN-koder kan provas fram ur en kopia. | [ ] |
| **Felaktiga uppgifter** genom fel tid, glömd utcheckning eller att någon checkar in åt någon annan. Det kan leda till felaktiga slutsatser vid kontrollbesök eller i lönen. | [ ] |
| **Uppgifter sparas för länge.** | [ ] |
| **Obehörig åtkomst** till liggaren i tjänsten. | [ ] |

## 5. Åtgärder som finns i NONOX

| Risk | Åtgärd i systemet |
|---|---|
| Övervakning | Modulen har ingen schemaläggning, ingen prestationsstatistik och ingen automatisk utcheckning. Påminnelser om glömd utcheckning går till personen och chefer, utan att något ändras automatiskt. Att föreslå tidrapporter är ett eget steg som kräver behörighet både för personalliggaren och för att attestera lön. |
| Plats | Mobil incheckning är avstängd som standard och slås på per lokal. Servern räknar ut avståndet och sparar bara det. Positionen sparas aldrig. Bara sidan för mobil incheckning får använda platstjänster (Permissions-Policy). |
| Foto | Ett foto kan bara sparas om rutan för samtycke är ikryssad. Tidpunkten för samtycket sparas och skrivs i behandlingshistoriken. "Ta bort foto" raderar filen direkt. Ingen ansiktsigenkänning görs. Filen krypteras i vila. |
| Personnummer | Krypteras i databasen. Maskeras i alla listor. Bara en användare med behörigheten `staff-ledger:view-sensitive` kan visa dem i klartext, och varje sådan visning skrivs i behandlingshistoriken. Vyn för kontrollbesök visar dem i sin helhet, och varje öppning och export loggas. |
| Kopierad kiosk | Kiosken saknar inloggning och får bara en enhetsnyckel. Servern sparar bara nyckelns hash, och en förlorad enhet kan spärras direkt. Kontrollbesökets uppgifter lämnas aldrig ut enbart mot enhetsnyckeln. Uppkopplad kräver de en chefs PIN-kod som kontrolleras på servern. Utan nät är de förseglade med AES-256-GCM under en nyckel som bara kan låsas upp med en chefs PIN-kod. Fem felaktiga PIN-koder spärrar personen på enheten i fem minuter. Triviala koder nekas. |
| Felaktiga uppgifter | Poster ändras aldrig. Rättelser och manuella registreringar sparas som egna poster med skäl, vem och när. Liggaren har en hashkedja per lokal som kontrolleras varje natt, och en databasregel stoppar ändring och radering. Klockavvikelser, sena överföringar och luckor flaggas på posten. |
| Lagringstid | `privacy:prune` gallrar äldre poster varje natt. Gallringen loggas, och hashkedjans nya början sparas så att kedjan fortfarande går att kontrollera. |
| Obehörig åtkomst | Behörigheter per roll (`staff-ledger:view`, `:manage`, `:control-visit`, `:view-sensitive`). Varje sida är låst till sitt företag. Inloggning sker med engångskod till e-post. |

## 6. Kvarstående risker

- **Fyrsiffriga PIN-koder.** Den som kopierar en kiosks lagring kan prova fram PIN-koder utan nät. En PIN-kod öppnar inget konto. Med en chefs PIN-kod kan dock kontrollbesökets förseglade uppgifter låsas upp, och de innehåller personnummer för 31 dagar. [Företaget bör använda dedikerade kiosker och spärra en förlorad enhet direkt.]
- **Foton på kiosken.** Fotona följer med kioskens personallista och lagras i webbläsaren på enheten utan NONOX:s egen kryptering. [Använd foto bara när det behövs.]
- **Samtycke till foto i en anställning.** Det är osäkert om samtycket kan vara frivilligt. TODO(legal-verify): om foto ska bygga på samtycke (artikel 6.1 a) eller på berättigat intresse (artikel 6.1 f).
- **Tidrapporter till lönen.** Om liggaren används för lön behandlas uppgifterna för ett nytt ändamål. [Företagets bedömning.]
- **Lagringstiden** följer 9 kap. 5 § tredje stycket skatteförfarandeförordningen enligt en AI-utredning 2026-11-02. Juristgranskning återstår.
- **Arbetsgivaruppgifter från 2027.** Enligt prop. 2025/26:282 (39 kap. 11 a § andra stycket SFL, riksdagsbeslut planerat 11 november 2026, tillämpas från 1 juli 2027) ska arbetsgivaren dokumenteras för alla som har en annan arbetsgivare. Det är inte infört i NONOX förrän reglerna och förordningen är beslutade. [Bedömningen ska ses över då.]

## 7. Slutsats

[Företagets bedömning: är den kvarstående risken hög? Om ja, ska IMY rådfrågas innan behandlingen börjar (artikel 36.1).]

| | |
|---|---|
| Bedömd av | [Namn, roll, datum] |
| Synpunkter från dataskyddsombud (om sådant finns) | [ ] |
| Synpunkter från de anställda eller deras företrädare (artikel 35.9) | [ ] |
| Ny bedömning senast | [Datum, och alltid när behandlingen ändras] |
