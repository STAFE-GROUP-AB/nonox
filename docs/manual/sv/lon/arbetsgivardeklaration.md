---
beskrivning: Skapa arbetsgivardeklarationen (AGI) för utbetalningsmånaden, lämna in den hos Skatteverket som fil eller via Skatteverkets API, markera den som inlämnad och gör rättelser.
ordning: 140
roller: [owner, accountant, payroll_admin]
modul: payroll
rutter: [payroll.agi, payroll.agi.file]
sokord: [AGI, arbetsgivardeklaration, individuppgift, huvuduppgift, Skatteverket, deklarera lön, rättelse AGI, XML, API, eget utrymme, granskningslänk, kontrollresultat]
---

# Arbetsgivardeklaration (AGI)

Varje månad som företaget betalar ut lön lämnar det en arbetsgivardeklaration på individnivå till Skatteverket: hur mycket lön och förmåner varje anställd har fått, hur mycket skatt som dragits och hur stora arbetsgivaravgifterna är. NONOX skapar deklarationen som en fil enligt Skatteverkets format. Du lämnar in filen i Skatteverkets e-tjänst.

Deklarationen gäller den månad lönen **betalas ut**, inte den månad den är intjänad. En lön för oktober som betalas ut den 25 oktober deklareras alltså för oktober.

![Arbetsgivardeklarationerna per månad med skatt, avgifter och status. Beloppen är demodata.](/bilder/manual/lon/arbetsgivardeklaration.webp)

## Innan du börjar

- Företagets organisationsnummer och telefonnummer måste vara ifyllda under **Företagsinställningar**. Skatteverket kräver ett telefonnummer till en kontaktperson.
- Alla lönekörningar med utbetalning i månaden ska vara attesterade.
- Regeln för deklarationens filformat under **Lön → Regler** måste vara kontrollerad.

> [!IMPORTANT]
> Arbetsgivardeklaration för enskild firma görs med ägarens personnummer, och det stöds inte ännu. Har du enskild firma med anställda behöver du lämna deklarationen direkt i Skatteverkets e-tjänst.

## Så här skapar och lämnar du in deklarationen

1. Öppna **Lön → Arbetsgivardeklaration**. Överst ser du **Nästa arbetsgivardeklaration** och vilken dag den ska lämnas senast.
2. Leta upp **Utbetalningsmånad** i tabellen. Där ser du skatten och arbetsgivaravgifterna.
3. Klicka på **Skapa arbetsgivardeklaration**.
4. Klicka på **Hämta fil** för att spara filen på datorn.
5. Logga in hos Skatteverket och lämna filen under **Arbetsgivardeklaration → Lämna via fil**. Följ Skatteverkets steg och signera.
6. Tillbaka i NONOX: skriv **Kvittensnummer (frivilligt)** från Skatteverkets kvittens och klicka på **Markera som inlämnad**.
7. När Skatteverket har tagit emot deklarationen: klicka på **Godkänd av Skatteverket**. Vägrade Skatteverket filen, klicka på **Avvisad**.

Det är ägaren, redovisningskonsulten och löneadministratören som kan skapa och lämna deklarationer.

> [!TIP]
> Upptäcker du ett fel innan du har lämnat in filen: rätta det och klicka på **Skapa filen på nytt**.

## Lämna deklarationen via Skatteverkets API

När installationen är kopplad till Skatteverket finns rutan **Lämna arbetsgivardeklarationen via Skatteverkets API** under tabellen, märkt **Beta**. Då läser NONOX in filen direkt i företagets eget utrymme hos Skatteverket, och du behöver inte ladda upp den själv.

> [!NOTE]
> På nonox.app är kopplingen inte påslagen än. Den är byggd mot Skatteverkets testtjänst och provas där av ett fåtal pilotanvändare. Till dess hämtar du filen och lämnar den hos Skatteverket som ovan.

