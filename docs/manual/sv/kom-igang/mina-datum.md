---
beskrivning: Mina datum visar företagets sista dagar för moms, deklarationer och årsredovisning, och du kan få dem i din egen kalender med påminnelser.
ordning: 125
rutter: [guide.dates]
sokord: [mina datum, frister, deadline, sista dag, kalender, prenumerera, ics, google kalender, outlook, apple kalender, påminnelse, momsdatum]
---

# Mina datum

Mina datum samlar de datum som gäller företaget: när momsdeklarationen ska vara inne, när arbetsgivardeklarationen ska lämnas, preliminärskatten, inkomstdeklarationen, årsstämman, årsredovisningen och andra datum med en sista dag. Datumen räknas fram ur företagets inställningar och räkenskapsår i NONOX.

> [!IMPORTANT]
> Kontrollera alltid datumen hos Skatteverket och Bolagsverket. Skatteverket kan ha beslutat något annat för just ditt företag, till exempel en annan momsperiod. Mina datum är en del av Företagsguiden, som är i beta.

## Öppna Mina datum

Klicka på **Mina datum** längst ner i menyn, under **Företagsguiden**. Du når sidan också från **Min startplan** och från **Nästa datum** på översikten.

## Vad sidan visar

![Ditt år i korthet på Mina datum för ett aktiebolag med kvartalsmoms, F-skatt och anställda: en rad för varje sorts datum, med nästa datum och inställningen som styr det.](/bilder/manual/kom-igang/mina-datum.webp)

Sidan börjar med **Så läser du sidan**, en kort förklaring av hur datumen räknas fram och flyttas när de infaller på en helg, att F-skatt inte är moms och vad märkena betyder. Klicka på rubriken för att fälla ihop den. NONOX kommer ihåg att du har fällt ihop den, för dig och för det här företaget.

### Ditt år i korthet

Under det står **Ditt år i korthet**: en rad för varje sorts datum som gäller företaget, till exempel **Moms – fyra gånger om året, en gång per kvartal (ni redovisar per kvartal)** eller **Preliminärskatt (F-skatt) – varje månad, oftast den 12:e (17:e i januari och augusti)**. Varje rad säger när det ska göras, vilket datum som är nästa och vilken inställning som styr det, till exempel **Styrs av Momsredovisning under Företagets uppgifter.** Stämmer något inte klickar du på **Ändra under Företagets uppgifter**.

**Visa datumen** visar bara datum av den sorten, och **Läs mer** öppnar artikeln i Företagsguiden. Raden för preliminärskatten säger också **Om ni ska betala, hur mycket och från vilken månad står i Skatteverkets beslut om debiterad preliminärskatt. Står det 0 kronor finns inget att betala.**, eftersom det bara står i beslutet.

### Nästa datum och datumen månad för månad

Sedan kommer det datum som ligger närmast. Under det kommer datumen månad för månad, de kommande sex månaderna. Klicka på **Visa alla datum (15 månader)** längst ner för att se fler, och på **Visa de närmaste sex månaderna** för att gå tillbaka. Datum som infaller samma dag står tillsammans. För varje datum ser du:

- ett märke för vad datumet gäller: **Moms**, **F-skatt**, **Arbetsgivare**, **Inkomstdeklaration**, **Bolagsverket**, **Bokslut** eller **EU-försäljning**. Varje märke har en egen färg och symbol;
- om det är en **Betalning**, en **Deklaration** eller **Deklaration och betalning**. Momsen deklareras och betalas samma dag. Preliminärskatten med F-skatt betalas bara, den deklareras inte;
- vad du ska göra, och för vilken period, till exempel **Deklarera och betala moms för kvartal 4 2026** eller **Betala F-skatt (preliminärskatt), december 2026**;
- varför det gäller företaget. Återkommer samma förklaring varje månad står den bara vid den första;
- hur många dagar som är kvar. Datum inom en vecka är markerade, och ett datum som har passerat står som **Passerat för 2 dagar sedan**;
- **Gör det i NONOX**, som öppnar sidan där du gör det, **Läs mer**, som öppnar artikeln i Företagsguiden, och **Källa**, som öppnar källan (Skatteverket, Bolagsverket eller lagtexten).

Vid det första datumet av varje sort står **Vad är det här?**. Klicka på det för en kort förklaring av vad datumet gäller, varför det finns och vad du gör i NONOX.

### Visa bara en sorts datum

Ovanför datumen står **Alla**, **Moms**, **Skatt**, **Arbetsgivare**, **Bolagsverket** och **Bokslut**, med antalet datum för varje. Klicka på en för att bara se de datumen. **Skatt** samlar preliminärskatten och inkomstdeklarationen, och **Moms** också den periodiska sammanställningen. Nästa datum överst följer valet. En sort som inte har några datum visas inte.

### Märkena

