---
beskrivning: Skapa arbetsgivardeklarationen (AGI) som fil för utbetalningsmånaden, lämna in den hos Skatteverket, markera den som inlämnad och gör rättelser.
ordning: 140
roller: [owner, accountant, payroll_admin]
modul: payroll
rutter: [payroll.agi, payroll.agi.file]
sokord: [AGI, arbetsgivardeklaration, individuppgift, huvuduppgift, Skatteverket, deklarera lön, rättelse AGI, XML]
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

## Deklarationsdagen

Vilken dag deklarationen ska vara inne beror på om företaget är momsregistrerat och omsätter mer än 40 miljoner kronor om året. Det anger du under företagets uppgifter (**Omsättningen är över 40 miljoner kronor om året**), och NONOX visar rätt dag för varje månad: den 12:e, eller den 26:e för större företag, som då betalar skatten redan den 12:e. Datumen räknas enligt skatteförfarandelagen och stäms av mot Skatteverkets lista. En månad som är sen visas med **skulle ha lämnats senast** och datumet.

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
Inte ännu. Du hämtar filen och lämnar den i Skatteverkets e-tjänst.

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
