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

## 5b. Lön (modul, under uppbyggnad)

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
- Semesterersättning när en anställning upphör (28–29 §§) hör till slutlönen.

## 5c. NONOX AI (modul)

- NONOX AI läser en leverantörsfaktura, ett kvitto eller annat underlag och
  fyller i ett förslag: leverantör, fakturauppgifter, belopp, moms och konton,
  eller en hel verifikation. Förslaget sparas och bokförs aldrig av AI:n; det
  gör en person efter kontroll, på samma sätt som när uppgifterna skrivs in för
  hand. Därför ändrar modulen ingenting i verifikationsflödet.
- Bara konton som finns aktiva i företagets kontoplan föreslås, och en
  leverantör väljs bara när den redan finns i registret.
- Underlaget skickas till den AI-leverantör företaget valt (OpenAI, Anthropic
  eller Google) med företagets egen API-nyckel, som kontrolleras hos
  leverantören och lagras krypterad. Behandlingshistoriken visar att en analys
  gjorts, med leverantör, modell och antal token, men aldrig underlagets
  innehåll ([ADR-018](../adr/ADR-018-nonox-ai.md)).

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

## 8. Öppna frågor (TODO legal-verify)

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
