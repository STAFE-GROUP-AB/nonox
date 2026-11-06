# Granskningschecklista: Företagsguiden (innehåll och beslutslogik)

| | |
|---|---|
| Modul | Företagsguiden: de offentliga sidorna `/foretagsguiden`, Min startplan, Mina datum (datumlogiken), Momsbufferten ("Pengar som inte är dina"), tips i appen. Granskas för att innehållet och beslutslogiken stämmer mot svenska regler. |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `docs/adr/ADR-051-foretagsguiden.md`, `docs/foretagsguiden/startplan.json` (51 steg), `docs/foretagsguiden/sv/` (72 artiklar i sex avsnitt), `docs/foretagsguiden/datum/REGLER.md`, `docs/foretagsguiden/SKRIVREGLER.md`, `app/Support/Guide/` (Guide, StartPlanStep, StepGroups, GuideValidator), `app/Livewire/Guide/` (StartPlan, MyDates, SkatteverketEvents), `app/Domain/Deadlines/` (providers för moms, arbetsgivardeklaration, preliminärskatt, årsbokslut, periodisk sammanställning, Workdays), `app/Domain/Guide/MoneyNotYours.php`, `app/Domain/Guide/MoneyNotYoursFigures.php` |
| Manualavsnitt | `docs/manual/sv/kom-igang/foretagsguiden.md`, `docs/manual/sv/kom-igang/mina-datum.md`, `docs/manual/sv/kom-igang/oversikten.md`, `docs/manual/sv/installningar/foretagets-uppgifter.md` |

## Förutsättningar

- **Beta:** Företagsguiden är märkt Beta tills en människa har granskat den (`mansklig_granskning` är tom i alla artiklar). Den här granskningen är den granskningen. Godkännandet nedan gäller hela Företagsguiden: startplanen, datumreglerna, Momsbufferten och de 72 artiklarna, men konsulten får begränsa godkännandet till delar (se "Godkänd med villkor").
- **Allt är på svenska** och alla adresser är svenska (`/foretagsguiden/starta/f-skatt`).
- **Testbolag** (påhittade): 
  - T1: aktiebolag, kalenderår, momsregistrerat, kvartalsmoms, F-skatt, ej arbetsgivare, omsättning under 40 milj. kr.
  - T2: enskild firma, bransch Kafé, momsregistrerad, årsmoms, F-skatt, arbetsgivare.
  - T3: handelsbolag, bransch Frisör och skönhet.
  - T4: aktiebolag med brutet räkenskapsår 2026-05-01 till 2027-04-30.
  - T5: ideell förening, kalenderår.
- **Datum som används:** helgdagar och veckodagar för 2027 och början av 2028 är kontrollerade mot Lag (1989:253) om allmänna helgdagar. Dagens datum ska vara efter 2026-11-06.
- **Hur man läser en datumrad:** `lördag` o.d. anger varför ett datum flyttas. Datum som lagen låter flytta (Lag 1930:173 2 §) flyttas till nästa vardag; midsommarafton, julafton och nyårsafton räknas som lediga.
- **Reglerna är fastställda av innehållssidan i `docs/foretagsguiden/datum/REGLER.md`.** Konsulten kontrollerar de mot lagtexten, inte mot NONOX egna uppgifter.
- **Menyer:** "Företagsguiden" längst ner i menyn; **Min startplan** (`/foretagsguiden/min-startplan`), **Mina datum** (`/foretagsguiden/mina-datum`).

---

## Del A. Beta och källor

### GUI-01 Beta-märkning och synlighet

- Förutsättningar: utloggad webbläsare samt inloggad användare i T1.
- Steg i appen:
  1. Öppna `/foretagsguiden` utloggad. Öppna en artikel, till exempel `/foretagsguiden/starta/f-skatt`.
  2. Logga in. Öppna **Företagsguiden** längst ner i menyn och **Min startplan**.
  3. Som ägare: **Företagets uppgifter**, avmarkera **Visa Företagsguiden** och **Spara**. Öppna `/foretagsguiden/min-startplan`.
- Förväntat resultat:
  - Varje offentlig sida, startplanen och Mina datum visar texten: "Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult."
  - Varje artikel visar "Senast kontrollerad" med datum (2026-11-04 eller 2026-11-05) och, i förekommande fall, rutan **Det här kontrollerar vi just nu** med de öppna frågorna.
  - Steg 3: Min startplan, tipsen och Mina datum försvinner ur appen för hela företaget; guiden på webbplatsen finns kvar.
  - Efter att konsulten godkänt ska Beta-texten tas bort per godkänd del; det görs i en separat ändring, inte i den här granskningen.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-02 Källor och kontrolldatum: stickprov mot primärkälla

- Förutsättningar: inga.
- Steg i appen: öppna minst tio artiklar, minst en per avsnitt (Starta företaget, Moms och skatt, Ansvar och regler, Viktiga datum, Branscher, Att växa). Följ varje länk under **Källor** och jämför de uppgifter som finns i artikeln (belopp, procent, paragrafer, datum) med källan.
- Förväntat resultat:
  - Varje artikel har minst en källa från listan över tillåtna myndigheter: skatteverket.se, bolagsverket.se, verksamt.se, riksdagen.se, bfn.se, forsakringskassan.se, arbetsmiljoverket.se, av.se, imy.se, konsumentverket.se, livsmedelsverket.se, kronofogden.se, tillvaxtverket.se, scb.se, regeringen.se, lagrummet.se, riksbank.se, tullverket.se, polisen.se, naturvardsverket.se, boverket.se, elsakerhetsverket.se, msb.se, fi.se, europa.eu, eur-lex.europa.eu, lansstyrelsen.se, prv.se, folkhalsomyndigheten.se och domstol.se. Inga andra källor (bloggar, konkurrenter) förekommer. Alla länkar är https.
  - Texten återger källan korrekt; där den inte gör det, för in avvikelsen. Konsulten antecknar vilka artiklar som är kontrollerade: ______
  - Alla "legal_verify"-frågor (56 st, se tabellen sist) är synliga för läsaren i rutan **Det här kontrollerar vi just nu** och de meningar de rör säger åt läsaren att kontrollera.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del B. Startplanens beslutslogik

Startplanen (`startplan.json`) har 51 steg. Ett steg visas för ett företag om (1) steget gäller bolagsformen eller saknar bolagsform, (2) steget gäller branschen eller saknar bransch (ett branschsteg visas inte för ett företag utan vald bransch), och (3) villkoret är uppfyllt (`vat_registered`: momsregistrerat; `employer_registered`: arbetsgivarregistrerat). På den offentliga sidan väljs bolagsform och bransch, men villkor ignoreras (alla villkorssteg visas, med etikett).

### GUI-03 Offentlig väljare: antal steg per bolagsform

- Förutsättningar: utloggad. Ingen bransch vald.
- Steg i appen: öppna `/foretagsguiden?bolagsform=AB`, sedan `EF`, `HB`, `KB`, `EK_FORENING`, `IDEELL_FORENING` och `STIFTELSE`. Räkna stegen i tidslinjen.
- Förväntat resultat (ingen bransch, alla villkorssteg medräknade):

| Bolagsform | Antal steg |
|---|---|
| Aktiebolag (AB) | 25 |
| Enskild firma (EF) | 21 |
| Handelsbolag (HB) | 23 |
| Kommanditbolag (KB) | 23 |
| Ekonomisk förening | 23 |
| Ideell förening | 19 |
| Stiftelse | 19 |

  - Skillnaderna kommer från de åtta stegen som är riktade till vissa bolagsformer: "Registrera företaget hos Bolagsverket" (AB, HB, KB, ekonomisk förening), "Öppna bolagets konto och sätt in aktiekapitalet" (bara AB), "Anmäl verklig huvudman" (AB, HB, KB, ekonomisk förening), "Lägg upp bolagets aktiebok" (AB), "Fundera på att skydda företagsnamnet" (EF), "Öppna ett konto för företaget" (EF, HB, KB, ekonomisk förening), "Skriv in årsstämma och årsredovisning i kalendern" (AB, ekonomisk förening) och "Skriv ett avtal mellan ägarna" (AB, HB, KB).
  - Villkorssteg har en etikett, till exempel "Om du är momsregistrerad".
  - Konsulten bedömer om bolagsformsfiltret är rätt (se GUI-06).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-04 Offentlig väljare: bolagsform och bransch

