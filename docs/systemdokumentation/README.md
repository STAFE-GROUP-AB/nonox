# Systemdokumentation – NONOX Ekonomisystem

Systemdokumentation och behandlingshistorik enligt bokföringslagen och BFNAR
2013:2 (`AGENTS.md` §10). Dokumentet beskriver hur systemet faktiskt fungerar i
den här versionen och uppdateras i varje ändring som påverkar bokföringen.

- Version: 0.15
- Datum: 2026-10-30
- Omfattning: företag, kontoplan, räkenskapsår, verifikationsserier,
  verifikationer och bokföring, underlag, konteringsmallar, rättelser,
  kontroll av huvudboken, ingående balanser, SIE-export och SIE-import,
  kundfakturor, leverantörsfakturor, behandlingshistorik.

## 1. Företag och behörighet

- Varje företag är en egen enhet. All bokföringsdata hör till exakt ett företag
  (`company_id`) och filtreras automatiskt så att ett företags data aldrig visas
  för ett annat ([ADR-005](../adr/ADR-005-tenancy.md)).
- Användare loggar in med en engångskod via e-post, och vissa bekräftar
  dessutom med en passkey (avsnitt 5a9). Behörigheten styrs av roller per
  företag ([ADR-003](../adr/ADR-003-company-roles.md)).

### 1.1 Överlåtelse och arkivering

Beskrivs i [ADR-024](../adr/ADR-024-company-archiving-and-ownership-transfer.md).

- Ägaren kan överlåta företaget till en annan medlem med en av företagsrollerna
  (Företagsinställningar → Ägarskap och arkiv). Medlemmen blir ägare, den
  tidigare ägaren blir administratör. Överlåtelsen skrivs i
  behandlingshistoriken (`company.ownership_transferred`).
- Ett företag raderas aldrig. Ägaren arkiverar det i stället. Före
  arkiveringen visas arkivpaketet för varje räkenskapsår (avsnitt 7a); ett
  paket räknas som aktuellt när det skapades efter årets senaste bokföring.
  Saknas ett aktuellt paket måste ägaren bekräfta att räkenskapsinformationen
  är sparad på annat sätt. Arkiveringen skrivs i behandlingshistoriken
  (`company.archived`) med paketen och bekräftelsen.
- Ett arkiverat företag kan bara läsas. Det spärras i två led: behörigheterna
  i ett arkiverat företag är bara de som läser och exporterar (verifikationer,
  fakturor, rapporter, SIE-export, arkivpaket, behandlingshistorik och
  lönearkiv), och en rad med företagsdata kan inte skapas eller ändras i ett
  arkiverat företag även om koden inte frågar efter behörighet (schemalagda
  körningar, kiosker). Inget kan bokföras, faktureras, betalas eller ändras,
  ingen lön köras och ingen in- eller utcheckning göras. Post till inkorgen
  tas inte emot utan loggas (`inbox.dropped`, `company_archived`), och avtal
  faktureras inte. Registerutdrag enligt GDPR fungerar som förut, och
  gallring av personuppgifter fortsätter.
- Varje sida i ett arkiverat företag visar att företaget är arkiverat och
  bara kan läsas. Arkiverade företag visas för sig, nedtonade, i
  företagsväljaren och under Mina företag.
- Ägaren kan ta företaget ur arkivet (`company.unarchived`).

## 1a. Mina företag (översikt för byråer)

- Sidan Mina företag (`/mina-foretag`) visar alla företag användaren är medlem
  i och vad som väntar i vart och ett: momsperioder som har slutat utan
  momsdeklaration i ett öppet räkenskapsår, månader med attesterad lön utan
  inlämnad arbetsgivardeklaration, räkenskapsår som har slutat men inte är
  stängda, förfallna kundfakturor, leverantörsfakturor att attestera eller
  betala inom en vecka, verifikationer i utkast, lönekörningar att attestera
  och ansökningar och utlägg att besvara.
- En uppgift visas bara om användarens roll i just det företaget har
  behörighet att utföra den, och lönens uppgifter bara när lönemodulen är
  påslagen. Översikten läser och ändrar ingenting.
- En uppgift öppnar rätt sida i rätt företag: företaget byts som med
  företagsväljaren (bytet loggas i behandlingshistoriken) och bara sidor i en
  fast lista kan öppnas.
- Moms och AGI visar sista dag för den äldsta perioden som återstår, och
  markeras som brådskande när det är högst sju dagar kvar och som försenade
  när dagen har passerat. Momsens dag räknas enligt Skatteverket: månadsmoms
  den 12:e i andra månaden efter perioden (den 17:e i januari och augusti),
  eller den 26:e i månaden efter (den 27:e i december) för företag med
  omsättning över 40 miljoner kronor, vilket anges i företagets inställningar;
  kvartalsmoms den 12:e i andra månaden efter kvartalet (den 17:e i augusti).
  En dag som infaller på helg flyttas till närmast följande vardag. AGI:s dag
  kommer från den kontrollerade regeln `agi.deadlines`; utan kontrollerad
  regel visas ingen dag.
- Fliken Månad för månad (Byråvy) visar alla företag för en vald månad,
  bland föregående två, innevarande och nästa: bokfört till och med (senaste
  bokförda verifikationens datum till och med månadens slut), inlästa
  bankposter som ännu inte är kopplade till en faktura, momsen och
  arbetsgivardeklarationen för månaden (Lämnad, Att göra, Försenad, Pågår,
  Kvartal eller Helår när ingen momsperiod slutar i månaden, Ej aktuell) och
  vad kunderna är skyldiga på fakturor utfärdade till och med månadens slut.
  Företag med försenat eller ogjort hamnar överst. En försenad eller ogjord
  deklaration öppnar rätt sida i rätt företag.
- Varje företag kan ha en ansvarig redovisningskonsult, vald bland
  företagets medlemmar med rollen Redovisningskonsult av en sådan konsult,
  ägaren eller en administratör. Alla i företaget ser vem som är ansvarig.
  Konsulterna kan skriva en anteckning om företaget som bara medlemmar med
  rollen Redovisningskonsult ser (tabellen `client_profiles`).
  Behandlingshistoriken visar att anteckningen ändrats och hur lång den är,
  inte vad den säger.
- Kunden ser på översikten panelen Din redovisningsbyrå: ansvarig konsult och
  vad företagets redovisningskonsulter har gjort de senaste 60 dagarna, dag för
  dag och per typ av åtgärd, räknat ur behandlingshistoriken. Panelen visas för
  den som får läsa behandlingshistoriken men inte själv är konsult i företaget.
- Översikten hämtar varje siffra med en fråga för alla företag tillsammans,
  så antalet databasfrågor är detsamma oavsett hur många klienter byrån har.

## 1b. Översikt (startsidan)

- Översikt (`/oversikt`) visar för det valda företaget fyra nyckeltal
  (saldot på företagskontot 1930 enligt bokföringen och hur långt det är
  avstämt, obetalda kundfakturor, obetalda leverantörsfakturor och nästa
  momsdeklaration med sista dag), "Att göra" och de fem senast bokförda
  verifikationerna. Fliken Statistik och prognos visar diagram med samma
  siffror som tabeller ([ADR-027](../adr/ADR-027-overview.md)).
- Översikten läser bara. Den bokför, ändrar och loggar ingenting.
- "Att göra" bygger på samma regler som Mina företag (1a) för det valda
  företaget, plus bankposter som inte är kopplade till en faktura, företags-
  kontot som inte är avstämt till och med förra månadens slut, underlag i
  inkorgen och glömda utcheckningar i personalliggaren när de modulerna är
  påslagna. Varje rad visas bara för en roll som får utföra den.
- Belopp på senaste verifikationer: rör verifikationen bank och kassa (konto
  1900–1999) visas nettoförändringen där med tecken, plus för pengar in och
  minus för pengar ut. Andra verifikationer visar summan av debet utan tecken.
- Siffrorna sparas i en cache per företag, roll och dag i högst tio minuter.
  Cachen töms när en transaktion som skriver i behandlingshistoriken eller
  ändrar verifikationer, fakturor, bankposter med mera har sparats, och när
  användaren trycker på pilen för att ladda om. Tidpunkten för uträkningen
  visas ("Uppdaterad 14:32").
- Prognosen för likviditeten är en enkel räkning som förklaras under
  diagrammet: dagens saldo på bank och kassa, plus obetalda kundfakturor och
  minus obetalda leverantörsfakturor den månad de förfaller, plus det
  genomsnittliga resultatet för de tre senaste hela månaderna. Den märks
  "Prognos" och är ingen AI.

## 1c. Byråns arbetsyta och uppgifter (ADR-028)

- Fliken Översikt på Mina företag summerar alla användarens företag: pengar
  på bank och kassa (konto 1900–1999) och saldot på momskontona (2600–2699)
  enligt det bokförda i räkenskapsåret som omfattar dagens datum, med dess
  ingående balanser, samt obetalda kundfakturor och leverantörsfakturor. Ett
  företags siffra räknas bara in om användarens roll där får se den.
  Sidan läser och ändrar ingen bokföring.
- Att göra på samma flik är en lista för alla företag: systemets egna
  påminnelser (moms, AGI, bokslut, årsredovisning för aktiebolag, förfallna
  kundfakturor, attester och betalningar, verifikationer i utkast, inkorgen,
  bankposter att stämma av, lön) och öppna uppgifter. Försenat först, sedan
  efter sista dag och prioritet. Varje rad öppnas i sitt eget företag genom
  företagsbytet. Filter (ansvarig konsult, sista dag, typ, status, bara mina)
  ligger i adressen och kan sparas som vyer per användare (`saved_views`).
- Resultatet sparas i cache per användare i tio minuter. En ändrad uppgift
  eller avbockning i ett företag gör cachen ogiltig för alla som ser företaget;
  knappen Läs in på nytt läser om allt.
- Uppgifter (`tasks`, `task_comments`, `task_checklist_items`,
  `task_recurrences`) hör till ett företag. Den som tilldelas en uppgift måste
  vara ägare eller medlem i företaget. Med `tasks:manage` (ägare,
  administratör, redovisningskonsult, bokförare) skapar, ändrar och tar man bort
  uppgifter; med `tasks:view` (alla roller) ser man företagets uppgifter. Den
  som har tilldelats en uppgift ser den, kommenterar, bockar av checklistan och
  ändrar status, oavsett roll, så länge personen är kvar i företaget.
- En uppgift kan kopplas till en verifikation, kundfaktura,
  leverantörsfaktura, lönekörning, post i inkorgen eller momsdeklaration i
  samma företag.
- Återkommande uppgifter skapas av `tasks:generate-recurring` (varje dag
  05.10) en gång per period; perioden ingår i en unik nyckel. Sista dag är den
  valda dagen i månaden efter perioden. NONOX beräknar inte myndigheternas
  datum för uppgifterna; användaren väljer dagen.
- Notiser lagras i tabellen `notifications` (tilldelad, kommenterad, klar).
  Den som valt det får en sammanfattning med e-post varje vardag 06.45
  (`tasks:send-digest`) med titel, företag och sista dag för sina öppna
  uppgifter, aldrig beskrivningar eller kommentarer.
- Månadsstängning visar per klient och månad stegen bank avstämd,
  leverantörsfakturor, kundfakturor, moms, lön och AGI, periodiseringar och
  rapport skickad. Steg som kan läsas ur bokföringen fylls i av sig själva;
  övriga bockas av för hand (`month_close_checks`, vem och när) och kan
  ångras. Avbockningar sparas per månad.
- Behandlingshistoriken visar `task.created`, `task.assigned`,
  `task.updated`, `task.status_changed`, `task.completed`, `task.commented`,
  `task.deleted`, `task.recurrence_created`, `task.recurrence_stopped`,
  `month_close.checked` och `month_close.unchecked`, med typ, status,
  tilldelad och datum men aldrig uppgiftens eller kommentarens text.
- Uppgifter är inte räkenskapsinformation och tas bort med företaget.
- Sidan Uppgifter (`/uppgifter`) visar uppgifterna i alla användarens
  företag: till mig, alla klienter, väntar på kund, lämnade till andra och
  klara. Byrån är företagets medlemmar med rollen Redovisningskonsult, kunden
  övriga inklusive ägaren; uppgifter kan lämnas åt båda hållen och varje
  tilldelning ger en notis och loggas med vilken sida mottagaren är på.
  Arkiverade företag räknas inte med i summor, Att göra och månadsstängning,
  och där kan inga uppgifter skapas eller ändras.

## 1d. Redovisningsbyråns varumärke för kunderna (ADR-030)

- Ägaren kan markera ett företag som redovisningsbyrå
  (`teams.is_accounting_firm`) och välja om kunderna ska se byråns logga och
  färger (`teams.firm_brand_for_clients`, på från början).
- Ett kundföretags ägare väljer en byrå (`teams.accounting_firm_id`) bland
  byråer där någon med rollen Redovisningskonsult i kundföretaget också är med.
  Båda sidors ägare kan ta bort kopplingen. Den tas bort av sig själv när
  ingen sådan konsult finns kvar, och när byrån slutar vara byrå. Varje
  ändring loggas i båda företagens behandlingshistorik
  (`company.accounting_firm_linked`, `company.accounting_firm_unlinked`,
  `company.firm_client_linked`, `company.firm_client_unlinked`,
  `company.accounting_firm_settings_changed`).
- Kopplingen styr bara utseendet. Den ger byrån ingen behörighet i
  kundföretaget; behörighet kommer bara av medlemskap.
- Kundsidan (ägaren, medlemmar utan rollen Redovisningskonsult och de
  anställda på Min sida) ser byråns logga och färger i appen, på Min sida och
  i mejlen om inloggning, inbjudningar, uppgifter och frånvarobeslut.
  Byråns konsulter ser företagets eget varumärke.
- Kundföretagets egna dokument – fakturor, offerter, order, påminnelser och
  lönebesked – har alltid kundföretagets eget varumärke. Ingen
  räkenskapsinformation ändras av kopplingen.

## 1e. Sökning (⌘K)

- Fältet "Sök eller gå till" i toppraden (⌘K eller Ctrl+K) söker i sidor och
  åtgärder, användarens andra företag, verifikationer, kund- och
  leverantörsfakturor, offerter, order, konton, kunder, leverantörer,
  artiklar, anläggningar, periodiseringar, projekt och kostnadsställen, och i
  Lön, Personalliggare och Inkorg när modulen är påslagen
  ([ADR-026](../adr/ADR-026-spotlight-search.md)).
- Varje del av sökningen körs i det valda företaget och prövar användarens
  behörighet på samma sätt som sidan den leder till. Sökningen läser bara;
  den ändrar och loggar ingenting.
- Personnummer söks, indexeras och visas aldrig. Den som får se fullständiga
  personnummer kan skriva exakt fyra siffror och få de personer vars nummer
  slutar på dem. Söktexten skickas i anropets kropp, inte i adressen, och
  sparas inte. Inkorgens krypterade e-posttext söks inte.
- "Senast öppnade" (`recent_items`) sparar typ, id, titel och adress per
  användare och företag, högst 50 rader. Titeln slås upp på nytt genom
  sökningen med samma behörighetskontroll innan den visas.

## 2. Kontoplan

- Ett nytt företag får en förifylld kontoplan med vanliga konton enligt BAS 2026,
  vald efter regelverk: K1 (enskild firma, ideell förening) eller K2/K3. Mallarna finns i `database/data/bas/2026/`.
- Kontonummer är fyrsiffriga (1000–9999) och unika inom företaget. Kontotyp sätts
  efter första siffran: 1 tillgång, 2 eget kapital och skulder, 3 intäkt, 4–9
  kostnad.
- Ett kontonummer ändras aldrig. Konton kan byta namn och inaktiveras, men tas
  aldrig bort.
- Varje tillagt eller ändrat konto loggas i behandlingshistoriken med värdet före
  och efter.