1. Skapa arbetsgivardeklarationen för månaden som vanligt och välj månaden under **Utbetalningsmånad** i rutan.
2. Klicka på **Logga in hos Skatteverket med e-legitimation**. Du loggar in hos Skatteverket, inte i NONOX, till exempel med BankID. Skatteverket kontrollerar vid varje steg att du får företräda arbetsgivaren, till exempel som firmatecknare, deklarationsombud eller registreringsombud för arbetsgivardeklaration. Inloggningen gäller i ungefär en timme. Gäller din inloggning bara momsdeklarationen behöver du logga in igen; NONOX ber då om tillgång till båda.
3. Klicka på **Skicka filen till Skatteverket**, kryssa i **Jag har granskat siffrorna** (du som kund ansvarar för att uppgifterna är riktiga) och klicka på **Ja, skicka filen**. Är perioden låst hos Skatteverket, eller håller deklarationen på att skrivas under, skickas ingenting. Har du redan läst in en fil för månaden som inte är underskriven tas den bort när den nya är sparad, så att bara den nya finns kvar; blir den nya inte sparad finns den förra kvar. En underskriven deklaration rör NONOX aldrig: den nya filen blir en rättelse.
4. Vänta en stund och klicka på **Hämta kontrollresultat**. Skatteverket kontrollerar filen på några sekunder till några minuter, beroende på hur många individuppgifter den har. Bara den som skickade filen kan hämta resultatet, spara, ta bort eller skapa granskningslänken; rutan visar vem som skickade den. En fil som någon annan skickade går också att ta bort på Mina sidor.
5. Läs vad rutan säger. Blev filen inte mottagen står det **Ingenting har sparats i företagets eget utrymme hos Skatteverket** och varför. Svarade Skatteverket inte i tid kan filen ha kommit fram ändå: titta på Mina sidor eller hämta status innan du skickar igen.
   - **Sparad i eget utrymme hos Skatteverket**: kontrollen hittade inga stoppande fel.
   - **Fel i kontrollen, inte sparad**: Skatteverkets fel står i rutan. Klicka på **Spara ändå i eget utrymme** om du vill rätta felen på Mina sidor, eller **Ta bort hos Skatteverket** om du vill rätta i NONOX och skicka en ny fil. Väljer du inget tar Skatteverket bort underlaget efter 24 timmar.
   - **Avvisad av Skatteverket, inte sparad**: filen gick inte att läsa eller följde inte Skatteverkets format. Ingenting är sparat.
6. Klicka på **Skapa granskningslänk** och sedan på **Granska och skicka in på Mina sidor**. Skatteverkets granskningssida öppnas i en ny flik. Där loggar en behörig företrädare in med e-legitimation, granskar deklarationen och skickar in den.
7. Kom tillbaka och klicka på **Hämta status från Skatteverket**. Där ser du periodens status hos Skatteverket, sista dag att deklarera och betala, händelserna och kvittenserna. Markera sedan filen som inlämnad i tabellen, med kvittensnumret.

Kan Skatteverket inte skapa länken säger rutan varför: underlag läses fortfarande in, perioden beräknas, deklarationen skrivs under eller är redan inskickad, eller så finns felaktiga underlag. Då visas **Öppna Skatteverkets felrapport**, som öppnar felrapporten på Mina sidor.

> [!IMPORTANT]
> Att läsa in underlaget är inte att lämna deklarationen. Skyldigheten att lämna den är inte fullgjord förrän en behörig person har granskat och skickat in den i Skatteverkets e-tjänst Arbetsgivardeklaration på Mina sidor. Där går underlaget också att ändra.

Felmeddelandena är Skatteverkets egna. Ett **Stoppande fel** måste rättas innan deklarationen kan skickas in. Ett **Rimlighetsfel** stoppar inte, men kontrollera uppgiften. Specifikationsnumret är den anställdes nummer i NONOX. Felen visas när du hämtar dem och sparas inte i NONOX, eftersom de kan återge uppgifter ur filen.

Skatteverket loggar alla anrop från NONOX och sparar loggarna i upp till 5 år för att förebygga och utreda säkerhetsincidenter. NONOX sparar Skatteverkets inlämnings-id, statusar, antal fel och varningar, granskningslänken och kvittensnumret, aldrig personnummer, namn eller vem som skrev under. **Logga ut från Skatteverket** avslutar inloggningen i NONOX direkt.

## Deklarationsdagen