| Märke | Betyder |
|---|---|
| **Datum i lagen** | Ett datum som följer av lagen. |
| **Rekommendation** | Ett datum som Företagsguiden föreslår, till exempel när det är klokt att göra bokslutet. Det är inget krav i lagen. |
| **Kontrollera** | Ett datum vars regel inte är bekräftad ännu, eller som beror på ett beslut om just ditt företag. Vid datumet står **Kontrollera datumet hos Skatteverket** eller **Kontrollera datumet hos Bolagsverket**, efter vem som bestämmer. Gäller det en hel serie, som preliminärskatten varje månad, står det bara vid den första. |
| **Redovisad** | Momsen eller arbetsgivardeklarationen för perioden är redovisad i NONOX. Den påminner vi inte om. |

Saknar NONOX företagets tidigare räkenskapsår står det **Datum för räkenskapsår före** med det första årets startdag. Lägg då in föregående räkenskapsår under **Räkenskapsår**, så visas också de datum som hör till det.

Vilka datum som visas beror på företagets uppgifter. Är företaget momsregistrerat visas momsdeklarationerna, är det registrerat som arbetsgivare visas arbetsgivardeklarationerna, och har det F-skatt visas preliminärskatten. Stämmer något inte, kontrollera uppgifterna under [Företagets uppgifter](/manual/installningar/foretagets-uppgifter).

## Mina datum visar inte

Det här kan NONOX inte räkna ut, så det står inte i Mina datum:

- **Beslut från Skatteverket som bara gäller ditt företag**, till exempel att få deklarera moms den 26:e, en tidigare dag för helårsmoms, ett senare första datum för preliminärskatten eller ett beslut om att en större arbetsgivare ska deklarera den 12:e. Följ beslutet.
- **Om ett handelsbolag har en juridisk person som delägare.** I stället visas **Kontrollera om handelsbolaget ska skicka in årsredovisningen** med datumet som då gäller för årsredovisningen.
- **När årsredovisningen ska till revisorn.** Den ska vara hos revisorn senast sex veckor före stämman, men NONOX vet inte om företaget har revisor eller när stämman hålls.
- **Kontrolluppgifter**, till exempel KU31 för utdelning till fysiska personer, som ska vara hos Skatteverket den 31 januari.
- **Inkomstdeklarationen för en ideell förening eller en stiftelse**, eftersom den beror på föreningens eller stiftelsens inkomster.

## Lägg till i din kalender

Du kan prenumerera på datumen i din egen kalender. Kalendern hämtar dem själv och påminner en vecka och en dag före varje datum. I kalendern börjar varje händelse med vad den gäller, till exempel **Moms: Deklarera och betala moms för kvartal 4 2026**.

## Så här gör du

1. Klicka på **Skapa kalenderlänk** längst ner på Mina datum. NONOX bekräftar med **Kalenderlänken är skapad. Kopiera den nu, den visas bara en gång.**
2. Klicka på **Kopiera länken**.
3. Lägg till länken i din kalender, se nedan.

**Google Kalender:** öppna Google Kalender på datorn, klicka på plustecknet vid **Andra kalendrar**, välj **Från webbadress**, klistra in länken och klicka på **Lägg till kalender**. Det kan dröja några timmar innan Google hämtar nya datum.

**Apple Kalender:** på Macen väljer du **Arkiv** och **Ny kalenderprenumeration**, klistrar in länken och klickar på **Prenumerera**. På iPhone går du till **Inställningar**, **Kalender**, **Konton**, **Lägg till konto**, **Annat** och **Lägg till prenumererad kalender**.

**Outlook:** i Outlook på webben väljer du **Lägg till kalender** och **Prenumerera från webben**, klistrar in länken och klickar på **Importera**.

Namnen på valen i kalenderprogrammen kan skilja sig lite mellan versioner.

> [!WARNING]
> Länken är personlig. Den som har den ser företagets datum utan att logga in, så dela den inte. Tappar du bort den klickar du på **Skapa en ny länk**. Då slutar den gamla att fungera.

Vill du inte ha datumen i kalendern längre klickar du på **Stäng av länken**. NONOX bekräftar med **Kalenderlänken är avstängd. Kalendern hämtar inga fler datum.** Länken slutar också att fungera om du inte längre är med i företaget, eller om företaget stänger av Företagsguiden.

## Påminnelser i NONOX

En vecka före ett datum och sista vardagen före det får ägaren och de som sköter företaget och bokslutet (administratörer och redovisningskonsulter) en notis i NONOX, under klockan. Inkomstdeklarationen och årsredovisningen får också en notis 30 dagar före. Notisen kommer en gång per datum, och inte alls för rekommendationer eller för en moms- eller arbetsgivardeklaration som redan är redovisad i NONOX. Datum inom två veckor står också under **Att göra** på översikten. Moms och arbetsgivardeklaration har egna rader där, som också vet om perioden redan är redovisad.

Stänger företaget av **Visa Företagsguiden** under företagets uppgifter försvinner Mina datum, påminnelserna och kalenderlänkarna för alla i företaget.

## Bra att veta

- Datumen sparas inte, de räknas fram varje gång. Ändrar du företagets momsperiod eller räkenskapsår ändras datumen direkt, också i kalendern nästa gång den hämtar dem.
- Det som sparas är din kalenderlänk, som en kontrollsumma så att ingen kan läsa den, och vilka påminnelser som har skickats till dig, så att ingen skickas två gånger.