- Förutsättningar: utloggad.
- Steg i appen: öppna `/foretagsguiden?bolagsform=AB&bransch=restaurant`, `?bolagsform=EF&bransch=cafe`, `?bolagsform=AB&bransch=consultant`, `?bolagsform=HB&bransch=hair_beauty` och `?bolagsform=AB&bransch=other`.
- Förväntat resultat:
  - AB Restaurang: 34 steg. EF Kafé: 30. AB Konsult: 28. HB Frisör och skönhet: 30. AB Annan bransch: 26.
  - Matris (antal steg, villkorssteg medräknade):

| | Ingen | Kafé | Restaurang | Hantverk och bygg | Konsult | Butik och e-handel | Frisör och skönhet | Redovisningsbyrå | Ekonomikonsult | Annan |
|---|---|---|---|---|---|---|---|---|---|---|
| AB | 25 | 34 | 34 | 31 | 28 | 31 | 32 | 31 | 32 | 26 |
| EF | 21 | 30 | 30 | 27 | 24 | 27 | 28 | 27 | 28 | 22 |
| HB, KB, Ekonomisk förening | 23 | 32 | 32 | 29 | 26 | 29 | 30 | 29 | 30 | 24 |
| Ideell förening, Stiftelse | 19 | 28 | 28 | 25 | 22 | 25 | 26 | 25 | 26 | 20 |

  - Branschsteg tillkommer för vald bransch. Till exempel får ett kafé: registrera livsmedelsanläggningen, ansök om serveringstillstånd, skaffa och anmäl kassaregister, starta personalliggaren, sätt rätt matmoms i kassan, bokför dagsrapporten från kassan, bestäm hur dricks fördelas, presentkort och inventera lagret (nio steg, varav tre villkorade).
  - Adressen är en vanlig GET-länk som går att dela; sidan fungerar utan skript.
  - Konsulten bedömer om varje branschs steg är rimliga och fullständiga för branschen (särskilt personalliggare, kassaregister och serveringstillstånd).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-05 Min startplan: villkor (moms, arbetsgivare) och grupper

- Förutsättningar: T1 (aktiebolag, momsregistrerat, ingen arbetsgivare, ingen bransch), sedan T1 med **Momsregistrerat** avmarkerat, sedan T2 (enskild firma, Kafé, momsregistrerad och arbetsgivare).
- Steg i appen: **Företagsguiden → Min startplan** (`/foretagsguiden/min-startplan`) för varje företag. Läs "X av Y klara", grupperna och antalet steg per grupp.
- Förväntat resultat:

| Företag | Antal steg | Registrering | Pengar och skatt | Bokföring i NONOX | Avtal och skydd | Din bransch | Att växa |
|---|---|---|---|---|---|---|---|
| T1 (AB, momsreg.) | 25 | 8 | 4 | 5 | 3 | - | 5 |
| T1 utan momsregistrering | 23 | 8 | 3 | 4 | 3 | - | 5 |
| T2 (EF, Kafé, moms, arbetsgivare) | 30 | 6 | 4 | 4 | 2 | 9 | 5 |
| T2 utan moms och arbetsgivare | 25 | 6 | 3 | 3 | 2 | 6 | 5 |

  - Utan momsregistrering försvinner "Sätt rätt momssats på det du säljer" och "Lämna din första momsdeklaration" (och för ett kafé matmomsen, presentkorten); utan arbetsgivarregistrering försvinner "Bestäm hur dricks fördelas".
  - Överst: "0 av 25 klara" (T1) och **Nästa steg** (första steget, "Välj bolagsform"). Stegen är numrerade i den ordning grupperna visar.
  - **Din bransch** syns bara när en bransch är vald; utan bransch visas inga branschsteg.
  - Konsulten bedömer om gruppindelningen (Registrering, Pengar och skatt, Bokföring i NONOX, Avtal och skydd, Din bransch, Att växa) och ordningen är rimlig för en nystartad verksamhet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-06 Bolagsformer som saknar steg: innehållsfrågor

- Förutsättningar: GUI-03.
- Steg i appen: öppna `?bolagsform=IDEELL_FORENING` och `?bolagsform=STIFTELSE`, och läs vilka steg som saknas jämfört med AB.
- Förväntat resultat och frågor till konsulten:
  - Ideell förening och stiftelse får inte "Registrera företaget hos Bolagsverket", "Anmäl verklig huvudman" eller "Öppna ett konto för företaget". 
  - Konsulten bedömer: (a) om en ideell förening eller stiftelse också ska anmäla verklig huvudman (lagen (2017:631) om registrering av verkliga huvudmän) och därför ska få steget; (b) om en ideell förening som driver näring ska registreras och få F-skatt; (c) att stegen "Öppna ett konto" och "Bestäm hur företagets pengar hålls isär" bör gälla alla bolagsformer. Jag kan inte avgöra juridiken här; svaret förs in under Kommentar.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-07 Kryssa i ett steg: behörighet och bekräftelse

- Förutsättningar: T1 med fyra användare: ägare, administratör, bokförare, revisor.
- Steg i appen:
  1. Som ägare: på Min startplan, kryssa i första steget.
  2. Kryssa i rutan igen.
  3. Som bokförare och som revisor: öppna Min startplan.
  4. Öppna behandlingshistoriken.
- Förväntat resultat:
  - Steg 1: bekräftelsen "Steget är markerat som klart." visas, med vem som gjorde det och när. Räknaren går från "0 av 25" till "1 av 25".
  - Steg 2: "Steget är öppnat igen."
  - Steg 3: bokföraren och revisorn ser planen men kan inte kryssa (bara de med behörigheten `company:update`, alltså ägare och administratör).
  - Steg 4: posterna `guide.step_completed` och `guide.step_reopened` med stegets nyckel och användare, utan fritext.
  - Efter att alla steg är klara försvinner "Fortsätt din startplan (X av Y klara)" under **Att göra** på översikten.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-08 Tips i appen och att dölja dem

- Förutsättningar: T1, inloggad som ägare.
- Steg i appen: öppna momssidan (**Bokföring → Moms**) och leta efter **Tips från Företagsguiden** längst ner. Klicka på **Dölj tips**. Öppna Min startplan och klicka på **Visa tipsen igen**.
- Förväntat resultat:
  - Upp till tre artiklar som hör till sidan och till företagets bolagsform och bransch. Momssidan ger artiklar om momsdeklarationen.
  - **Dölj tips** döljer tipsen bara för dig och bara i det här företaget; andra användare ser dem. **Visa tipsen igen** återställer.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del C. Mina datum: datumlogik (kontrolleras mot lagtext)

Alla datum nedan följer `docs/foretagsguiden/datum/REGLER.md`, som konsulten kontrollerar mot Skatteförfarandelagen (2011:1244) 26, 30, 32, 33, 35, 62 och 65 kap., Lag (1930:173) om beräkning av lagstadgad tid, ÅRL, ABL och BFL. Vardagsflytt: fredag/måndag anges där det spelar roll. Flytt sker bara om dagen infaller på lördag, söndag, helgdag eller midsommar-, jul- eller nyårsafton.

### GUI-09 Moms per kvartal (T1, aktiebolag, kalenderår, under 40 milj. kr)

- Förutsättningar: T1. Öppna `/foretagsguiden/mina-datum`.
- Steg i appen: läs raderna för moms och klicka på **Visa alla datum (15 månader)** om de ligger utanför de närmaste sex månaderna.
- Förväntat resultat (andra månaden efter kvartalet, den 12:e; den 17:e i augusti):

