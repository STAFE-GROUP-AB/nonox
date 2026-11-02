---
beskrivning: Slå på Planering, lägg in avdelningar och kompetenser, och välj aviseringar, personalens egna val, arbetstidsregler, attest och försäljningskonton.
ordning: 10
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.settings]
sokord: [slå på planering, avdelningar, kompetenser, inställningar schema, arbetstidsregler, vilotid, tolerans, avrundning, försäljningskonton]
---

# Kom igång och inställningar

Innan du lägger det första schemat slår du på modulen och lägger in avdelningarna ni planerar i. Allt annat har färdiga standardvärden som du kan ändra när du vill.

![Avdelningar och kompetenser under Planering, Inställningar.](/bilder/manual/planering/installningar.webp)

## Innan du börjar

- Lönemodulen ska vara påslagen. Planering kan inte slås på utan den.
- De som ska planeras ska finnas under **Lön → Anställda** med en anställning. Schemat visar de anställda som har en anställning under perioden du tittar på.
- Vill du jämföra schemat med den faktiska tiden ska personalen vara kopplad till sin anställning i personalliggaren, under **Anställd i lönen**. Se [Lägg till personal](/manual/personalliggare/lagg-till-personal).

## Så här slår du på Planering

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Öppna fliken **Moduler**.
3. Klicka på reglaget vid **Planering** och sedan **Slå på**.

Nu finns **Planering** i menyn, med **Schema**, **Mallar**, **Tid att attestera**, **Öppna pass och byten**, **Bemanning och kostnad** och **Inställningar**. Bara ägare och administratörer kan slå på moduler.

## Avdelningar

En avdelning är en del av verksamheten som ni planerar pass i, till exempel Kök, Servering, Bar eller Kassa. Passen får avdelningens färg i schemat.

### Så här lägger du till en avdelning

1. Gå till **Planering → Inställningar**.
2. Klicka på **Ny avdelning** under **Avdelningar**.
3. Skriv **Namn** och välj en **Färg**, eller en egen färg.
4. Har ni personalliggaren kan du välja **Lokal i personalliggaren**. Då kan schemat filtreras per lokal.
5. Ändra **Ordning** om avdelningen ska stå före eller efter de andra.
6. Klicka på **Spara**.

En avdelning ni inte använder längre kan du **Arkivera**. Den visas då inte som en egen rad när schemat visas per avdelning, och när du väljer avdelning för ett pass står den som arkiverad. Passen som redan har den behåller den. Med **Återställ** tar du tillbaka den.

## Kompetenser

En kompetens är något ett pass kan kräva, till exempel kassa, kock eller öppnare. Skriv namnet under **Ny kompetens** och klicka på **Lägg till**. I tabellen under kompetenserna kryssar du för vem som har vilken.

Kompetenserna används på tre sätt:

- Du kan filtrera schemat på kompetens.
- Ett pass kan kräva en kompetens.
- Ett öppet pass som kräver en kompetens visas bara för den personal som har den, och bara de kan be om det.

**Ta bort** tar bort kompetensen från både personal och pass.

## Modulens inställningar

Resten ställer du in under **Planering → Inställningar**, på kortet **Regler, aviseringar och lön**. Samma inställningar finns också under **Inställningar för företaget → Moduler**, med knappen **Inställningar** på kortet för Planering. Klicka på **Spara** när du är klar.

Alla som får planera kan ändra inställningarna. **Tolerans vid attest**, avrundningen och lönearterna för övertid och mertid ändrar lönen, så dem kan bara den som får attestera tid ändra. För andra står det **Bara den som attesterar tid till lönen kan ändra de här.**

| Inställning | Vad den gör |
|---|---|
| **Schemat öppnas i** | **Veckovy** eller **Dagvy**. |
| **Tidssteg när du drar (minuter)** | Hur passen hakar fast när du drar dem: 5, 10, 15, 30 eller 60 minuter. Standard är 15. |
| **Publicera i förväg (dagar)** | Hur långt fram schemat bör vara publicerat. Bara en påminnelse i Att göra på översikten; inget publiceras automatiskt. Standard är 14 dagar. |
| **Aviseringar till personalen** | Om personalen får besked **När schemat publiceras, med nya och ändrade pass** och **Direkt när ett publicerat pass ställs in**. En ändring i ett publicerat pass skickas när du publicerar igen. |
| **Personalens egna val i Min sida** | Om personalen **Får ta öppna pass**, **Får be om att byta pass (en chef godkänner alltid)** och **Får ange när de kan arbeta**. |
| **Arbetstidsregler** | Reglerna som varnar i schemat. Se [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar#arbetstidsreglerna). |
| **Minderåriga** | Varningar för pass som inte passar anställda under 18 år. Avstängt från början. |
| **Tolerans vid attest (minuter)** | Skillnader mellan schemat och personalliggaren som är mindre än så godkänns utan avvikelse. Standard är 5 minuter. |
| **Avrunda in- och utcheckning (minuter)** | 0 betyder ingen avrundning, vilket är standard. |
| **Löneart för övertid (månadslön, heltid)** och **Löneart för mertid (månadslön, deltid)** | Vilken löneart tid utöver schemat skickas på för månadsavlönade. Se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen). |
| **Försäljning från konto** och **till och med konto** | Vilka intäktskonton som räknas som dagens försäljning. Standard är 3000–3799. |

> [!NOTE]
> Arbetstidsreglerna och gränserna för minderåriga är förifyllda men inte kontrollerade av en jurist. Ett kollektivavtal kan ge andra värden. Kontrollera dem mot ert avtal, eller fråga er redovisningskonsult, innan ni litar på varningarna.

## Stäng av Planering

Stänger du av modulen döljs schemat och inga fler aviseringar om pass skickas. Pass, mallar och avdelningar finns kvar om du slår på den igen.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Roller och behörigheter](/manual/planering/roller-och-behorigheter)
- [Moduler](/manual/installningar/moduler)
