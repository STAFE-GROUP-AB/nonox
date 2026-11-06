# Avisering: juridiska dokument version 1.6

Status: **utkast för granskning, inte skickat.** Skrivet 2026-11-06 för beslut 5, 8, 10, 18 och 19
i [manskliga-beslut.md](manskliga-beslut.md) och de öppna frågorna i ADR-054 och ADR-063. Ingen
jurist har granskat texten.

## Vad som ändras i version 1.6

- **Allmänna villkor (`villkor.md`):**
  - §2 Kundens ansvar: NONOX är ett verktyg och ansvarar inte för att Kundens uppgifter är riktiga;
    det tjänsten räknar fram är ett underlag. Kunden granskar allt innan något lämnas till
    Skatteverket, Bolagsverket eller en bank och bekräftar "Jag har granskat siffrorna" före varje
    inlämning och betalfil (beslut 10, `App\Support\Review\ReviewConfirmation`).
  - §10 Ansvar (tidigare §7): ansvar bara för skada orsakad med uppsåt eller genom grov
    vårdslöshet. Taket "det Kunden betalat de senaste tolv månaderna" tas bort (skälet står i en
    kommentar i villkoren). Nytt: Kunden får säga upp avtalet och få tillbaka förbetald avgift om
    tjänsten är väsentligt felaktig och felet inte rättas inom skälig tid.
  - §4 och nya §5 NONOX Connect: alla moduler och NONOX API ingår i priset; NONOX Connect är ett
    frivilligt tillägg som kostar leverantörens pris plus 10 %, med båda beloppen redovisade och
    leverantörens prisändringar meddelade (beslut 8).
  - Nya §6 Betafunktioner och §7 NONOX API och AI-assistenter (beslut 18).
  - §12 (tidigare §9): fortsatt användning efter ändringsdagen innebär godkännande; vid en ändring
    till nackdel får Kunden säga upp och få tillbaka förbetald avgift. Ny klausul om överlåtelse
    till ett annat bolag i samma koncern som tar över driften.
  - Inledningen: tjänsten är till för verksamheter, inte för konsumenter.
  - Numreringen ändras: Kundens uppgifter §8, Tillgänglighet §9, Uppsägning §11.
- **Integritetspolicyn:** avsnitt 1 får en punkt om Skatteverket som självständigt
  personuppgiftsansvarigt. Avsnitt 2 får en rad om inloggningen hos Skatteverket: krypterade
  nycklar, högst cirka 65 minuter. Avsnitt 4 får ett stycke om vad som skickas till och tas emot
  från Skatteverket för momsdeklarationen, arbetsgivardeklarationen, skattekontot och
  kundhändelserna, om Skatteverkets auditloggning i upp till fem år, och ett stycke om
  AI-assistenter som företaget själv kopplar och deras leverantörer som mottagare (beslut 18).
- **Personuppgiftsbiträdesavtalet:** punkt 1 får en rad "Skatteverket" och ett stycke om hur
  personuppgiftsansvaret fördelas för uppgifter från och till Skatteverket. Det är den skriftliga
  överenskommelse som Skatteverkets villkor rekommenderar. Punkt 6 får ett stycke om AI-assistenter
  genom NONOX API.
- **Underbiträden:** en rad om att Skatteverket inte är underbiträde, och en om att leverantören
  av en AI-assistent som företaget kopplar inte är underbiträde utan företagets eget val.
- **Namnbytet:** dagens NONOX Connect (API och MCP) heter NONOX API i alla dokument. Namnet NONOX
  Connect betyder från version 1.6 de betalda kopplingarna i villkoren §5.
- **Driftbolaget byts** (se nedan): villkoren, biträdesavtalet och integritetspolicyn får det nya
  bolaget genom konfigurationen (`{foretag}`, `{orgnr}`, `{adress}`). Inga nya texter i repot
  namnger något av bolagen.

**Obs:** sidorna /villkor, /integritetspolicy, /personuppgiftsbitradesavtal och /underbitraden
visar texten för version 1.6 ("gäller från 2027-01-11") så snart den driftsätts, före
juristgranskningen. Driftsätt dokumenten först när juristen har godkänt dem, eller när det är
beslutat att utkastet får visas.

## Varför `EFFECTIVE` är 2027-01-11

