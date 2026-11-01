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
- Användare loggar in med en engångskod via e-post. Behörigheten styrs av roller
  per företag ([ADR-003](../adr/ADR-003-company-roles.md)).

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

## 2. Kontoplan

- Ett nytt företag får en förifylld kontoplan med vanliga konton enligt BAS 2026,
  vald efter regelverk: K1 (enskild firma, handelsbolag) eller K2/K3
  (aktiebolag). Mallarna finns i `database/data/bas/2026/`.
- Kontonummer är fyrsiffriga (1000–9999) och unika inom företaget. Kontotyp sätts
  efter första siffran: 1 tillgång, 2 eget kapital och skulder, 3 intäkt, 4–9
  kostnad.
- Ett kontonummer ändras aldrig. Konton kan byta namn och inaktiveras, men tas
  aldrig bort.
- Varje tillagt eller ändrat konto loggas i behandlingshistoriken med värdet före
  och efter.

## 3. Räkenskapsår

- Ett räkenskapsår börjar den första och slutar den sista dagen i en månad.
- Normalt är det tolv månader. Det första räkenskapsåret får vara 6–18 månader
  (se öppen fråga nedan).
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
- **VIES:** ett kundnummer kan kontrolleras mot EU:s register VIES från
  kundformuläret. Svaret, namnet och konsultationsnumret sparas i
  `vat_number_checks` (append-only, modell och databastrigger). Var senaste
  kontrollen av numret ogiltig går en momsfri EU-faktura inte att utfärda.
- **EU-rapporter** (`/eu-rapporter`): periodisk sammanställning per månad
  eller kvartal, per köpares momsregistreringsnummer i kolumnerna varor,
  trepartshandel och tjänster (hela kronor), med fil i Skatteverkets format
  SKV574008 för Filöverföring; samt underlag för OSS-deklarationen per
  kvartal, land och momssats i kronor. Endast utfärdade fakturor räknas;
  krediteringar minskar beloppen.
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

## 5a. Leverantörsfakturor

- Leverantörer finns i ett eget register med löpnummer. Bankgiro och plusgiro
  kontrolleras mot kontrollsiffran.
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
- Betalfiler: attesterade fakturor kan betalas med en fil till banken (ISO
  20022 pain.001.001.03, [ADR-014](../adr/ADR-014-payment-files.md)). Filen
  sparas som den skapades, med kontrollsumma, och kan inte ändras. Betalningen
  bokförs först när användaren bekräftar att banken har betalat, på
  betaldagen i serie E. Betalningsuppgifterna tas från leverantörens uppgifter
  när fakturan registrerades.
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

## 5a5. Bokslut

- Under Bokföring → Bokslut görs bokslutet per räkenskapsår i fyra steg.
- **Checklista.** Systemet kontrollerar själv i bokföringen att det inte finns
  utkast i året, att alla momsperioder är bokförda, att varje bank- och
  kassakonto med saldo eller rörelse är avstämt utan skillnad den sista dagen,
  att avskrivningar och periodiseringar är bokförda till och med årets sista
  månad, att årets skatt är bokförd (inte för enskild firma, handelsbolag och
  kommanditbolag) och att årets resultat är bokat. Användaren bekräftar själv
  kassainventering, kundfordringar mot 1510, leverantörsskulder mot 2440,
  skattekontot mot 1630, löner och semesterskuld samt varulager; en punkt som
  inte gäller företaget bockas också av. Bockarna sparas i `closing_checks`
  och kan tas bort så länge året är öppet.
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
- **Resultatdisposition** bokförs i nästa år på datum för årsstämman: årets
  resultat flyttas från 2099 till 2098, med beslutad utdelning på 2898. För
  enskild firma, handelsbolag och kommanditbolag flyttas resultatet från 2019
  till 2010. Varje skatt-, resultat- och dispositionspost sparas i
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
- Raderna bildas av BAS-kontointervall (`K2Schedule`). Årets resultat i
  balansräkningen är resultaträkningens resultat oavsett om det är bokat på
  2099; ett tidigare resultat som inte disponerats visas som balanserat
  resultat. Saknas föregående år i systemet används årets ingående balanser
  som jämförelse för balansräkningen.
- Rapporten visas som sida och laddas ner som PDF. Revisionsberättelse och
  fastställelseintyg läggs till utanför systemet. Digital inlämning till
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
`docs/tillganglighet.md`.

- **Företagsbindning:** varje Livewire-sida är låst till företaget den
  visades i (LivewireCompanyGuard). Byter användaren företag i en annan flik
  svarar den gamla sidan 404.