Vilken dag deklarationen ska vara inne beror på om företaget är momsregistrerat och har ett beskattningsunderlag för moms över 40 miljoner kronor om året. Det anger du under företagets uppgifter (**Beskattningsunderlaget för moms är över 40 miljoner kronor om året**), och NONOX visar rätt dag för varje månad: den 12:e, eller den 26:e för större företag, som då betalar skatten redan den 12:e. Datumen räknas enligt skatteförfarandelagen och stäms av mot Skatteverkets lista. En månad som är sen visas med **skulle ha lämnats senast** och datumet.

## Vad står i filen?

- **Huvuduppgiften**: summan av arbetsgivaravgifterna och den avdragna skatten för månaden.
- **En individuppgift per anställd**: kontant lön, förmåner, avdragen skatt (eller SINK och A-SINK), och kryss för milersättning och traktamente.
- **Personer utan svenskt nummer**: de redovisas med födelsetid (ruta 222), födelseort (077), födelseland (078), medborgarskap (081), namn (216, 217) och adress (218–220, med landskod i 221). En adress utan postnummer skrivs som fri adress (230). Saknas något av det på personalkortet går deklarationen inte att skapa.
- **Förmåner som den anställda har betalat helt**: förmånen redovisas ändå, med 0 i ruta 012 (bilförmån i 013), som Skatteverket vill. Med drivmedelsförmån i ruta 018 står alltid bilförmånen i 013, även när den är 0.
- **Regionalt stöd**: när företaget har valt det står underlaget i ruta 471 och avdraget i ruta 476 i huvuduppgiften, och summan av arbetsgivaravgifterna är efter avdraget.
- **A-SINK**: med skatten i ruta 275 står verksamhetens art i ruta 112. Saknas den på personalkortet går deklarationen inte att skapa förrän du har fyllt i den.
- **Lön utan arbetsgivaravgift**: så länge en persons ersättning under året är under 1 000 kronor redovisas lönen i ruta 131 (förmåner i 132–134) i stället för 011. Den månad årets ersättning når 1 000 kronor läggs de tidigare beloppen till i ruta 011 och anges i ruta 010, och avgiften tas på hela beloppet. Se [Arbetsgivaravgifter och tjänstepension](/manual/lon/arbetsgivaravgifter-och-pension).
- **Avdragen skatt** är den skatt som faktiskt drogs. Var skatten större än kontantlönen redovisas bara det som drogs.

Belopp anges i hela kronor. Varje anställd har sitt anställningsnummer som specifikationsnummer, så att en rättelse ersätter rätt uppgift. Filen kontrolleras mot Skatteverkets schema när schemat finns installerat på servern, och den sparas med en kontrollsumma.

> [!NOTE]
> Filen innehåller personnummer. Varje gång någon hämtar den skrivs det i behandlingshistoriken.

## Så här rättar du en inlämnad deklaration

Har du rättat lönen efter att deklarationen är inlämnad, eller upptäckt ett fel:

1. Öppna **Lön → Arbetsgivardeklaration** och leta upp månaden.
2. Klicka på **Skapa rättelse**.
3. Hämta filen och lämna in den hos Skatteverket som förut.
4. Markera den som inlämnad, och sedan som godkänd.

En rättelse innehåller alla individuppgifter för månaden igen. En anställd som inte längre ska finnas med i månaden får en borttagsmarkering. När rättelsen är godkänd visas de tidigare filerna som **Ersatt av rättelse**.

## Vanliga frågor

**Kan NONOX lämna deklarationen direkt till Skatteverket?**
NONOX kan läsa in filen i företagets eget utrymme hos Skatteverket när installationen är kopplad till Skatteverket (se ovan), men själva inlämningen gör en behörig person på Mina sidor. Utan koppling hämtar du filen och lämnar den i Skatteverkets e-tjänst.

**Varför kan jag inte skapa deklarationen?**
Läs meddelandet. Vanliga orsaker: **En lönekörning i månaden är inte attesterad**, organisationsnummer eller telefonnummer saknas, ett personnummer är fel, eller regeln för filformatet är inte kontrollerad.

**Måste lönen vara bokförd först?**
Nej. Men lönemodulen kan inte stängas av, och löneåret kan inte avslutas, så länge en månad med lön saknar inlämnad deklaration.

**Var ser jag om deklarationen stämmer med bokföringen?**
Under **Lön → Rapporter → Avstämning**. Se [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing)
- [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte)
- [Momsdeklaration](/manual/moms/momsdeklaration)