| Period | Sista dag | Veckodag |
|---|---|---|
| Kvartal 4 2026 | 2027-02-12 | fredag |
| Kvartal 1 2027 | 2027-05-12 | onsdag |
| Kvartal 2 2027 | 2027-08-17 | tisdag |
| Kvartal 3 2027 | 2027-11-12 | fredag |

  - Raden lyder "Deklarera och betala moms för kvartal N ÅÅÅÅ". Moms deklareras och betalas samma dag (SFL 62 kap. 3 §), märket är **Deklaration och betalning**.
  - Källa och märke "Datum i lagen" finns. Texten "Kontrollera datumet hos Skatteverket" visas inte (lagen avgör).
  - Konsulten verifierar SFL 26 kap. 26-27 §§.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-10 Moms per månad: helg- och 17:e-regler

- Förutsättningar: ett bolag med momsperiod månad och beskattningsunderlag under 40 milj. kr. Perioder i 2027.
- Steg i appen: läs datumen på Mina datum (visa alla 15 månader).
- Förväntat resultat (andra månaden efter perioden; den 12:e, den 17:e i januari och augusti):

| Period | Sista dag |
|---|---|
| November 2026 | 2027-01-18 (17 jan är söndag) |
| December 2026 | 2027-02-12 (fredag) |
| Januari 2027 | 2027-03-12 (fredag) |
| Februari 2027 | 2027-04-12 (måndag) |
| Mars 2027 | 2027-05-12 (onsdag) |
| April 2027 | 2027-06-14 (12 juni är lördag) |
| Maj 2027 | 2027-07-12 (måndag) |
| Juni 2027 | 2027-08-17 (tisdag) |
| Juli 2027 | 2027-09-13 (12 sep är söndag) |
| Augusti 2027 | 2027-10-12 (tisdag) |
| September 2027 | 2027-11-12 (fredag) |
| Oktober 2027 | 2027-12-13 (12 dec är söndag) |
| November 2027 | 2028-01-17 (måndag) |
| December 2027 | 2028-02-14 (12 feb är lördag) |

  - Över 40 milj. kr: den 26:e i månaden efter perioden, 27 december för perioden november. Exempel: november 2027 → 2027-12-27 (26 dec är annandag jul, söndag); oktober 2027 → 2027-11-26 (fredag).
  - Artikeln `/foretagsguiden/viktiga-datum/momsdeklarationens-datum` säger "momsen för november ... 17 januari" utan årtal. För 2027 är det 18 januari. Konsulten bedömer om texten är vilseledande.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-11 Helårsmoms per bolagsform

- Förutsättningar: bolag med momsperiod år, kalenderår 2026 om inget annat anges. Slå på "EU-handel" i företagets uppgifter där det anges.
- Förväntat resultat:

| Bolag | Sista dag | Förklaring |
|---|---|---|
| Enskild firma, ingen EU-handel | 2027-05-12 (onsdag) | SFL 26 kap. 33 a § |
| Enskild firma med EU-handel | 2027-02-26 (fredag) | 33 a § andra stycket, 33 §: den 26:e i andra månaden efter året |
| Aktiebolag, ingen EU-handel, elektroniskt | 2027-08-17 (tisdag) | 33 b §: för räkenskapsår som slutar september-december gäller augusti året efter (den 17:e elektroniskt); papper 12 juli |
| Handelsbolag (alla) | 2027-02-26 (fredag) | 33 §; 33 b § omfattar inte handelsbolag |
| Aktiebolag med brutet år 2026-05-01 till 2027-04-30, elektroniskt | 2027-12-13 (12 dec söndag) | 33 b § (året slutar april: 30 april, då 12 dec) |
| Samma, på papper | 2027-11-12 (fredag) | |
| Samma, med EU-handel | 2027-06-28 (26 juni lördag) | 26:e i andra månaden efter räkenskapsårets slut |
| Ideell förening, kalenderår, ingen inkomstdeklaration | 2027-02-26, märkt **Kontrollera** | 33 §; om föreningen deklarerar gäller 33 b §-datumet (artikeln nämner det) |

  - Konsulten verifierar månadsintervallen i SFL 3 kap. 5 §: ett räkenskapsår som slutar januari-april räknas som 30 april, maj-juni som 30 juni, juli-augusti som 31 augusti och september-december som 31 december.
  - Konsulten verifierar öppen fråga: om köp av tjänster från land utanför EU (ruta 22) ger det tidigare datumet (`legal_verify` i momsdeklarationens-datum).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-12 Arbetsgivardeklaration: liten och stor arbetsgivare

- Förutsättningar: bolag registrerat som arbetsgivare. Fall 1: ej momsregistrerat eller momsunderlag under 40 milj. kr. Fall 2: momsregistrerat med omsättning över 40 milj. kr (den inställning som styr "stor arbetsgivare", SFL 26 kap. 32 §).
- Förväntat resultat:
  - Fall 1 (deklarera och betala samma dag; den 12:e, den 17:e i januari och augusti): utbetalningsmånad december 2026 → 2027-01-18 (17 jan söndag); juli 2027 → 2027-08-17 (tisdag); januari 2027 → 2027-02-12 (fredag).
  - Fall 2 (betalning den 12:e, deklaration den 26:e): utbetalningsmånad december 2026: betala 2027-01-18, deklarera 2027-01-26 (tisdag). Två datum visas för samma månad. November 2027: betala 2027-12-13 (12 dec söndag), deklarera 2027-12-27 (26 dec annandag jul).
  - Hänvisning: SFL 26 kap. 26, 30, 32 §§ och 62 kap. 3 §. 
  - Konsulten verifierar att betalningen för stor arbetsgivare förfaller två veckor före deklarationen (62 kap. 3 § andra stycket), eftersom det är datumet som kostar ränta.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-13 Preliminärskatt (F-skatt): serie och flytt

- Förutsättningar: T1 med F-skatt (aktiebolag), kalenderår. T4 (brutet år). Ett enskilt företag (EF).
- Förväntat resultat (märkt **Kontrollera**; belopp och första månad står i Skatteverkets beslut):
  - T1, beskattningsår 2027: tolv betalningar, från andra månaden: 2027-02-12, 2027-03-12, 2027-04-12, 2027-05-12, 2027-06-14 (12 juni lördag), 2027-07-12, 2027-08-17, 2027-09-13 (12 sep söndag), 2027-10-12, 2027-11-12, 2027-12-13 (12 dec söndag), 2028-01-17.
  - T4 (räkenskapsår 2026-05-01 till 2027-04-30): serien 2026-06-12 till 2027-05-12, tolv betalningar (17 aug 2026 och 17 jan 2027).
  - Aktiebolag med räkenskapsår 2025-10-01 till 2026-09-30: räknas som kalenderår (SFL 3 kap. 5 §): serien februari 2026 till januari 2027; rekommendation "Fyllnadsinbetalning?" 2027-02-12.
  - Enskild firma: kalenderår, februari-januari.
  - Handelsbolag: inga betalningar (delägarna betalar själva), bara en anmärkning.
  - Radens text: "Om ni ska betala, hur mycket och från vilken månad står i Skatteverkets beslut om debiterad preliminärskatt. Står det 0 kronor finns inget att betala."
  - Konsulten verifierar SFL 62 kap. 4 § och att allt F-skatt-datum inte är moms (sidans förklaring).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-14 Inkomstdeklaration

- Förutsättningar: T1 (kalenderår), T4 (brutet år), EF, HB.
- Förväntat resultat:

| Bolag och räkenskapsår | Elektroniskt | Papper |
|---|---|---|
| AB, kalenderår 2026 | 2027-08-02 (1 aug söndag) | 2027-07-01 |
| AB, räkenskapsår 2026-05-01 till 2027-04-30 | 2027-12-01 | 2027-11-01 |
| AB, räkenskapsår slutar 2027-06-30 | 2028-01-17 (15 jan lördag) | 2027-12-15 |
| Enskild firma, kalenderår 2026 | 2027-05-03 (2 maj söndag) | |
| Handelsbolag, kalenderår 2026 (särskilda uppgifter) | 2027-08-02 | samma dag |

  - Konsulten verifierar SFL 32 kap. 1-2 §§ (2 maj för EF; legal person: sista dagen i månad enligt 3 kap. 5 §, elektroniskt en månad senare), och 33 kap. 6 och 10 §§ för handelsbolag.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-15 Årsstämma och årsredovisning (inklusive skottår)