Personuppgiftsbiträdesavtalet är en del av villkoren (villkor §3). Villkoren får bara ändras med
60 dagars varsel, med e-post till varje kunds ägare och på webbplatsen (villkor §9 i version 1.5,
§12 i version 1.6). Fram till dess gäller den tidigare versionen för befintliga kunder. 2027-01-11
är 60 dagar efter 2026-11-12. **Skickas aviseringen senare än 2026-11-12 ska `EFFECTIVE` flyttas
fram så att det blir minst 60 dagar efter utskicket, och datumet i mejlet ändras likadant.**
Produktionsnycklarna sätts inte före `EFFECTIVE`.

Integritetspolicyn har ingen egen regel om varsel ("Vi uppdaterar policyn när tjänsten ändras").
`VERSION` och `EFFECTIVE` gäller ändå alla dokument samtidigt.

Ändringen av ansvaret i villkoren (§2 och §10) är till nackdel för kunden. Den görs med den
ensidiga ändringsrätten i §9 (version 1.5). Därför ska mejlet säga det rakt ut, inte bara länka
till texten: en ändring som kunden inte har fått klar information om riskerar att inte bli en del
av avtalet, eller att jämkas enligt avtalslagen 36 §.

## Byte av driftbolag i samma version

Beslut 2026-11-06 (Andreas): ett annat bolag i koncernen tar över driften av den driftade tjänsten
och blir avtalspart, personuppgiftsbiträde och personuppgiftsansvarig i stället för det nuvarande
driftbolaget. Det nya driftbolaget är samma bolag som har tecknat Skatteverkets avtal, vilket löser
den tidigare fråga 1 nedan. Bytet aviseras i samma utskick och med samma 60 dagar som version 1.6.

Repot är publikt, och uppgifter om vilket bolag som driftar tjänsten ska bara finnas i
konfigurationen och databasen (QUICKSTART §1.5). Därför används platshållare här:
`[NUVARANDE DRIFTBOLAG]`, `[NYTT DRIFTBOLAG]`, `[NYTT ORG.NR]`, `[NY ADRESS]` och
`[SVARSADRESS]`. De fylls i när mejlet skickas, inte i repot.

### Vad villkoren och biträdesavtalet säger om överlåtelse

- **Allmänna villkor, version 1.5 (gällande)** har ingen bestämmelse om överlåtelse av avtalet.
  Det som finns är regeln om ändringar, §9: "Vi kan ändra villkoren med 60 dagars varsel. Varsel
  lämnas med e-post till Kundens ägare och på {webbplats}. Fram till dess gäller den tidigare
  versionen för befintliga kunder." Och om uppsägning, §8: "Avtalet löper ett år i taget och
  förlängs automatiskt. Det kan sägas upp när som helst före förnyelsen."
- **Version 1.6** får en klausul om överlåtelse till ett bolag i samma koncern (§12). Den gäller
  framtida byten; bytet nu sker samma dag som klausulen börjar gälla och vilar därför på kundens
  godkännande.
- **Personuppgiftsbiträdesavtalet** har ingen bestämmelse om överlåtelse. Punkt 5 gäller bara
  underbiträden: "Biträdet meddelar minst 30 dagar i förväg innan ett underbiträde läggs till eller
  byts, och den personuppgiftsansvarige får invända." Punkt 10: "Avtalet gäller före villkoren vid
  motstridighet i fråga om personuppgifter. Svensk lag gäller."
- **Integritetspolicyn** har ingen regel om varsel ("Vi uppdaterar policyn när tjänsten ändras").

Byte av driftbolag är byte av avtalspart, inte en ändring av villkorens text. Därför räcker det inte
säkert att använda varselregeln. Ett byte av personuppgiftsbiträde är inte heller ett byte av
underbiträde enligt punkt 5. Utkastet förutsätter att **kunden behöver godkänna överlåtelsen**,
och att det sker så här: kunden får beskedet minst 60 dagar i förväg. En kund som inte godtar
bytet kan säga upp avtalet före {datum}. En kund som fortsätter att använda tjänsten efter {datum}
godkänner nya villkor och nytt biträdesavtal med `[NYTT DRIFTBOLAG]`. Godkännandet registreras
genom att företaget får `agreement_version` 1.6.