- Ett inköpskonto (4000–4899) kan markeras som handelsvaror. Det sparas som
  kontots SRU-kod 7512 (INK2R 3.6) och följer med i SIE-filens #SRU; en SIE-fil
  med #SRU 7512 på ett inköpskonto som läggs till vid inläsningen ger samma
  markering (#89).

## 3. Räkenskapsår

- Ett räkenskapsår börjar den första och slutar den sista dagen i en månad.
- Normalt är det tolv månader. När bokföringsskyldigheten börjar eller året
  läggs om får det vara kortare (ingen nedre gräns) eller högst 18 månader
  (BFL 3 kap. 3 §). Det gäller när det första årets datum ändras och när ett år
  läses in från en SIE-fil.
- En enskild firma ska ha kalenderår (BFL 3 kap. 1 §). Ett nytt företag med
  företagsformen enskild firma får räkenskapsår från januari, och det första
  året kan bara ändras så att det slutar den 31 december. Har en befintlig
  enskild firma brutet räkenskapsår ändras inget år som finns; sidan
  Räkenskapsår, företagsinställningarna och SIE-granskningen visar en
  varning, och nästa år som skapas slutar den 31 december (omläggning till
  kalenderår), därefter hela kalenderår.
- Handels- och kommanditbolag ska ha kalenderår om en fysisk person beskattas
  för bolagets inkomst. NONOX känner inte till delägarna; väljs en annan
  startmånad än januari måste användaren bekräfta att ingen fysisk person
  beskattas, och bekräftelsen loggas.
- Regelverk (K1/K2/K3): K1 kan bara väljas för enskild firma och ideell
  förening. För K1 och K2 visas storleksvillkoren (K1: nettoomsättning normalt
  högst 3 miljoner kronor; K2: inte större företag enligt ÅRL 1 kap. 3 §, inte
  publikt aktiebolag eller moderföretag i större koncern), som användaren
  bekräftar; storleken kontrolleras inte eftersom NONOX saknar uppgifterna.
  Bekräftelsen loggas i behandlingshistoriken. Reglerna gäller när företaget
  skapas och när värdet ändras i inställningarna; ett sparat värde som ser fel
  ut behålls och visas med en varning.
- Reglerna ovan bygger på AI-utredning 2026-11-02; juristgranskning återstår
  (ADR-010).
- Ett nytt räkenskapsår skapas alltid direkt efter det senaste, så att åren aldrig
  överlappar eller lämnar en lucka.
- Det första räkenskapsåret kan få nya datum så länge det är företagets enda år och
  är öppet.
- Ett räkenskapsår är öppet eller stängt. Stängning sker vid bokslut, som ännu inte
  finns.

## 4. Verifikationer

### 4.1 Serier och numrering

- Varje företag har serierna A Manuell, B Kundfakturor, C Kundinbetalningar,
  D Leverantörsfakturor, E Leverantörsbetalningar, L Lön, M Moms och
  I Ingående balanser/import.
- En verifikation får sitt nummer när den bokförs, inte när den skapas. Numret är
  löpande och utan luckor per serie och räkenskapsår och börjar på 1 varje år.
- Numret sätts medan räkenskapsårets rad i databasen är låst, så två samtidiga
  bokföringar kan inte få samma nummer. Misslyckas en bokföring används numret
  inte.

### 4.2 Utkast och bokföring

- En verifikation börjar som utkast. Utkast kan ändras och tas bort fritt.
- Vid bokföringen kontrolleras att verifikationen har minst två rader, att debet
  och kredit är lika stora och större än noll, att datumet ligger i ett öppet
  räkenskapsår, att perioden inte är låst och att alla konton är aktiva.
  Databasen kontrollerar balansen en gång till.
- Periodlåset kontrolleras medan bokföringen håller ett delat lås (FOR KEY
  SHARE) på företagets rad. Den som låser en period (periodlås, stängning av
  räkenskapsår) tar ett uppdateringslås (FOR UPDATE) på samma rad. Därför
  väntar de in varandra: en bokföring som pågår blir klar innan låset flyttas,
  och en bokföring som börjar efteråt ser det nya låset. Låsen tas alltid i
  ordningen verifikation, räkenskapsår, företag, nummerräknare.
- Varje rad har antingen ett debet- eller ett kreditbelopp i hela ören, aldrig
  båda och aldrig negativt.
- Raderna visas och skrivs i ordningen konto, kostnadsställe, projekt, radtext,
  debet, kredit och kontosaldo; kostnadsställe och projekt bara när företaget
  använder dem. Kontosaldot är bara information och påverkar inte bokföringen:
  räkenskapsårets ingående balans plus de bokförda verifikationerna, aldrig
  utkast. Medan en verifikation skrivs är det saldot till och med
  verifikationens datum plus raderna ovanför på samma konto; på en bokförd
  verifikation saldot efter raden i huvudbokens ordning (datum, serie, nummer,
  rad), samma belopp som huvudboken visar. Saldot visas på kontots normala
  sida: tillgångar och kostnader som debet, eget kapital, skulder och intäkter
  som kredit, så ett negativt saldo betyder till exempel ett övertrasserat
  bankkonto. Saldona räknas med en fråga per verifikation
  (`App\Domain\Accounting\AccountBalances`).

### 4.3 Oföränderlighet

- En bokförd verifikation och dess rader kan aldrig ändras eller tas bort. Det
  hindras av behörighetsregler, av programmet och av databasen själv, även vid
  direkta databasändringar.

### 4.4 Rättelse

- Ett fel rättas med en rättelseverifikation. Den vänder alla rader i den
  felaktiga verifikationen och kan samtidigt bokföra rätt rader.
- Rättelsen hänvisar till originalet och originalet till rättelsen. En orsak
  måste anges. En verifikation kan rättas en gång.

### 4.5 Underlag

- Kvitton och fakturor sparas som underlag till verifikationen: PDF, JPG, PNG
  eller WebP, högst 10 MB. Filtypen bestäms av filens innehåll, inte av namnet.
- Varje fil får en kontrollsumma (SHA-256) när den sparas och sparas under ett
  genererat namn utanför den publika webbplatsen.
- Ett underlag kan aldrig ändras. Det kan tas bort så länge verifikationen är ett
  utkast, men aldrig efter att den har bokförts.
- Underlag kan läggas till en bokförd verifikation, till exempel ett kvitto som
  kommer senare. Verifikationen i sig ändras inte, och tillägget skrivs i
  behandlingshistoriken (se öppen fråga nedan).
- **Delade filer.** Samma sparade fil kan vara underlag för flera poster:
  ett mejl i inkorgen blir underlag för varje verifikation det leder till,
  och en leverantörsfakturas fil och ett utläggskvitto kopplas till den
  verifikation som bokför dem. Filen kopieras inte; varje post pekar på
  samma fil med samma kontrollsumma. När ett utkast tas bort, ett underlag
  tas bort från ett utkast, ett inkorgsmeddelande raderas eller gallras,
  ett utkast till leverantörsfaktura tas bort eller ett utlägg gallras eller
  raderas tas först posten bort. I samma databastransaktion, med ett lås per
  fil, kontrolleras sedan om någon annan post – i någon tabell och i något
  företag – fortfarande pekar på filen. Bara en fil som ingen pekar på
  raderas från disken, och först när transaktionen har gått igenom. En fil
  som en bokförd verifikation eller inkorgen pekar på raderas alltså aldrig.
  Listan över kolumner som kan peka på en fil kontrolleras av ett test mot
  databasschemat, så att en ny tabell inte kan glömmas bort. Grund: mottagen
  uppgift ska användas som verifikation (BFL 5 kap. 6 §), mottagna
  elektroniska handlingar bevaras i det skick de kom (BFL 7 kap. 1 § tredje
  stycket) i sju år (7 kap. 2 §), och verifikationens uppgifter får inte
  raderas (BFNAR 2013:2 punkt 5.4) – enligt granskning 2026-11-02,
  juristgranskning återstår (AUD-BOK-1, #41).
- När alla utkast från ett inkorgsmeddelande har tagits bort och inget är
  bokfört från det går meddelandet tillbaka till **Att granska**, så att det
  kan godkännas igen eller raderas. Det skrivs i behandlingshistoriken.
- Den nattliga kontrollen (`ledger:verify`) rapporterar fortfarande varje
  underlag som saknas eller har ändrats.

### 4.6 Konteringsmallar

- Mallarna fyller i rader utifrån ett belopp inklusive moms, till exempel
  "Inköp med 25 % moms". Momsen räknas fram som belopp × momssats ÷ (1 +
  momssats), avrundat till närmaste öre, och nettobeloppet som belopp minus moms,
  så att verifikationen alltid balanserar.
- Mallen bokför ingenting själv. Användaren granskar raderna och bokför som
  vanligt.

### 4.7 Kontroll av huvudboken

- Varje bokförd verifikation får en kontrollsumma (SHA-256) som bygger på dess
  innehåll och på den föregående verifikationens kontrollsumma i samma
  räkenskapsår. En ändring i efterhand bryter kedjan och upptäcks.
- Kommandot `php artisan ledger:verify` räknar om kedjan och kontrollerar balans,
  numrering och att varje underlag finns kvar och är oförändrat. Det körs automatiskt varje natt klockan 03.15, och resultatet
  sparas i behandlingshistoriken ([ADR-007](../adr/ADR-007-vouchers.md)).

### 4.8 Ingående balanser

- Ingående balans lagras per räkenskapsår och balanskonto i hela ören, debet
  positivt och kredit negativt (tabellen `opening_balances`). Den är inte en
  verifikation ([ADR-008](../adr/ADR-008-sie-and-opening-balances.md)).
- Utgående balans för ett balanskonto är ingående balans plus årets bokförda
  verifikationer på kontot. Årets resultat per resultatkonto är summan av årets
  bokförda verifikationer.
- Ingående balanser kan bara ändras medan räkenskapsåret är öppet. Både
  programmet och databasen vägrar ändringar för ett stängt år.

### 4.9 Export i SIE4-format

- Varje räkenskapsår kan laddas ner som en SIE-fil av typ 4 (SIE filformat
  utgåva 4C) från sidan Räkenskapsår. Det kräver behörigheten `export:sie`.
- Filen innehåller företagets uppgifter, hela kontoplanen, ingående och
  utgående balanser, resultat för året och året före samt alla bokförda
  verifikationer med rader, serie för serie i nummerordning. Utkast ingår inte.
- Filen skrivs i teckentabell 437 (PC8) med en kontrollsumma (`#KSUMMA`), så att
  den som läser filen kan se om den har ändrats eller skadats på vägen.
- Varje export skrivs till behandlingshistoriken.

### 4.10 Kostnadsställen och projekt

- En verifikationsrad kan ha ett kostnadsställe och ett projekt, till exempel
  en restaurang och en ombyggnad ([ADR-009](../adr/ADR-009-cost-centres-and-projects.md)).
- Varje kostnadsställe och projekt har en kod som aldrig ändras och ett namn
  som kan bytas. De tas aldrig bort, bara inaktiveras. Ett inaktiverat går
  inte att välja på nya verifikationer men finns kvar på gamla.
- Ett konto kan kräva kostnadsställe eller projekt. Då går en verifikation
  inte att bokföra utan det.
- En rättelse får samma kostnadsställe och projekt som raderna den vänder.
- Kostnadsställen och projekt följer med i SIE-export och SIE-import
  (dimension 1 och 6).
- Varje ändring skrivs till behandlingshistoriken.
- Listorna över kostnadsställen och projekt visar intäkter, kostnader och
  resultat per objekt för innevarande räkenskapsår (bokförda rader på
  resultatkonton med objektet), för den som får se rapporter.

### 4.11 Inläsning av SIE4-filer

- Bokföring från ett annat program läses in med en SIE-fil av typ 4, ett
  räkenskapsår per fil, från sidan Läs in SIE-fil. Det kräver behörigheten
  `import:sie`.
- Inläsningen sker i två steg. Först granskas filen utan att något ändras, och
  användaren får en rapport. Filen läses in först när användaren har bekräftat.
- Filen läses inte in om kontrollsumman saknas i slutet eller inte stämmer, om
  den gäller ett annat organisationsnummer, om en verifikation inte
  balanserar eller ligger utanför räkenskapsåret, om verifikationerna redan är
  inlästa, om räkenskapsåret är stängt eller om perioden är låst.
- Allt läses in i en enda databastransaktion: räkenskapsår, konton, serier,
  ingående balanser och verifikationer. Verifikationerna bokförs som vanliga
  bokförda verifikationer med källan "SIE-import" och ingår i kedjan av
  kontrollsummor (avsnitt 4.7).
- En serie behåller filens nummer om den är tom här och filens nummer är
  obrutna från 1. Annars får verifikationerna nästa lediga nummer här, så att
  numreringen förblir obruten. Filens serie, nummer, registreringsdatum och
  signatur sparas för varje verifikation.
- Rader som filen anger som tillagda (`#RTRANS`) eller borttagna (`#BTRANS`)
  i efterhand sparas som historik med verifikationen. Den historiken och
  kopplingen till filens verifikationer kan bara läggas till, aldrig ändras
  eller tas bort; databasen vägrar.
- Efter inläsningen jämförs varje kontos utgående balans och resultat, och
  antalet verifikationer, med filen. Stämmer något inte, rullas hela
  inläsningen tillbaka och skillnaderna visas.
- Varje granskning och inläsning skrivs till behandlingshistoriken.

### 4.12 Rapporter

- Saldobalans, resultatrapport, balansrapport, huvudbok och verifikationslista
  tas fram ur de bokförda verifikationerna och de ingående balanserna, för
  valfri period inom ett räkenskapsår och per kostnadsställe eller projekt
  ([ADR-010](../adr/ADR-010-reports-vat-year-end.md)). Utkast ingår aldrig.
- Rapporterna kan laddas ner som CSV och skrivas ut eller sparas som PDF.
- Varje konto i rapporterna och på en verifikation leder till huvudboken för
  just det kontot (kontoanalys) med samma år, period, kostnadsställe och
  projekt, och därifrån till verifikationerna och deras kontering.

### 4.13 Moms

- Momsdeklarationens rutor räknas fram ur rörelserna på momskonton och
  försäljnings- och inköpskonton enligt BAS, per redovisningsperiod. Beloppen
  anges i hela kronor.
- Momsen bokförs med en verifikation i serie M per period: momskontona
  nollställs, beloppet förs till 2650 och öresavrundningen till 3740. Rutorna
  sparas med verifikationen och kan inte ändras. En period bokförs en gång.
- Efter deklarationen kan bokföringen låsas till och med periodens sista dag.
  Låset kan bara flyttas framåt.

### 4.14 Årsskifte

- Utgående balanser för balanskonton förs över som ingående balanser till
  nästa räkenskapsår, som skapas om det inte finns. Ett resultat som inte är
  bokfört förs till konto 2099 (2019 för enskild firma och handelsbolag).
- Överföringen kan göras om så länge nästa år är öppet; de ingående
  balanserna ersätts då och det skrivs i behandlingshistoriken.

## 5. Fakturering

- Kunder och artiklar finns i egna register. Kunder får löpnummer;
  personnummer sparas krypterade.
- En faktura är ett utkast tills den skapas. Då får den nästa nummer i en
  obruten nummerserie, ett OCR-nummer med kontrollsiffra och en kopia av
  kundens uppgifter. Därefter kan fakturan inte ändras; databasen vägrar.
  Ett fel rättas med en kreditfaktura
  ([ADR-011](../adr/ADR-011-invoicing.md)).
- Belopp: rad = antal × pris minus rabatt, moms per momssats på radernas
  summa, totalen avrundad till hela kronor (öresavrundning).
- Bokföring enligt fakturametoden: när fakturan skapas, verifikation i serie
  B (1510 mot intäktskonton, utgående moms och 3740). Betalning: serie C,
  1930 mot 1510. Enligt kontantmetoden bokförs intäkt och moms först vid
  betalning.
- En kreditfaktura tar ut hela den obetalda fakturan och vänder dess
  verifikation.
- Inbetalningar kan läsas in från bankens fil (ISO 20022 camt.054,
  [ADR-015](../adr/ADR-015-incoming-payment-files.md)). Systemet föreslår
  faktura efter OCR-numret; användaren kontrollerar och bokför. En inbetalning
  bokförs högst en gång, även om den finns i flera filer. Filen sparas som den
  kom, med kontrollsumma.
- Avtal (avtalsfakturering) skapar en vanlig faktura per period, som utkast
  eller skapad och skickad direkt. Fakturan bokförs som alla andra fakturor.
- Offerter och order kan bli order respektive fakturautkast med samma rader.
  De bokförs inte; det gör först fakturan.
- Varje skapad, skickad och betald faktura, offert och order skrivs till
  behandlingshistoriken.

### 5.1 Omvänd betalningsskyldighet för byggtjänster

- En kund kan markeras som byggföretag med omvänd betalningsskyldighet. Nya
  fakturor till kunden blir då utan moms; det går att ändra per faktura, och
  en kreditfaktura behåller originalets behandling.
- Fakturan visar texten "Omvänd betalningsskyldighet för byggtjänster" och
  både säljarens och köparens momsregistreringsnummer. Fakturan kan inte
  utfärdas om något av numren saknas.
- Försäljningen bokförs på 3231 (läggs till i kontoplanen vid behov) i
  stället för radernas intäktskonton, och redovisas i momsdeklarationens
  ruta 41.

### 5.1a Försäljning till andra EU-länder och export

- En kund har ett land (landskod, standard SE) och kan ha en vanlig typ av
  försäljning utomlands, som nya fakturor får; det går att ändra per faktura
  och en kreditfaktura behåller originalets val.
- Fakturan blir utan svensk moms och bokförs på kontot för typen i stället
  för radernas intäktskonton (läggs till i kontoplanen vid behov):

  | Typ | Konto | Momsruta |
  |---|---|---|
  | Varor till företag i annat EU-land | 3108 | 35 |
  | Tjänster till företag i annat EU-land | 3308 | 39 |
  | Export av varor utanför EU | 3105 | 36 |
  | Tjänster till kund utanför EU | 3305 | 40 |

- Vid EU-försäljning måste kunden ha ett annat EU-land än Sverige och ett
  momsregistreringsnummer från det landet (EL för Grekland), och företaget
  ett eget. Vid export måste kunden ha ett land utanför EU. Fakturan visar en
  text på svenska och engelska och båda momsregistreringsnumren.
- **Trepartshandel som mellanman:** konto 3107, ruta 38, ingen moms; kräver
  köparens momsregistreringsnummer som övrig EU-försäljning.
- **Privatperson i annat EU-land (OSS):** fakturan får köparlandets
  normalskattesats (`OssRates`, per land och från-datum), försäljningen
  bokförs på 3106 och momsen på 2619. Konto 2619 räknas inte in i någon ruta
  och flyttas inte till 2650 vid momsavräkningen, eftersom momsen redovisas i
  OSS-deklarationen. Kräver inget momsregistreringsnummer men ett EU-land.
- **Tröskelvärdet för OSS** (ML 6 kap. 62–65 §§, `OssThreshold`, enligt
  AI-utredning 2026-11-02, juristgranskning återstår): så länge företagets
  försäljning till privatpersoner i andra EU-länder (alla länder tillsammans,
  exklusive moms, i kronor, utfärdade fakturor med kreditfakturor avdragna,
  per kalenderår efter fakturadatum) är högst 99 680 kr i år och var det i
  fjol, får en sådan faktura **svensk moms**: radernas egna momssatser och
  konton, utgående moms på 2611/2621/2631 och rutorna 05 och 10–12 i den
  vanliga momsdeklarationen. Fakturan markeras
  `swedish_vat_under_threshold` och räknas mot tröskeln men tas inte med i
  OSS-underlaget och får ingen OSS-text. Den faktura som gör att summan går
  över tröskeln, och alla senare, får köparlandets moms via OSS (6 kap.
  63 §). Var förra årets försäljning över tröskeln gäller OSS hela året.
  Beloppet finns på ett ställe (`OssThreshold::AMOUNT_ORE`) med källa.
  - Företaget kan ange att det har begärt beskattning i köparens land eller
    är registrerat för OSS (6 kap. 64 §), med ett från-datum
    (`teams.oss_elected`, `oss_elected_from`, under Företagsuppgifter, i
    behandlingshistoriken som `company.updated`). Då används OSS från det
    datumet oavsett tröskeln.
  - Beslutet tas när utkastet sparas och kontrolleras igen när fakturan
    utfärdas: har andra fakturor sedan dess flyttat företaget över tröskeln
    avvisas utfärdandet tills utkastet sparats igen. En kreditfaktura följer
    alltid fakturan den krediterar.
  - Fakturaredigeraren visar hur mycket som sålts i år och i fjol, varnar
    när 80 % av tröskeln är nådd och säger till när fakturan går över den.
- **VIES:** ett kundnummer kan kontrolleras mot EU:s register VIES från
  kundformuläret. Svaret, namnet och konsultationsnumret sparas i
  `vat_number_checks` (append-only, modell och databastrigger). Var senaste
  kontrollen av numret ogiltig går en momsfri EU-faktura inte att utfärda.
  Ett leverantörsnummer kan kontrolleras på samma sätt från
  leverantörsformuläret; svaret visas och loggas i behandlingshistoriken med
  konsultationsnumret (`supplier.vat_number_checked`) men stoppar ingenting.
- **Företagsuppgifter från register (ADR-035):** i formulären för nytt
  företag, företagsinställningar, kunder och leverantörer kan namn, adress,
  postnummer, ort och (för nytt företag) företagsform hämtas från
  Bolagsverkets API för värdefulla datamängder med organisationsnumret.
  Numret kontrolleras med Luhn innan något skickas, och en enskild firmas
  personnummer skickas med århundrade. Svaret fyller bara tomma fält (eller
  fält som en tidigare hämtning fyllt i); fält som användaren skrivit något
  annat i ändras först när användaren väljer registrets uppgifter. Inget
  sparas förrän formuläret sparas, och hämtningen påverkar ingen bokföring.
  Svaren cachas en kort stund (10 minuter) under en hash av numret, och
  antalet hämtningar begränsas per företag, användare och installation.
  Funktionen syns bara när installationen har uppgifter från Bolagsverket.
- **EU-rapporter** (`/eu-rapporter`): periodisk sammanställning per månad
  eller kvartal, per köpares momsregistreringsnummer i kolumnerna varor,
  trepartshandel och tjänster (hela kronor), med fil i Skatteverkets format
  SKV574008 för Filöverföring; samt underlag för OSS-deklarationen per
  kvartal, land och momssats, i kronor som bokfört (för avstämning mot 2619)
  och i euro, som deklarationen lämnas i (ML 22 kap. 42 §). Försäljning
  fakturerad i euro räknas med fakturans eurobelopp; annan valuta, kronor
  inräknat, räknas om från fakturans belopp i den valutan med Europeiska
  centralbankens referenskurs för kvartalets sista dag (Skatteverket,
  "Deklarera och betala moms i One Stop Shop"), eller nästa publicerade kurs
  om ingen publicerades den dagen. Momsen i euro räknas per land och
  momssats på eurounderlaget, halvt uppåt. Kurserna hämtas från ECB:s
  dataportal (`EcbRates`, serie `EXR.D.{valuta}.EUR.SP00.A`, utan nyckel)
  och cachas; saknas en kurs visas kronorna och en upplysning i stället för
  euro. Underlaget kan laddas ner som CSV (`vat.oss_report_downloaded` i
  behandlingshistoriken). Endast utfärdade fakturor räknas; krediteringar
  minskar beloppen.
- Kan inte kombineras med omvänd byggmoms eller ROT/RUT.
- Ruta 35 matades tidigare av 3106 (momspliktig EU-försäljning); den matas nu
  av 3108, momsfri försäljning av varor till annat EU-land.

### 5.2 ROT- och RUT-avdrag

- En faktura till en privatperson (kunden har personnummer) kan ha ROT- eller
  RUT-avdrag. Rader markeras som arbetskostnad; avdraget är en andel av
  arbetskostnaden inklusive moms, avrundat nedåt till hela kronor och inom
  det som återstår av köparens tak för året på företagets egna fakturor.
  Andelar och tak finns i `TaxReduction` och gäller från ett datum.
- Kunden betalar fakturan minus avdraget. Vid utfärdandet bokförs kundens
  del på 1510 och avdraget på 1513 Kundfordringar – delad faktura (läggs
  till vid behov); intäkter och moms bokförs på hela beloppet. För ROT krävs
  fastighetsbeteckning eller bostadsrättsförening. Avdrag fungerar än så
  länge bara med fakturametoden, och inte tillsammans med omvänd
  betalningsskyldighet.
- Utbetalningen begärs i Skatteverkets e-tjänst. När Skatteverket betalar
  registreras det på fakturan: bankkontot mot 1513 i serie C. Det mottagna
  beloppet är det enda på en utfärdad faktura som får ändras utöver status,
  betalningar och utskick (modell och databastrigger).
- En kreditfaktura krediterar även avdraget.

### 5.3 Kundfakturor i utländsk valuta

([ADR-025](../adr/ADR-025-exchange-differences.md))

- En kund har en valuta (ISO 4217, standard SEK) som nya fakturor börjar i;
  den kan ändras per faktura. En faktura i annan valuta skrivs i den valutan:
  priserna och fakturans summor är hundradelar av valutan och avrundas inte
  till hela enheter (öresavrundning bara i kronor).
- **Kurs.** Fakturan bokförs till en kurs i kronor per enhet (miljondelar,
  `exchange_rate_micros`). Samma kurs används för bokföringen och för
  momsens beskattningsunderlag, och den tas för dagen då momsen uppstår
  (ML 8 kap. 21 §, 7 kap. 3–4 §§; enligt AI-utredning 2026-11-02,
  juristgranskning återstår): leveransdatumet när fakturan har ett, annars
  fakturadatumet, som då får stå för leveransen. Förvald är Riksbankens
  mittkurs (SWEA, `SEK{valuta}PMI`) den dagen eller senaste bankdag före
  (dagens kurs om dagen ligger i framtiden); användaren kan skriva en egen. Kursen, dess datum och källa (`riksbank` eller
  `manual`) sparas på fakturan.
- **Belopp i kronor.** Varje rad räknas om till kronor med kursen (halvt
  uppåt). Momsen i kronor räknas per momssats på radernas kronor, och
  fakturans kronor (`net_ore`, `vat_ore`, `total_ore`) är det som bokförs och
  det som alla rapporter, momsdeklarationen, SIE-filen och kundreskontrans
  summor visar. Valutabeloppen sparas bredvid (`foreign_*_minor`, även per
  rad).
- **Fakturan** visar rader och summor i valutan, och under summan momsen per
  sats och summan i kronor, med kursen och dess källa. Företagets IBAN och
  BIC visas i betalningsrutan.
- **Bokföring** som en faktura i kronor: fakturametoden i serie B när
  fakturan skapas (verifikationstexten anger valutabelopp och kurs),
  kontantmetoden vid betalning.
- **Betalning** registreras i valutan: hur mycket av valutabeloppet som
  betalas (förifyllt med det som återstår) och hur många kronor som kom in på
  banken (förifyllt med Riksbankens kurs på betaldagen, att rätta mot
  kontoutdraget). Betalningen minskar fordran med sin andel av fakturans
  kronor (`kronor × betalt i valuta / valutabelopp`, halvt uppåt); den
  betalning som gör valutabeloppet fullt betalt tar exakt det som återstår,
  så 1510 slutar på noll. Skillnaden mot kronorna på banken bokförs i samma
  verifikation i serie C: kursvinst kredit 3960, kursförlust debet 7960
  (läggs till med BAS-namn vid behov). Enligt kontantmetoden bokförs intäkter
  och moms med sin andel av fakturans kronor, alltså till fakturans kurs, och
  skillnaden mot banken på 3960/7960. Betalningen sparar valutabeloppet och
  kursdifferensen (`invoice_payments.foreign_amount_minor`,
  `exchange_difference_ore`, positiv vinst). Mer än det som återstår i
  valutan avvisas, liksom ett valutabelopp på en faktura i kronor.
- **Bankens fil** (camt.054): skiljer sig det överförda beloppets valuta
  (`InstdAmt`) från kontots sparas valutan och beloppet på inbetalningen
  (`bank_transactions.foreign_currency`, `foreign_amount_minor`), och det
  bokförda beloppet i kontots valuta används som kronor. En faktura i valuta
  föreslås på OCR-numret när valutabeloppet ryms i det som återstår; saknar
  filen valutabelopp räknas inbetalningen som betalning av hela det som
  återstår i valutan. Konton i annan valuta än kronor stöds inte.
- **Kreditfaktura** får alltid originalets valuta och kurs (även om utkastet
  sparas med något annat), så den tar ut fordran exakt.
- **Påminnelse** gäller det som återstår i valutan, som sparas på
  påminnelsen (`invoice_reminders.foreign_remaining_minor`).
- ROT- och RUT-avdrag kräver kronor. Offerter, order och avtalsfakturor är i
  kronor.
- Valuta och kurs ändras aldrig efter att fakturan skapats: modellen och
  databastriggern släpper bara igenom status, betalt i kronor och i valuta,
  Skatteverkets ROT/RUT-betalning och utskick. En kontroll i databasen kräver
  valutabelopp och positiv kurs när valutan inte är SEK.
- Behandlingshistoriken får valuta, valutabelopp och kurs när fakturan skapas
  (`invoice.issued`) och valutabelopp, avräknade kronor och kursdifferens vid
  betalning (`invoice.payment_registered`).
- API och webhooks: se 5f; beloppen i `*_ore` är alltid kronor.

## 5a. Leverantörsfakturor

- Leverantörer finns i ett eget register med löpnummer. Bankgiro och plusgiro
  kontrolleras mot kontrollsiffran.
- En faktura som tolkats (av NONOX AI eller i inkorgen) kopplas till en
  leverantör som redan finns i registret (`SupplierMatcher`), i den här
  ordningen: organisationsnummer, momsregistreringsnummer, bankgiro,
  plusgiro, IBAN, e-postdomän (inte för gratistjänster som Gmail) och sist
  namnet utan bolagsform och skiljetecken. Aktiva leverantörer går före
  inaktiva. "Lägg till leverantör" väljer en befintlig träff i stället för
  att skapa en kopia, och ett liknande namn föreslås som "Menade du …?" som
  en person kan koppla.
- En leverantörsfaktura är ett utkast tills den registreras. Fakturan sparas
  som PDF eller bild; filtypen läses från innehållet. Raderna (kostnad utan
  moms per konto, med kostnadsställe eller projekt) plus momsen måste vara
  lika med beloppet att betala.
- När fakturan registreras får den nästa nummer i en obruten nummerserie och
  en kopia av leverantörens uppgifter. Därefter kan den inte ändras eller tas
  bort; databasen vägrar ([ADR-013](../adr/ADR-013-supplier-invoices.md)).
- Bokföring enligt fakturametoden: vid registreringen, verifikation i serie D
  på fakturadatum (kostnadskonton och 2641 ingående moms debet, 2440
  leverantörsskulder kredit) med fakturan som underlag. Betalning: serie E,
  2440 mot 1930. Enligt kontantmetoden bokförs kostnad och moms först vid
  betalning, med fakturan som underlag på den första betalningen.
- Attest: en registrerad faktura måste attesteras innan den kan betalas.
  Den som registrerade fakturan får inte attestera den, om inte företaget har
  stängt av kravet (för företag med en person). Ändringen av kravet skrivs
  till behandlingshistoriken.
- Varje sparat utkast, registrering, attest och betalning skrivs till
  behandlingshistoriken. Betalningar kan bara läggas till, aldrig ändras.
- Leverantörsfakturor där köparen redovisar momsen (inköp från andra EU-länder,
  tjänster från länder utanför EU och omvänd betalningsskyldighet i Sverige)
  bokförs på de BAS-konton som ger rätt rutor i momsdeklarationen, med
  utgående och ingående moms. Kreditnotor från leverantörer bokförs med
  omvända belopp och kvittas mot en faktura eller betalas tillbaka
  ([ADR-016](../adr/ADR-016-supplier-credit-and-foreign-purchases.md)).
- Leverantörsfakturor i utländsk valuta
  ([ADR-025](../adr/ADR-025-exchange-differences.md)): fakturan sparar valuta
  (ISO 4217), beloppet i valutan (i hundradelar) och kursen den bokförs till
  (kronor per enhet, i miljondelar). Kursen fylls i med Riksbankens mittkurs
  på fakturadatumet och kan ändras; beloppet i kronor måste vara valutabeloppet
  gånger kursen, med högst en krona i avvikelse. Fakturan bokförs i kronor som
  andra fakturor.
- Betalning av en faktura i utländsk valuta: användaren anger hur mycket av
  valutabeloppet som betalas (förifyllt med det som återstår) och hur många
  kronor banken drog (förifyllt med Riksbankens kurs på betaldagen, ändras
  till beloppet på kontoutdraget). Betalningen minskar skulden med sin andel
  av de bokförda kronorna, avrundat till hela ören, och den sista betalningen
  med allt som återstår, så att 2440 går jämnt ut. Verifikationen i serie E:
  2440 debet med den bokförda andelen, 1930 kredit med kronorna som betalades
  och skillnaden på 7960 Valutakursförluster (debet) eller 3960
  Valutakursvinster (kredit) på fordringar och skulder av rörelsekaraktär.
  Samma kurs ger ingen differensrad. Enligt kontantmetoden bokförs kostnader
  och moms till den bokförda kursen och skillnaden på samma sätt. Saknas
  kontot i kontoplanen läggs det till med BAS-namnet och det loggas.
  Delbetalningar minskar valutabeloppet; det som återstår i valutan visas
  bredvid kronorna. Betalningen sparar valutabeloppet och kursdifferensen och
  skrivs till behandlingshistoriken. Fakturor i utländsk valuta kan inte tas
  med i betalfiler (de betalar kronor till bankgiro och plusgiro) och kan
  inte kvittas mot kreditnotor ännu. Orealiserade kursdifferenser på obetalda
  fakturor vid bokslutet bokförs i bokslutet (avsnitt 5a5).
- Betalfiler: attesterade fakturor kan betalas med en fil till banken (ISO
  20022 pain.001.001.03, [ADR-014](../adr/ADR-014-payment-files.md)). Filen
  sparas som den skapades, med kontrollsumma, och kan inte ändras. Betalningen
  bokförs när användaren bekräftar att banken har betalat, på betaldagen i
  serie E, eller när bankens svar läses in (avsnitt 5a1b).
  Betalningsuppgifterna tas från leverantörens uppgifter när fakturan
  registrerades.
- Godkännande av betalfiler: en ny betalfil måste godkännas av någon med
  rätt att godkänna betalningar (`payments:approve`: ägare, administratör och
  attestant) innan den kan laddas ned och läsas in i banken, och innan den kan
  markeras som betald. Den som skapade filen får inte godkänna den, med samma
  inställning som för attest av fakturor (krav på att någon annan attesterar,
  på som standard). Godkännandet sparas en gång med tid och person, skrivs
  till behandlingshistoriken (`payment_batch.approved`, med filens
  kontrollsumma) och kan inte ändras eller tas bort; databasen vägrar också
  att markera en fil som betald utan godkännande. En fil som inte är godkänd
  kan makuleras. Betalfiler som bekräftades innan godkännandet infördes
  saknar godkännande men kan laddas ned som historik.

### 5a1b. Bankens svar på betalfiler (automatisk avstämning)

Bankens svar på en betalfil läses in under Leverantörsfakturor → Betalfiler
([ADR-014](../adr/ADR-014-payment-files.md)). Koden finns i
`App\Support\Pain002\PaymentStatusReport`,
`App\Support\Camt054\DebitNotification`,
`App\Domain\Payables\OutgoingPaymentMatcher` och
`App\Actions\Payables\ApplyPaymentResponses`.

- **Filer som läses.** Statusrapport (ISO 20022 pain.002.001.03 och senare
  versioner), debiteringsavisering (camt.054.001.02 och senare) och
  kontoutdrag (camt.053). Filen får vara högst 10 MB, läses utan nätverk och
  avvisas om den har en DOCTYPE (inga externa eller expanderande entiteter).
  Filen sparas som den kom med sin SHA-256 i `payment_response_imports`, som
  databasen inte låter ändra eller ta bort. Samma fil (samma SHA-256) kan inte
  läsas in två gånger.
- **Referenser.** Varje betalning i en betalfil får ett eget end-to-end-id,
  `LF<fakturans löpnummer>-<betalfilens nummer>`, och filen har ett eget
  meddelande-id (`MsgId`, också `PmtInfId`). Bankerna skickar tillbaka dem i
  sina svar. Betalfiler från före den här ändringen har end-to-end-id
  `LF<löpnummer>`; de hittas också, och finns samma id i flera filer väljs
  den som väntar på banken.
- **Svar.** Varje svar sparas i `payment_responses` med vad banken sa (typ,
  bankens referens, referenserna ovan, belopp, datum, mottagarens konto,
  orsakskod och bankens text). Ett svar identifieras av typ och bankens
  referens (eller ett fingeravtryck när banken inte ger någon), så samma svar
  i två filer läses bara en gång. Databasen låter bara ett öppet svar
  (`SUGGESTED`, `UNMATCHED`) få sin matchning och sitt utfall, en gång;
  innehållet ändras aldrig och inget tas bort.
- **Matchning.** 1) På referens: end-to-end-id, avgränsat av filens
  meddelande-id när banken anger det. Ett svar utan end-to-end-id (hela filen
  avvisad, eller hela filen dragen som ett belopp) kopplas till filen på
  meddelande-id. 2) Annars, för pengar som dragits: samma belopp i kronor,
  bokföringsdag från 3 dagar före till 10 dagar efter filens betaldag, samma
  bankgiro eller plusgiro om banken anger mottagarens konto, bland
  betalningar som varken är betalda eller avvisade. Bara när exakt en
  betalning passar blir det ett förslag, som en person bekräftar. Ett svar
  utan referens som inte passar någon betalning (till exempel ett kortköp i
  ett kontoutdrag) sparas inte.