- Förutsättningar: aktiebolag med olika räkenskapsår.
- Förväntat resultat (sex månader för stämman, sju månader för förseningsavgift, månader räknas enligt Lag 1930:173 1 §):

| Räkenskapsår slutar | Årsstämma senast | Årsredovisning (förseningsavgift efter) |
|---|---|---|
| 2026-12-31 | 2027-06-30 (onsdag) | 2027-07-31 (lördag; visas oflyttad, märkt **Kontrollera**) |
| 2027-04-30 | 2027-10-30 (lördag; flyttas inte) | 2027-11-30 |
| 2027-06-30 | 2027-12-30 | 2028-01-30 (söndag; oflyttad) |
| 2027-08-31 | 2028-02-29 (skottår) | 2028-03-31 |

  - Konsulten verifierar ABL 7 kap. 10 § och ÅRL 8 kap. 3 och 6 §§, och frågan i artikeln `arsstamma-och-arsredovisning`: om Bolagsverket flyttar sjumånadersgränsen när sista dagen är helg, och hur den räknas för ett räkenskapsår som slutar 30 juni (30 eller 31 januari). 
  - Rekommendationen "Gör bokslutet" är märkt **Rekommendation**, tre månader efter räkenskapsårets slut för aktiebolag.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-16 Periodisk sammanställning (EU-försäljning)

- Förutsättningar: momsregistrerat bolag med fakturerad EU-försäljning av varor i januari 2027 (kundmarkering EU).
- Förväntat resultat:
  - Elektroniskt: den 25:e i månaden efter perioden: 2027-02-25 (torsdag). Papper: den 20:e: 2027-02-20 är lördag, alltså 2027-02-22 (måndag).
  - Datumet visas bara för perioder som har EU-försäljning. En period utan sådan försäljning ger ingen rad.
  - Konsulten verifierar SFL 35 kap. 3, 4 och 9 §§.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-17 Redovisad, Passerat och påminnelser

- Förutsättningar: T1 med en momsrapport bokförd i NONOX för kvartal 4 2026. Ett inkomstdeklarationsdatum 2027-08-02.
- Förväntat resultat:
  - Kvartal 4 2026 visas som **Redovisad**; ingen påminnelse skickas för den. Ett datum som har passerat visas som "Passerat för N dagar sedan" med länk till `/foretagsguiden/viktiga-datum/om-du-missar-ett-datum`.
  - För inkomstdeklarationen 2027-08-02 skickas en notis (klockan) till ägaren och de som har rätt att ändra företaget eller sköta bokslutet: 30 dagar före (2027-07-03), 7 dagar före (2027-07-26) och sista vardagen före (2027-07-30, fredag). Rekommendationer får ingen notis. Samma notis skickas inte två gånger.
  - Datum inom två veckor visas under **Att göra** på översikten.
  - Konsulten bedömer påminnelsernas tidpunkter (SFL 62 kap. 2 § räknar en betalning när den är bokförd på skattekontot; en banköverföring kan ta tre bankdagar).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del D. Momsbufferten ("Pengar som inte är dina")

### GUI-18 Beräkning av Momsbufferten

- Förutsättningar: T1, momsregistrerat, arbetsgivare, F-skatt. Mina datum visas. Antalet preliminärskattebetalningar som räknas beror på dagens datum: det är F-skattedagarna efter i dag fram till och med nästa momsdag. Räkneexemplet gäller dagens datum 2027-01-05 (nästa momsdag 2027-02-12; F-skattedagarna efter i dag är 2027-01-18 och 2027-02-12, alltså två). Kör du fallet ett annat datum, räkna antalet själv och byt 2 mot ditt antal i förväntade resultat. Bokför följande saldon (lägg dem som ingående balanser plus verifikationer; belopp i kronor, debet positivt):
  - 1930 Företagskonto +250 000,00
  - 2611 Utgående moms 25 % -60 000,00
  - 2641 Debiterad ingående moms +22 000,00
  - 2650 Redovisningskonto för moms -10 000,00
  - 2710 Personalskatt -18 000,00
  - 2731 Avräkning lagstadgade sociala avgifter -12 000,00
  - 1630 Skattekonto +5 000,00
  - 2619 OSS-moms -4 000,00
  - **Debiterad preliminärskatt per månad** (under Företagets uppgifter): 8 000,00.