<!-- TODO(legal-verify): om tyst godkännande genom fortsatt användning räcker för att överlåta avtalet och biträdesavtalet till ett annat bolag, och för att ändra ansvaret till kundens nackdel, eller om varje kund aktivt måste godkänna (till exempel med en ruta i tjänsten) innan {datum}. Utkastet förutsätter att godkännande krävs, eftersom villkoren saknar en klausul om överlåtelse. -->
<!-- TODO(legal-verify): att biträdesavtalet med [NYTT DRIFTBOLAG] uppfyller artikel 28.3 (avtal som binder biträdet gentemot den personuppgiftsansvarige) från {datum}, och vad som gäller för en kund som inte har godkänt. -->
<!-- TODO(legal-verify): byte av personuppgiftsansvarig för det driftbolaget själv ansvarar för (konton, kön till betan, kundregistret, CRM-kontakter, företagsregistret för prospektering): rättslig grund för att föra över uppgifterna till [NYTT DRIFTBOLAG], och informationen enligt artikel 13 och 14 till de registrerade, även till dem i kön som inte är kunder. -->
<!-- TODO(legal-verify): om kundfakturor redan utställda av [NUVARANDE DRIFTBOLAG] och förbetalda årsavgifter ska överlåtas eller faktureras om. -->

## Avisering till kunderna (e-post till varje företags ägare och ruta i tjänsten)

Mejlet skickas av `[NUVARANDE DRIFTBOLAG]`, som fortfarande är avtalspart när det går ut. Namnen
skrivs därför ut i klartext i mejlet. Platshållaren `{foretag}` får inte användas, eftersom den
pekar på det nuvarande driftbolaget fram till {datum} och sedan på det nya. Datumet är skrivet ut
(11 januari 2027) och ändras om `EFFECTIVE` flyttas.

Innan mejlet skickas:

1. Fyll i platshållarna inom hakparentes. `[SVARSADRESS]` är Reply-To och kopia till Andreas.
2. Stryk punkt 3 eller de delar av den som inte är i drift för alla kunder den 11 januari 2027,
   och stycket om AI-assistenter (punkt 5) om MCP-servern inte är godkänd av juristen då.
3. Rendera mejlet genom projektets egen mejlmall (`mail:send --render`), granska bilden och skicka
   det först bara till Andreas (förhandsgranskning). Skicka till kunderna först efter hans ja.

Mejlet nedan är den slutliga texten.

---

**Ämne:** NONOX från 11 januari 2027: nytt driftbolag och nya villkor

Hej,

Den 11 januari 2027 börjar version 1.6 av NONOX:s allmänna villkor, personuppgiftsbiträdesavtal
och integritetspolicy att gälla. Fram till dess gäller de nuvarande. Här är det ni behöver veta.

**1. Nytt driftbolag**
[NYTT DRIFTBOLAG] ([NYTT ORG.NR], [NY ADRESS]) tar över driften av NONOX från [NUVARANDE
DRIFTBOLAG]. Ert avtal och personuppgiftsbiträdesavtalet förs över, så att [NYTT DRIFTBOLAG] blir
er avtalspart och ert personuppgiftsbiträde. Tjänsten, priset, era uppgifter och servrarna i
Sverige är desamma, och ni behöver inte göra något. Fakturor för tjänsten kommer från [NYTT
DRIFTBOLAG].

**2. Ni granskar siffrorna, och vårt ansvar ändras**
NONOX är ett initiativ med öppen källkod och ett verktyg. Vi ansvarar inte för att era uppgifter
är riktiga. Det NONOX räknar fram är ett underlag som ni kontrollerar. Före varje inlämning till
Skatteverket eller Bolagsverket och före varje betalfil bekräftar ni därför "Jag har granskat
siffrorna".

Hittills har vi ansvarat för skada upp till det ni har betalat de senaste tolv månaderna. Från 11
januari 2027 ansvarar vi bara för skada som vi har orsakat med uppsåt eller genom grov
vårdslöshet. Det är en ändring till er nackdel, och därför skriver vi det här rakt ut. Är tjänsten
väsentligt felaktig och vi inte rättar felet inom skälig tid, kan ni säga upp avtalet och få
tillbaka avgiften för resten av året.

**3. Koppling till Skatteverket**
Den som har rätt behörighet kan logga in hos Skatteverket med BankID och från NONOX lämna
momsdeklarationen, läsa in arbetsgivardeklarationen, se skattekontot och hämta Skatteverkets
meddelanden om företaget. NONOX anropar Skatteverket bara när någon själv klickar. Skatteverket
ansvarar för sin egen behandling av uppgifterna. När uppgifterna har kommit till NONOX är ert
företag personuppgiftsansvarigt och vi personuppgiftsbiträde, som för resten av bokföringen. Vad
som skickas och sparas står i integritetspolicyn.