- **Godkänd** (pain.002 ACCP, ACTC, ACSP, ACSC, ACWC, ACCC): betalningen
  markeras som godkänd av banken. Inget bokförs.
- **Avvisad** (pain.002 RJCT, för en betalning, ett betalningsblock eller hela
  filen): betalningen markeras som avvisad med bankens orsakskod och text
  (`supplier_payment_batch_items.bank_status`, `reason_code`,
  `reason_text`). Orsaken visas på svenska efter ISO 20022:s kodlista
  (ExternalStatusReason1Code, `App\Domain\Payables\BankReasonCode`), med
  bankens egen text bredvid. Fakturan var aldrig bokförd som betald; den
  är attesterad och obetald och kan läggas i en ny betalfil. Skrivs till
  behandlingshistoriken (`payment_batch.payment_rejected`) och visas under
  "Att göra" på Översikt tills fakturan är betald eller ligger i en ny fil.
  Kommer en avvisning för en betalning som redan är bokförd görs ingenting
  automatiskt; svaret väntar på en person som rättar med en
  rättelseverifikation.
- **Dragen från kontot** (camt.054/camt.053, bokförd debitering): betalningen
  bokförs genom samma `PaySupplierInvoice` som när en person registrerar en
  betalning eller bekräftar filen, på bankens bokföringsdag i serie E, så
  fakturametoden och kontantmetoden bokförs exakt som annars. Bara ett svar
  som matchats på referens, i en fil som en person läst in, bokförs
  automatiskt. Det bokförs inte automatiskt när filen inte är godkänd i
  NONOX (svaret väntar tills den är godkänd), när beloppet skiljer sig från
  filen, när betalningen inte är i kronor, när fakturan har betalats på annat
  sätt eller när betalfilen redan är makulerad eller betald. En betalning som
  redan har en betalning bokförd bokförs aldrig igen; svaret blir "Redan
  hanterad". Alla bokföringar för ett svar sker i en transaktion.
- **Återförd** (camt.054 kredit med `RvslInd`, med betalarens referenser):
  pengarna har kommit tillbaka. Inget görs automatiskt; en person rättar
  betalningen med en rättelseverifikation och betalar igen.
- **Filen stängs** när varje betalning i den är betald eller avvisad: betald
  om banken betalade någon, makulerad om banken avvisade alla
  (`payment_batch.confirmed` eller `payment_batch.cancelled` med
  `via: bank_response`). Knappen "Banken har betalat" bokför bara de
  betalningar banken inte redan svarat om, och en fil där någon betalning är
  bokförd kan inte makuleras.
- **Bankkopplingen** (modulen Bank, ADR-034): en hämtad debitering i kronor
  som passar en betalning (på referens eller belopp) blir ett förslag som en
  person bekräftar. Inget bokförs utan en person. Bokförs samma betalning
  sedan från en fil, eller bekräftas filen för hand, blir förslaget "Redan
  hanterad".
- **Behörighet.** Att läsa in svar, bekräfta och avfärda kräver
  `payments:create` (ägare, administratör, redovisningskonsult, bokförare);
  bokföringen kräver dessutom rätt att betala fakturan. Revisorn ser svaren.
  Bekräftelser och avfärdanden skrivs till behandlingshistoriken
  (`payment_response.confirmed`, `payment_response.dismissed`), liksom varje
  inläst fil (`payment_response_file.imported` med SHA-256 och antal).
- **Valuta.** Betalfiler betalar bara kronor, så kursdifferenser uppstår inte
  här. Ett svar i annan valuta bokförs inte automatiskt.

## 5a2. Anläggningsregister

- Under Bokföring → Anläggningar registreras tillgångar som skrivs av över
  flera år (inventarier, datorer, maskiner, bilar) med anskaffningsdag,
  anskaffningsvärde, restvärde, nyttjandeperiod och första avskrivningsmånad,
  samt konton. Förslagen följer BAS (till exempel 1220, 1229 och 7832 för
  inventarier) och kan ändras per tillgång. Inköpet bokförs som vanligt när
  tillgången köps; registret bokför avskrivningarna och avyttringen.
- Avskrivningen är linjär enligt plan: det som ska skrivas av fördelas jämnt
  över nyttjandeperioden. För varje månad räknas hur mycket som ska vara
  avskrivet vid månadens slut, och skillnaden mot det som redan är avskrivet
  bokförs; avrundningen blir därför exakt och en missad månad tas igen. En
  månads avskrivning bokförs en gång, som en verifikation i serie A den sista
  dagen i månaden, och varje tillgångs del sparas i
  `fixed_asset_depreciations`, som databasen inte låter ändra eller ta bort.
- En tillgång som flyttas från ett annat system tar med det som redan är
  avskrivet.
- Vid försäljning eller utrangering bokas anskaffningsvärdet och de
  ackumulerade avskrivningarna bort, priset exklusive moms bokförs på valt
  konto och skillnaden blir vinst (3973) eller förlust (7973).
- När en månad har bokförts kan bara benämning och anteckning ändras.
- Registret kräver behörigheten att hantera räkenskapsår (bokslut).
- **Tillgång direkt från inköpet.** En rad i debet på ett av företagets
  anskaffningskonton, i en verifikation eller en leverantörsfaktura, kan bära
  registrets uppgifter: benämning (radtexten), slag, nyttjandeperiod,
  anskaffningsvärde (tomt: radens belopp utan moms), anskaffningsdag (tomt:
  verifikationens datum eller fakturadatum) och första avskrivningsmånad
  (tomt: anskaffningsmånaden). Kontot för ackumulerade avskrivningar och
  avskrivningskontot följer slaget, radens kostnadsställe följer med.
  - Anskaffningskontona väljer företaget under Anläggningar → Tillgångar från
    inköp. Utan eget val gäller tillgångskontona för registrets slag (1210,
    1220, 1240, 1250); kontona för ackumulerade avskrivningar (12x9) är inte
    med, eftersom en debet där är en avyttring.
  - Under företagets gräns för inventarier av mindre värde visas bara en
    upplysning, inget förslag. Gränsen är företagets egen inställning och
    NONOX föreslår inget belopp (`TODO(legal-verify)`: halva prisbasbeloppet
    exklusive moms enligt Skatteverket, inventarier som köps samtidigt räknas
    ihop).
  - På ett utkast sparas förslaget i `fixed_asset_proposals` (rad räknad
    bland utkastets sparade rader). Tillgången skapas i samma
    databastransaktion som bokför verifikationen (PostVoucher) eller
    registrerar leverantörsfakturan (RegisterSupplierInvoice), och får
    `acquisition_voucher_id`, `acquisition_voucher_row_id` och
    `supplier_invoice_id`. Misslyckas bokföringen skapas ingen tillgång;
    går tillgången inte att skapa bokförs inget. Hör förslaget inte längre
    till en inköpsrad på ett anskaffningskonto stoppas bokföringen med ett
    fel på raden. Avskrivningar bokförs inte här, utan av registrets
    månadsavskrivning.
  - Bara den som har behörigheten för registret kan lägga förslag; en
    bokförare kan sedan bokföra utkastet. Förslag på kreditnotor och på
    inköp där köparen redovisar momsen tas inte emot.
  - Under kontantmetoden bokförs leverantörsfakturan först vid betalning;
    tillgången skapas ändå vid registreringen och länkas då bara till
    fakturan (`TODO(legal-verify)`: anskaffningsdag och bokföring av
    anskaffningen under kontantmetoden).
  - Registret visar "Anskaffad med verifikation A57" med länk, och
    verifikationen visar de tillgångar som skapades när den bokfördes.
    Tillgången ändras och avyttras sedan enligt reglerna ovan.
  - Nya tillgångar numreras under ett eget lås per företag
    (`pg_advisory_xact_lock`), inte företagets rad, så två samtidiga
    bokföringar med tillgångar inte låser varandra.
  - Behandlingshistoriken: `fixed_asset.proposed` när ett utkasts förslag
    ändras, `fixed_asset.created` med verifikation, rad och faktura, och
    `fixed_asset.settings_changed`.

## 5a3. Periodiseringar

- Under Bokföring → Periodiseringar registreras belopp som hör till andra
  månader än de bokförs i. Det finns fyra slag:
  - **Förutbetald kostnad** (till exempel en årsförsäkring) och
    **förutbetald intäkt** (till exempel ett årsabonnemang) ligger på ett
    balanskonto (förslag 1790 respektive 2990) och fördelas jämnt över ett
    antal månader till ett resultatkonto.
  - **Upplupen kostnad** (förslag 2990) och **upplupen intäkt** (förslag 1790)
    hör till en månad men faktureras senare. De bokförs den sista dagen i
    månaden och återförs den första dagen i nästa månad.
- Om fakturan för en förutbetald post redan är bokförd på resultatkontot kan
  periodiseringen först flytta hela beloppet till balanskontot.
- Fördelningen räknas som för avskrivningar: för varje månad räknas hur mycket
  som ska vara fördelat vid månadens slut, och skillnaden mot det redan
  bokförda bokförs. Avrundningen blir exakt och en missad månad tas igen.
- En månads körning bokför högst två verifikationer i serie A: återföringarna
  den första dagen i månaden och resten den sista dagen. Varje steg sparas i
  `accrual_postings` (OPENING, RELEASE, ACCRUAL, REVERSAL), som databasen inte
  låter ändra eller ta bort; samma steg kan därför inte bokföras två gånger.
- En upplupen post bokförs bara i sin egen månad. Återföringen görs i den
  första körningen för en senare månad.
- När något har bokförts kan bara benämning och anteckning ändras, och
  periodiseringen kan inte tas bort.
- Periodiseringarna kräver behörigheten att hantera räkenskapsår (bokslut).

## 5a4. Bankavstämning

- Under Bokföring → Bankavstämning stäms bank- och kassakonton (1900–1999) av
  mot kontoutdraget per datum. Systemet visar kontots saldo i bokföringen den
  dagen (ingående balans plus bokförda verifikationer t.o.m. datumet).
- Användaren anger saldot på kontoutdraget och markerar de bokförda rader som
  ännu inte syns hos banken. Det som ska stå på kontoutdraget är saldot i
  bokföringen minus dessa rader; skillnaden mot kontoutdraget visas direkt.
- Raderna att gå igenom är de som bokförts sedan föregående avstämning av
  kontot (eller sedan räkenskapsårets början), plus de rader som föregående
  avstämning lämnade som ej hos banken. De är förmarkerade tills användaren
  tar bort markeringen.
- En avstämning med skillnad kräver en förklaring. Avstämningen bokför
  ingenting; en bankavgift eller ränta som saknas bokförs som vanligt.
- Varje sparad avstämning (datum, saldon, rader ej hos banken, skillnad,
  förklaring, vem och när) sparas i `bank_reconciliations`, som databasen inte
  låter ändra eller ta bort, och skrivs i behandlingshistoriken.
- Avstämning kräver behörigheten att bokföra verifikationer.
- Med modulen Bank (avsnitt 5g) och kontot kopplat till ett bankkonto visar
  sidan bankens saldo för datumet. "Använd bankens saldo" fyller i saldot och
  markerar som ej hos banken de rader (från den dag banken började hämtas)
  som ingen banktransaktion bokförd senast datumet är matchad mot. Personen
  kontrollerar och sparar som vanligt; inget sparas automatiskt.

## 5a5. Bokslut

- Under Bokföring → Bokslut görs bokslutet per räkenskapsår i fyra steg.
- **Checklista.** Systemet kontrollerar själv i bokföringen att det inte finns
  utkast i året, att alla momsperioder är bokförda, att varje bank- och
  kassakonto med saldo eller rörelse är avstämt utan skillnad den sista dagen,
  att avskrivningar och periodiseringar är bokförda till och med årets sista
  månad, att obetalda fakturor är bokförda vid årets slut (kontantmetoden),
  att fordringar och skulder i utländsk valuta är omvärderade, att årets
  skatt är bokförd (inte för enskild firma, handelsbolag och
  kommanditbolag) och att årets resultat är bokat. Användaren bekräftar själv
  kassainventering, kundfordringar mot 1510, leverantörsskulder mot 2440,
  skattekontot mot 1630, löner och semesterskuld samt varulager; en punkt som
  inte gäller företaget bockas också av. Bockarna sparas i `closing_checks`
  (vem och när) och kan tas bort så länge året är öppet; varje bock och
  borttagning skrivs till behandlingshistoriken. Punkterna för obetalda
  fakturor och omvärdering kan också bekräftas som bokförda för hand
  (samma tabell, nycklarna `unpaid` och `currency`), för det fall
  verifikationen gjorts manuellt, till exempel när Riksbanken saknar kurs.
- **Bokslutsverifikationer** bokförs den sista dagen i året i serie A.
  Skatten (8910 mot 2512) anges av användaren; noll sparas utan verifikation.
  Därefter bokas årets resultat efter skatt på 8999 mot 2099 (2019 för
  enskild firma, handelsbolag och kommanditbolag), så att resultaträkningen
  går jämnt ut. Bokförs något i året efteråt bokas mellanskillnaden på samma
  sätt. Saknade konton läggs till med BAS-namn.
- **Stäng året.** Kräver att hela checklistan är klar, att tidigare år är
  stängda och behörigheten att låsa perioder. Utgående balanser förs till
  nästa år (som skapas vid behov), året får status stängt med tid och person,
  och bokföringen låses till och med årets sista dag. Det går inte att ångra;
  inget kan bokföras i ett stängt år.
- **Obetalda fakturor vid årets slut** (kontantmetoden, BFL 5 kap. 2 §,
  [ADR-013](../adr/ADR-013-supplier-invoices.md); enligt AI-utredning
  2026-11-02, juristgranskning återstår): med kontantmetoden bokförs
  fakturor när de betalas, men alla kund- och leverantörsfakturor som är
  obetalda den sista dagen bokförs den dagen. Obetalt är fakturans belopp
  minus betalningar och krediteringar till och med den dagen; en betalning
  efter den sista dagen räknas inte av. Kundfakturorna bokförs i en
  verifikation i serie A: 1510 debet mot intäktskonton, utgående moms och
  öresavrundning kredit, fördelade som en betalning med kontantmetoden
  (`InvoiceBooking`). Leverantörsfakturorna bokförs i en egen verifikation:
  kostnadskonton (med kostnadsställe och projekt) och ingående moms debet
  mot 2440 kredit (`SupplierInvoiceBooking`). En delvis krediterad faktura
  bokförs med det som återstår. Fakturor i utländsk valuta bokförs till
  fakturans kurs och omvärderas sedan (nästa punkt). Varje verifikation
  återförs med en omvänd verifikation första dagen i nästa år (som måste
  finnas), så att betalningen sedan bokförs som vanligt enligt
  kontantmetoden och intäkt, kostnad och moms bokförs en gång. Steget görs
  efter årets sista dag, en gång, före omvärderingen och årets resultat;
  sparas i `year_end_entries` (typ `UNPAID_RECEIVABLES` med fordran och
  `UNPAID_PAYABLES` med skulden) och i behandlingshistoriken
  (`closing.unpaid_invoices_booked` med verifikationerna och återföringarna).
  Checklistan blockerar stängningen så länge det finns obetalda fakturor och
  steget inte är bokfört eller bekräftat som bokfört för hand; punkten
  gäller inte med fakturametoden eller utan obetalda fakturor. Momsen bokförs
  på de vanliga momskontona den sista dagen och kommer därför med i
  momsdeklarationen för perioden med årets sista dag om den inte redan är
  bokförd; annars ligger den kvar på momskontona och återförs första dagen
  i nästa år, så att den redovisas vid betalningen. Vilken period momsen ska
  redovisas i är inte verifierat (se Öppna frågor).
- **Omvärdering av valutafordringar och valutaskulder** (ADR-025; K2 punkt
  13.5 och 17.8, K3 punkt 30.3; enligt AI-utredning 2026-11-02,
  juristgranskning återstår): kund- och leverantörsfakturor i utländsk
  valuta som är obetalda den sista dagen (betalningar och krediteringar till
  och med den dagen räknas av; en delvis krediterad faktura värderas för det
  som återstår) värderas till Riksbankens kurs den dagen. Med kontantmetoden
  görs det efter att de obetalda fakturorna bokförts (föregående punkt). Skillnaden mot
  de bokförda kronorna bokförs den sista dagen i serie A: 1510 och 2440 mot
  3960 (vinst) och 7960 (förlust), och återförs med en omvänd verifikation
  första dagen i nästa år (som måste finnas), så att en senare betalning
  fortfarande räknas mot fakturans egen kurs. Bokförs en gång per år, före
  årets resultat, efter årets sista dag; sparas i `year_end_entries` (typ
  `CURRENCY_REVALUATION`, beloppet är nettovinsten). Checklistan har en
  punkt för det som blockerar stängningen: klar när omvärderingen är bokförd,
  när kursen inte ger någon skillnad eller när någon bekräftat att den är
  bokförd för hand; gäller inte utan obetalda fakturor i valuta. Saknar
  Riksbanken kurs bokförs omvärderingen för hand och bekräftas i
  checklistan. Bankkonton i utländsk valuta finns inte i systemet (alla
  bankkonton förs i kronor), så det finns inga sådana saldon att omvärdera.