- Steg i appen: öppna `/foretagsguiden/mina-datum`, avsnittet **Pengar som inte är dina**, och klicka på **Så räknar vi**.
- Förväntat resultat:
  - **På banken och i kassan:** 250 000,00 kr.
  - **Moms att betala:** (60 000,00 - 22 000,00) + 10 000,00 = 38 000,00 + 10 000,00 = 48 000,00 kr.
  - **Moms i OSS-deklarationen:** 4 000,00 kr.
  - **Personalskatt och arbetsgivaravgifter:** 18 000,00 + 12 000,00 = 30 000,00 kr.
  - **Redan på skattekontot:** 5 000,00 kr.
  - **Preliminärskatt:** 2 betalningar × 8 000,00 = 16 000,00 kr.
  - **Kvar efter moms och skatter:** 250 000,00 + 5 000,00 - 48 000,00 - 4 000,00 - 30 000,00 - 16 000,00 = **157 000,00 kr**. Det som inte är företagets: 48 000,00 + 4 000,00 + 30 000,00 + 16 000,00 = 98 000,00 kr (250 000,00 + 5 000,00 - 157 000,00 = 98 000,00).
  - Kontona med deras saldon visas under **Så räknar vi**; datumet siffrorna gäller och den senast bokförda verifikationen står under siffrorna.
  - Konsulten verifierar att personalskatt (2710) och sociala avgifter (2730/2731) hör till pengar som inte är företagets, och frågan om vilken dag Skatteverket debiterar preliminärskatten på skattekontot (`TODO(legal-verify)` i `MoneyNotYours.php:291`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-19 Momsbufferten: gränsfall och avrundning

- Förutsättningar: T1 (och ett bolag utan momsregistrering).
- Förväntat resultat:
  - **Moms att få tillbaka:** 2641 +7 000,00 och inget på 2611, bank 100 000,00: texten **Moms att få tillbaka** (7 000,00) visas men räknas inte in. Kvar = 100 000,00; det som inte är företagets = 0,00.
  - **Momsen att betala är mer än pengarna på banken:** bank 30 000,00, 2611 -50 000,00: kvar = 30 000,00 - 50 000,00 = -20 000,00; varningen **Momsen att betala är mer än pengarna på banken** med länk till `/foretagsguiden/moms-och-skatt/satt-undan-pengar`.
  - **Konton som inte är företagets pengar:** 1970 +20 000,00 och 1990 +40 000,00 räknas inte med; de visas under **Räknas inte som företagets pengar**. Bank (1910-1969 och 1980-1989, till exempel 1940 och 1950) räknas.
  - **Inte momsregistrerat** och inga saldon på momskonton: **Företaget är inte momsregistrerat.** och ingen momsrad.
  - **Skuld på skattekonto:** 1630 -3 000,00 visas som **Skuld på skattekonto** och läggs till de skatter som ska betalas (kvar minskar med 3 000,00).
  - **Månad redan debiterad:** en kredit på 1630 på exakt 8 000,00 inom månaden ger att den månadens preliminärskatt inte räknas en gång till.
  - **Ungefärligt belopp, "Momsen per deklaration":** 10 934,00 kr visas som "ca 10 900 kr"; 10 950,00 som "ca 11 000 kr"; 10 949,99 som "ca 10 900 kr"; 0 som "ca 0 kr". Avrundning till närmaste hundratal, halv upp.
  - **Kontantmetoden:** obetalda fakturor har ingen moms i bokföringen och räknas inte med; sidan säger det.
  - Obokförda fakturor och utkast syns inte; sidan säger det.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del E. Artiklarnas innehåll: sakuppgifter och räkneexempel

För varje fall: öppna artikeln, jämför varje uppgift med den uppgivna källan (och med lagtext/myndighetssida) och kontrollera räkneexemplet. Där konsulten har en annan uppfattning än artikeln förs det in som avvikelse.

### GUI-20 Så fungerar moms: satser, omräkning och räkneexempel

- Förutsättningar: artikeln `/foretagsguiden/moms-och-skatt/sa-fungerar-moms`, `/foretagsguiden/starta/momsregistrering` och `/foretagsguiden/moms-och-skatt/satt-undan-pengar`.
- Förväntat resultat (räkna själv och jämför):
  - Moms ur pris inklusive moms: 25 %: 25/125 = 20 % av priset; 12 %: 12/112; 6 %: 6/106. Exempel: 12 500,00 kr inklusive 25 % → moms 12 500,00 × 25 / 125 = 2 500,00, netto 10 000,00. 11 200,00 inklusive 12 % → 11 200,00 × 12 / 112 = 1 200,00. 5 300,00 inklusive 6 % → 5 300,00 × 6 / 106 = 300,00. 1 249,00 inklusive 25 % → 249,80.
  - Artikelns exempel: utgående moms 1 200 kr (catering 10 000 kr × 12 %), ingående moms 1 000 kr (kaffekvarn 4 000 kr × 25 %), att betala 1 200 - 1 000 = 200 kr.
  - "Sätt undan pengar": kund betalar 12 500 kr (10 000 kr + 2 500 kr moms), skatteandel 15 % (60 000 / 400 000 = 15 %) → moms 2 500 kr och skatt 1 500 kr (15 % av 10 000 kr); totalt undan 4 000 kr av 12 500 kr.
  - Momssatser 2026 enligt artikeln: 25 % för det mesta, 12 % för restaurang- och cateringtjänster och hotellrum, 6 % för böcker, tidningar, persontransporter, inträde, och livsmedel från och med 1 april 2026 till och med 31 december 2027; från 1 januari 2028 åter 12 % på livsmedel (ML 9 kap. 3 § enligt SFS 2026:119).
  - Undantaget för små företag: 120 000 kr per kalenderår (beskattningsunderlag utan moms), även under de två föregående åren (höjd 1 januari 2025).
  - Redovisningsperiod: underlag högst 1 milj. kr: år, kvartal eller månad; högst 40 milj. kr: kvartal eller månad; över 40 milj. kr: månad; byte till glesare period först efter 24 månader.
  - Konsulten verifierar varje siffra och paragraf mot Skatteverket och ML 2023:200, särskilt den tillfälligt sänkta livsmedelsmomsen (`legal_verify` i `sa-fungerar-moms`: inget beslut om andra satser för 2027 hittades 2026-11-04).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-21 Moms på mat och servering; ROT och RUT

- Förutsättningar: artiklarna `/foretagsguiden/branscher/moms-pa-mat-och-servering` och `/foretagsguiden/branscher/rot-och-rut`.
- Förväntat resultat:
  - Kafé Lindens dag: servering vid bord 11 200 kr inklusive 12 % → moms 1 200 kr, netto 10 000 kr; avhämtning 5 300 kr inklusive 6 % → moms 300 kr, netto 5 000 kr. Total moms 1 500 kr på 16 500 kr inklusive moms.
  - Egen kontroll (inte i artikeln): en nota med mat 1 000,00 kr inklusive 12 % och vin 500,00 kr inklusive 25 % → momsen på maten 1 000,00 × 12 / 112 = 107,14 kr; på vinet 500,00 × 25 / 125 = 100,00 kr; totalt 207,14 kr. Maten och alkoholen ska delas upp (ML 9 kap. 5 §).
  - Skatteverkets exempel: kaffe och bulle vid bordet 12 %, limpa som tas med hem 6 %. Alkoholfria drycker 12 % som del av servering, 6 % vid avhämtning. Kranvatten 25 %.
  - ROT: arbete 40 000 kr och material 10 000 kr inklusive moms. ROT-avdraget är 30 % av arbetskostnaden = 40 000 × 0,30 = 12 000 kr. Kunden betalar 50 000 - 12 000 = 38 000 kr; företaget begär 12 000 kr från Skatteverket. Tak 75 000 kr per person och år för ROT och RUT tillsammans, varav högst 50 000 kr ROT; RUT 50 %: arbete 8 000 kr → avdrag 4 000 kr. Begäran senast 31 januari året efter betalningsåret.
  - Konsulten verifierar procentsatserna för 2026 (ROT 30 %, RUT 50 %) och taket; artikeln hänvisar till att satserna för 2025 var tillfälligt annorlunda (ROT 50 % under en period).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-22 Bokföringsfrister och arkivering

- Förutsättningar: artiklarna `/foretagsguiden/ansvar-och-regler/bokforingsskyldighet` och `/foretagsguiden/ansvar-och-regler/arkivering`.
- Förväntat resultat (BFNAR 2013:2 punkterna 3.6-3.9; BFL 7 kap. 2 §):
  - Alla företag: bokföring senast 50 dagar efter utgången av den månad händelsen inträffade. Artikelns exempel: en faktura från mars ska vara bokförd senast 20 maj (31 mars + 50 dagar: 30 dagar april + 20 dagar maj). Egen kontroll: en händelse i januari 2027 → 31 januari + 50 dagar = 22 mars 2027 (28 dagar i februari).
  - Nettoomsättning normalt högst 3 milj. kr: 50 dagar efter kvartalets utgång. En händelse i februari 2027 (kvartal 1 slutar 31 mars) → 20 maj 2027.
  - Högst 50 verifikationer, högst 250 affärshändelser per år, nettoomsättning normalt högst 1 milj. kr: 60 dagar efter räkenskapsårets slut. Räkenskapsår slutar 2026-12-31 → 2027-03-01 (31 + 28 dagar + 1).
  - Kontanta in- och utbetalningar ska föras in senast nästa arbetsdag.
  - Arkivering: räkenskapsinformation sparas till och med det sjunde året efter utgången av det kalenderår då räkenskapsåret avslutades. Räkenskapsår kalenderåret 2026 → till och med 2033-12-31. Brutet räkenskapsår 2026-07-01 till 2027-06-30 avslutas 2027 → till och med 2034-12-31.
  - Pappersunderlag får förstöras efter överföring till annan handling bara om överföringen inte riskerar att något ändras eller försvinner (BFL 7 kap. 6 §).
  - Konsulten verifierar de tre tidsgränserna i BFNAR 2013:2 och frågan i artikeln om hur "normalt högst tre miljoner" bedöms det första räkenskapsåret (`legal_verify`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-23 Välj bolagsform, registrering och årsredovisning

- Förutsättningar: artiklarna `/foretagsguiden/starta/valj-bolagsform`, `/foretagsguiden/starta/registrera-foretaget`, `/foretagsguiden/ansvar-och-regler/arsredovisning-och-bokslut`, `/foretagsguiden/ansvar-och-regler/styrelse-och-vd`.
- Förväntat resultat:
  - Aktiebolag: aktiekapital minst 25 000 kr (ABL 1 kap. 5 §); ansökan hos Bolagsverket inom sex månader från att stiftelseurkunden undertecknades; ägarna svarar normalt inte personligen. Handelsbolag: minst två delägare, solidariskt ansvar (HBL 2 kap. 20 §), registrering krävs. Enskild firma: ingen registrering krävs, kalenderår som räkenskapsår, förenklat årsbokslut om omsättningen normalt är högst 3 milj. kr (BFL 6 kap. 3 §).
  - Kontantmetoden får användas om omsättningen normalt är högst 3 milj. kr per år; obetalda fordringar och skulder ska ändå bokföras vid räkenskapsårets utgång (BFL 5 kap. 2 §).
  - Större företag: mer än ett av tre villkor under vart och ett av de två senaste räkenskapsåren: i genomsnitt fler än 50 anställda, balansomslutning över 40 milj. kr, nettoomsättning över 80 milj. kr (ÅRL 1 kap. 3 §).
  - Förseningsavgift: 7 500 kr redan vid en dags försening för privata aktiebolag och ekonomiska föreningar (ÅRL 8 kap. 6 §), ytterligare 7 500 kr efter två månader och därefter enligt artikeln.
  - Konsulten verifierar belopp och gränser mot ÅRL, BFL och ABL i lydelsen som gäller i november 2026, och frågan i artikeln om BFN:s K1-regler för handelsbolag (`legal_verify`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-24 Ta betalt i tid: ränta och avgifter

- Förutsättningar: artikeln `/foretagsguiden/vaxa/ta-betalt-i-tid`.
- Förväntat resultat:
  - Dröjsmålsränta = referensräntan + 8 procentenheter (räntelagen 6 §). Referensränta enligt artikeln: 2,00 % (Riksbanken, fastställd för andra halvåret 2026), alltså dröjsmålsränta 10,00 % per år. Exempel: 20 000,00 kr, förfallodag passerad i 45 dagar: 20 000,00 × 10,00 % × 45 / 365 = 246,58 kr.
  - Förseningsersättning mellan företag: 450 kr (4 a §). Inkassokrav: högst 180 kr; avbetalningsplan: högst 170 kr (4 §). Påminnelseavgift bara om det avtalats i förväg.
  - Betalningstid mellan företag: 30 dagar efter krav om inget annat avtalats (2 a §).
  - Referensräntan för första halvåret 2027 är inte fastställd i artikeln (`legal_verify`); konsulten kontrollerar när den beslutats.
  - Beloppen stämmer med lagen "i den lydelse som gällde i november 2026".
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### GUI-25 Skatter och avgifter 2026 (egenavgifter, arbetsgivaravgifter, bolagsskatt, 3:12)

- Förutsättningar: artiklarna `/foretagsguiden/moms-och-skatt/egenavgifter-och-lon`, `/foretagsguiden/moms-och-skatt/satt-undan-pengar` och `/foretagsguiden/moms-och-skatt/skattekontot`.
- Förväntat resultat:
  - Egenavgifter 28,97 % för den som är född 1959 eller senare; schablonavdrag 25 % (inkomstår 2026). Arbetsgivaravgifter 31,42 % (född 1959-2002). Bolagsskatt 20,6 %.
  - Nedsättning för unga 1 april 2026 till 30 september 2027: för den som är född 2003-2007 gäller 20,81 % på ersättning upp till 25 000 kr och 31,42 % på resten. Exempel: månadslön 30 000 kr → 25 000 × 20,81 % = 5 202,50 kr + 5 000 × 31,42 % = 1 571,00 kr = 6 773,50 kr. Full avgift för samma lön 30 000 × 31,42 % = 9 426,00 kr.
  - 3:12: grundbelopp 322 400 kr för inkomstår 2026 och 333 600 kr för 2027, "fyra inkomstbasbelopp": 4 × 80 600 = 322 400 och 4 × 83 400 = 333 600 (konsulten verifierar inkomstbasbeloppen 80 600 för 2025 och 83 400 för 2026). Två tredjedelar av utdelning inom gränsbeloppet tas upp i kapital, där skatten är 30 %.
  - Skattekontot: intäktsränta 45 % av basräntan men 0 % när basräntan är på sin lägsta nivå 1,25 %; kostnadsränta basräntan + 15 procentenheter (SFL 65 kap.). Betalning räknas när den är bokförd på skattekontot (62 kap. 2 §). Kunde inte beräknas: räntebelopp, eftersom basräntan för perioden inte står i artikeln.
  - Konsulten verifierar varje procentsats mot Skatteverket och att "ändrad 2027" fortfarande är öppen (`legal_verify`: budgetpropositionen för 2027).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Hämtat med `grep -rn "TODO(legal-verify)"` över `app/Domain/Guide`, `app/Domain/Deadlines`, `app/Livewire/Guide`, `app/Support/Guide`, `docs/foretagsguiden/datum/REGLER.md` och `docs/foretagsguiden`. Dessutom tas varje fråga under `legal_verify:` i de 72 artiklarnas inledande data med (56 st), eftersom de är det som läsaren ser under "Det här kontrollerar vi just nu". Datumen som beror på en öppen regel visas för användaren med märket **Kontrollera** (`legalVerify` i `VatDeadlineProvider`, `PreliminaryTaxProvider` och `YearEndDeadlineProvider`). Inga artiklar har ännu `mansklig_granskning` ifyllt.

| Nr | Fil:rad | Frågan | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/Guide/MoneyNotYours.php:291 | Vilken dag Skatteverket debiterar preliminärskatten på skattekontot, vilket avgör när ett företag bokför den (påverkar Momsbufferten). | | |
| 2 | app/Domain/Deadlines/Providers/YearEndDeadlineProvider.php:105 | Ett större handelsbolag eller kommanditbolag (BFL 6 kap. 1 § 6, ÅRL 8 kap. 3 § 5) lämnar också in årsredovisning inom sex månader; räknas inte ut. | | |
| 3 | docs/foretagsguiden/datum/REGLER.md:73 | Om Lag (1930:173) 2 § flyttar Bolagsverkets sjumånadersgräns (ÅRL 8 kap. 6 §) när sista dagen är helg; visas oflyttad med märket Kontrollera. | | |
| 4 | docs/foretagsguiden/datum/REGLER.md:166 | Om köp av tjänster från land utanför EU (ruta 22) ger det tidigare datumet för helårsmoms (SFL 26 kap. 33 a § andra stycket). | | |
| 5 | docs/foretagsguiden/datum/REGLER.md:265 | Produktfråga: möjlighet att ange att företaget saknar F-skatt eller första betalningen enligt beslutet; tills dess visas serien från andra månaden. | | |
| 6 | docs/foretagsguiden/datum/REGLER.md:403 | Gäller §0.1 (helgflytt) Bolagsverkets sjumånadersgräns för förseningsavgift. | | |
| 7 | docs/foretagsguiden/datum/REGLER.md:451 | Vilka övriga kontrolluppgifter (KU80/KU81, KU70 med flera) som ett litet bolag i våra branscher kan utlösa; ingen har kontrollerat det. | | |
| 8 | docs/foretagsguiden/sv/ansvar-och-regler/arsredovisning-och-bokslut.md:30 | Bokföringslagen 6 kap. 3 § låter ett handelsbolag med bara fysiska personer som delägare och en nettoomsättning på normalt högst tre miljoner kronor göra ett förenklat årsbokslut, men BFN:s K1-regler finns bara för enskilda ... | | |
| 9 | docs/foretagsguiden/sv/ansvar-och-regler/bokforingsskyldighet.md:18 | Hur bedöms "normalt högst tre miljoner kronor" det första räkenskapsåret, när det inte finns några tidigare år att jämföra med? BFN:s vägledning till K1 beskriver bara enstaka år över gränsen. Fråga Bokföringsnämnden. | | |
| 10 | docs/foretagsguiden/sv/ansvar-och-regler/gdpr-for-foretagare.md:21 | EU-kommissionen har föreslagit att undantaget i artikel 30.5 ska gälla företag med färre än 750 anställda och att villkoret om tillfällig behandling tas bort (IMY, februari 2026). Har ändringen antagits, och från när gäller den? | | |
| 11 | docs/foretagsguiden/sv/ansvar-och-regler/kassaregister-och-personalliggare.md:26 | Byggbranschen har egna regler (byggherrens anmälan, elektronisk liggare, andra kontrollavgifter). De beskrivs inte här och behöver en egen granskad artikel. | | |
| 12 | docs/foretagsguiden/sv/ansvar-och-regler/styrelse-och-vd.md:22 | EU:s sjätte penningtvättsdirektiv ska vara helt genomfört i svensk rätt senast i juli 2027 (prop. 2025/26:129). Ändrar det vem som ska anmäla verklig huvudman, eller vad som ska anmälas? | | |
| 13 | docs/foretagsguiden/sv/branscher/ata-och-projekt.md:19 | Hur redovisas pågående arbeten i en enskild firma som upprättar förenklat årsbokslut (K1)? Artikeln beskriver bara K2 för aktiebolag. | | |
| 14 | docs/foretagsguiden/sv/branscher/dricks.md:17 | Ska dricks som gästen lägger på kortbetalningen redovisas med moms? Skatteverkets sidor om dricks som lästes den 5 november 2026 tar inte upp momsen. Kontrollera med Skatteverket eller din redovisningskonsult. | | |
| 15 | docs/foretagsguiden/sv/branscher/dricks.md:18 | Vilket konto kortdricks som personalen själv fördelar ska bokföras på tills den betalas ut är inte kontrollerat av en redovisningskonsult. Exemplet med 2890 är NONOX standardförslag. | | |
| 16 | docs/foretagsguiden/sv/branscher/frisor-och-skonhet.md:19 | Vilka behandlingar som ska anmälas till kommunen enligt förordningen om miljöfarlig verksamhet och hälsoskydd 38 § avgörs av vilka verktyg som används. Fråga kommunen om din behandling omfattas. | | |
| 17 | docs/foretagsguiden/sv/branscher/inkop-fran-eu.md:19 | Artikeln gäller ett momsregistrerat företag med momspliktig verksamhet. Ett företag utan sådan verksamhet har andra regler och ett tröskelvärde för inköp. Fråga Skatteverket. | | |
| 18 | docs/foretagsguiden/sv/branscher/kassaregister-i-praktiken.md:23 | Från den 1 januari 2027 ska kassaregister uppfylla SKVFS 2021:17. Den 5 november 2026 hittades inga övergångsregler på Skatteverkets sidor för register som i dag uppfyller SKVFS 2014:9. Kontrollera med Skatteverket eller ... | | |
| 19 | docs/foretagsguiden/sv/branscher/klientarbete-och-gdpr.md:18 | Är en redovisningsbyrå alltid personuppgiftsbiträde åt kunden för löpande bokföring och lön, eller kan byrån i vissa uppdrag vara självständigt personuppgiftsansvarig? Artikeln utgår från att byrån är biträde och uppmanar läsaren ... | | |
| 20 | docs/foretagsguiden/sv/branscher/klientarbete-och-gdpr.md:19 | Finns det någon lagstadgad tystnadsplikt för redovisningskonsulter som inte är revisorer? Artikeln beskriver sekretess som något som avtalas. | | |
| 21 | docs/foretagsguiden/sv/branscher/kortavgifter-och-swish.md:19 | Har avgiften från kortinlösaren eller banken moms? Det framgår av avräkningen eller fakturan. Om avgifter för betaltjänster är undantagna från moms är inte kontrollerat här. | | |
| 22 | docs/foretagsguiden/sv/branscher/kortavgifter-och-swish.md:20 | Kontona i exemplen (1680 för kortfordran, 6570 för avgifter) är NONOX standardförslag och inte kontrollerade av en redovisningskonsult. BAS har 1580 för fordringar på kontokort, som inte finns i NONOX kontoplansmall. | | |
| 23 | docs/foretagsguiden/sv/branscher/lager-och-inventering.md:15 | Artikeln beskriver K2 för årsredovisning (BFNAR 2016:10). En enskild firma eller ett handelsbolag som gör årsbokslut följer andra allmänna råd från Bokföringsnämnden. Kontrollera vilka regler som gäller för ditt företag. | | |
| 24 | docs/foretagsguiden/sv/branscher/lager-och-inventering.md:16 | Kontona 1460 och 4960 är NONOX standardförslag för handelsvaror och inte kontrollerade av en redovisningskonsult för varje bransch. | | |
| 25 | docs/foretagsguiden/sv/branscher/livsmedel-och-tillstand.md:20 | Avgifterna för registrering, livsmedelskontroll och prövning av serveringstillstånd bestäms av varje kommun och anges inte här. Kontrollera med kommunen. | | |
| 26 | docs/foretagsguiden/sv/branscher/livsmedel-och-tillstand.md:21 | Vilka krav kommunens egna riktlinjer (alkohollagen 8 kap. 9 §) ställer på lokal och matutbud efter lagändringen den 1 juni 2026 varierar. Kontrollera med kommunen. | | |
| 27 | docs/foretagsguiden/sv/branscher/moms-pa-mat-och-servering.md:19 | Gränsfall mellan livsmedel och restaurangtjänst, till exempel när kunden först efter betalningen bestämmer sig för att sätta sig, avgörs från fall till fall. Är du osäker, fråga Skatteverket. | | |
| 28 | docs/foretagsguiden/sv/branscher/personalliggare-bygg.md:18 | Hur länge ska entreprenören och byggherren spara personalliggaren på en byggarbetsplats? Artikeln hänvisar till Skatteverket. | | |
| 29 | docs/foretagsguiden/sv/branscher/personalliggare-i-praktiken.md:21 | Hur ska en salong göra när egenföretagare som hyr stol arbetar i samma lokal? Skatteförfarandelagen 39 kap. 11 § nämner personer i en annan verksamhet som näringsidkaren bedriver i samma lokal, men Skatteverkets sidor säger inte ... | | |
| 30 | docs/foretagsguiden/sv/branscher/presentkort.md:16 | Hur ska ett presentkort som aldrig används och som har gått ut redovisas, i momsen och som intäkt? Det tas inte upp här. Fråga din redovisningskonsult. | | |
| 31 | docs/foretagsguiden/sv/branscher/presentkort.md:17 | Hur den del av en såld enfunktionsvoucher som inte är moms ska bokföras (som skuld eller som intäkt) fram till att kortet används är inte kontrollerat av en redovisningskonsult. Fråga din redovisningskonsult. | | |
| 32 | docs/foretagsguiden/sv/branscher/presentkort.md:18 | Om ett presentkort är en enfunktionsvoucher eller en flerfunktionsvoucher avgörs från fall till fall. Är du osäker, fråga Skatteverket. | | |
| 33 | docs/foretagsguiden/sv/branscher/rot-och-rut.md:25 | Har kunden en skyldighet att betala mellanskillnaden om Skatteverket inte betalar ut hela beloppet, när inget annat är avtalat? Skatteverket hänvisar bara till att parterna kan avtala om det. | | |
| 34 | docs/foretagsguiden/sv/branscher/stolshyra.md:23 | Är hyran för en stol eller ett behandlingsrum i en salong en momsfri upplåtelse av lokal, eller en momspliktig tjänst när utrustning, produkter, bokning eller reception ingår? Den 5 november 2026 hittades inget ställningstagande ... | | |
| 35 | docs/foretagsguiden/sv/branscher/stolshyra.md:24 | Hur ska stolshyrare och deras arbetstid antecknas i salongens personalliggare? Skatteverkets sidor säger det inte uttryckligen. | | |
| 36 | docs/foretagsguiden/sv/branscher/stolshyra.md:25 | När en stolshyrare i praktiken arbetar under anställningsliknande former (salongen sätter priser, äger kunderna, bestämmer arbetstider) kan salongen räknas som arbetsgivare. Gränsen avgörs från fall till fall. | | |
| 37 | docs/foretagsguiden/sv/branscher/uppdrag-och-f-skatt.md:22 | Kan en uppdragsgivare bli skyldig att betala arbetsgivaravgifter enligt socialavgiftslagen för en uppdragstagare med F-skatt i andra fall än ansvaret i skatteförfarandelagen 59 kap. 7 §? Socialavgiftslagen är inte kontrollerad. | | |
| 38 | docs/foretagsguiden/sv/branscher/uppdrag-och-f-skatt.md:23 | Kan en arbetsrättslig domstol bedöma uppdraget som en anställning oberoende av F-skatten? Artikeln tar inte upp arbetsrätten. | | |
| 39 | docs/foretagsguiden/sv/moms-och-skatt/egenavgifter-och-lon.md:22 | Egenavgifter 28,97 %, schablonavdrag 25 %, arbetsgivaravgifter 31,42 % och bolagsskatt 20,6 % står i lag och gäller tills riksdagen ändrar dem. Den 4 november 2026 fanns inget beslut om ändrade satser för 2027; kontrollera ... | | |
| 40 | docs/foretagsguiden/sv/moms-och-skatt/egenavgifter-och-lon.md:23 | De nya 3:12-reglerna gäller från inkomstår 2026 (grundbelopp 322 400 kronor för 2026, 333 600 kronor för 2027 enligt Skatteverket). Låt en redovisningskonsult granska att sammanfattningen är rättvisande innan artikeln lämnar beta. | | |
| 41 | docs/foretagsguiden/sv/moms-och-skatt/sa-fungerar-moms.md:20 | Den 4 november 2026 hittades inget riksdagsbeslut om andra ändrade momssatser för 2027 än att den sänkta livsmedelsmomsen gäller hela 2027. Kontrollera budgetpropositionen för 2027 och riksdagens beslut om den innan tabellen ... | | |
| 42 | docs/foretagsguiden/sv/moms-och-skatt/satt-undan-pengar.md:17 | Procentsatserna står i lag och gäller tills riksdagen ändrar dem. Den 4 november 2026 fanns inget beslut om ändrade satser för 2027; kontrollera budgetpropositionen för 2027 och riksdagens beslut innan satserna märks med 2027. | | |
| 43 | docs/foretagsguiden/sv/starta/arbetsgivare.md:21 | Arbetsgivaravgifternas nivåer och nedsättningar (till exempel för unga, äldre och de första anställda) för 2026 och 2027 anges inte här och behöver en egen granskad artikel. | | |
| 44 | docs/foretagsguiden/sv/starta/inkop-fore-start.md:24 | Aktiebolag – får bolaget dra av kostnader och ingående moms för inköp som grundaren gjorde innan stiftelseurkunden skrevs under, när bolaget tar över dem efter registreringen? Vilka villkor gäller? | | |
| 45 | docs/foretagsguiden/sv/starta/inkop-fore-start.md:25 | Aktiebolag – får bolaget dra av ingående moms på en faktura som är ställd till grundaren och inte till bolaget? Guiden visar därför aktiebolagsexemplet utan momsavdrag. | | |
| 46 | docs/foretagsguiden/sv/starta/inkop-fore-start.md:26 | Vilket verifikationsdatum ska inköp före räkenskapsårets första dag få – inköpsdagen, dagen då bolaget tog över inköpet eller räkenskapsårets första dag? | | |
| 47 | docs/foretagsguiden/sv/starta/inkop-fore-start.md:27 | Finns en tidsgräns för hur långt före starten ett inköp får vara gjort för att momsen ska få dras av? Skatteverkets sida om inköp före starten anger ingen. | | |
| 48 | docs/foretagsguiden/sv/starta/inkop-fore-start.md:28 | K2 (BFNAR 2016:10 punkt 10.5) har en motsvarande gräns som skattereglerna. Vilken gräns gäller i bokföringen för ett företag som tillämpar K3, där motsvarande fasta beloppsgräns saknas i artikelns källor? | | |
| 49 | docs/foretagsguiden/sv/vaxa/finansiering.md:21 | Beskrivningen av villkorade och ovillkorade aktieägartillskott bygger på Skatteverkets broschyr SKV 292 utgåva 32 (2024). Kontrollera den senaste utgåvan innan beslut. | | |
| 50 | docs/foretagsguiden/sv/vaxa/forsta-anstallningen.md:32 | Växa-stödets villkor, och om stödet finns kvar efter 2026, behöver kontrolleras hos Skatteverket innan det anges för 2027. | | |
| 51 | docs/foretagsguiden/sv/vaxa/forsta-anstallningen.md:33 | Vilka försäkringar som kan krävas i en viss bransch utan kollektivavtal (utöver den lagstadgade arbetsskadeförsäkringen) beskrivs inte här i detalj och behöver en egen granskning. | | |
| 52 | docs/foretagsguiden/sv/vaxa/fran-enskild-firma-till-aktiebolag.md:20 | Villkoren för underprisöverlåtelse i inkomstskattelagen 23 kap. är fler och mer detaljerade än de som nämns här. Kontrollera dem med en redovisningskonsult för den egna situationen. | | |
| 53 | docs/foretagsguiden/sv/vaxa/fran-enskild-firma-till-aktiebolag.md:21 | Andelen 79,4 procent står i inkomstskattelagen 34 kap. 20 § (lydelse november 2026) och motsvarar 100 procent minus bolagsskatten; kontrollera den om bolagsskatten ändras. | | |
| 54 | docs/foretagsguiden/sv/vaxa/fran-enskild-firma-till-aktiebolag.md:22 | Hur den enskilda firmans sista år deklareras och hur F-skatt, moms och arbetsgivarregistrering avslutas beskrivs inte i detalj här. | | |
| 55 | docs/foretagsguiden/sv/vaxa/hitta-kunder.md:18 | Omfattas reklammejl till en namngiven anställds e-postadress hos ett aktiebolag (fornamn@foretaget.se) av samtyckeskravet i 19 § marknadsföringslagen, eller bara av 20 §? Kontrollera innan du mejlar sådana adresser utan samtycke. | | |
| 56 | docs/foretagsguiden/sv/vaxa/hitta-kunder.md:19 | Gäller kontrollen mot spärregistret NIX också samtal till enskilda näringsidkare på ett nummer som är både privat och i firman? | | |
| 57 | docs/foretagsguiden/sv/vaxa/offerter-och-avtal.md:18 | Ångerrätten vid avtal på distans och utanför affärslokaler, och resten av konsumenttjänstlagen, beskrivs inte här i detalj. Kontrollera dem hos Konsumentverket innan du skickar offerter till privatpersoner. | | |
| 58 | docs/foretagsguiden/sv/vaxa/satt-ratt-pris.md:16 | Egenavgifterna i exemplet räknas förenklat på den önskade inkomsten. Det verkliga underlaget är överskottet efter ett schablonavdrag; kontrollera med Skatteverket eller din redovisningskonsult innan du använder exemplet som ... | | |
| 59 | docs/foretagsguiden/sv/vaxa/skydda-namn-och-varumarke.md:17 | Hur ett varumärke registreras, vad det kostar och vad det skyddar beskrivs här bara i stora drag. Uppgifterna finns hos Patent- och registreringsverket, som ännu inte är en tillåten källa i guiden. | | |
| 60 | docs/foretagsguiden/sv/vaxa/ta-betalt-i-tid.md:20 | Referensräntan för första halvåret 2027 beslutas av Riksbanken inför årsskiftet; kontrollera den nya räntan innan räntesatsen anges för 2027. | | |
| 61 | docs/foretagsguiden/sv/viktiga-datum/arsstamma-och-arsredovisning.md:21 | Lagen (1930:173) om beräkning av lagstadgad tid 2 § flyttar den sista dagen för en åtgärd som infaller på en lördag, söndag eller helgdag till nästa vardag. Tillämpar Bolagsverket den på sjumånadersgränsen för förseningsavgift i ... | | |
| 62 | docs/foretagsguiden/sv/viktiga-datum/arsstamma-och-arsredovisning.md:22 | Räknar Bolagsverket sjumånadersgränsen för ett räkenskapsår som slutar 30 juni till 30 januari (lagen om beräkning av lagstadgad tid 1 §) eller till 31 januari? | | |
| 63 | docs/foretagsguiden/sv/viktiga-datum/momsdeklarationens-datum.md:14 | Ger köp av tjänster från ett land utanför EU (ruta 22) det tidigare datumet för helårsmoms enligt 26 kap. 33 a § andra stycket och 33 b § tredje stycket? | | |


## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen **Företagsguiden** är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor eller skäl (till exempel vilka avsnitt eller artiklar som ska kvarstå som Beta): ____________________________________________

Godkännandet gäller enbart den här modulen.