**4. NONOX API och NONOX Connect**
Vårt öppna API, som hittills har hetat NONOX Connect, heter nu NONOX API och ingår som förut.
Namnet NONOX Connect används i stället för kopplingar där en annan leverantör tar betalt av oss,
till exempel e-faktura genom Peppol och bankkoppling. De är frivilliga och kostar det leverantören
tar av oss plus 10 %. Ni ser båda beloppen innan ni slår på något. Alla moduler ingår som förut.

**5. Egna AI-assistenter**
Ni kommer att kunna koppla en egen AI-assistent, till exempel Claude eller ChatGPT, till NONOX.
Den kan läsa det ni tillåter och skapa utkast, men aldrig bokföra, skicka eller betala något.
Leverantören av assistenten väljer ni själv, och den tar emot uppgifterna enligt ert eget avtal
med den, inte som vårt underbiträde.

De nya versionerna:
- Allmänna villkor: https://nonox.app/villkor
- Personuppgiftsbiträdesavtal: https://nonox.app/personuppgiftsbitradesavtal
- Integritetspolicy: https://nonox.app/integritetspolicy
- Underbiträden: https://nonox.app/underbitraden

Fortsätter ni att använda NONOX efter 11 januari 2027 godkänner ni bytet av driftbolag och de nya
versionerna. Godtar ni dem inte kan ni säga upp avtalet före dess och få tillbaka det ni har
betalat för tiden efter 11 januari 2027. All bokföring kan alltid exporteras som SIE4 och
arkivpaket. Har ni frågor, svara på det här mejlet.

Med vänliga hälsningar
Andreas Kviby

*Det här mejlet skickas från [NUVARANDE DRIFTBOLAG] till ägaren av varje företag i NONOX. Svar går
till [SVARSADRESS].*

---

<!-- TODO(legal-verify): återbetalning av förbetald avgift till den som säger upp före 11 januari 2027, och vid väsentligt fel (villkoren §10 och §12). Det är ett åtagande om pengar som kräver Andreas beslut innan mejlet skickas; stryks det ur mejlet ska det också strykas ur villkoren. -->
<!-- TODO(legal-verify): om ett aktivt godkännande krävs i stället för fortsatt användning (se ovan); då ändras sista stycket före hälsningen. -->

## Var driftbolaget syns (kontrollerat 2026-11-06)

**Konfiguration (`config/app.php` → `app.landing.*`, från serverns `.env`):**

| Variabel | Nyckel | Används i |
|---|---|---|
| `NONOX_COMPANY_NAME` | `company_name` | `{foretag}` i alla juridiska sidor (`LegalPageController::placeholders`); sidfoten på de publika sidorna "© år [namn] · Org.nr …" (`LandingPage.php:157`, `start/partials/public-footer.blade.php:63`) |
| `NONOX_COMPANY_ORG_NUMBER` | `org_number` | `{orgnr}` i de juridiska sidorna; sidfoten. Saknas den visas "del av Stafe Group AB" (fast text i `LegalPageController.php:64`) |
| `NONOX_COMPANY_ADDRESS` | `company_address` | `{adress}` i integritetspolicyn, villkoren och biträdesavtalet |
| `NONOX_PRIVACY_EMAIL` | `privacy_email` | `{epost}` i de juridiska sidorna; reserv för säkerhetsadressen |
| `NONOX_SECURITY_EMAIL` | `security_email` | /sakerhet (`SecurityPageController`) |
| `MAIL_FROM_NAME` | `mail.from.name` | Avsändarnamnet i alla mejl; i `.env.example` `"${APP_NAME}"` (alltså "NONOX", inte ett bolagsnamn). Kontrollera serverns värde |
| `MAIL_FROM_ADDRESS`, Mailgun-domänen | `mail.*`, `services.mailgun` | Avsändaradress; domänen mg.nonox.app hör till tjänsten och inte till ett bolag, men Mailgun-kontot gör det |

**Fasta texter i koden (oberoende av konfigurationen):**