- **Inloggning:** engångskod med tak per e-postadress (10 felaktiga koder i
  timmen). Koden fylls bara i automatiskt på en lokal adress och står inte i
  ämnesraden. Sessioner upphör efter 12 timmar, och användaren kan logga ut
  från alla andra enheter.
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
  API-nycklar, inloggningskoder, väntande inbjudningar och profilbild tas
  bort. Därefter läses alla främmande nycklar mot `users` ur databasens
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
  godkännande (inskickade tidrapporter och personalliggarens förslag). En
  tidrapport för en månad vars lönekörning redan är attesterad betalas inte
  automatiskt; den läggs in som timlön i en senare körning.
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
- Ett utlägg från Min sida läses av NONOX AI när det skickas, när modulen är
  påslagen, utan att den anställde själv behöver behörigheten att använda AI.
  Läsningen är ett förslag som lagras på utlägget tills någon med ansvar för
  lönerna godkänner eller avslår det (avsnitt 5b).
- Bara konton som finns aktiva i företagets kontoplan föreslås, och en
  leverantör väljs bara när den redan finns i registret.
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
- **Uppgifter per post:** namn, person- eller samordningsnummer (krypterat),
  arbetsgivarens namn och organisationsnummer för inhyrd personal, lokal,
  händelse (in eller ut), tid enligt enheten, tid då servern tog emot posten,
  sätt (kiosk med kod, kiosk med QR-kod, mobil, manuellt, rättelse), enhet och
  löpnummer, vem som registrerade (manuellt och rättelse) och orsak.
  Personens uppgifter kopieras till posten, så liggaren visar vad som gällde
  när den skrevs även om registret ändras.
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
  tio minuter.
- **Kod (PIN):** koden sparas aldrig, bara ett kontrollvärde (PBKDF2) som
  kiosken kan pröva mot utan nät. Fem fel låser personen på kiosken i fem
  minuter.
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
  till bokföring sparas lika länge som bokföringen.
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

- Personalliggare: exakt vilka uppgifter som ska registreras och hur länge
  liggaren ska bevaras enligt 39 kap. skatteförfarandelagen och Skatteverkets
  föreskrifter från 2027 (antaget: två år efter utgången av det kalenderår då
  liggaren fördes); branschernas avgränsning; om en manuell registrering eller
  rättelse behöver fler uppgifter; Skatteverkets XML-schema och API för
  elektronisk överföring från 1 juli 2027 (TODO SPEC).

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
- Minsta längd för ett första räkenskapsår: `AGENTS.md` anger 6 månader;
  bokföringslagen 3 kap anger högst 18.
- BAS-licensen och kontroll av mallarnas kontonummer och namn mot den officiella
  BAS 2026-kontoplanen.
- Vilka företagsformer som får använda K1, K2 och K3.
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
- Kontantmetoden: obetalda kundfakturor vid räkenskapsårets slut ska bokföras
  som fordringar, och obetalda leverantörsfakturor som skulder, vid bokslutet.
- Import av varor från länder utanför EU (ruta 50, 60–62) och fakturor i
  utländsk valuta är inte byggda ännu; vilket datum som styr växelkursen för
  varor från andra EU-länder ska bekräftas.
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
  ska kontrolleras mot EU-kommissionens databas (TEDB); reducerade satser och
  tröskeln 10 000 euro hanteras inte, och omräkningen till euro görs av
  användaren.
- VIES: om kontroll krävs före varje försäljning och hur gammal den får vara.
- Periodisk sammanställning: vilken period en försäljning hör till
  (fakturadatum används), avrundning till hela kronor och filens
  teckenkodning (ISO-8859-1).
- Inkomstdeklaration: BAS-intervallen bakom varje fältkod ska kontrolleras
  mot den officiella BAS–INK2-kopplingen (särskilt 4000–4999, koncernposter
  och bokslutsdispositioner). Identiteten skrivs som 16 följt av
  organisationsnumret. Övriga INK2S-punkter (schablonintäkt på
  periodiseringsfond, delägarrätter, handelsbolag, fastigheter m.m.) fylls i
  för hand i Skatteverkets tjänst. Fältkoderna ska kontrolleras på nytt för
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
  och 2898 ska kontrolleras mot gällande BAS. Utdelningen prövas inte mot fritt
  eget kapital eller försiktighetsregeln (ABL 17 kap). Eget kapital för
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
  efter 90 dagar är rätt. Om ett underlag som tas emot med e-post och sparas
  som `.eml` uppfyller kraven på hur räkenskapsinformation ska bevaras (BFL 7
  kap.). SFTI:s konvention för bankgiro och plusgiro i Peppol-fakturor.

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
