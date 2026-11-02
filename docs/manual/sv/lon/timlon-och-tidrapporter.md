---
beskrivning: Hur timanställda får lön – timmar du skriver in, godkända tidrapporter från personalliggaren och timmar från tidigare månader som ingen lön har betalat.
ordning: 60
roller: [owner, admin, payroll_admin]
modul: payroll
sokord: [timlön, timanställd, tidrapport, timmar, personalliggare lön, sena timmar, extrapersonal]
---

# Timlön och tidrapporter

En timanställd får lön för de timmar hen har arbetat. I NONOX kan timmarna komma in på tre sätt, och du kan blanda dem:

1. Du skriver in dem i lönekörningen.
2. De kommer från **godkända tidrapporter**, till exempel från personalliggaren.
3. De kommer från en fil från ert schemasystem (se [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem)).

Oavsett väg räknas timmarna gånger anställningens timlön, och raden beskattas, ger arbetsgivaravgift och räknas in i semesterlönegrundande lön på samma sätt.

Månadsavlönade påverkas inte av tidrapporter: deras timmar ingår i månadslönen.

![Tidrapporter föreslagna från personalliggaren, godkända och väntande.](/bilder/manual/lon/timlon-och-tidrapporter.webp)

## Förutsättningen: timlön på anställningen

Under **Lön → Anställda** ska personen ha **Lön per** satt till **Timme** och timlönen i **Lön, kronor**. Se [Anställda](/manual/lon/anstallda).

## Så här skriver du in timmar

1. Öppna månadens lönekörning.
2. Under **Timmar och tillägg**: välj **Anställd** och lönearten **Timlön**.
3. Skriv **Antal timmar, mil eller dagar**, till exempel 42,5.
4. Klicka på **Lägg till** och sedan **Beräkna**.

## Så här kommer timmarna från personalliggaren

Har ni modulen Personalliggare kan arbetspassen bli tidrapporter, så att du slipper räkna timmar för hand.

1. Under **Personalliggare → Personal**: välj rätt person i **Anställd i lönen** för var och en.
2. Öppna **Personalliggare → Tidrapporter**, välj period och klicka på **Föreslå**. NONOX räknar timmar per dag från avslutade arbetspass. Pass utan utcheckning tas inte med; rätta dem först.
3. Granska förslaget per person och klicka på **Godkänn**. Då skrivs godkända tidrapporter med lönearten Timlön.
4. Öppna lönekörningen och klicka på **Beräkna**.

Godkända tidrapporter daterade i lönekörningens månad läggs ihop till en rad på lönebeskedet: **Timlön enligt tidrapport**, med antal timmar gånger timlönen.

Att godkänna förslag kräver både rätt att hantera personalliggaren och rätt att attestera lön. Läs mer under [Personalliggare](/manual/personalliggare).

Har ni modulen Planering jämförs tiden i stället med schemat under **Planering → Tid att attestera**, och de attesterade dagarna blir tidrapporter med timlön, OB och övertid. Se [Tid att attestera](/manual/planering/tid-att-attestera).

> [!NOTE]
> Varje tidrapport betalas en gång. När lönekörningen räknas knyts tidrapporterna till den, och när den är attesterad kan de inte ändras. En dag som redan finns i en tidrapport föreslås aldrig igen.

### Timmar som väntar på godkännande

Finns det timmar i månaden som ännu inte är godkända visar lönekörningen till exempel **12 timmar i perioden väntar på godkännande och kommer inte med i lönen förrän de är godkända**. Godkänn dem och beräkna lönekörningen igen, eller låt dem vänta till nästa månad.

## Timmar från tidigare månader

Ibland godkänns timmar efter att månadens lön redan är attesterad. NONOX betalar dem aldrig automatiskt, utan frågar dig i nästa lönekörning:

**12 godkända timmar från tidigare månader har inte betalats ut.** Under rubriken ser du vem det gäller, hur många timmar och vilka månader.

- Klicka på **Ta med i den här lönekörningen** för att betala dem nu. De blir egna rader på lönebeskedet, till exempel **Timlön enligt tidrapport (oktober 2026)**, med timlönen i den anställning timmarna arbetades i.
- Klicka på **Inte nu** för att vänta. Frågan kommer tillbaka i nästa lönekörning.

Ändrar du dig klickar du på **Ändra valet** så länge lönekörningen inte är attesterad. Beräkna lönekörningen igen efter valet.

> [!NOTE]
> Timmar från tidigare månader som betalas nu redovisas i arbetsgivardeklarationen för den månad de betalas ut, som all annan lön i lönekörningen. Är du osäker på hur det ska redovisas i ert fall, fråga er redovisningskonsult.

## Vanliga frågor

**Hur får timanställda OB och övertid?**
Lägg in dem som egna rader med lönearterna **OB-tillägg** och **Övertid**, med belopp, eller med antal timmar om modulen Avtal prissätter dem. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).

**Kan de anställda rapportera sin tid själva på Min sida?**
Inte ännu. Tiden kommer från personalliggaren, från en fil eller från det du skriver in.

**Får timanställda semesterersättning?**
Ja, antingen som semesterersättning med varje lön, om det är valt på anställningen, eller som semesterlön enligt procentregeln. Se [Semester](/manual/lon/semester).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem)
- [Personalliggare](/manual/personalliggare)
- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen) – tid från schemat i Planering.
- [Anställda](/manual/lon/anstallda)