- `LegalPageController.php:57` och `LandingPage.php:157`: reservvärdet för bolagsnamnet när
  `NONOX_COMPANY_NAME` saknas.
- `LegalPageController.php:64`: "del av Stafe Group AB" när organisationsnumret saknas.
- `resources/views/start/partials/public-footer.blade.php:12`: "NONOX är utvecklat av Stafe
  Development AB och ingår i Stafe Group AB." Det beskriver vem som utvecklar programmet, inte
  driftbolaget, och kan vara kvar om det fortfarande stämmer.
- `resources/views/manual/layout.blade.php:121`, `resources/views/developers/layout.blade.php:100`
  och `README.md:529`: "utvecklat av Stafe Development AB". Detta gäller utvecklaren, inte
  driftbolaget.
- `docs/juridik/regelverk.md:5`: "**Stafe Development AB** utvecklar NONOX och driftar tjänsten på
  nonox.app". **Den här raden måste ändras**, eftersom den säger att Stafe driftar tjänsten.
  Visas inte som juridisk sida (`regelverk` finns inte i `DOCUMENTS`).

<!-- TODO: om reservvärdena i koden ska bli neutrala (till exempel tomma) i stället för ett bolagsnamn; de syns bara om .env saknar värdet. Inte ändrat i natt. -->

**Databasen:**

- `InstanceSettings::OPERATOR` (`operator.company_id`): driftbolaget i installationen, valt i
  HQ → Inställningar (ADR-044). Kunderna och kön kopieras dit som kunder och prospekt
  (`OperatorSync`, `SyncToOperator`, `operator:sync-customers`), och CRM:s utskick pausas där
  (`MailingPause`). Det är driftbolagets egen bokföring och fakturering av tjänsten.
- `teams.agreement_version`: vilken version av villkoren ett företag godkände när det skapades
  (`CreateCompany`).

## Ändringen på servern på {datum} (görs av en människa, inte nu)

Ändra i `.env` för nonox.app genom Forge (namn, inga värden):

1. `NONOX_COMPANY_NAME` sätts till det nya driftbolagets namn.
2. `NONOX_COMPANY_ORG_NUMBER` sätts till det nya organisationsnumret (den är satt i dag, till det
   nuvarande bolagets).
3. `NONOX_COMPANY_ADDRESS` kontrolleras. Den är redan densamma om bolagen har samma adress.
4. `NONOX_PRIVACY_EMAIL` och `NONOX_SECURITY_EMAIL` kontrolleras, så att de går till det nya
   driftbolaget.
5. `MAIL_FROM_NAME` kontrolleras: behåll "NONOX" om det är det värdet.
6. `php artisan config:cache` på servern (artisan är tillåtet; ingen composer eller npm).

I HQ → Inställningar: välj det nya driftbolagets företag som driftbolag (`operator.company_id`),
om det inte redan är valt. Kontrollera sedan att kunderna finns där, med `operator:sync-customers`
om det behövs.

Samma dag, före ändringarna: `VERSION` 1.6 och `EFFECTIVE` ska vara driftsatta, och mejlet ska ha
gått ut minst 60 dagar tidigare.

## Checklista för bytet

- [ ] Sidfoten på de publika sidorna visar det nya namnet och organisationsnumret.
- [ ] /integritetspolicy, /villkor, /personuppgiftsbitradesavtal och /underbitraden visar det nya
      bolaget, organisationsnumret och adressen, version 1.6, "gäller från {datum}".
- [ ] `docs/juridik/regelverk.md:5` säger vem som driftar tjänsten.
- [ ] Mailgun: avsändarnamnet i mejlen, och att Mailgun-kontot (mg.nonox.app) står på det nya
      driftbolaget eller är ett underbiträde till det.
- [ ] Underbiträdesavtal (DPA) med Vultr, Laravel Forge och Mailgun i det nya driftbolagets namn,
      eller att kontona förs över. Annars är det nuvarande bolaget ett underbiträde som ska stå på
      /underbitraden med 30 dagars varsel (biträdesavtalet punkt 5).
- [ ] Fakturor för tjänsten utställs från det nya driftbolagets företag i NONOX från {datum}:
      nummerserie, bankgiro, momsnummer och F-skatt på fakturan.
- [ ] Förbetalda årsavgifter och öppna kundfakturor från det nuvarande bolaget hanteras (se
      TODO ovan).