- **Resultatdisposition** bokförs i nästa år på datum för årsstämman: årets
  resultat flyttas från 2099 till 2098, med beslutad utdelning på 2898. För
  enskild firma, handelsbolag och kommanditbolag flyttas resultatet från 2019
  till 2010. Utdelningen får inte vara större än det fria egna kapitalet i
  det stängda årets balansräkning (balanserat resultat och årets resultat,
  `DividendLimit`, ABL 17 kap. 3 §); en större utdelning avvisas och
  ingenting bokförs. Fältet visar det fria egna kapitalet. Enligt granskning
  2026-11-02, juristgranskning återstår (BS-AUD-BOK-3, #85). Även efter ett
  förlustår får utdelning bokföras ur balanserade vinstmedel så länge det fria
  egna kapitalet räcker; 2098 tar då både förlusten och utdelningen
  (BS-AUD-BOK-13, #86). Varje skatt-,
  resultat- och dispositionspost sparas i
  `year_end_entries`, som databasen inte låter ändra eller ta bort.
- Bokslutet kräver behörigheten att hantera räkenskapsår.

## 5a6. Årsredovisning (K2)

- Under Bokföring → Årsredovisning upprättas årsredovisningen för aktiebolag
  och ekonomiska föreningar enligt K2 (BFNAR 2016:10). Siffrorna läses ur
  bokföringen varje gång rapporten visas; det som sparas
  (`annual_reports`) är texterna: allmänt om verksamheten, väsentliga
  händelser, föreslagen utdelning, medelantal anställda, ort, datum och
  vilka som skriver under.
- Rapporten innehåller förvaltningsberättelse med flerårsöversikt
  (nettoomsättning, resultat efter finansiella poster och soliditet för upp
  till fyra år), förändringar i eget kapital och förslag till
  resultatdisposition; resultaträkning (kostnadsslagsindelad) och
  balansräkning med föregående år; noter om redovisningsprinciper,
  medelantal anställda och anläggningstillgångar; samt underskrifter.
- Raderna bildas av BAS-kontointervall (`K2Schedule`). Balansräkningen följer
  K2:s uppställningsform (BFNAR 2016:10, ÅRL bilaga 1) med samma BAS-indelning
  som INK2R: Förbättringsutgifter på annans fastighet (1120–1129) och
  Pågående nyanläggningar och förskott (1180–1189, 1280–1289) är egna rader;
  bland skulderna står Checkräkningskredit (2480–2489, långfristig 2330–2339),
  Övriga skulder till kreditinstitut (2410–2419, långfristiga 2340–2359),
  Förskott från kunder (2420–2429), Pågående arbete för annans räkning
  (2430–2439), Fakturerad men ej upparbetad intäkt (2450–2459),
  Leverantörsskulder (2440–2449), Skulder till koncern-, intresse- och
  gemensamt styrda företag (2460–2469, 2860–2869), Skatteskulder och Övriga
  skulder var för sig. Enligt granskning 2026-11-02, juristgranskning
  återstår (BS-AUD-BOK-4, #88).
- Resultaträkningen delar 4000–4999 efter K2 och INK2R: lagerförändring av
  produkter i arbete, färdiga varor och pågående arbete (4940–4959,
  4970–4979) är en rörelseintäkt (INK2R 3.2, 7411 när den ökar resultatet och
  7510 när den minskar det); förändring av lager av handelsvaror (4960–4969)
  och inköpskonton markerade som handelsvaror är Handelsvaror (3.6, 7512);
  övriga inköp, legoarbeten och 4900–4939, 4980–4999 är Råvaror och
  förnödenheter (3.5, 7511). Blir ett fält som bara har en sida på blanketten
  negativt visar inkomstdeklarationen en varning. Enligt granskning
  2026-11-02, juristgranskning återstår (BS-AUD-BOK-9, #89).
- Årets resultat i
  balansräkningen är resultaträkningens resultat oavsett om det är bokat på
  2099; ett tidigare resultat som inte disponerats visas som balanserat
  resultat. Saknas föregående år i systemet används årets ingående balanser
  som jämförelse för balansräkningen.
- Förändringar i eget kapital specificeras som en tabell med en kolumn per
  post (aktiekapital, reservfond, balanserat resultat, årets resultat) och
  totalt, och raderna Belopp vid årets ingång (föregående års
  balansräkning), Utdelning och Balanseras i ny räkning (ur årsstämmans
  disposition av föregående års resultat, `year_end_entries` DISPOSITION
  med verifikation i året), Årets resultat, Övriga förändringar (allt annat
  på 2080–2099, t.ex. nyemission eller aktieägartillskott) och Belopp vid
  årets utgång. Raderna summerar per kolumn. ÅRL 6 kap. 2 § andra stycket.
  Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-5,
  #90).
- Föreslagen utdelning får inte vara större än det fria egna kapitalet
  (balanserat resultat och årets resultat, `DividendLimit`); en större
  utdelning avvisas med hänvisning till ABL 17 kap. 3 § (ekonomisk förening:
  lag 2018:672 12 kap. 3 §). Är det fria egna kapitalet negativt avvisas all
  utdelning. Fältet visar det fria egna kapitalet och påminner om
  försiktighetsregeln, som styrelsen bedömer. Enligt granskning 2026-11-02,
  juristgranskning återstår (BS-AUD-BOK-2, #84).
- Sist i rapporten finns fastställelseintyget (ÅRL 8 kap. 3 §): en
  styrelseledamot eller VD intygar att resultat- och balansräkningen har
  fastställts, fastställelsedagen och stämmans beslut om vinsten eller
  förlusten, samt att kopian överensstämmer med originalet. Fälten
  fastställelsedag (efter räkenskapsårets slut), beslut, namn och roll sparas
  i `annual_reports`. Är beslutet tomt skrivs det ur den bokförda
  resultatdispositionen; finns ingen lämnas tomma rader. Bolagsverkets
  formkrav har inte kunnat läsas. Enligt granskning 2026-11-02,
  juristgranskning återstår (BS-AUD-BOK-10, #91).
- Rapporten visas som sida och laddas ner som PDF. Revisionsberättelsen
  läggs till utanför systemet. Digital inlämning till
  Bolagsverket (iXBRL) finns ännu inte.
- Kräver behörigheten att hantera räkenskapsår.

## 5a7. Inkomstdeklaration 2 (SRU)

- Under Bokföring → Inkomstdeklaration tas INK2 med INK2R och INK2S fram ur
  bokföringen för aktiebolag och ekonomiska föreningar. INK2R fylls fält för
  fält från BAS-kontointervall (`Ink2Codes`) i hela kronor; årets resultat
  är resultaträkningens, oavsett om det är bokat på 2099.
- INK2S: bokfört resultat, bokförd skatt (läggs tillbaka), de justeringar
  användaren anger (ej avdragsgilla kostnader, ej skattepliktiga intäkter,
  ej bokförda intäkter och kostnader, outnyttjat underskott) samt om en
  konsult biträtt och om årsredovisningen reviderats. Överskott eller
  underskott förs till INK2 punkt 1.1 eller 1.2. Justeringarna sparas i
  `income_tax_returns`.
- Schablonintäkt på periodiseringsfonder (INK2S 4.6 a, fält 7654, IL 30 kap.
  6 a §): systemet föreslår ingående saldo på 2110–2149 vid
  beskattningsårets ingång × statslåneräntan vid utgången av november året
  före det kalenderår då beskattningsåret slutar (lägst 0,5 %) × årets
  månader/12, avrundat till hela kronor (`TaxAllocationReserves`).
  Räntorna är data med källa (Skatteverket: 1,96 % nov 2024, 2,55 % nov
  2025). Förslaget räknas in i 4.15 och 1.1 och i SRU-filen. Ett inmatat
  belopp ersätter förslaget, även 0; ett tomt fält behåller förslaget.
  Saknas räntan för året visas en varning och fältet fylls i för hand.
  Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-8,
  #83).
- Särskild löneskatt på pensionskostnader: INK2 1.4 (fält 7132) eller 1.5
  (fält 7133, negativt underlag) och INK2S 4.21 (fält 8022, pensionskostnader
  som ingår i 3.8) föreslås ur årets nettorörelse på BAS 7400–7499. Den
  särskilda löneskatten själv (7530-gruppen) räknas inte in. Användaren kan
  ändra varje fält; 1.4 och 1.5 får inte båda fyllas i. Fälten ändrar inte
  det skattemässiga resultatet. Är 4.21 ifyllt men 1.4 och 1.5 tomma visas
  en varning. Underlaget enligt lag (1991:687) 2 § är inte alltid detsamma
  som den bokförda kostnaden; förslaget ska kontrolleras. Enligt granskning
  2026-11-02, juristgranskning återstår (BS-AUD-BOK-14, #87).
- SRU-filerna (INFO.SRU och BLANKETTER.SRU, ISO 8859-1, en post per rad)
  laddas ner som ZIP och lämnas i Skatteverkets e-tjänst Filöverföring.
  Fältkoderna är hämtade ur Skatteverkets fältnamnstabeller för
  beskattningsperiod 2026P4; för en period vars tabeller inte har
  kontrollerats laddas inga filer ner, och sidan visar bara siffrorna.
- Varje nedladdning skrivs i behandlingshistoriken.

## 5a8. Flytt från Fortnox via API

Under Bokföring → Läs in SIE-fil → Flytta från Fortnox (`/flytt/fortnox`)
kan den som har behörigheten `import:fortnox` hämta ett företags uppgifter
direkt från Fortnox. Koden ligger i `app/Migration/Fortnox`.

- **Anslutning:** OAuth2 med PKCE mot Fortnox. Kopplingen sparas bara om
  Fortnox-kontots organisationsnummer är detsamma som företagets. Nycklarna
  lagras krypterade per företag och raderas när flytten avslutas.
- **Endast läsning:** NONOX skriver aldrig till Fortnox. Anropen hålls inom
  Fortnox gräns (25 per 5 sekunder), väntar vid 429 och förnyar nyckeln när
  den löper ut.
- **Bokföringen:** varje räkenskapsår hämtas som SIE4 och går genom den
  vanliga inläsningen i två steg (4.11): rapport först, inget bokförs innan
  användaren godkänner. Verifikationerna behåller serier och nummer.
- **Register:** kunder, leverantörer och artiklar läggs till om de inte redan
  finns (samma namn eller organisationsnummer, eller samma artikelnummer).
  Det som inte går att läsa sparas utan det fältet, eller listas.
- **Underlag:** filer kopplade till verifikationer i Fortnox hämtas i
  omgångar om 15 och kopplas till samma verifikation i NONOX (via
  ursprunglig serie och nummer). Varje fil hämtas en gång
  (`fortnox_imported_files`).
- **Avstämning:** obetalda kund- och leverantörsfakturor i Fortnox jämförs
  med saldot på 1510 och 2440 i det senaste året.

## 5a9. Dataskydd och säkerhet

Se `docs/gdpr/` (registerförteckning, konsekvensbedömningar för lön,
personalliggare, inkorg och AI, incidentrutin, lagringstider,
säkerhetskopiering) och `docs/juridik/` (integritetspolicy, villkor,
personuppgiftsbiträdesavtal, underbiträden, AI i NONOX, regelverk, frågor
till jurist). Säkerhetsrapporter: `SECURITY.md`. Tillgänglighet:
`docs/tillganglighet.md` (granskning mot WCAG 2.2 AA, kriterium för
kriterium) och den publika redogörelsen `docs/juridik/tillganglighet.md`
på /tillganglighet. De delade layouterna ger varje inloggad sida en
hopplänk, ett `main`-landmärke, en sidtitel från sidans rubrik och en
uppläsningsyta; `resources/js/accessibility.js` kopplar fältfel till sina
fält och flyttar fokus till första felet. Strukturen skyddas av
`tests/Feature/AccessibilityTest.php`.

- **Återställningstest:** `scripts/restore-test.sh` återställer en
  säkerhetskopia i ett tillfälligt PostgreSQL-kluster och kör
  `backup:verify-restore`, som kontrollerar hashkedjan, balansen och
  numreringen i varje företags bokföring, varje underlags kontrollsumma,
  personalliggarens kedjor och leverantörsfakturornas filer. Den driftade
  tjänsten kör testet varje vecka. Rutinen beskrivs i
  `docs/gdpr/sakerhetskopiering.md`.
- **Programvarukomponenter:** varje release har en förteckning (SBOM,
  CycloneDX) över paketen i `composer.lock` och `package-lock.json`.
- **Företagsbindning:** varje Livewire-sida är låst till företaget den
  visades i (LivewireCompanyGuard). Byter användaren företag i en annan flik
  svarar den gamla sidan 404.
- **Inloggning:** engångskod med tak per e-postadress (10 felaktiga koder i
  timmen). Koden fylls bara i automatiskt på en lokal adress och står inte i
  ämnesraden. Sessioner upphör efter 12 timmar, och användaren kan logga ut
  från alla andra enheter.
- **Passkey som andra steg** ([ADR-029](../adr/ADR-029-passkeys.md)): den som
  i något företag kan se sjukfrånvaro, lönebesked eller fullständiga
  personnummer (`payroll:view-sensitive`, `payroll:view`,
  `payroll:archive-view`, `staff-ledger:view-sensitive`,
  `staff-ledger:control-visit`, i ett företag där modulen någon gång varit
  påslagen), alla i ett företag där ägaren valt "Kräv passkey för alla i
  företaget", och alla som själva lagt till en passkey, bekräftar inloggningen
  med passkey efter koden, innan sessionen börjar. Den som måste ha en men
  saknar lägger till en direkt efter koden. Passkeys kräver
  användarverifiering (PIN, fingeravtryck, ansikte); bara den publika nyckeln
  sparas. Den sista passkeyn kan inte tas bort medan en krävs. Tillagd passkey
  och återställning mejlas till användaren och loggas (namn, aldrig
  nyckeldata). Återställning görs av en ägare eller administratör i ett
  företag där personen är med, som själv loggat in med passkey (inte av
  ägaren av en administratör), loggas i det företaget och loggar ut personen
  överallt; som sista utväg med `php artisan passkeys:reset`. Lokalt kan
  steget hoppas över med `PASSKEYS_SKIP_LOCALLY` (kräver `APP_ENV=local` och
  lokal adress).
- **Säkerhetsrubriker:** begränsad CSP, X-Frame-Options, Referrer-Policy,
  Permissions-Policy och HSTS.
- **Lön:**
  - Frånvaroorsaker och anteckningar kräver payroll:view-sensitive, och
    läsningen loggas.
  - Byte av bankkonto kräver en andra persons bekräftelse innan en lönefil
    kan skapas.
  - Personnummer och bankkonton skickas inte till sidans tillstånd.
  - Skäl och anteckningar krypteras, och lönebelopp skrivs inte i
    behandlingshistoriken.
- **Registerutdrag** (art. 15/20) för konto, kund, leverantör, anställd,
  person i personalliggaren (`/registerutdrag/personal/{id}`, kräver
  staff-ledger:view-sensitive, fungerar även med modulen avstängd) och
  avsändare till inkorgen (`/registerutdrag/inkorg?avsandare=…&format=csv`,
  kräver inbox:manage, bara det aktuella företagets meddelanden), loggade.
  Utdraget ur personalliggaren innehåller aldrig pinkod, QR-nyckel eller
  kontrollnyckel, och fotot beskrivs men skickas inte med. Inkorgens utdrag
  innehåller metadata (datum, ämne, status, bilagornas namn), inte texten.
  I behandlingshistoriken står en nyckelhash av avsändarens adress, inte
  adressen. **Radering** (art. 17): kund och leverantör raderas om de aldrig
  bokförts, annars anonymiseras de. Fakturorna behåller sina uppgifter.
- **Radera mitt konto** (art. 17, `DeleteAccount`): nekas så länge
  användaren äger ett företag som inte är arkiverat. Medlemskap, sessioner,
  API-nycklar, inloggningskoder, väntande inbjudningar, passkeys, senast
  öppnade i sökningen (`recent_items`), sparade vyer, notiser och profilbild
  tas bort. Därefter läses alla främmande nycklar mot `users` ur databasens
  katalog: hänvisar ingen rad längre till användaren raderas raden, annars
  pseudonymiseras den (namnet "Raderad användare", en slumpad adress på
  `konto.invalid`, `anonymised_at` satt, sessioner ogiltiga). Verifikationer,
  fakturor och behandlingshistorik behåller användar-id:t (bokföringslagen
  7 kap., art. 17.3 b). Åtgärden loggas som `user.account_deleted`, en rad
  för kontot och en per företag, utan namn, adress eller IP-adress.
- **Gallring:** `privacy:prune` körs varje natt.
- **Avtal:** på den driftade tjänsten godkänns villkor och
  personuppgiftsbiträdesavtal när ett företag skapas. Vem, när och vilken
  version sparas.
- **Cookies:** bara nödvändiga, samtyckesruta och /cookies. Typsnitten
  hämtas från den egna servern.
- **E-post:** tak per företag och timme, 300 dokument och 30 inbjudningar.

## 5b. Lön (modul)

Kapitlet beskriver lönemodulen som helhet: regler och skattetabeller,
lönekörningens flöde (beräkning, attest, utbetalning, bokföring, skattekonto,
arbetsgivardeklaration), semester, frånvaro, förmåner och pension, lönerevision
och slutlön, rapporter och årsskifte, avstängning med lönearkiv och flytt av
lönedata från ett tidigare lönesystem. Varje lönekörning går igenom stegen
utkast → beräknad → attesterad → utbetald → bokförd; från attesten är
lönebeskeden låsta i databasen.

- Lön är en modul som varje företag slår på under Moduler. Den följer
  `PAYROLL_MODULE.md`.
- Alla satser och belopp (arbetsgivaravgifter, sjuklön, skatteregler) är
  versionerade regler med källa. En regel används först när en namngiven,
  behörig person har kontrollerat den, och ändras aldrig därefter; en ändring
  blir en ny version. Saknas en kontrollerad regel för utbetalningsdagen
  stoppas lönekörningen.
- Skatteavdraget tas från Skatteverkets skattetabeller, inlästa från deras fil
  med kontrollsumma.
- En lönekörning beräknas för alla anställda eller inte alls. När den är
  attesterad kan den inte ändras; databasen vägrar. Den som beräknade får inte
  attestera, om inte företaget har stängt av kravet. Fel rättas i en senare
  lönekörning.
- Personnummer och bankuppgifter sparas krypterade och skrivs aldrig till
  behandlingshistoriken. Frånvarons orsak visas bara för den som har
  behörighet till känsliga uppgifter.
- När lönen är utbetald bokförs lönekörningen som en verifikation i serie L
  på utbetalningsdagen: varje lönerad på lönearternas konton (med
  anställningens kostnadsställe), personalskatt på 2710, arbetsgivaravgifter
  på 7510 mot 2730 och nettolönerna mot 1930. Verifikationen balanserar alltid:
  brutto − skatt ± nettoposter = netto.
- Frånvaro räknas med de timmar som anges per dag, men bara på dagar då den
  anställde ska arbeta enligt schemat och som inte är helgdagar. Övriga dagar
  i perioden räknas med noll timmar (en sjukperiod räknar ändå sina
  kalenderdagar). Frånvaro registreras i lönekörningen för den månad den
  inträffar.
- Karensavdrag per sjuklöneperiod (sjuklönelagen 6–7 §§, prop. 2017/18:96
  s. 68–69; enligt AI-utredning 2026-11-02, juristgranskning återstår).
  Sjukdagar länkas till sjuklöneperioder: en ny sjukdag inom fem dagar från
  förra periodens slut fortsätter den, även över månadsskiften, och dagarna
  numreras genom hela kedjan. Varje period får ett eget karensavdrag, högst
  periodens egen sjuklön. Den del sjuklönen inte räckte till dras från
  periodens senare sjuklönedagar, i samma eller nästa lönekörning och efter
  återinsjuknande; när perioden är slut dras den inte. Karensavdrag görs
  inte när den anställda redan har haft tio karensavdrag de senaste tolv
  månaderna räknat bakåt från den nya periodens första dag; ett tillfälle är
  ett gjort avdrag, inte en frånvaropost. Varje lönebesked sparar per
  sjukperiod vad avdraget skulle bli, vad som drogs och avtalets dagar från
  dag 15 (`sick_pay_periods`, per företag); raderna låses med lönekörningen
  när den attesteras, och nästa körning läser bara attesterade körningar.
  En sjukperiod som ingen attesterad körning har med räknas som redan dragen,
  och lönebeskedet säger det.
- Semester (semesterlagen): semesteråret börjar den månad företaget valt
  (1 april enligt lagen) och intjänandeåret är året före. Betalda dagar =
  semesterdagar per år × (anställningsdagar − dagar med frånvaro som inte är
  semesterlönegrundande) ÷ dagar i intjänandeåret, avrundat uppåt (7 §).
  Semesterdagar är vardagar utom helgdagar, midsommar-, jul- och nyårsafton
  (3 a §). Sammalöneregeln ger semestertillägg 0,43 % av månadslönen per
  betald dag; procentregeln ger 12 % av intjänandeårets semesterlönegrundande
  lön delat med antalet betalda dagar. Dagar utöver saldot blir obetald
  semester. En anställning kan i stället få semesterersättning 12 % med varje
  lön. Saldot räknas fram ur anställningar, frånvaro och attesterade
  lönebesked; inget saldo lagras som kan glida isär från underlaget.
  Semesterlöner bokförs på 7082.
- Självservice: en anställd anmäler sjukdom eller vård av barn och ansöker om
  semester på Min sida. Ansökan räknas inte i lönen förrän någon med ansvar för
  lönekörningar har godkänt den, med timmar per dag (förvalt veckoarbetstiden
  delat med fem). Den som ansökt kan inte godkänna sin egen ansökan om
  attestkravet är på. En månad vars lönekörning redan är attesterad tar inte
  emot frånvaro; en beräknad körning blir utkast igen.
- Förmåner (kost, bil, drivmedel, övrigt) beskattas och ger arbetsgivaravgift
  men betalas inte ut. Kostförmån värderas med Skatteverkets belopp ur
  reglerna, och den anställdes egen betalning dras från nettolönen och sänker
  förmånsvärdet. Förmånsvärdet bokförs inte som en egen kostnad; den verkliga
  kostnaden bokfördes vid inköpet och avgifterna på förmånen ligger i 7510.
- Milersättning och inrikes traktamente är skattefria upp till reglernas belopp
  och betalas med nettolönen (7331, 7321); det som betalas över blir
  skattepliktig lön (7332, 7322).
- Tjänstepension enligt anställningens pensionsplan: en procent av lönen upp
  till 7,5 inkomstbasbelopp om året och en annan över, plus särskild löneskatt.
  Båda är arbetsgivarens kostnad, syns på lönebeskedet för information och
  bokförs 7410/2740 och 7530/2730.
- Utlägg: den anställde lämnar in med kvitto på Min sida. När det godkänns
  bokförs kostnaden och ingående moms mot 2890 med kvittot som underlag, och
  nästa lönekörning betalar tillbaka beloppet via 2890. Ett utlägg knyts till
  den körning som betalar det och betalas aldrig två gånger.
- Tidrapporter (GitHub issue #15): lönekörningen betalar godkända
  tidrapporter med lönearten Timlön som är daterade i körningens månad, för
  anställningar med timlön som gäller den dagen. Timmarna läggs ihop till en
  rad "Timlön enligt tidrapport" med antal timmar × anställningens timlön, och
  beskattas, ger arbetsgivaravgift och semesterlönegrundande lön som
  lönearten säger, med samma regler som timlön som skrivs in för hand.
  Månadsavlönade påverkas inte; deras timmar ingår i månadslönen. Körningen
  som betalar en tidrapport knyts till den (`time_reports.payroll_run_id`), så
  ingen tidrapport betalas två gånger: en ny beräkning släpper körningens
  tidrapporter och knyter dem på nytt, en körning som öppnas igen släpper dem,
  och en annan körning för samma månad tar inte en tidrapport som redan är
  knuten. När körningen är attesterad vägrar databasen att ändra eller ta bort
  dess tidrapporter. Tidrapporter som inte är godkända räknas inte i lönen;
  lönekörningens sida visar hur många timmar i perioden som väntar på
  godkännande (inskickade tidrapporter och personalliggarens förslag).
- Tidrapporter med tillägg (ADR-037): godkända tidrapporter på löne­arter
  som räknas i timmar och är tillägg (OB, övertid, mertid och avtalets
  OB- och övertidsarter) betalas i körningen för sin egen månad, även för
  månadsavlönade. De blir en rad per löneart, "<löneart> enligt tidrapport",
  med timmarna ihoplagda. En avtalsprissatt löneart prissätts av Avtal
  dag för dag med den avtalsversion och det prissteg som gällde den dagen;
  annars används timlönen, och saknas den stoppas körningen. Sådana
  tidrapporter skapas bara av Planering (se 5i); timlön, sena tidrapporter
  och inlästa löneunderlag räknas som förut.
- Sena tidrapporter: en godkänd tidrapport med Timlön som är daterad före
  körningens månad och som ingen körning har betalat (den godkändes efter
  att dess månads körning attesterades) betalas aldrig automatiskt. En
  ordinarie lönekörning frågar den som kör lönen: sidan visar hur många
  timmar det gäller, för vem och från vilka månader, med valen "Ta med i den
  här lönekörningen" och "Inte nu". Frågan gäller anställda i körningen som
  hade en anställning med timlön som gällde den dagen; den ställs inte så
  länge tidrapportens egen månad har en ordinarie körning som är öppen (den
  körningen betalar den). Hela frågan läses i en databasfråga för alla
  anställda. Svaret sparas per tidrapport på körningen
  (`payroll_runs.late_time_reports_included` och `late_time_reports_skipped`)
  och i behandlingshistoriken (`payroll.late_time_reports_included`,
  `payroll.late_time_reports_skipped`, `payroll.late_time_reports_reset`).
  Medtagna tidrapporter knyts till körningen som andra tidrapporter och
  betalas som egna rader, en per månad och anställning, till exempel
  "Timlön enligt tidrapport (oktober 2026)", med timmar × timlönen i den
  anställning timmarna arbetades i. En ny beräkning följer svaret; "Inte nu"
  lämnar tidrapporterna obetalda och nästa körning frågar igen. Tidrapporter
  som godkänns efter svaret frågas om för sig. Svaret kan ändras ("Ändra
  valet") medan körningen är öppen; när den är attesterad vägrar databasens
  trigger på `payroll_runs` att ändra det. Timmarna redovisas i AGI för
  körningens utbetalningsmånad, som annan lön som betalas i körningen
  (`TODO(legal-verify)` i ADR-012).
- Löneunderlag från schemasystem (PAYROLL_MODULE.md B.16a): i en öppen
  lönekörning kan en lönefil läsas in, PAXml 1.0 eller 2.0–2.3 enligt de
  officiella schemana på paxml.se, eller en CSV-fil med fasta kolumner.
  Filen läses som en ström; en fil med DOCTYPE vägras innan något element
  läses, inga entiteter expanderas och inget hämtas över nätet. Högst 10 MB
  och 50 000 transaktioner. Originalet sparas krypterat i den privata
  lagringen och kan bara hämtas med behörigheten att se känsliga
  löneuppgifter; varje hämtning loggas. Lönetransaktioner och
  tidtransaktioner blir rader med status Förslag; allt annat i filen
  (personuppgifter, scheman, resor, saldon) läses inte. Den anställda hittas
  på anställningsnumret, annars på personnumret, som bara används i minnet
  och aldrig sparas på raden eller i behandlingshistoriken. Varje kod i
  filen kopplas till en löneart per källsystem, en gång, och kopplingen
  används för alla senare filer från samma system. Frånvarokoder (sjukdom,
  vård av barn, semester, ledighet med flera), schematid och saldon läses
  aldrig in som lön; frånvaro registreras i lönekörningen. En rad utan
  à-pris och belopp kan bara vara timlön, eftersom till exempel OB med hela
  timlönen vore fel, och negativa värden läses inte in. En person godkänner
  raderna; varje godkänd rad blir en rad i lönekörningen med källan
  "IMPORT" och körningen måste beräknas igen. Inläsningen godkänner,
  beräknar eller bokför aldrig något själv. Samma fil (SHA-256) läses bara in
  en gång per period. "Läs in filen igen" läser den sparade filen med dagens
  kopplingar och anställda och ersätter alla rader utom de godkända. Tas en
  godkänd rad bort i lönekörningen blir den ett förslag igen. En fil kan tas
  bort så länge körningen är öppen, med sina godkända rader. Inläsning,
  godkännande, avvisning, ångrat godkännande, borttagning, hämtning och
  ändrade kopplingar loggas (`payroll.file_*`, `payroll.import_*`).
- Utlägg med NONOX AI påslaget: den anställde skickar bara kvittot. NONOX AI
  läser filen direkt när den skickas och avgör först om den är ett utlägg: ett
  kvitto eller en faktura märkt som betald. En obetald faktura, något som inte
  är ett köp eller en oläslig bild nekas på en gång med skälet till den
  anställde, och ingenting sparas. Är det ett utlägg läser AI:n datum, vad som
  köptes, belopp och moms och föreslår ett kostnadskonto (4000–7999) ur
  kontoplanen. Förslaget lagras på utlägget, som väntar på godkännande;
  ingenting bokförs av AI:n. Går AI-leverantören inte att nå sparas utlägget
  ändå och den som godkänner fyller i uppgifterna. Den som godkänner ser kvittot och
  AI:ns anteckningar, kan rätta datum, text, belopp, moms och konto, eller låta
  AI:n läsa kvittot igen. Kunde AI:n inte läsa beloppet fyller den som godkänner
  i det; ett utlägg utan belopp kan inte godkännas. Vilka fält som rättades vid
  godkännandet står i behandlingshistoriken. En HEIC-bild
  från iPhone görs om till JPEG enbart för analysen (Imagick med libheif krävs
  på servern); originalet sparas oförändrat som underlag. En läsning som
  misslyckas skrivs med skälet i applikationsloggen.
- Semesterlöneskuld: kvarvarande betalda dagar plus dagarna som tjänas in i
  innevarande intjänandeår, värderade under sammalöneregeln till företagets
  dagsvärde (löneinställning, % av månadslönen) plus semestertillägg, och under
  procentregeln till 12 % av underlaget. Sociala avgifter med den anställdes
  senaste avgiftssats. När företaget bokför skulden varje månad tar
  lönekörningens verifikation i serie L med raderna som för 2920 och 2940 till
  skulden på periodens sista dag (motkonton 7090 och 7519), mätt mot saldot i
  huvudboken på utbetalningsdagen, så att ingående balanser räknas. Varje
  bokförd skuld sparas per anställd i en tabell som bara går att lägga till i.
- Semesterdagar räknas som företaget valt. Förval är den begränsade
  nettometoden: bara schemalagda arbetsdagar räknas, och den som arbetar färre
  än fem dagar i veckan tjänar in 25 × arbetsdagar ÷ 5 dagar. Alternativet är
  semesterlagen som den är skriven: alla vardagar räknas och alla tjänar in 25.
  Midsommar-, jul- och nyårsafton räknas aldrig. Semesterersättning som betalas
  med varje lön beskattas enligt tabell, eller som engångsbelopp om företaget
  valt det.
- Semesterdagar för den som arbetar helger (9 §), med lagens metod: vid ledighet kortare än fem
  vardagar räknas en lördag eller söndag som annars hade varit arbetsdag som
  semesterdag. En vanlig söndag i schemat är en arbetsdag; röda dagar är det
  aldrig.
- Procentregelns underlag (16 b §): semesterlönegrundande lön utan lön för dagar
  med semesterlönegrundande frånvaro (sjuklön och sjukavdrag), med avdrag för
  tjänstledighet och obetald semester, plus den lön en timavlönad skulle ha haft
  de dagarna (timmar enligt frånvaron × timlön).
- Semesterårsskifte (18, 26, 26 a §§) under Lön → Semesterår: dagar som inte
  tagits ut får sparas om de är fler än 20 och inga sparade dagar har använts
  under året; resten betalas ut. Under sammalöneregeln betalas 12 % av den
  rörliga lönen under året. Beloppen betalas i nästa lönekörning som
  engångsbelopp och knyts till den körningen när den attesteras, så att de
  aldrig betalas två gånger. Ett stängt semesterår går inte att ändra.
  Sparade dagar används efter årets egna dagar och gäller i fem år.
- Lönefil: för en attesterad lönekörning skapas en ISO 20022 pain.001.001.03
  med kategorin SALA, en betalning per anställd till dess bankkonto (clearing
  och kontonummer), från företagets bank enligt betalningsinställningarna.
  Behandlingshistoriken visar vem som tog ut filen, antal och summa, aldrig
  kontonummer.
- Skattekontot: när lönen är bokförd bokförs personalskatten (2710) och
  arbetsgivaravgifter med särskild löneskatt (2730) mot 1630 den dag
  Skatteverket drar dem, en gång per lönekörning.
- Skattekontot bokas med personalskatt och arbetsgivaravgifter, det som
  arbetsgivardeklarationen debiterar. Särskild löneskatt på pensions­
  kostnader bokas på 2514 och betalas med företagets preliminärskatt.
- Arbetsgivardeklaration (AGI): per utbetalningsmånad skapas en XML-fil
  enligt Skatteverkets schema version 1.1 av alla attesterade lönekörningar
  med utbetalning i månaden. Huvuduppgiften har summa arbetsgivaravgifter
  (fält 487) och avdragen skatt (497); varje anställd får en individuppgift
  med kontant ersättning (011), förmåner (012, 013, 018), avdragen skatt
  (001, eller 274/275 för SINK och A-SINK), kryss för milersättning (050)
  och traktamente (051). Specifikationsnumret är den anställdes nummer, så
  att en rättelse ersätter rätt uppgift. Belopp anges i hela kronor utan
  öre. Filen sparas med SHA-256 och valideras mot schemat när det finns
  installerat. Statusen följs för hand: skapad, inlämnad (med kvittens­
  nummer), godkänd eller avvisad. En ny fil för en redan inlämnad månad är
  en rättelse: alla individuppgifter skickas om och den som inte längre
  finns i månaden får en borttagsmarkering (fält 205). När rättelsen är
  godkänd markeras de tidigare filerna som ersatta. Deklarationsdagen visas
  per månad från regeldata (agi.deadlines). Varje nedladdning loggas,
  eftersom filen innehåller personnummer.
- Lönerevision: en ny månadslön gäller från den första i en månad och
  sparas som lönehistorik. Lönekörningen använder lönen som gällde periodens
  första dag. Har en månad redan attesterats med den gamla lönen räknas
  skillnaden på månadslöneraden fram i nästa lönekörning som retroaktiv lön
  per månad, skattad som engångsbelopp. Beloppet räknas fram ur data varje
  gång (rätt lön minus utbetald lön minus redan utbetald retroaktiv lön för
  månaden), så att samma skillnad aldrig betalas två gånger. Avdrag och
  semesterlön i de månaderna räknas inte om.
- Lönerapporter (Lön → Rapporter) räknas per utbetalningsmånad från
  attesterade lönekörningar: lönejournal, lönearter, hittills i år per
  anställd, kostnad per kostnadsställe, pension per pensionsplan och en
  avstämning per månad av skatt och arbetsgivaravgifter mellan lönebeskeden,
  den senast inlämnade arbetsgivardeklarationen och lönebokföringen (2710
  och 7510). En revisor ser bara de sammanställda rapporterna.
- Löneårsskifte: ett kalenderår avslutas när året är slut, alla lönekörningar
  med utbetalning i året är bokförda och alla månader med lön har en
  inlämnad arbetsgivardeklaration. Årets summor sparas och ingen lönekörning
  kan sedan få utbetalningsdag i året. Avslutet kan inte ändras eller tas
  bort (databasregel). Semesteråret avslutas separat.
- Flytt av lönedata (Lön → Flytt av lönedata): från ett tidigare lönesystem
  läses anställda, belopp hittills i år (bruttolön, skatt, avgiftsunderlag,
  arbetsgivaravgifter, förmåner och engångsbelopp), semestersaldon för det
  pågående semesteråret och gamla lönebesked (PDF) in från CSV-filer i ett
  eget, dokumenterat format. Varje fil läses in bara när alla rader är rätt.
  Beloppen hittills i år blir årets ackumulatorer och de ingående
  semestersaldona ersätter det systemet inte själv kan veta om året. Medan
  flytten pågår kan ingen lönekörning räknas. Flytten är klar först när
  inläst skatt och inlästa arbetsgivaravgifter stämmer med bokföringen för
  året (2710 kredit, 7510 netto), med en marginal på en krona per anställd
  för öresavrundning. Varje steg loggas. Gamla lönebesked sparas med
  kontrollsumma, i Lönearkivet och på den anställdes Min sida.
- Avstängning av lönemodulen: modulen kan inte stängas av så länge en
  lönekörning är attesterad men inte bokförd, en månad med lön saknar
  inlämnad arbetsgivardeklaration eller ett godkänt utlägg som en
  lönekörning har tagit med inte är utbetalt. Ingen data tas bort. Medan
  modulen är avstängd finns Lönearkivet (`/lonearkiv`) kvar för den som har
  behörigheten payroll:archive-view: lönekörningar med lönebesked som PDF,
  arbetsgivardeklarationernas filer och de sammanställda rapporterna, bara
  att läsa. Den som bara är anställd i företaget får ett besked om att Min
  sida är stängd.
- Slutlön: en anställning avslutas med sista anställningsdag, som inte får
  ligga i en redan attesterad månad. Lönekörningen för den månaden betalar
  månadslönen till och med dagen och semesterersättning (28 §) för betalda
  dagar som är kvar och semester som tjänats in under pågående intjänandeår,
  värderad som semesterlöneskulden den sista dagen och skattad som
  engångsbelopp. Ersättningen betalas en gång per anställning. Därefter är
  den anställde inte med i lönekörningarna. Underlag för arbetsgivarintyg
  (lön och frånvarodagar per månad, de sista tretton månaderna) kan hämtas
  som CSV; uttaget loggas.
- Nytt löneår (regler 2027): reglerna för varje år finns i
  `app/Modules/Payroll/database/data/{år}/rules.json` med värde, giltighet,
  källa, läsdatum och ett kort citat. Filen för 2027 (läst 2026-11-02) har
  de värden som var beslutade den dagen; värden som myndigheten ännu inte
  hade publicerat står under `pending` med myndighet och väntad
  publicering, och nycklar som en tillsvidareversion redan täcker står under
  `continued` med källa till lagen i kraft. Inget värde är gissat eller
  kopierat från året före. Alla regler läses in som overifierade: kommandot
  `payroll:import-rules` (utan år: alla år) körs varje natt 03:40 från
  schemaläggaren, så ett nytt års fil når varje installation efter
  driftsättningen. Inläsningen ändrar aldrig en version som någon har
  kontrollerat, varken för installationen eller för ett företag, och rör inte
  en oförändrad version; en rättelse av en kontrollerad version blir en ny
  version. Lön → Regler visar överst **Inför löneåret** för nästa
  utbetalningsår (från oktober) eller innevarande år: varje regel som
  kontrollerad, ej kontrollerad, inte publicerad ännu (med myndighet och
  väntad publicering) eller saknad från ett visst datum, med källa och
  knappen Kontrollera (samma åtgärd, behörighet payroll:settings och
  behandlingshistorik `payroll.rule_verified` som i listan per datum), samt
  om årets skattetabeller är inlästa.
- Utbetalningsdagen styr: lönekörningen väljer regler efter
  utbetalningsdagen och skattetabell efter utbetalningsdagens år, så lön för
  december 2026 som betalas i januari 2027 räknas med 2027 års regler och
  tabeller. Det stämmer med lagtexterna för de satser som ändras 2027:
  SINK-lagens nya 7 § tillämpas på inkomster som tas emot efter
  2026-12-31, lagen om sänkta avgifter för unga gäller ersättning som ges ut
  under perioden och socialavgiftslagens ändringar tillämpas på ersättning
  som ges ut efter ikraftträdandet. Semesterlönegrundande frånvaro hör till
  intjänandeåret, men värdena är samma 2026 och 2027.
  Skatteförfarandelagen 11 kap. 16 § andra stycket tillåter ("får") att
  skatteavdraget i januari görs enligt den tabell som senast tillämpades året
  före. Regeln är frivillig, så det är tillåtet att NONOX i stället stoppar
  körningen tills årets tabeller är inlästa (kontrollerat av två oberoende
  AI-genomgångar 2026-11-02, juristgranskning återstår). Tabellerna hämtas
  automatiskt när de publiceras.
- Skattetabeller för ett nytt år: saknas årets tabeller stoppas en
  lönekörning med utbetalning det året med beskedet "Skattetabellerna för
  {år} är inte inlästa ännu" (en gång för hela körningen), innan något
  räknas. Tabellerna läses in med `payroll:import-tax-tables {år} {fil}` från
  Skatteverkets textfil eller med `payroll:import-tax-tables {år} --download`
  (och varje natt 04:10 med `payroll:fetch-published-tax-tables`, som tar
  årets och från oktober nästa års tabeller när de är publicerade, aldrig
  rör ett inläst år och väntar med ett år som har färre tabeller än året
  före; inläsningen loggas som `payroll.tax_tables_imported`). Efter
  inlästa tabeller, och när den nattliga regelinläsningen har nya eller
  ändrade versioner, får alla med `payroll:settings` i varje aktivt företag
  med Lön en notis (`PayrollYearNotice`) om vad som ska kontrolleras, med
  länk till Lön → Regler i företaget. `--download` och den nattliga
  hämtningen läser från Skatteverkets öppna data (RowStore-datasetet för allmänna tabeller,
  månadslön), som skrivs om till textfilens postformat och går igenom samma
  kontroller. Den 2 november 2026 gav öppna data för 2026 exakt samma 7 966
  rader som Skatteverkets textfil. Skatteverket tar fram 2027 års tabeller i
  december 2026. Ett år läses in en gång och ändras aldrig. Tabellerna är
  Skatteverkets egna data och kontrolleras av programmet (rader, luckor,
  antal tabeller); till skillnad från lönereglerna är de ingen tolkning som
  en person måste godkänna, men varje företag får notisen och kontrollerar
  de anställdas tabell och kolumn.

Nyckeltal för lön visas överst på alla lönesidor för den som får se lönen:
månadens lönekostnad (bruttolön och arbetsgivaravgifter för attesterade
körningar med utbetalning i månaden, jämfört med förra månaden), nästa
löneutbetalning med nettobelopp och status, avdragen skatt och
arbetsgivaravgifter som ännu inte är bokförda mot skattekontot, utbetalda
månader utan inlämnad arbetsgivardeklaration, senast beräknade
semesterlöneskuld och antal anställda. Varje siffra är en fråga.

## 5c. NONOX AI (modul)

- NONOX AI läser en leverantörsfaktura, ett kvitto eller annat underlag och
  fyller i ett förslag: leverantör, fakturauppgifter, belopp, moms och konton,
  eller en hel verifikation. Förslaget sparas och bokförs aldrig av AI:n; det
  gör en person efter kontroll, på samma sätt som när uppgifterna skrivs in för
  hand. Därför ändrar modulen ingenting i verifikationsflödet.
- När modulen är påslagen och personen får använda AI läses ett underlag direkt
  när det laddats upp, dragits in eller fotograferats med mobilens kamera, på
  verifikationen och leverantörsfakturan. Det fyller bara i formuläret som när
  någon klickar Analysera; knappen finns kvar för ett nytt försök. Fotot
  kontrolleras och sparas som annat underlag.
- Ett utlägg från Min sida läses av NONOX AI när det skickas, när modulen är
  påslagen, utan att den anställde själv behöver behörigheten att använda AI.
  Läsningen är ett förslag som lagras på utlägget tills någon med ansvar för
  lönerna godkänner eller avslår det (avsnitt 5b).
- Bara konton som finns aktiva i företagets kontoplan föreslås, och en
  leverantör väljs bara när den redan finns i registret (avsnitt 5a).
- Kvittoläsaren för utlägg läser bara det som står på kvittot. Den bedömer
  aldrig inköpet eller den anställda (till exempel om något ser privat ut);
  anteckningarna gäller bara det som inte gick att läsa, som ett oläsligt
  belopp, en utländsk valuta eller moms som saknas. Den som godkänner
  utlägget bedömer det.
- Underlaget skickas till den AI-leverantör företaget valt (OpenAI, Anthropic
  eller Google) med företagets egen API-nyckel, som kontrolleras hos
  leverantören och lagras krypterad. Behandlingshistoriken visar att en analys
  gjorts, med leverantör, modell och antal token, men aldrig underlagets
  innehåll ([ADR-018](../adr/ADR-018-nonox-ai.md)).

## 5d. Personalliggare (modul)

- Modulen `staff_ledger` slås på och av per företag under Moduler
  ([ADR-022](../adr/ADR-022-staff-ledger.md)). Den för en elektronisk
  personalliggare per lokal för restaurang, fordonsservice, livsmedels- och
  tobaksgrossister, kropps- och skönhetsvård (även frisörer) och tvätterier.
  Personalliggaren är inte räkenskapsinformation enligt bokföringslagen, men
  den hanteras med samma skydd.
- **Uppgifter per post:** namn, person- eller samordningsnummer eller, för
  den som saknar svenskt nummer, utländskt identitetsnummer med land
  (krypterat; 9 kap. 5 § skatteförfarandeförordningen, "motsvarande
  utländska nummer", enligt AI-utredning 2026-11-02, juristgranskning
  återstår), arbetsgivarens namn och organisationsnummer, eller utländskt
  nummer med land, för inhyrd personal, lokal,
  händelse (in eller ut), tid enligt enheten, tid då servern tog emot posten,
  sätt (kiosk med kod, kiosk med QR-kod, mobil, manuellt, rättelse), enhet och
  löpnummer, vem som registrerade (manuellt och rättelse) och orsak.
  Personens uppgifter kopieras till posten, så liggaren visar vad som gällde
  när den skrevs även om registret ändras.
- **Utländska nummer:** kontrolleras aldrig med kontrollsiffra, sparas som
  de skrivits (utan inledande och avslutande mellanslag, högst 30 tecken)
  och landet anges med ISO 3166-1 alfa-2 (Sverige kan inte väljas). En
  dubblett hittas oberoende av mellanslag, bindestreck och versaler inom
  samma land. Landet ingår i postens hash bara när det finns, så poster
  skrivna före 2026-11-07 har oförändrad hash. I listor visas numret som
  "Utländskt nummer (land), dolt"; på kontrollbesöket, i PDF, CSV (egna
  kolumner för land) och i den provisoriska XML-filen (`UtlandskIdentitet`
  respektive `UtlandsktNummer` med `Nummer` och `Land`) visas det i sin
  helhet. TODO(legal-verify): hur Skatteverkets kommande schema anger ett
  utländskt nummer. Arbetsgivaruppgiften enligt prop. 2025/26:282
  (39 kap. 11 a § andra stycket SFL, riksdagsbeslut planerat 2026-11-11,
  tillämpas från 2027-07-01) är inte införd; förordningen är inte utfärdad.
- **Bevarande:** en post sparas till och med 31 december två år efter det
  kalenderår då företagets räkenskapsår (beskattningsår) som innehåller
  postens datum slutade, 9 kap. 5 § tredje stycket
  skatteförfarandeförordningen (2011:1261). Kalenderårsföretag: en post från
  2025 sparas till och med 2027-12-31. Räkenskapsår maj–april: en post från
  juni 2025 (året slutar 2026-04-30) sparas till och med 2028-12-31.
  Räkenskapsåret hämtas ur företagets räkenskapsår; täcker inget datumet
  räknas ett tolvmånadersår fram ur första månaden i räkenskapsåret, och om
  det överlappar ett befintligt räkenskapsår, eller om företaget saknar
  räkenskapsår, sparas posten. Både postens tid och mottagningstiden ska
  ligga utanför bevarandetiden. `privacy:prune` tar bort poster från
  kedjans äldsta ände och stannar vid första post som ska sparas.
  Arkiverade personer utan kvarvarande poster tas bort när
  arkiveringsdagen ligger utanför bevarandetiden enligt samma regel.
  Ändrat 2026-11-07 (tidigare räknades från postens kalenderår) enligt
  AI-utredning 2026-11-02, juristgranskning återstår.
- **Oändringsbarhet:** posterna går bara att lägga till. Modellen vägrar
  ändring och borttagning, och en trigger i databasen vägrar UPDATE, DELETE
  och TRUNCATE. Varje post har en hashkedja per företag och lokal:
  hash = sha256(föregående hash || postens innehåll som kanonisk JSON). Posten
  får sitt id innan den skrivs, och lokalens rad låses under tiden, så kedjan
  följer id-ordningen. Kedjans senaste hash sparas på lokalen.
- **Rättelser:** ett fel rättas med en ny post som pekar på den ursprungliga:
  "felaktig registrering" (fel person, dubbeltryck) eller "ändrad tid", alltid
  med orsak, vem och när. En glömd utcheckning registreras manuellt av en chef
  med orsak. Den ursprungliga posten visas alltid. Ingen checkas ut
  automatiskt; efter ett antal timmar (förval 12) får personen och cheferna en
  påminnelse.
- **Kontroll:** `php artisan personalliggare:verify {företag?}` räknar om
  varje lokals kedja och rapporterar ändrade poster, borttagna poster och en
  kedja vars början inte stämmer med den senaste gallringen. Den körs varje
  natt kl. 03.30 och resultatet skrivs till behandlingshistoriken och visas i
  översikten.
- **Kiosk utan internet:** kiosken är en installerbar webbsida. Varje in- och
  utcheckning sparas först på enheten med enhetens tid, ett löpnummer och en
  egen hashkedja, och skickas sedan i ordning. Servern sparar både enhetens
  tid och mottagningstiden, tar emot sena poster, tar aldrig bort eller
  skriver om en händelse och tar emot samma händelse bara en gång. Stor
  skillnad i enhetens klocka, poster som skickats i efterhand, saknade
  löpnummer och bruten kedja på enheten markeras på posten.
- **Mobil incheckning:** positionen kommer från personens egen telefon och
  kan inte kontrolleras av servern. Varje sådan post markeras därför
  "Position uppgiven av telefonen, ej verifierad" i liggaren och i
  kontrollbesökets export, och försöken begränsas till tio per person och
  tio minuter. Behörigheten `staff-ledger:self` har rollerna Personal i
  personalliggaren, Anställd (Lön) och Schemaläggare (Planering), eftersom
  en person har en roll per företag (beslut 2026-11-02, issue #15).
  Incheckningen kräver dessutom att inloggningen är kopplad till personen
  och att lokalen har mobil incheckning påslagen.
- **Kod (PIN):** koden sparas aldrig, bara ett kontrollvärde (PBKDF2) som
  kiosken kan pröva mot utan nät. Fem fel låser personen på kiosken i fem
  minuter.
- **Kioskens kopia:** kiosken hämtar lokalen, personalen med kontrollvärden
  för koderna (aldrig koderna), en hash av varje QR-kod och vem som är
  incheckad. Personnummer, inhyrd personals arbetsgivare och de senaste 31
  dagarnas pass för kontrollbesöket skickas aldrig öppet till en kiosk bara
  för att den är kopplad. Öppet kommer de först när servern prövat en chefs
  kod. För offlineläget kommer de förseglade: krypterade (AES-256-GCM) med en
  slumpad nyckel, som i sin tur är krypterad med en nyckel som härleds ur
  varje chefs kod. Fyra siffror är få, så den som kommer åt kioskens lagring
  kan fortfarande pröva alla koder; förseglingen gör att det är den enda
  vägen in.
- **Kontrollbesök:** "Skatteverket är här" visar vem som är incheckad nu och
  liggaren för vald period med fullständiga personnummer, i appen för den som
  har behörigheten och på kiosken med en chefs kod. Varje öppning och export
  loggas. En öppning som kiosken gjort utan nät loggas när den synkar, som
  rapporterad av enheten och ej verifierad av servern, bara för en aktiv chef
  med kod, en gång per öppning och högst fem per synkning. Export som PDF, CSV
  och XML (NONOX:s eget preliminära format med schema, tills Skatteverket
  publicerat sitt) för högst 13 månader åt gången, och från kiosken en
  ZIP-fil till USB-minne byggd ur enhetens egen kopia.
- **Gallring:** poster äldre än lagringstiden tas bort från kedjans äldsta
  ände av `privacy:prune`. Kedjans nya början sparas på lokalen och i
  behandlingshistoriken, så resten av kedjan kan fortfarande kontrolleras.
- **Lön:** när lönemodulen är på kan liggaren föreslå tidrapporter. Förslaget
  skrivs till lönens tidrapporter först när det godkänts, och liggaren ändras
  aldrig. Godkända tidrapporter betalas som timlön i lönekörningen för
  månaden (5b); förslag som inte är behandlade visas där som timmar som
  väntar på godkännande.
## 5e. Inkorg (modul)

Inkorgen tar emot underlag med e-post och gör förslag som en person
godkänner ([ADR-023](../adr/ADR-023-inbox.md)).

- **Adresser.** Varje företag har en egen adress, `{namn-slumpdel}@in.nonox.app`,
  med tilläggen `+kvitto`, `+faktura` och `+kassa` som ledtråd om vad som
  skickas. Ägaren kan byta adressen; den gamla slutar då genast ta emot.
  Det finns också en gemensam adress, `mejl@in.nonox.app`, där avsändarens
  adress avgör företaget. Finns avsändaren på flera företags listor läggs
  meddelandet åt sidan utan företag tills en medlem i ett av dem hämtar det;
  det fördelas aldrig av systemet självt. Den som kör NONOX själv kan i
  stället låta systemet hämta e-post från en egen brevlåda (IMAP).
- **Godkända avsändare.** Varje företag har en lista med adresser och hela
  domäner. Bara e-post från någon på listan tolkas; annat hamnar i karantän
  tills en person godkänner avsändaren.
- **Kontroller före all tolkning.** SPF, DKIM och DMARC och mottagande servers
  skräppostmärkning läses. Ett meddelande som underkänns hamnar i karantän
  även från en godkänd avsändare. Bilagor som kan innehålla program, skript
  eller makron spärras, och med virussökning (ClamAV) söks varje bilaga
  igenom. En spärrad bilaga sparas men lämnas aldrig ut. Inget i ett
  meddelande visas som en webbsida i systemet; PDF och bilder visas i en
  sandlåda, allt annat laddas ner.
- **Arkiv.** Originalmeddelandet sparas byte för byte som `.eml`, och varje
  bilaga oförändrad, krypterade på den privata disken med kontrollsumma
  (SHA-256). Samma meddelande två gånger sparas en gång.
- **Tolkning.** SIE4-filer blir förslag till verifikationer, Peppol-fakturor
  (BIS Billing 3.0) förslag till leverantörsfakturor, och dagsrapporter från
  kassasystem (CSV, XLSX, text eller PDF med textlager) läses med en mall
  som sparats för avsändaren. Ur en PDF läses bara texten, högst 5 MB och
  10 sidor; en inskannad PDF utan text kräver NONOX AI eller en person. Ett
  mejl som nämner en bilaga som inte följde med får en varning. Annars läser NONOX AI underlaget om företaget slagit på det.
  För en dagsrapport säger AI:n bara vad varje belopp är och vilket konto det
  hör till; verifikationen byggs av systemet ur beloppen, och debet och
  kredit samt momsen per momssats kontrolleras innan förslaget räknas som
  klart. Allt annat hamnar i en kö där en person fyller i verifikationen
  eller fakturan bredvid bilagan.
- **Varningar.** Ett bankgiro, plusgiro eller IBAN som skiljer sig från
  leverantörsregistret, en avsändardomän som liknar en känd, en bilaga som
  kommit förut och en faktura med samma nummer, belopp och leverantör ger en
  varning. Den som godkänner måste bekräfta att varningen är kontrollerad.
- **Bokföring och koppling.** När en person godkänner bokförs förslaget med
  systemets vanliga funktioner (utkast, bokföring, registrering av
  leverantörsfaktura), så numrering, kontrollkedja och oföränderlighet är
  desamma som annars. Originalmeddelandet och alla bilagor kopplas som
  underlag till varje verifikation det leder till. Ett meddelande som lett
  till bokföring sparas lika länge som bokföringen. Filerna delas, inte
  kopieras, och raderas bara när ingen post längre pekar på dem (avsnitt
  4.5).
- **Mallar och automatisk bokföring.** När ett förslag godkänns sparas hur
  det tolkades som en mall för avsändaren och formatet. Nästa underlag i
  samma format tolkas med mallen utan AI. Per mall kan en person slå på
  automatisk bokföring. Den bokför bara när underlaget stämmer exakt med
  mallen, verifikationen balanserar, inga varningar finns och avsändaren är
  godkänd och kontrollerad, och bara så länge den personen själv får
  bokföra. Den bokför i den personens namn, och behandlingshistoriken visar
  att det skett automatiskt (`inbox.auto_booked`). AI bokför aldrig själv.
- **Behandlingshistorik.** Mottagande, karantän, godkända avsändare,
  tolkning, godkännande, avvisning, svar till avsändaren, mallar, automatisk
  bokföring, adressbyte och hämtning från den gemensamma adressen skrivs
  till behandlingshistoriken. Lösenordet till en egen brevlåda lagras
  krypterat och skrivs aldrig dit.
- **Gallring.** Avvisade meddelanden tas bort efter 7 dagar. Meddelanden i
  karantän som ingen hanterat, och meddelanden till den gemensamma adressen
  som inget företag hämtat, tas bort efter 90 dagar (`inbox:prune`).
  Skräppost från avsändare som inte är godkända sparas aldrig; godkända
  avsändares meddelanden sparas alltid, i karantän om de flaggats.
  Meddelanden som lett till bokföring tas aldrig bort här.

## 5f. NONOX Connect – öppet API (modul)

Ett API för andra program ([ADR-032](../adr/ADR-032-public-api-and-nonox-connect.md),
beskrivning av varje anrop i [docs/api/openapi.yaml](../api/openapi.yaml)).
Modulen är avstängd från början; när den är av svarar API:t 404 för
företaget.

- **Vem som ansluter.** En app som den som driftar NONOX har registrerat
  (`php artisan connect:app`) ber om åtkomst med OAuth 2.0 (auktoriseringskod
  med PKCE S256). Bara en ägare eller administratör kan godkänna, för ett
  företag i taget, och bara efter en färsk bekräftelse med passkey. API-nycklar
  för egna skript skapas under Anslutningar på samma villkor, har alltid ett
  slutdatum, visas en gång och sparas bara som SHA-256. Ägaren får ett mejl
  om varje ny nyckel.
- **Vad en anslutning får göra.** Behörigheterna (scopes) är samma nycklar
  som rollernas behörigheter. Vid varje anrop gäller bara de behörigheter som
  både anslutningen har och personen som gav den har just då. Tas personen
  bort ur företaget slutar anslutningen fungera direkt. Ett arkiverat företag
  kan bara läsas.
- **Bokföringen.** API:t använder samma funktioner som appen: utkast sparas
  med samma kontroller, bokföring ger nästa nummer i serien och hashkedjan
  som vanligt, och en bokförd verifikation kan bara rättas med en
  rättelseverifikation. Underlag kontrolleras på samma sätt som i appen.
- **Upprepade anrop.** Varje POST kräver en `Idempotency-Key`. Samma nyckel
  med samma innehåll inom 24 timmar ger samma svar igen utan att något
  bokförs en gång till.
- **Behandlingshistorik.** Varje skrivande anrop loggas med app eller nyckel,
  person, adress, svarskod och Idempotency-Key, utöver det som funktionen
  själv loggar. Godkända och återkallade appar, återanvända tokens och nya
  eller återkallade nycklar loggas också.
- **Personuppgifter.** Personnummer lämnas aldrig ut via API:t; för en kund
  syns bara om ett personnummer finns.
- **Fakturor, leverantörsfakturor och betalningar.** Kundfakturor skapas som
  utkast och blir fakturor (nummer, OCR, bokföring i serie B) med samma
  funktion som i appen; leverantörsfakturor registreras (serie D), attesteras
  och betalas (serie E), och inbetalningar bokförs i serie C. Ett anrop som
  bokför kräver att anslutningen också har behörigheten att bokföra
  verifikationer. Attest av en annan person än den som registrerade gäller
  likadant i API:t som i appen. Rapporter, momsdeklarationen per period och
  SIE-exporten räknas fram av samma kod som i appen; momsen bokförs och
  perioder låses bara i appen.
- **Fakturor i utländsk valuta** (5.3). En kundfaktura får valutan i
  `invoice_currency`, radpriserna i `unit_price_minor` (hundradelar av
  valutan) och eventuellt en egen kurs i `exchange_rate_micros`; utan kurs
  används Riksbankens. En betalning anger `foreign_amount_minor` (förvalt det
  som återstår) och `amount_ore`, kronorna som kom in. Alla `*_ore` är
  kronor; svaren har valutabeloppen bredvid. Webhooks för mejlad och betald
  faktura har med valuta, valutabelopp och kursdifferens.
- **Webhooks.** En ägare eller administratör kan, med passkey, låta företaget
  skicka händelser (bokförd verifikation, mejlad faktura, betald faktura,
  attesterad leverantörsfaktura) till en https-adress på internet. Händelsen
  skickas först när bokföringen är sparad och innehåller bara id, nummer,
  datum och belopp. Den signeras med en hemlighet som visas en gång och
  sparas krypterad. Adresser i interna nät vägras, före varje försök. Svarar
  mottagaren inte försöker NONOX igen i ungefär ett dygn och stänger sedan av
  webhooken och mejlar ägaren. Leveransloggen sparar tid, försök och
  statuskod, aldrig mottagarens svar, och rensas efter 30 dagar. Att lägga
  till, ändra hemlighet, stänga av och ta bort loggas.
- **SFTP för SIE-filer.** Ett företag kan få en egen SFTP-mapp där ett
  annat program lämnar SIE-filer (ADR-032, avsnittet "SFTP"). SFTP-servern
  (SFTPGo) driftas bredvid NONOX och frågar NONOX vid varje inloggning och
  varje uppladdning; den har inga egna användare. Uppsättningen beskrivs i
  `deploy/sftpgo/README.md`.
  - *Dataflöde.* Programmet loggar in med en SSH-nyckel och lägger filen i
    `/in`. SFTP-servern frågar NONOX om filen får tas emot; svarar NONOX nej
    tar servern bort filen direkt. Annars läser NONOX filen i bakgrunden,
    kontrollerar den (vanlig textfil, börjar med `#FLAGGA`, högst 50 MB)
    och granskar den med samma funktion som en SIE-fil som laddas upp i
    webbläsaren. Resultatet är en SIE-inläsning som väntar på att en person
    med behörighet att läsa in SIE-filer läser rapporten och bekräftar.
    **Ingenting bokförs utan den bekräftelsen**; det finns inget
    automatiskt läge. Granskningen görs i den ansvariga personens namn (den
    som slog på SFTP), som måste ha kvar både behörigheten att hantera
    anslutningar och att läsa in SIE-filer. Ansvarig person och ägare får en
    notis, och en webhook `sie.uploaded` kan skickas.
  - *Säkerhet.* Bara inloggning med nyckel (ed25519, eller RSA med minst
    3072 bitar); lösenord, FTP, WebDAV och SFTP-serverns webbgränssnitt är
    avstängda. Nycklar och listan med tillåtna adresser ändras bara av ägare
    eller administratör med passkey. Användarnamnet är slumpat och säger
    inget om företaget. Företaget hittas bara från användarnamnet, och
    nyckeln måste vara en av just det företagets nycklar, så en nyckel kan
    aldrig ge åtkomst till ett annat företags mapp. SFTP-servern och NONOX
    delar en hemlighet som kontrolleras vid varje anrop; utan den är SFTP
    avstängt. Stängs SFTP eller NONOX Connect av, eller förlorar den
    ansvariga personen sin behörighet, nekas nästa inloggning.
  - *Gallring av filer.* Filen ligger okrypterad i SFTP-mappen bara tills
    NONOX har läst den (sekunder) och tas sedan alltid bort, inläst eller
    inte. NONOX krypterade kopia följer SIE-inläsningen och sparas som
    räkenskapsinformation. En fil som NONOX lägger i `/ut` (SIE-export för
    innevarande år) tas bort efter sju dagar; något som blivit kvar i `/in`
    tas bort efter ett dygn (`connect:prune`). Uppladdningsloggen (namn,
    storlek, kontrollsumma, adress, status och skäl, aldrig innehållet) och
    nycklarna, också borttagna, sparas med företaget.
  - *Behandlingshistorik.* Påslagning och avstängning, tillagda och
    borttagna nycklar, ändrade adresser, inloggningar och nekade
    inloggningar (högst en gång per tio minuter per konto, adress och slag),
    mottagna, avvisade och granskade filer och utlagda exporter loggas.
- **Återkallelse.** En app kan själv återkalla sin anslutning (RFC 7009);
  det loggas som en återkallelse av appen.
- **Testföretag.** Ett testföretag är ett eget företag med påhittade
  uppgifter för att prova API:t. Det är märkt på varje sida, har ett
  organisationsnummer som inget riktigt företag kan ha, mejlar aldrig
  fakturor, påminnelser eller offerter och räknas inte med i statistik eller
  debitering. Dess bokföring följer samma regler som allt annat men är inte
  någons riktiga räkenskapsinformation.

## 5g. Bank (modul)

Bankens egna uppgifter hämtas med öppen bankdata (PSD2,
kontoinformation) i stället för att filer laddas upp
([ADR-034](../adr/ADR-034-nonox-banking.md)). NONOX kan bara läsa;
betalningar görs fortfarande med betalfiler som signeras i banken.

- **Leverantör.** Installationen väljer leverantör (`services.banking.driver`):
  Enable Banking, eller en låtsasbank för test och demo som inte går att
  använda i produktion. Enable Banking autentiseras med en JWT som signeras
  med installationens privata RSA-nyckel; nyckeln ligger utanför koden.
- **Koppling.** En person med `banking:manage` väljer bank och kontotyp och
  godkänner medgivandet i banken med BankID. Svaret från banken tas bara
  emot för det flöde som samma webbläsare startade, för samma företag och
  användare, en gång och inom 15 minuter. Medgivandet gäller den tid banken
  tillåter, högst 180 dagar.
- **Konton.** Varje bankkonto kopplas till ett konto i kontoplanen
  (1900–1999), ett bankkonto per konto, i företagets valuta. Konton som inte
  kopplas hämtas inte.
- **Hämtning.** Bokförda transaktioner och saldon hämtas 1–4 gånger per dygn
  (inställning, standard 2) och med "Hämta nu". Första hämtningen går 90
  dagar bakåt, senare hämtningar fem dagar före den förra. Varje transaktion
  sparas en gång per konto, identifierad med bankens id eller, utan id, med
  en kontrollsumma av datum, belopp, motpart, meddelande, referens och
  ordningen bland lika transaktioner samma dag. En hämtad transaktion kan
  inte ändras eller tas bort (modell och databastrigger); den får bara sin
  matchning och sin inbetalning.
- **Inbetalningar.** En insättning som betalar en obetald faktura eller har
  ett OCR-nummer blir en inbetalning under Inbetalningar → Från banken, med
  fakturan föreslagen på samma sätt som från bankens fil (avsnitt 5, camt.054).
  Samma bankreferens kan inte bli två inbetalningar. En person bokför.
- **Betalningar ut.** En dragning i kronor som passar en betalning i en
  betalfil (på referens eller belopp, avsnitt 5a1b) blir ett förslag under
  Leverantörsfakturor → Betalfiler. En person bekräftar, och då bokförs
  betalningen i serie E. Dragningar som inte passar någon betalfil lämnas.
- **Matchning.** För varje transaktion föreslås den bokförda raden på
  kontot med samma belopp och närmast datum inom sju dagar, varje rad en
  gång. En person med `banking:view` och rätt att bokföra bekräftar eller tar
  bort matchningen. Modulen skapar eller bokför aldrig verifikationer själv.
- **Medgivandets slut.** Den som får hantera Bank får e-post en gång före
  slutdatumet (standard 14 dagar) och ser det under Att göra. När banken
  inte längre godtar medgivandet markeras kopplingen som utgången utan att
  något annat påverkas. Bortkoppling ber leverantören avsluta medgivandet;
  sessionen glöms även om leverantören inte svarar.
- **Säkerhet.** Session, leverantörens kontoid och IBAN lagras krypterade
  och skrivs aldrig i loggar eller behandlingshistorik. Svaret från banken
  är begränsat till tio försök per minut och användare.
- **Behandlingshistorik.** Påbörjad och slutförd koppling, kontokoppling,
  hämtning (antal), misslyckad hämtning, utgånget medgivande, bortkoppling,
  matchning och påminnelser.
- **Bevarande.** Hämtade transaktioner och saldon tas inte bort när
  kopplingen tas bort eller modulen stängs av.

## 5h. Avtal (modul)

Kollektivavtalet i lönen ([ADR-033](../adr/ADR-033-nonox-avtal.md),
`docs/avtal/`). Modulen `agreements` kräver Lön och är avslagen från början.
Byggt i den här versionen: steg A1–A4 i `docs/avtal/gap-analys.md` och
förslag från NONOX AI (ADR-033 §6).

- **Avtalsdata.** Avtalens fakta (minimilöner, OB- och övertidssatser,
  lönegrupper, dagtyper, delare) ligger som versionerade filer i formatet
  `nonox.agreement/1` under
  `app/Modules/Agreements/database/data/agreements/<nyckel>/<variant>-<giltig_från>.json`,
  aldrig som kod eller avtalstext. Varje värde har en källa (dokument och
  sida i parternas publicerade text). Filerna kontrolleras mot formatet
  innan de läses in (källa för varje värde, satssteg inom versionens
  giltighet, dagtyper och koder som finns). Ett värde som bara finns i en
  sekundär källa är märkt `unverified`, och då kan versionen aldrig
  kontrolleras (Frisöravtalet 2025–2027, vars text inte är publik).
- **Versioner och kontroll.** Versionerna ligger i den gemensamma tabellen
  `agreement_rule_versions` med kontrollsumma (SHA-256 av reglerna och
  källorna). De läses in som **okontrollerade** (`agreements:import`, och
  när modulen slås på läggs de som saknas till). En version används i lönen
  först när en namngiven person har jämfört den med källorna: för hela
  installationen med `agreements:verify {nyckel} {giltig_från} --by=Namn`,
  eller för företaget av en användare med `agreements:verify` under Lön →
  Avtal → Versioner (`agreement_rule_verifications`, kan bara läggas till).
  En kontrollerad version kan inte ändras eller tas bort (Postgres-trigger);
  bara `valid_to` kan förlängas när ett avtal löper vidare. En rättelse blir
  en ny version.
- **Bindning och anställning.** Företaget väljer avtal och om det är bundet
  som medlem i arbetsgivarorganisationen eller genom hängavtal, med datum
  (`collective_agreements`). Varje anställning får avtal, lönegrupp,
  branschvana från ett datum och om övertid och OB betalas, tas ut som
  komptid eller ingår i lönen. En anställd som inte omfattas lämnas utan
  avtal och får varken avtalsregler eller varningar. När ett avtal binds
  läggs avtalets lönearter till (till exempel `GR_OB`), med samma skatt,
  avgifter, semestergrund och konton som OB-tillägg eller Övertid.
- **Stopp i lönekörningen.** För varje anställning som är knuten till ett
  avtal måste en kontrollerad version gälla för periodens första och sista
  dag och utbetalningsdagen, och företaget måste vara bundet under perioden.
  Annars stoppas lönekörningen med en förklaring i checklistan. Det finns
  ingen reserv till en äldre version eller till allmänna regler.
- **Prissättning (A3).** En rad med en avtalsart och timmar men utan
  à-pris och belopp, inmatad eller från ett löneunderlag (PAXml eller CSV),
  prissätts med avtalets sats för arbetsdagen (radens datum, annars
  periodens första dag): kronor per timme, procent av timlönen (månadslönen
  delad med avtalets delare, eller avtalets formel för deltid) eller
  månadslönen delad med ett tal. Övertid där avtalet säger att tillägget
  betalas utöver timlönen räknas som timlön plus tillägg. Raden får avtalets
  version, steg, sats, underlag och källa i sin förklaring, och lönekörningen
  visar dem. Utan modulen prissätts en avtalsart aldrig med timlönen; raden
  stoppar lönekörningen. Står det på anställningen att OB eller övertid
  ingår i lönen stoppas en sådan rad.
- **Avtalskalender (A2).** Helgdagarna enligt lag och aftnarna (påsk-,
  midsommar-, jul-, nyårs-, trettondedags- och valborgsmässoafton,
  skärtorsdagen, dagen före Kristi himmelsfärd, pingstafton) räknas fram för
  varje år, och avtalets dagtyper (storhelg, helgdag och liknande) blir
  tidsperioder med klockslag. En rad för en dagtyp på en dag som inte är en
  sådan dag ger en varning.
- **Minimilön (A4).** Varje lönebesked jämförs med avtalets minimilön för
  lönegruppen, åldern (från födelsedatumet, enligt avtalets regel för när en
  ny ålder börjar gälla), branschvanan och tiden i företaget, i det steg som
  gäller vid periodens slut. Den högsta raden personen uppfyller gäller.
  Lägre lön, saknad lönegrupp och ungdomslön efter den ålder den gäller till
  ger en varning på lönekörningen med källan; lönen ändras aldrig. Varningen
  kan stängas av under Moduler → Avtal.
- **NONOX AI (ADR-033 §6).** När både NONOX AI och Avtal är på kan en
  användare med `ai:use` och `agreements:manage` ladda upp företagets
  dokument (hängavtal, medlemsbekräftelse, lönebesked) och anställdas
  anställningsavtal (PDF med text eller textfil, högst 5 MB, krypterat på
  den privata disken). Bara texten skickas, sida för sida, och personnummer,
  bankuppgifter, adresser, e-postadresser och telefonnummer döljs först.
  `AgreementIdentificationAgent` föreslår avtal och variant för företaget,
  `EmploymentTermsAgent` avtal, lönegrupp, befattning, anställningsform,
  veckoarbetstid, branschvana och avvikelser per anställd; lön och
  startdatum visas bara för jämförelse. Varje förslag sparas med citat och
  sida och om citatet finns ordagrant i dokumentet. Inget förs in förrän en
  person godkänner, ändrar eller avvisar förslaget; "Godkänn alla som
  stämmer med dokumentet" gäller bara förslag vars citat hittades. Avtalets
  belopp och satser kommer aldrig från AI:n. `LocalAgreementAgent` läser
  ett uppladdat lokalt avtal och föreslår avvikelser (se Lokala avtal nedan).
- **Behandlingshistorik.** Inläsning, kontroll, bindning, anställningens
  avtalsuppgifter, uppladdning och borttagning av dokument, varje analys
  (agent, antal förslag, antal dolda uppgifter) och varje beslut om ett
  förslag skrivs till behandlingshistoriken, utan dokumentets innehåll.
  AI-anropet loggas som `ai.document_analyzed` med leverantör, modell och
  antal tokens.
- **Pass med klockslag och OB ur passen (A5–A6)** finns i modulen Planering
  (5i): attesterad tid delas upp per OB-art med avtalets OB-fönster och
  dagtyper. Har avtalet en avrundningsregel (`rounding.ob`, till exempel
  varje påbörjad halvtimme) avrundas OB-minuterna per kod och pass, och
  dagen säger det med källan.
- **Övertid, mertid och komptid (A7).** Övertid och mertid för
  månadsavlönade räknas per beräkningsperiod (Planering → Inställningar,
  1–52 veckor, standard 1). Längre period än avtalets
  `calculation_period.max_weeks` (timavlönade `max_weeks_hourly`) ger en
  varning; utan kollektivavtal räknas högst fyra veckor oavsett inställningen
  (arbetstidslagen 5 § andra stycket). Godkänd frånvaro och uttagen komptid
  på schemalagda dagar räknas som arbetad tid (7 § andra stycket). Avtal med
  `working_time.overtime_method: DAILY` (Visita–Unionen, Handels tjm) räknar
  i stället varje dags tid utöver dagens schema, utan nettning. Avtalets
  avrundning av övertid (`rounding.overtime`) används per dag eller per
  tillfälle (Gröna Riks: högst 10 minuters förlängning är inte övertid),
  aldrig på periodens summa. Timavlönade får periodens övertid och mertid
  bara när avtalet ger dem tillägg (`overtime.hourly_paid`, Gröna Riks och
  Detaljhandeln), på avtalets kod med bara tillägget; timlönen ändras inte.
  Enligt AI-utredning 2026-11-02, juristgranskning återstår. På periodens
  sista dag väljer chefen per tillfälle om tiden betalas eller sparas som
  komptid; standard är anställningens uppgift om övertidsersättning.
  Komptiden blir timmar ledighet enligt avtalets faktor för lönearten
  (`overtime.comp_time.hours_per_hour`), annars företagets egen faktor.
  Saldot förs i Lön (`comp_time_entries`: intjänad, uttagen, utbetald,
  justering) och visas på Lön → Komptid, på lönespecen och i Min sida. En
  utbetalning och timavlönades uttag betalas i månadens lönekörning på
  lönearten Komptid med timvärdet av lönen; månadsavlönades uttag betalas av
  lönen. Saldot kan inte bli negativt, en attesterad månad kan inte ändras,
  och öppnas dagen igen i Planering tas den intjänade komptiden bort (vägras
  om den redan är uttagen).
- **Semestergrund för övertid och komptid.** Lönearterna Övertid och
  Komptid är semesterlönegrundande (semesterlagen 16 b §) och, under
  sammalöneregeln, rörlig lön som ger 12 % vid semesterårets slut (16 a §
  tredje stycket). Avtalskoder har `vacation_basis` med källa: Gröna Riks och
  Detaljhandeln ja, Visita–Unionen och Handels tjm nej (semesterlönen ingår
  i övertidsersättningen). Utbetald komptid följer flaggan på övertiden den
  tjänades in på, äldst först, och raden bär flaggan i sin förklaring
  (`explanation.vacation_basis`), som semesterreskontran läser före
  lönearten. En migrering satte flaggan på befintliga systemlönearter med
  det gamla värdet. Enligt AI-utredning 2026-11-02, juristgranskning
  återstår.
- **Sjuklön enligt schemat (A8).** När Avtal och Planering är på och
  anställningen följer ett avtal: en sjukdag med publicerade pass räknas med
  passens timmar. Förlorad OB på passen (avtalets fönster, avrundning och
  sats) ger sjuklönens procent som en egen rad, när avtalsdatan säger att OB
  ingår (`sick_pay.includes_ob`) och OB inte ingår i lönen (sjuklönelagen
  6 §: "anställningsförmåner"). Karensavdraget räknas på en genomsnittlig
  vecka med OB, och utan veckoarbetstid (oregelbunden tid) på en
  genomsnittlig vecka (prop. 2017/18:96 s. 68): i första hand den
  attesterade tiden och OB i upp till 13 kalenderveckor före sjukperiodens
  första vecka, räknat från den första veckan med attesterad tid och utan
  veckor med bara frånvaro; finns ingen sådan historik det publicerade
  schemat från sjukperiodens första vecka, delat med de veckor som har pass
  (en vecka utan pass räknas inte som 0 timmar). Finns varken historik,
  schema eller veckoarbetstid stoppas körningen med ett meddelande.
- **Avtalets sjuklön (A8, rättad 2026-11-02).** Enligt AI-utredning
  2026-11-02, juristgranskning återstår. Gäller när Avtal är på, även utan
  Planering. Avtalsversionen och anställningstidsgruppen avgörs på
  sjukperiodens första dag. `sick_pay.hour_value` ger avtalets eget timvärde
  för sjukavdrag och sjuklön för månadslön (Visita–Unionen 12/52, Handels
  tjänstemän 12,2/52, Gröna Riks månadslönen delad med månadens
  arbetstimmar, Detaljhandeln 4,6 % per hel dag och 1/166 per timme) och
  `sick_pay.karens` karensavdraget i tid (20 % av veckoarbetstiden, i
  Detaljhandeln den i förväg överenskomna). Det formatet inte kan uttrycka
  blir en notering på lönespecen. Tjänstemannaavtalen drar från dag 15 per
  kalenderdag enligt `sick_pay.after_employer_period`: en andel av årslönen
  upp till 10 prisbasbelopp och en annan över, högst en dags fasta lön, till
  dag 90 (minst ett års anställning eller direkt övergång från en
  anställning med sjuklön till dag 90, en ruta på anställningen) eller 45,
  och högst 105 respektive 45 dagar från dag 15 på tolv månader bakåt från
  sjukperiodens första dag; därefter vanligt sjukavdrag. Månadslönen i
  avdraget tar med fasta tillägg (på anställningen) och snittet av rörlig
  lön (provision, bonus) på attesterade lönespecar de tolv månaderna före.
  TODO(legal-verify): kost- och bostadsförmån i månadslönen och
  deltidssjukskrivning från dag 15 (noteringar på lönespecen), om
  Detaljhandelns karenstid ska värderas per timme, och vilka veckor som är
  representativa.
- **Semester enligt avtalet (A9).** Avtalsdatans `vacation` ger
  procentsats (procentregeln och semesterersättning med lönen),
  semestertillägg per dag (sammalöneregeln) och lägsta semesterlön per dag
  efter ålder och branschvana. `AgreementPayTerms` lägger värdena på
  `VacationInput` (`withAgreementTerms`) och lönens eget semestersteg räknar
  med dem; raden förklarar avtal och källa. Säger avtalet en annan
  semesterregel än anställningens blir det en varning. Semesterskulden,
  semesterårets slutbetalningar och semesterersättning vid anställningens
  slut räknas fortfarande med lagens värden.
- **Pension och avtalsförsäkringar (A10).** Avtalsdatan pekar på lönens
  regler `pension.saf_lo`/`pension.itp1` och
  `insurance.fora.arbetare`/`insurance.fora.tjansteman` (versionerade,
  kontrolleras under Lön → Regler; saknas en kontrollerad regel stoppas
  körningen). Har anställningen ingen egen pensionsplan används avtalets,
  med åldersgränser och tak (ITP 1: 30 IBB). `AgreementCostsStep` lägger en
  rad per premie (bp > 0) på kontant lön utan förmåner, med tak 7,5 PBB där
  regeln säger så, och hängavtalets administrationsavgift. Premierna är
  arbetsgivarens kostnad (`EMPLOYER`) och bokförs i lönens verifikation:
  tjänstepension 7410/2740, särskild löneskatt 7530/2514,
  avtalsförsäkringar (löneart `AVTALSFORSAKRING`) 7570/2740.
  TODO(legal-verify): BAS-namnet på 7570 och premieunderlaget per försäkring.
- **Fora- och Collectum-underlag (A10).** Lön → Avtal → Fora och Collectum
  visar per utbetalningsmånad kontant bruttolön, pension och premier per
  anställd i attesterade körningar; CSV (kolumner i
  `docs/avtal/datamodell.md` §6.7) kräver `agreements:manage` och loggas
  (`agreements.premium_basis_downloaded`). Det är NONOX eget underlag, inte
  Foras JSON-format eller Collectums format; inget skickas.
- **Provision, tillägg och kostavdrag (A11).** Avtalskoder av sorten
  `COMMISSION` (omsättning som antal; provision minus garanterad lön, eller
  hela provisionen utan garanti, på löneart `PROVISION`), `SUPPLEMENT`
  (per timme eller tillfälle, löneart `AVTALSTILLAGG`, semestergrund per
  kod) och `MEAL` (dagar; blir kostförmån till Skatteverkets värde plus
  nettoavdrag `FORMAN_EGENBETALNING` som minskar förmånsvärdet).
- **Lokala avtal.** En lokal avvikelse sparas per sökväg i
  `collective_agreements.overrides` (tillåtna sökvägar i `LocalOverrides`),
  för hand eller som godkänt AI-förslag, och används i en körning först när
  en person med `agreements:verify` har kontrollerat den. Ändras den måste
  den kontrolleras igen. Raden förklarar att värdet kommer från det lokala
  avtalet (källa `LOKALT#s. N`). Beräkningsperiod, komptid och fri text
  sparas men räknas inte.
- **Gyllene lönespecar (A12).** `tests/Fixtures/agreements/payslips` har
  minst tio fall per avtal i våg 1 (dag, kväll, natt, helg, helgdag/storhelg,
  ung, deltid, övertid, semester, kost/hängavtal), uträknade för hand
  (`expected-payslips.py`) ur avtalsfilerna; provision med garanti räknas
  mot ett syntetiskt avtal eftersom Frisöravtalets satser inte är publika.
  Pass och mertid (A5–A7) ingår inte i dessa fall. Sjuklönen ingår: två
  sjukperioder i en månad, karensrest över månadsskiftet och efter
  återinsjuknande, dagnummer genom en kedja, tio tillfällen räknade från
  sjukperiodens första dag, avtalens timvärden och karens i tid, taket 105
  dagar, gruppen på sjukperiodens första dag, direkt övergång, fasta tillägg
  och rörligt snitt i månadslönen och deltid från dag 15.
- **Inte byggt ännu.** Av A7–A8: övertidsarter per tillfälle och minst tre
  timmar, kost- och bostadsförmån i avtalets månadslön för sjukavdraget och ett
  mindre dagsavdrag för den som är deltidssjuk från dag 15. Av A9–A11: rörlig lön
  och 4,6 %-regeln i
  tjänstemannaavtalens semester, garantibelopp för deltid i handeln, ITP 2,
  sjukpension och premiebefrielse i ITP 1, TGL för tjänstemän, Foras och
  Collectums egna filformat.

## 5i. Planering (modul)

Schema för personalen (ADR-037, `PLANNING_MODULE.md`). Kräver Lön; läser
Personalliggare och Avtal när de är påslagna.

- **Schemat skriver aldrig i personalliggaren.** Liggaren ska föras när
  passet faktiskt börjar och slutar; Planering läser den bara.
- **Pass** (`planning_shifts`) planeras per anställd och avdelning, eller som
  öppna pass utan anställd. Ett pass är utkast tills perioden publiceras;
  publiceringen sparar passet som det publicerades (`published_snapshot`)
  och aviserar de berörda i appen och med e-post. Ett publicerat pass som tas
  bort ställs in och den anställde får besked. Allt skrivs till
  behandlingshistoriken.
- **Lönekostnad** per pass är en uppskattning: timlön, eller månadslön
  omräknad med veckoarbetstiden, plus OB enligt avtalet. Semesterlön och
  arbetsgivaravgifter räknas bara med kontrollerade löneregler; det som inte
  kan prissättas visas som saknat. Försäljningen hämtas ur bokförda
  verifikationer på intäktskontona i inställningarna (standard 3000–3799).
- **Arbetstidsregler och minderåriga** är data med källa i inställningarna
  och varnar bara; de stoppar aldrig ett schema (TODO(legal-verify), se 8).
- **Helgdagar** räknas fram i `App\Domain\Calendar\SwedishHolidays` enligt
  lagen (1989:253) om allmänna helgdagar, plus aftnarna som avtalen använder.
  Klockslag sätts som väggklocka, så sommartidsbytena räknas rätt.
- **Min sida:** den anställde ser sitt publicerade schema, kan ta öppna pass,
  be om att byta eller lämna ett pass och ange när den kan arbeta. Chefen
  beslutar; arbetstidsreglerna körs igen vid beslutet. En kalenderlänk (iCal)
  kan skapas; bara en hash av länken sparas och länken kan återkallas.
- **Tid att attestera.** Publicerade pass jämförs med liggarens pass per
  anställd och dag (avrundning och tolerans enligt inställningarna). En
  saknad in- eller utcheckning stoppar dagen. Chefen kan skriva in egen tid,
  också en dag utan planerat pass ("Lägg till arbetad tid", för företag utan
  personalliggare); liggaren ändras aldrig. Övertid och mertid för
  månadsavlönade räknas per beräkningsperiod (en vecka eller ett rullande
  schemas längd, högst fyra veckor utan kollektivavtal) eller per dag när
  avtalet säger det, och för timavlönade bara när avtalet ger tillägg; de kan på periodens sista dag tas ut som komptid i stället
  för pengar (5h, A7). Attesterade dagar blir löneunderlag: timavlönade
  får varje arbetad minut som Timlön plus OB; månadsavlönade får bara OB och
  tid utöver planen på den övertids- eller mertidsart som valts i
  inställningarna. Kortare tid dras aldrig av; frånvaro hanteras i Lön. OB
  delas upp med avtalets OB-fönster och dagtyper och räknas inte när OB ingår
  i lönen; ett avtal utan kontrollerad version stoppar dagen.
- **Skicka till lönen** skriver en godkänd tidrapport per dag och löneart
  (timmar med två decimaler). Att skicka igen ändrar inget. En dag hoppas
  över med en förklaring om en annan tidrapport redan täcker den eller om
  månadens lönekörning är attesterad; rättelsen görs då i nästa körning. En
  dag kan öppnas igen bara medan lönekörningen som betalar den inte är
  attesterad. Medan Planering är påslagen skapar personalliggaren inga egna
  tidrapportförslag.
- **Bemanning och kostnad** visar planerad och faktisk tid och kostnad mot
  försäljningen per dag och vecka; allt där är uppskattningar och ändrar
  ingen bokföring.
- **API och webhooks:** schemat kan läsas via NONOX Connect
  (`planning:view`); händelserna `planning.published` och `planning.attested`.
- **Behörigheter:** `planning:view`, `planning:manage`, `planning:attest` och
  `self:schedule`; rollen Schemaläggare planerar men attesterar inte.

## 6. Belopp

- Belopp lagras som hela ören i heltal, aldrig som flyttal. Avrundning sker till
  närmaste öre, och exakt mittemellan avrundas bort från noll
  ([ADR-006](../adr/ADR-006-money-and-audit-log.md)).

## 7. Behandlingshistorik

- Alla ändringar av företag, medlemmar, kontoplan och räkenskapsår, alla
  bokföringar och rättelser av verifikationer, varje kontroll av huvudboken, varje
  SIE-export, varje granskad och inläst SIE-fil samt
  in- och utloggningar, skrivs till tabellen `audit_logs` med användare, tidpunkt, åtgärd
  och värden före och efter.
- Tabellen går bara att lägga till i. Databasen själv vägrar ändringar och
  borttagningar.

## 7a. Arkivpaket

- Under Räkenskapsår skapar den som har behörigheten `export:archive` (ägare,
  administratör, redovisningskonsult, revisor) ett arkivpaket per räkenskapsår.
  Paketet byggs i bakgrunden och sparas; tidigare paket finns kvar, och ett år
  har högst ett paket under arbete åt gången.
- Paketet är en ZIP-fil med en mapp `Arkiv_<organisationsnummer>_<årets
  datum>` som innehåller:
  - `sie/`: årets SIE4-fil (samma som SIE-exporten).
  - `rapporter/`: saldobalans, resultatrapport, balansrapport, huvudbok och
    verifikationslista för hela året som CSV och PDF. Långa rapporter delas i
    flera PDF-filer om 500 rader.
  - `verifikationer/<verifikation>/`: underlagen till varje bokförd
    verifikation under sitt ursprungliga namn.
  - `kundfakturor/`: utfärdade kund- och kreditfakturor med fakturadatum under
    året, som PDF i fakturans egen layout.
  - `leverantorsfakturor/`: leverantörsfakturorna med fakturadatum under året,
    som de laddades upp.
  - `moms/`: rutor och belopp för varje redovisad momsperiod och filen till
    Skatteverket.
  - `lon/`: lönebesked för attesterade lönekörningar med utbetalningsdag under
    året, lönebesked från ett tidigare lönesystem och arbetsgivardeklarationerna
    (XML) för årets månader.
  - `behandlingshistorik/`: alla poster för företaget från årets första dag
    till dess paketet skapas, som CSV och JSON, med användarens namn och
    e-post.
  - `systemdokumentation/`: den här dokumentationen i den version som gällde
    när paketet skapades.
  - `LASMIG.txt` som förklarar paketet på svenska, `manifest.json` med storlek
    och SHA-256 för varje fil och `SHA256SUMS.txt` som kan kontrolleras med
    `shasum -a 256 -c` eller `sha256sum -c`.
- Paketets egen SHA-256 sparas och skrivs i behandlingshistoriken när paketet
  skapas, och varje nedladdning loggas. Filerna går att läsa utan NONOX.

## 8. Öppna frågor (TODO legal-verify)

- Avtal (ADR-033): upphovsrätt till en sammanställning av ett avtals
  värden och korta citat i källhänvisningar; ansvar för levererade
  avtalsvärden och ansvarsfriskrivningens lydelse; om hängavtalet binder
  företaget till samma version och försäkringar som medlemsvarianten;
  värden som bara finns i medlemsutgåvor; vilken dag som avgör minimilönens
  steg och ålder i avtal som inte säger det (Lager och E-handel, bageri,
  Handelns tjänstemannaavtal, Frisör); om övertidsprocenten i Lager och
  E-handel och bageriavtalet betalas utöver timlönen; om förslagen om
  lönegrupp från NONOX AI är högrisk enligt AI-förordningens bilaga III
  punkt 4 b.

- Personalliggare: vilka uppgifter som ska registreras från 2027 enligt
  39 kap. skatteförfarandelagen, förordningen och Skatteverkets föreskrifter
  (arbetsgivaruppgiften i prop. 2025/26:282 är inte införd); juristgranskning
  av bevarandetiden (9 kap. 5 § tredje stycket skatteförfarandeförordningen,
  två år efter utgången av det kalenderår då beskattningsåret gick ut) och
  av utländska identitetsnummer (AI-utredning 2026-11-02); hur Skatteverkets
  schema anger ett utländskt nummer; branschernas avgränsning; om en manuell registrering eller
  rättelse behöver fler uppgifter; Skatteverkets XML-schema och API för
  elektronisk överföring från 1 juli 2027 (TODO SPEC).

- Löneunderlag från schemasystem: om den inlästa originalfilen (PAXml eller
  CSV) måste sparas som underlag när lönekörningen är attesterad, eller om de
  godkända raderna räcker; hur länge en fil som tagits bort ur en öppen
  körning behöver finnas kvar (i dag tas den bort direkt).

- Semester: vilken metod ett kollektivavtal föreskriver för deltidsanställdas
  semesterdagar; om en semester som delas på två månader ska bedömas som en
  ledighet (9 §);
  löneavdraget för semesterdagar under procentregeln för månadsavlönade;
  skatt på semesterersättning som betalas med varje lön (förval tabell,
  inställning för engångsbelopp); begränsningen i 26 a § till dagar som tillsammans med intjänade
  dagar överstiger 25; vilken lön som är rörlig lön (nu: tillägg som är
  semesterlönegrundande, t.ex. OB); värdet av sparade dagar under
  procentregeln; sparade dagar som inte tas ut inom fem år; värdering av
  semesterlöneskulden under sammalöneregeln och sociala avgifter på skulden
  för anställda med nedsatt avgift.
- Lön: AGI-beloppens avrundning till hela kronor och skillnaden mot
  Skatteverkets egen avgiftsberäkning; deklarationsdag för företag med
  omsättning över 40 miljoner kronor; arbetsgivardeklaration för enskild
  firma; direkt inlämning via Skatteverkets API; om Skatteverkets XSD får
  följa med i ett öppet förråd; hur särskild löneskatt på pensionskostnader
  når skattekontot; semesterersättning vid anställningens slut (29 §) utan
  kollektivavtal och avdrag för förskottssemester; skatt på
  semesterersättning vid anställningens slut som engångsbelopp; vilka
  uppgifter arbetsgivarintyget kräver; retroaktiv lön på avdrag och
  semesterlön i redan utbetalda månader; det tidigare lönesystemets egna
  exportformat (t.ex. PAXml) och API.
- Räkenskapsårets längd och kalenderår (BFL 3 kap., AI-utredning 2026-11-02):
  att ingen nedre gräns gäller, att omläggning till kalenderår för en enskild
  firma inte kräver Skatteverkets tillstånd, att kommanditbolag omfattas av
  kalenderårsregeln för handelsbolag och att en enskild firmas första år får
  förlängas till 31 december året efter. `AGENTS.md` §6.2 ("6–18 månader")
  behöver rättas av ägaren.
- BAS-licensen och kontroll av mallarnas kontonummer och namn mot den officiella
  BAS 2026-kontoplanen.
- Vilka företagsformer som får använda K1, K2 och K3 (AI-utredning
  2026-11-02): särskilt K1 för handels- och kommanditbolag med bara fysiska
  delägare (spärrat tills vidare), K2 årsbokslut (BFNAR 2017:3) för enskild
  firma och handelsbolag, och K2 för stiftelser och ideella föreningar.
- Att lägga till underlag till en redan bokförd verifikation, så länge
  verifikationen i sig är oförändrad.
- SIE-företagstyperna "I" och "S" avser ideella föreningar och stiftelser som
  bedriver näring. Vi använder dem för alla ideella föreningar och stiftelser.
- Momsrutornas koppling till BAS-konton, att ören stryks per ruta, filen
  till Skatteverket (eSKDUpload 6.0) och rutor utan konton (06, 07, 08, 37,
  38, 41).
- Konto för obokfört resultat vid årsskifte per företagsform, särskilt
  handelsbolag.
- Fakturans obligatoriska innehåll enligt mervärdesskattelagen; omvänd
  skattskyldighet, EU- och exportfakturor är inte byggda ännu.
- Kontantmetoden vid årets slut (byggt, avsnitt 5a5): att återföring första
  dagen i nästa år är en godtagbar metod för de obetalda fakturorna (BFL och
  BFNAR 2006:1 säger vad som ska bokföras, inte hur det tas bort), och i
  vilken momsperiod momsen på dem redovisas (Mervärdesskattelag 2023:200,
  7 kap.).
- Import av varor från länder utanför EU (ruta 50, 60–62) är inte byggd ännu;
  vilket datum som styr växelkursen för varor från andra EU-länder ska
  bekräftas.
- Leverantörsfakturor i utländsk valuta: att Riksbankens mittkurs på
  fakturadatumet är rätt bokföringskurs (ML 8 kap. 21 § nämner ECB:s kurs
  för momsens beskattningsunderlag); att realiserade kursdifferenser på
  leverantörsskulder hör hemma på 3960/7960; hur orealiserade
  kursdifferenser på obetalda skulder ska värderas vid bokslutet (byggt
  enligt K2 punkt 13.5 och 17.8 och K3 punkt 30.3, även med kontantmetoden;
  juristgranskning återstår).
- Kundfakturor i utländsk valuta: att Riksbankens mittkurs på fakturadatumet
  duger som kurs för momsens beskattningsunderlag (ML 8 kap. 21 §; rådets
  direktiv 2006/112/EG art. 91); vilken paragraf i ML 17 kap. som motsvarar
  direktivets art. 230 om att momsbeloppet ska anges i kronor på en faktura i
  annan valuta, och om även summan i kronor krävs; att realiserade
  kursdifferenser på kundfordringar hör hemma på 3960/7960; att Riksbankens
  mittkurs på årets sista dag duger som balansdagens kurs (K2:s kommentar
  nämner den, men normalt bankens köpkurs för fordringar och säljkurs för
  skulder), och återföringen första dagen i nästa år; och OSS-försäljning i
  annan valuta (OSS-deklarationen görs i euro).
- Inlästa verifikationer bokförs med en behandlingshistorikpost per inläsning,
  inte en per verifikation. Varje verifikations ursprung finns i tabellen
  `imported_vouchers`. Är det tillräckligt som behandlingshistorik enligt
  BFNAR 2013:2?
- Momsdagar: sista dag för helårsmoms beror på bolagsform, EU-handel och hur
  inkomstdeklarationen lämnas och visas därför inte. Momsdagarna för månads-
  och kvartalsmoms är hämtade från Skatteverkets sida och broschyr SKV 408
  (2026) och ska kontrolleras av en behörig person.
- Anläggningar: skattemässiga avskrivningar (räkenskapsenlig avskrivning,
  30- och 20-regeln) och överavskrivningar (2150/8850) hanteras inte; registret
  följer planen. Moms vid försäljning av inventarier och om avyttringsmånaden
  ska skrivas av lämnas till användaren. Kontot 7835 för avskrivningar på
  datorer ska kontrolleras mot gällande BAS.
- ROT och RUT: andelarna (30 % ROT, 50 % RUT från 2026-01-01), taken
  (50 000 kr ROT, 75 000 kr totalt per person och år), avrundningen, vad som
  räknas som arbetskostnad och vilka uppgifter fakturan ska ha ska
  kontrolleras. Taket prövas bara mot företagets egna fakturor. Begäran om
  utbetalning som fil, kontantmetoden och avslag från Skatteverket (där
  köparen ska betala resten) hanteras inte ännu.
- Omvänd byggmoms: fakturatexten, kravet på båda momsregistreringsnumren,
  kontot 3231 och ruta 41 ska kontrolleras mot mervärdesskattelagen och
  Skatteverkets vägledning. Systemet avgör inte om en tjänst är en byggtjänst
  eller om köparen är ett byggföretag; det gör användaren.
- EU och export: kontona 3108/3308/3105/3305, rutorna 35/39/36/40 och
  fakturatexterna ska kontrolleras mot mervärdesskattelagen (17 kap.) och
  BAS. Momsregistreringsnumret kontrolleras inte mot VIES, och systemet
  hanterar inte bevis för att varor lämnat Sverige.
- Trepartshandel: konto 3107 och fakturatexten är inte bekräftade mot BAS och
  ML; ruta 37 (mellanmans inköp) bokförs inte automatiskt.
- OSS: konto 3106/2619 är inte bekräftade mot BAS. Momssatserna i `OssRates`
  ska kontrolleras mot EU-kommissionens databas (TEDB); reducerade satser
  hanteras inte. Tröskelvärdet 99 680 kr (ML 6 kap. 62 §): att året tas från
  fakturadatumet, att registrering för OSS räknas som begäran enligt 6 kap.
  64 §, att all OSS-markerad försäljning är distansförsäljning av varor
  eller TBE-tjänster (andra tjänster till privatpersoner följer egna regler
  och räknas inte för sig). Omräkningen till euro: vilken kurs som gäller när
  ECB inte publicerar någon på kvartalets sista dag (nästa publicerade
  används; direktivets art. 369h är inte läst), avrundningen av momsen i
  euro, att eurofakturor räknas med sina eurobelopp, vilken period en
  försäljning hör till och rättelser av tidigare kvartal.
- Valutakurs för momsens beskattningsunderlag: om Riksbankens mittkurs
  räknas som "senast noterade genomsnittliga kurs på den mest representativa
  valutamarknaden i Sverige" (ML 8 kap. 21 §); Skatteverket nämner ECB:s
  kurs och Nasdaq OMX Stockholms fastställda mittkurs. Vid förskott (7 kap.
  7 §) och byggtjänster med omvänd betalningsskyldighet (7 kap. 8 §) borde
  dagen vara betalningsdagen respektive fakturadagen; NONOX använder
  leveransdatumet om det är ifyllt, annars fakturadatumet.
- VIES: om kontroll krävs före varje försäljning och hur gammal den får vara.
- Företagsuppgifter från Bolagsverket (ADR-035): rättslig grund för att
  skicka en enskild firmas personnummer till Bolagsverket och visa svaret,
  villkoren för vidareanvändning och källangivelse, samt Bolagsverkets
  fullständiga lista över koder för organisationsform.
- Periodisk sammanställning: vilken period en försäljning hör till
  (fakturadatum används), avrundning till hela kronor och filens
  teckenkodning (ISO-8859-1).
- Inkomstdeklaration: BAS-intervallen bakom varje fältkod ska kontrolleras
  mot den officiella BAS–INK2-kopplingen (särskilt 4000–4999, koncernposter
  och bokslutsdispositioner). Identiteten skrivs som 16 följt av
  organisationsnumret. Övriga INK2S-punkter (delägarrätter, handelsbolag,
  fastigheter m.m.) fylls i för hand i Skatteverkets tjänst. Schablonintäkten
  på periodiseringsfonder: statslåneräntorna per år, att ingående saldo på
  2110–2149 är detsamma som gjorda avdrag, avrundningen till hela kronor och
  månadsräkningen för ett brutet år (#83). Underlaget för särskild
  löneskatt: att BAS 7400–7499 är rätt förslag och vilka poster enligt lag
  (1991:687) 2 § som skiljer sig från den bokförda kostnaden (#87). Fältkoderna ska kontrolleras på nytt för
  varje ny beskattningsperiod innan den läggs till i `VERIFIED_PERIODS`.
- Årsredovisning: radindelningen och BAS-intervallen (särskilt 4000–4999 och
  8000–8799) ska kontrolleras mot ÅRL bilaga 2–3, BFNAR 2016:10 och den
  officiella BAS–K2-kopplingen. Notuppsättningen (bara redovisningsprinciper,
  anställda och anläggningstillgångar), standardtexterna, antal år i
  flerårsöversikten och skattesatsen 20,6 % i soliditetsberäkningen ska
  kontrolleras. Ekonomiska föreningar får samma uppställning som aktiebolag.
- Bokslut: skatten räknas inte fram av systemet. Sidan visar 20,6 % av
  resultatet före skatt som jämförelse; skattesatsen och skillnaden mellan
  bokfört och skattemässigt resultat (INK2) ska kontrolleras. Kontona 2512
  och 2898 ska kontrolleras mot gällande BAS. Utdelningen prövas mot fritt eget
  kapital i det senaste årets balansräkning, men inte mot ändringar i bundet
  eget kapital efter balansdagen, utdelningar som redan gjorts sedan stämman
  (ABL 17 kap. 4 §) eller försiktighetsregeln (17 kap. 3 § andra stycket),
  som styrelsen bedömer (#84, #85). Eget kapital för
  handelsbolag och kommanditbolag hålls inte per delägare. Ideella föreningar
  och stiftelser behandlas som aktiebolag vad gäller skatt och konton.
- Periodiseringar: systemet varken kräver eller hindrar en periodisering;
  användaren avgör vad som är väsentligt. Ska systemet påminna om en
  beloppsgräns under vilken K2-företag får avstå från att periodisera, och i
  så fall vilken (kontrollera BFNAR 2016:10)?
- Flytt från Fortnox via API: Fortnox villkor för integrationer som läser
  andra företags uppgifter, och om NONOX behöver vara registrerad
  integrationspartner. Hur `/3/archive/{id}` lämnar ut filer ska prövas mot
  ett riktigt konto.
- Arkivpaket: räcker det att paketet innehåller underlag, fakturor och
  lönebesked per räkenskapsår, eller ska även räkenskapsinformation som hör
  till året men daterats utanför det (till exempel en faktura daterad efter
  årsskiftet för en kostnad som periodiserats) följa med? Och ska paketet
  signeras eller tidsstämplas utöver kontrollsummorna?

- Inkorg: hur dricks som betalats med kort, presentkort (enfunktions- eller
  flerfunktionsvoucher, ML 2023:200 3 kap.) och öresavrundning ska bokföras
  från en dagsrapport; systemet föreslår bara och en person godkänner.
  Gällande momssatser på rapportens datum (25, 12, 6 och 0 %). Om ett
  avvisat underlag kan vara räkenskapsinformation, och därmed om gallringen
  efter 7 dagar är rätt. Om ett underlag som tas emot med e-post och sparas
  som `.eml` uppfyller kraven på hur räkenskapsinformation ska bevaras (BFL 7
  kap.). SFTI:s konvention för bankgiro och plusgiro i Peppol-fakturor.

- Bank: om Stafe måste registreras hos Finansinspektionen som ombud för
  leverantören av kontoinformation; Enable Banking som underbiträde; om
  transaktioner som hämtats från banken är räkenskapsinformation i sig och
  ska ingå i arkivpaketet (ADR-034).

### Lön, löneåret 2027 (5b)

Alla värden i `data/2027/rules.json` är overifierade tills en människa har
kontrollerat dem mot källan. Särskilt:

- Budgetpropositionen för 2027 hade inte lämnats 2026-11-02 (valår, senast
  12 november). Arbetsgivaravgifter, allmän löneavgift, särskild löneskatt
  och regionalt stöd är hämtade ur lagtexten i kraft; kontrollera dem igen
  efter riksdagens beslut i december.
- Avgift för äldre 2027: socialavgiftslagen 2 kap. 27 § (67 år vid årets
  ingång) ger födda 1938–1959; övergångsbestämmelsen för födda 1937 och
  tidigare gäller fortfarande.
- Sänkt avgift för unga 2027: lag (2026:100) ger födda 2004–2008 till och
  med 30 september 2027; därefter ingen nedsättning.
- SINK 20 procent från 2027 (SFS 2025:1356), sjöinkomst 15 procent.
- Inrikes traktamente 2027 är räknat ur inkomstskattelagen 12 kap. 11, 13,
  16 och 21 §§ med prisbasbeloppet 59 600 kr (0,5 procent avrundat till
  tiotal = 300 kr); jämför med Skatteverkets "Belopp och procent 2027" när
  den publiceras.
- Filformat för arbetsgivardeklarationen 2027: version 1.1 är den enda som
  Skatteverkets schemalager visade 2026-11-02; kontrollera i januari 2027.
- Inte publicerade 2026-11-02 (läggs in när de kommer): inkomstbasbelopp
  (regeringen, november 2026), kostförmån, bilförmånens statslåneränta (30
  november 2026), måltidsavdrag på traktamente, utlandstraktamente,
  milersättning, gåvor, friskvård, deklarationsdagar 2027 och tabellen för
  engångsbelopp (Skatteverket, december 2026).
- Om skatteavdraget i januari får göras med förra årets tabell
  (skatteförfarandelagen 11 kap. 16 § andra stycket) när årets tabeller inte är inlästa.

### Planering (5i)

- Arbetstidslagens gränser i Planerings standardregler (5, 8, 10, 10 b, 13,
  14 och 15 §§) och hur kollektivavtalen avviker från dem.
- Gränserna för minderåriga enligt AFS 2023:2.
- Helgdagskalendern mot gällande lagtext.
- Övertid och mertid räknas mot dagens plan; lagen och avtalen räknar mot
  ordinarie arbetstid över en vecka eller längre.
- Om timavlönade ska ha övertids- eller mertidstillägg för tid utöver
  schemat (i dag Timlön), och vilken av avtalets övertidsarter som gäller
  (väljs av företaget).
- OB räknas över hela passet inklusive rast, eftersom liggaren inte vet när
  rasten tas; chefen kan dela upp dagens tid.

## 9. Ändringshistorik

| Version | Datum | Ändring |
|---|---|---|
| 0.1 | 2026-10-29 | Första versionen: företag, kontoplan, räkenskapsår, verifikationsserier, behandlingshistorik. |
| 0.2 | 2026-10-29 | Verifikationer: utkast, bokföring med obruten numrering, oföränderlighet, rättelse, kontrollsummor och nattlig kontroll av huvudboken. |
| 0.3 | 2026-10-29 | Underlag med kontrollsummor, konteringsmallar, kontroll av underlag i den nattliga kontrollen. |
| 0.4 | 2026-10-29 | Ingående balanser per räkenskapsår och konto, export av räkenskapsår i SIE4-format. |
| 0.5 | 2026-10-29 | Inläsning av SIE4-filer i två steg med avstämning, historik för tillagda och borttagna rader. |
| 0.6 | 2026-10-29 | Kostnadsställen och projekt på verifikationsrader, i SIE-export och SIE-import. |
| 0.7 | 2026-10-29 | Rapporter, momsdeklaration med bokföring av momsen och periodlås, årsskifte. |
| 0.8 | 2026-10-29 | Kunder, artiklar, kundfakturor med OCR och PDF, betalningar och kreditfakturor. |
| 0.9 | 2026-10-29 | Offerter och order som blir fakturor. |
| 0.10 | 2026-10-30 | Leverantörer och leverantörsfakturor med underlag, attest och betalningar (serie D och E). |
| 0.11 | 2026-10-30 | Betalfiler till banken (pain.001) för attesterade leverantörsfakturor. |
| 0.12 | 2026-10-30 | Inläsning av inbetalningar från bankens fil (camt.054) med förslag på faktura efter OCR. |
| 0.13 | 2026-10-30 | Avtalsfakturering. |
| 0.14 | 2026-10-30 | Kreditnotor från leverantörer och inköp där köparen redovisar momsen. |
| 0.15 | 2026-10-30 | Moduler och lönemodulens första del: regler, skattetabeller, beräkning och attesterade lönekörningar. |
| 0.16 | 2026-10-31 | Lönemodulen: lönebesked och Min sida, semester, förmåner, traktamente och pension, utlägg, lönefil och skattekontot, arbetsgivardeklaration (AGI), lönerevision och retroaktiv lön, slutlön, rapporter, löneårsskifte, lönearkiv och flytt av lönedata. |
| 0.17 | 2026-10-31 | Utlägg med bara kvittot: NONOX AI läser uppgifterna, den som godkänner kontrollerar och rättar. |
| 0.18 | 2026-10-31 | Arkivpaket per räkenskapsår med SIE, rapporter, underlag, fakturor, moms, lön, behandlingshistorik och systemdokumentation, med SHA-256 för varje fil. |
| 0.19 | 2026-10-31 | Mina företag: översikt över alla företag och vad som väntar i vart och ett, för byråer och ägare med flera företag. |
| 0.20 | 2026-10-31 | Mina företag: sista dag för moms och AGI, och månadsstatus per företag. |
| 0.21 | 2026-10-31 | Mina företag: ansvarig konsult och byråns anteckningar, Byråvy per månad, och kundens panel Din redovisningsbyrå. |
| 0.22 | 2026-10-31 | Nytt utseende (ADR-019): mörk sidomeny, nya färger, och appen följer företagets egen färg under Varumärke. |
| 0.23 | 2026-11-01 | Anläggningsregister med linjära avskrivningar enligt plan, avyttring och utrangering. |
| 0.24 | 2026-11-01 | Periodiseringar: förutbetalda och upplupna kostnader och intäkter, med månadsvis fördelning och återföring. |
| 0.25 | 2026-11-01 | Bankavstämning per konto mot kontoutdraget, med poster som förs vidare tills de når banken. |
| 0.26 | 2026-11-01 | Bokslut: checklista, skatt och årets resultat, stängning av året och resultatdisposition. |
| 0.27 | 2026-11-01 | Årsredovisning enligt K2 som sida och PDF, med siffror ur bokföringen. |
| 0.28 | 2026-11-01 | Inkomstdeklaration 2 (INK2, INK2R, INK2S) ur bokföringen som SRU-filer. |
| 0.29 | 2026-11-01 | Omvänd betalningsskyldighet för byggtjänster på kundfakturor, konto 3231 och momsruta 41. |
| 0.30 | 2026-11-01 | ROT- och RUT-avdrag på kundfakturor, delad fordran på 1513 och Skatteverkets betalning. |
| 0.31 | 2026-11-01 | Resultat per projekt och kostnadsställe i registren. |
| 0.35 | 2026-11-01 | Säkerhetsgranskning och GDPR: företagsbindning av sidor, härdad inloggning och sessioner, säkerhetsrubriker, skydd av hälsouppgifter och bankkonton i lönen, registerutdrag, radering och anonymisering, gallring, cookies, integritetspolicy, villkor och personuppgiftsbiträdesavtal. |
| 0.36 | 2026-11-01 | Begränsad behandling (GDPR art. 18) för kunder och leverantörer: inga nya fakturor, offerter, avtal eller leverantörsfakturor medan begränsningen gäller. Radering av anställda inom lagringstiden för lönebesked. Samma organisationsnummer kan finnas i flera konton i den driftade tjänsten. Inbjudningar gäller 14 dagar. Tak per timme för SIE-uppladdning, VIES, arkivpaket och bankfiler. Säkerhetskopior krypteras och lagras bara i Sverige i 7 dagar. |
| 0.38 | 2026-11-02 | Inkorg (modul): egen e-postadress per företag och en gemensam adress, lista över godkända avsändare, SPF/DKIM/DMARC och spärr av farliga bilagor före all tolkning, originalet som `.eml` kopplat som underlag, SIE4, Peppol och dagsrapporter från kassasystem med mallar, NONOX AI, varningar för bedrägeri och dubbletter, och automatisk bokföring bara med en mall som en person godkänt. |
| 0.39 | 2026-11-01 | Inkorg: dagsrapporter som PDF läses ur PDF-filens text och kan få en mall, så nästa rapport tolkas utan AI. Varning när mejlet nämner en bilaga som saknas. Inkommande mejl kan läsas igen och raderas så länge de inte blivit bokföring eller utkast; raderingen loggas. Personalliggare: verksamheten Övrig för lokaler som bara använder tidsredovisning. Leverantörsfakturor i utländsk valuta från inkorgen räknas om till kronor med Riksbankens mittkurs på fakturadatumet (eller en kurs som personen skriver), och originalbeloppet och kursen skrivs i fakturans text. |
| 0.40 | 2026-11-01 | Betalfiler godkänns av någon med rätt att godkänna betalningar innan de kan laddas ned eller markeras som betalda, och inte av den som skapade filen så länge företaget kräver att någon annan attesterar. |
| 0.34 | 2026-11-01 | Flytt från Fortnox via API: anslutning med OAuth2 och PKCE, bokföring per år som SIE4 genom den vanliga granskningen, register, underlag och avstämning av obetalda fakturor. |
| 0.33 | 2026-11-01 | Trepartshandel (3107, ruta 38), OSS för privatpersoner i andra EU-länder (köparlandets moms på 2619, utanför momsdeklarationen), VIES-kontroll av momsregistreringsnummer och EU-rapporter: periodisk sammanställning med fil och OSS-underlag. |
| 0.32 | 2026-11-01 | Försäljning till andra EU-länder och export: kundens land, konton 3108/3308/3105/3305, momsrutorna 35/39/36/40 och fakturatexter. Ruta 35 matas av 3108 i stället för 3106. |
| 0.37 | 2026-11-02 | Personalliggare (modul): lokaler, personal, kiosk som fungerar utan internet, personlig QR-kod, mobil incheckning med platskontroll, liggare med hashkedja och rättelser, kontrollbesök med export och förslag till tidrapport i lönen. |
| 0.41 | 2026-11-01 | Dataskydd: "Radera mitt konto" på profilen (art. 17). Nekas så länge användaren äger ett företag som inte är arkiverat. Medlemskap, sessioner, API-nycklar, inloggningskoder, väntande inbjudningar och profilbild tas bort; kontot raderas om inget längre hänvisar till det, annars pseudonymiseras det ("Raderad användare", en adress på .invalid, ingen inloggning) så att verifikationer och behandlingshistorik fortfarande visar vem som gjorde vad. Registerutdrag för en person i personalliggaren och för en avsändare till inkorgen (JSON, för inkorgen även CSV), loggade. |
| 0.42 | 2026-11-01 | Säkerhetsgranskning: bokföring och periodlås väntar in varandra (bokföringen håller ett delat lås på företagets rad, periodlåset ett uppdateringslås), så en verifikation kan inte hamna i en period som låses samtidigt. Belopp från Fortnox, inkorgens tolkning och utlägg läses exakt till hela ören (8,155 kr blir 816 öre). Personalliggare: mobil incheckning markeras som självrapporterad position och begränsas per person, kontrollbesök som kiosken rapporterar från offlineläge loggas bara för aktiva chefer och som ej verifierade, och exporten gäller högst 13 månader. Tak per timme för PDF:er och rapportfiler. |
| 0.43 | 2026-11-01 | Lön: lönekörningen betalar godkända tidrapporter som timlön (timmar × timlön) för anställda med timlön, varje tidrapport knuten till den körning som betalar den och låst när körningen attesteras. Lönekörningens sida visar timmar som väntar på godkännande. |
| 0.44 | 2026-11-01 | Inkorgens gallring enligt beslut: avvisat efter 7 dagar, ohanterad karantän och omatchad post till den gemensamma adressen efter 90 dagar, skräppost från avsändare som inte är godkända sparas aldrig. Godkända avsändares post sparas alltid. ClamAV skannar alla bilagor i den driftade tjänsten. |
| 0.45 | 2026-11-01 | Lön: godkända tidrapporter från tidigare månader som ingen lönekörning har betalat blir en fråga i nästa ordinarie lönekörning – "Ta med i den här lönekörningen" eller "Inte nu". Svaret sparas på körningen och i behandlingshistoriken, följs vid ny beräkning och låses när körningen attesteras. Medtagna timmar betalas som egna rader med den månad de hör till. |
| 0.46 | 2026-11-01 | Företag: ägaren kan överlåta företaget till en annan medlem (blir själv administratör) och arkivera det. Före arkiveringen visas arkivpaketen per räkenskapsår; saknas ett aktuellt paket krävs en bekräftelse. Ett arkiverat företag kan bara läsas: bara läsande behörigheter gäller, och företagsdata kan inte skapas eller ändras ens av schemalagda körningar. Inkommande post till ett arkiverat företag loggas och tas inte emot. Ägaren kan ta företaget ur arkivet. |
| 0.47 | 2026-11-01 | Leverantörsfakturor i utländsk valuta: valuta, valutabelopp och bokföringskurs sparas på fakturan (från inkorgen och i formuläret, med Riksbankens kurs förifylld). Betalning i valutan med kronorna banken drog; skillnaden mot de bokförda kronorna bokförs på 3960 eller 7960 i betalningens verifikation. Delbetalningar i valutan, och återstående valutabelopp visas. Sådana fakturor tas inte med i betalfiler eller kvittningar. |
| 0.48 | 2026-11-01 | Passkeys (ADR-029): andra steg efter e-postkoden för den som kan se hälsouppgifter eller fullständiga personnummer, och för alla i ett företag som kräver det. Passkeys under Profil, återställning av ägare eller administratör, loggat och mejlat. |
| 0.49 | 2026-11-01 | Översikt: nyckeltal, "Att göra" från bokföringen och modulerna, senaste verifikationer med belopp ur bankens synvinkel, cache per företag och roll med omladdning, och fliken Statistik och prognos med diagram, tabeller och en förklarad likviditetsprognos. Översikten läser bara. |
| 0.50 | 2026-11-01 | Byråns arbetsyta på Mina företag: summor och en gemensam Att göra-lista för alla företag med filter och sparade vyer, månadsstängning per klient, uppgifter med tilldelning, kommentarer, checklistor och återkommande uppgifter, notiser och daglig sammanfattning med e-post (ADR-028). |
| 0.51 | 2026-11-01 | Redovisningsbyråns varumärke (ADR-030): ett företag kan markeras som byrå, kundföretagets ägare väljer sin byrå bland dem som en gemensam redovisningskonsult är med i, och kundsidan ser byråns logga och färger i appen, på Min sida och i mejl om inloggning och uppgifter. Kundens egna fakturor, offerter, order och lönebesked behåller kundens varumärke. Kopplingen ger ingen behörighet och loggas i båda företagen. |
| 0.52 | 2026-11-01 | Återställningstest av säkerhetskopior (`scripts/restore-test.sh`, `backup:verify-restore`): kopian återställs i en tillfällig databas, och bokföringens hashkedja, underlagens kontrollsummor, personalliggaren och leverantörsfakturornas filer kontrolleras. Körs varje vecka i den driftade tjänsten. SBOM (CycloneDX) som bilaga till varje release. |
| 0.53 | 2026-11-01 | NONOX går att installera som app på hemskärmen (PWA, ADR-031): manifest för appen och för Min sida, och en service worker som bara visar en offlinesida och inte sparar några uppgifter i enheten. Bokföringen påverkas inte. |
| 0.54 | 2026-11-01 | Lön: löneunderlag från schemasystem som PAXml (1.0, 2.0–2.3) eller CSV läses in i en öppen lönekörning som förslag. Koderna kopplas till lönearter en gång per källsystem, frånvaro och saldon läses aldrig in som lön, och en person godkänner raderna innan de blir rader i lönekörningen. Samma fil läses en gång per period; originalet sparas krypterat och varje steg loggas. |
| 0.55 | 2026-11-01 | NONOX Connect (modul, ADR-032): öppet API under `/api/v1` för företaget, kontoplan, momskoder, räkenskapsår, verifikationsserier, verifikationer (utkast, bokföring, rättelse, underlag), kunder och leverantörer. Appar ansluts med OAuth 2.0 och PKCE efter godkännande med passkey av ägare eller administratör, API-nycklar har slutdatum och skapas med passkey. Bokförda verifikationer kan fortfarande bara rättas, och varje skrivande anrop loggas. |
| 0.56 | 2026-11-01 | Bank (modul, ADR-034): koppling av bankkonton med BankID genom Enable Banking (öppen bankdata, bara läsning), hämtning av transaktioner och saldon 1–4 gånger per dygn som sparas en gång och inte kan ändras, inbetalningar med förslag på faktura, förslag på matchning mot bokförda rader som en person bekräftar, bankens saldo i bankavstämningen, påminnelse före medgivandets slut och bortkoppling. Inga verifikationer skapas av modulen. |
| 0.57 | 2026-11-01 | Utvecklarwebben för NONOX Connect under `/utvecklare` (svenska, engelska med `?lang=en`): guider från `docs/api/guider`, en referens som genereras på servern från `docs/api/openapi.yaml`, kodexempel, ändringslogg (`docs/api/CHANGELOG.md`) och specifikationen som YAML och JSON. Ändrar ingen bokföring: sidorna läser bara specifikationen och inställningarna för gränser. |
| 0.58 | 2026-11-01 | Avtal (modul, ADR-033): kollektivavtalens värden som versionerad data med källa, inläst okontrollerad och använd först efter en namngiven kontroll (installationen eller företaget), skyddad mot ändring när den är kontrollerad. Företagets bindning (medlem eller hängavtal) och anställningens avtal, lönegrupp och branschvana. Lönekörningen stoppas utan kontrollerad version; redan uppdelade OB- och övertidstimmar prissätts med avtalets sats med version och källa på raden; avtalskalender med aftnar och storhelger; minimilönekontroll med varning. NONOX AI föreslår avtal och anställningsuppgifter ur maskerad text, med citat och sida, och inget förs in utan en person. |
| 0.59 | 2026-11-01 | NONOX Connect: kundfakturor, leverantörsfakturor med attest av någon annan, betalningar, artiklar, kostnadsställen och projekt, rapporter, momsdeklaration per period, periodernas låsning och SIE-export i API:t, med samma funktioner som appen; ett anrop som bokför kräver behörighet att bokföra. Signerade webhooks som skickas efter att bokföringen sparats, med skydd mot anrop till interna nät och avstängning efter ett dygns fel. Appar kan återkalla sin egen anslutning. Testföretag med exempeldata, tydligt märkta och utan utskick. |
| 0.61 | 2026-11-01 | Verifikationens rader i ordningen konto, kostnadsställe, projekt, radtext, debet, kredit och kontosaldo, i inmatningen, på verifikationen och i verifikationslistan. Kontosaldo (ingående balans plus bokförda verifikationer, aldrig utkast) visas när ett konto väljs och efter varje rad, på kontots normala sida; bara information, bokföringen ändras inte. Konton i rapporterna och på verifikationen leder till huvudboken för kontot (kontoanalys) med samma filter. Underlag kan fotograferas med mobilen, och NONOX AI läser det direkt efter uppladdningen när modulen är påslagen; AI:n fyller bara i formuläret. |
| 0.62 | 2026-11-01 | Anläggningstillgång direkt från inköpet: en rad i debet på ett anskaffningskonto i en verifikation eller leverantörsfaktura kan bli en tillgång i registret. Förslaget sparas med utkastet, och tillgången skapas i samma transaktion som bokföringen eller registreringen, länkad till verifikationen och raden. Anskaffningskonton och gräns för inventarier av mindre värde är företagets egna inställningar. |
| 0.63 | 2026-11-01 | Företagsuppgifter från Bolagsverket (ADR-035): namn, adress, postnummer, ort och företagsform hämtas med organisationsnumret i formulären för nytt företag, företagsinställningar, kunder och leverantörer. Numret kontrolleras med Luhn först, svaret är ett förslag som bara fyller tomma fält och ändrar inget användaren skrivit utan att fråga, och inget sparas förrän formuläret sparas. VIES-kontroll av leverantörens momsregistreringsnummer, loggad i behandlingshistoriken. Ändrar ingen bokföring. |
| 0.64 | 2026-11-01 | Manualen för användare under `/manual` (ADR-036): artiklar i Markdown från `docs/manual/sv`, sök i webbläsaren och en knapp **Hjälp** i appen och på Min sida som öppnar artikeln om sidan man står på. Visas när de publika sidorna är påslagna. Ändrar ingen bokföring: sidorna läser bara textfilerna. |
| 0.65 | 2026-11-02 | Modulen Planering (5i, ADR-037): schema med publicering, Min sida, attest av tid mot personalliggaren och löneunderlag. Lönekörningen betalar nu också godkända tidrapporter på tilläggsarter i timmar (OB, övertid, mertid), även för månadsavlönade, prissatta dag för dag av Avtal. Avtalets dagtyper sätter klockslag som väggklocka, så OB-gränserna är rätt på dagarna då sommartiden ändras. |
| 0.66 | 2026-11-02 | Personalliggare: rollerna Anställd och Schemaläggare får checka in och ut i sin egen mobil (`staff-ledger:self`), på lokaler med mobil incheckning och med inloggningen kopplad till personen. Ändrar ingen bokföring. |
| 0.67 | 2026-11-02 | Tillgänglighet (5a9): granskning mot WCAG 2.2 AA, sidtitlar per sida, hopplänk, fokusmarkering som inte döljs, felmeddelanden kopplade till fälten och upplästa, kontrast i färgtokens, tillgänglighetsredogörelse på /tillganglighet. Ändrar ingen bokföring. |
| 0.68 | 2026-11-02 | Lön: löneåret 2027 (5b, ADR-012). Regler för 2027 som data med källa, overifierade; värden som inte är publicerade ännu är listade som väntande. Nattlig inläsning av regelfilerna som aldrig skriver över en kontrollerad version. Översikt över nästa löneår och kontroll av regler i Lön → Regler. Skattetabeller kan hämtas från Skatteverkets öppna data. Lön utan årets skattetabeller stoppas med ett tydligt besked. |
| 0.69 | 2026-11-02 | Leverantörsbetalningar (5a1b, ADR-014): bankens svar på betalfiler (pain.002, camt.054 debet) läses in och matchas mot betalningarna på end-to-end-id, och i sista hand belopp och datum efter bekräftelse. Bokförd debitering bokförs som betalning i serie E med samma åtgärd som manuell betalning; avvisade betalningar blir obetalda med bankens orsak. Samma fil och samma svar tas bara emot en gång. |
| 0.70 | 2026-11-02 | Kundfakturor i utländsk valuta (5.3, ADR-025): belopp i valutan och i kronor med kurs, datum och källa; bokföring och moms i kronor; betalning i valuta med realiserad kursdifferens på 3960/7960; kreditfaktura med originalets kurs. Omvärdering av öppna kund- och leverantörsfakturor i utländsk valuta på balansdagen med återföring dagen efter; i bokslutets checklista en varning, inte ett hinder, tills regeln är kontrollerad. |
| 0.71 | 2026-11-02 | NONOX Connect: SFTP för SIE-filer (ADR-032). En nyckelinloggning per företag via SFTPGo:s krokar, företaget avgörs av servern. En uppladdad fil blir en väntande SIE-inläsning som en person måste bekräfta; inget bokförs automatiskt. Filen tas bort ur mappen efter läsningen. Behandlingshistorik för nycklar, inloggningar och filer. |
| 0.72 | 2026-11-02 | Avtal A5–A8 på Planering (5h, 5i, ADR-033): avrundning av OB och övertid enligt avtalsdata, övertid och mertid över en beräkningsperiod på flera veckor, komptid med saldo (Lön → Komptid), sjuklön med OB enligt det publicerade schemat och karensavdrag på ett genomsnitt som tar med schemats OB, avdrag dag 15–90 enligt avtal. Alla värden overifierade. |
| 0.73 | 2026-11-02 | Avtal A9–A12 (5h, ADR-033): avtalets semester (procentsats, semestertillägg, lägsta semesterlön per dag), tjänstepension SAF-LO och ITP 1 och avtalsförsäkringar som arbetsgivarkostnad (7410/2740, 7530/2514, 7570/2740), Fora- och Collectum-underlag som CSV, provision med garanti, tillägg och kostavdrag, lokala avvikelser som används först efter kontroll, LocalAgreementAgent och gyllene lönespecar. En lönekörning med bundet avtal stoppas tills pensions- och försäkringsreglerna är kontrollerade. Alla värden overifierade. |
| 0.74 | 2026-11-02 | Lön: skattetabellerna hämtas varje natt från Skatteverkets öppna data så snart de är publicerade (årets och från oktober nästa års), aldrig för ett redan inläst år och inte för ett år med färre tabeller än året före. Notis till alla med `payroll:settings` i varje aktivt företag med Lön när tabeller läses in och när nya eller ändrade lönevärden kommer in, med vad som ska kontrolleras. Ändrar ingen bokföring. |
| 0.75 | 2026-11-02 | Räkenskapsår och regelverk (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #29, #30): ingen minsta längd på räkenskapsår, högst 18 månader, enskild firma på kalenderår och handelsbolag med fysisk delägare likaså om inget annat intygas; K1 bara för enskild firma och ideell förening, K2 och K1 kräver att användaren intygar storleksvillkoren, befintliga val varnas men stoppas inte. Enskild firma och handelsbolag får ägarens egna kapital (2010–2019) i kontoplanen oavsett regelverk. |
| 0.76 | 2026-11-02 | Bokslut (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #25, #28): med kontantmetoden bokförs obetalda kund- och leverantörsfakturor den sista dagen i ett eget steg och återförs första dagen nästa år (BFL 5 kap. 2 §); omvärdering av valutaposter gäller också kontantmetoden och delkrediterade fakturors rest; checklistans punkter för obetalda fakturor och valuta stoppar bokslutet men kan bockas av när posten är bokförd för hand. |
| 0.77 | 2026-11-02 | Moms (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #24, #26, #27): kursen för momsens beskattningsunderlag i utländsk valuta tas på leveransdagen (annars fakturadagen), ML 8 kap. 21 §; OSS-underlaget visas också i euro, med fakturans eurobelopp eller ECB:s kurs för kvartalets sista dag (ML 22 kap. 42 §); tröskeln 99 680 kr (ML 6 kap. 62 §): under den och utan val av beskattning i köparens land får distansförsäljningen svensk moms. Nytt företagsval "Har ansökt om beskattning i köparens land (OSS)". |
| 0.78 | 2026-11-02 | Personalliggare (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #37, #38): posterna sparas till och med 31 december två år efter det kalenderår då räkenskapsåret gick ut (skatteförfarandeförordningen 9 kap. 5 § 3 st.), vid osäkerhet behålls posten; utländskt identitetsnummer med land för personal och för arbetsgivare, med i kontrollbesökets export och i hashkedjan när det finns. |
| 0.79 | 2026-11-02 | Underlag (enligt granskning 2026-11-02, juristgranskning återstår; AUD-BOK-1, issue #41): en lagrad fil raderas bara när ingen rad i någon tabell hänvisar till den (kontrolleras under lås i samma transaktion, filen tas bort först efter commit). Tidigare kunde ett raderat utkast ta med sig underlaget till en bokförd verifikation från samma mejl. När alla utkast från ett mejl raderas återgår mejlet till Att granska. |
| 0.80 | 2026-11-02 | Övertid och semesterlön (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #34, #35, #36): övertid och komptid är semesterlönegrundande enligt semesterlagen 16 b § om inte avtalet säger annat (Visita–Unionen och Handels tjänstemän: nej); godkänd frånvaro på ordinarie tid räknas som arbetad tid (ATL 7 §); utan kollektivavtal högst 4 veckors beräkningsperiod (ATL 5 §); övertid per dag där avtalet säger det; tillägg för timavlönade enligt avtal; övertid avrundas per dag eller tillfälle, aldrig på periodens summa. |
| 0.81 | 2026-11-02 | Sjuklön och karens (enligt AI-utredning 2026-11-02, juristgranskning återstår; issue #31, #32, #33): karensavdraget har tak per sjuklöneperiod och rest förs vidare inom perioden och vid återinsjuknande inom fem dagar; tio avdrag räknas som gjorda avdrag tolv månader bakåt från periodens första dag (sjuklönelagen 6–7 §§); genomsnittsveckan bygger på attesterad tid 1–3 månader före sjukperioden (prop. 2017/18:96); avtalens timvärden och karens i tid som data; avtalssjuklön dag 15–90 med tak 105/45 dagar, dagstak och direkt övergång. Avtalets sjuklönedel gäller nu också utan Planering. |