- [ ] Skatteverket: avtalen för de fem API:erna är redan i det nya driftbolagets namn. Produktions-
      nycklarna sätts först efter {datum}.
- [ ] Enable Banking: avtalet tecknas i det nya driftbolagets namn (beslut 3).
- [ ] Qvalia (Peppol): kontot står på det nya driftbolaget (beslut 2).
- [ ] Bolagsverket (inlämning av årsredovisning), om det tecknas: i det nya driftbolagets namn.
- [ ] `agreement_version` 1.6 på nya företag; bestäm hur befintliga företags godkännande
      registreras (se TODO ovan).
- [ ] Rendera och granska mejlet innan det skickas, först bara till Andreas.

## Det här måste en människa bestämma före publicering

1. ~~Vem är programvaruföretaget?~~ **Beslutat 2026-11-06:** det nya driftbolaget, som har
   tecknat Skatteverkets avtal, tar över driften (avsnittet ovan). Det som återstår är om
   överlåtelsen kräver kundens aktiva godkännande (TODO ovan).
2. **Vem är personuppgiftsansvarig efter överföringen** när den som anropar är ett ombud, till
   exempel en redovisningsbyrå? Texten väljer kundföretaget i alla fyra flödena. Villkoren säger
   "Slutanvändaren" (arbetsgivardeklarationen) och "Mottagaren av uppgifter" (skattekontot,
   kundhändelserna), och Kundhändelser definierar mottagaren som programvaruföretaget.
3. **Varsel: 60 dagar.** Med bytet av driftbolag ändras både villkoren och biträdesavtalet, så
   vägen med kortare varsel (bara integritetspolicyn) finns inte längre.
4. **Auditloggning för momsen:** villkoren för momsdeklarationen säger "gallras enligt
   Skatteverkets gällande tillämpningsbeslut", inte fem år. Policyn skriver "upp till fem år" för
   alla anrop. Rätta texten om det inte stämmer för momsen.
5. **Vilka delar är i drift** när versionen börjar gälla? Etapp 1 (moms) är driftsatt bakom
   pilotlistan; etapp 2–4 ligger på grenen. En del som inte är i drift stryks ur policyn före
   publicering.
6. Registerförteckningen säger "Inte i drift … förrän integritetspolicyn har uppdaterats" för
   varje etapp. Den raden uppdateras när version 1.6 gäller och produktionsnycklarna är satta.
7. **Återbetalning.** Villkoren §10 och §12 och mejlet lovar att den som säger upp vid väsentligt
   fel, eller för att den inte godtar en ändring till nackdel, får tillbaka förbetald avgift för
   tiden efter uppsägningen. Det är ett förslag som stärker villkoren mot avtalslagen 36 §, men
   det är ett åtagande om pengar: godkänn eller stryk på alla tre ställena.
8. **NONOX Connect i detalj** (villkoren §5): faktureringsintervall, hur leverantörens fasta
   avgifter fördelas mellan kunderna, valutakurs, när avgiften börjar tas ut för kopplingar som
   används under piloten, och om Skatteverket (som kanske inte tar betalt) ska stå bland
   exemplen. Behöver ADR:en som beslut 8 kräver.
9. **De publika sidorna** säger "Alla moduler ingår". De måste också säga, lika tydligt, att
   NONOX Connect kostar leverantörens pris plus 10 % (marknadsföringslagen 10 §), och "NONOX
   Connect" som namn på API:et byts mot "NONOX API" överallt. Hör inte till de juridiska
   dokumenten, men ska vara klart samma dag som mejlet går ut.
10. **Ansvarstaket.** Taket på tolv månaders avgift är borttaget eftersom det inte kan gälla vid
    grov vårdslöshet eller uppsåt, som nu är de enda fallen. Juristen prövar det och om
    friskrivningen för normal vårdslöshet håller mot avtalslagen 36 § (kommentarerna i
    villkoren §10).


## AI-assistenter som mottagare (beslut 18)

Det tidigare separata utkastet om MCP-servern och AI-assistenter är nu infört som utkast i
integritetspolicyn avsnitt 4, personuppgiftsbiträdesavtalet punkt 6, underbiträdessidan och
registerförteckningen (`docs/gdpr/registerforteckning.md`). Texterna säger att funktionen inte är
tillgänglig ännu. MCP-servern släpps inte förrän en jurist har godkänt dem; frågorna står i
kommentaren i integritetspolicyn.
