---
beskrivning: Jämför schemat med den faktiska tiden från personalliggaren, se avvikelserna, ändra tiden vid behov, attestera dagarna och skicka dem till lönen.
ordning: 80
roller: [owner, admin, payroll_admin]
modul: planning
rutter: [planning.attest]
sokord: [attestera tid, attest, plan mot faktiskt, avvikelser, tidrapport, löneunderlag, skicka till lönen, sen incheckning, glömd utcheckning]
---

# Tid att attestera

Här jämför du schemat med tiden som faktiskt arbetades, dag för dag och person för person. Du godkänner, attesterar, dagarna, och skickar dem sedan till lönen. Då blir de godkända tidrapporter som nästa lönekörning betalar, utan att någon behöver skriva in timmar.

Sidan finns under **Planering → Tid att attestera** för dig som får attestera tid. En schemaläggare ser den inte.

![Tid att attestera: plan, faktisk tid, OB och vad som går till lönen per person och dag.](/bilder/manual/planering/attest.webp)

![Bara dagar med avvikelser, till exempel sen start eller pass utan plan.](/bilder/manual/planering/attest-avvikelser.webp)

## Var den faktiska tiden kommer ifrån

- **Med personalliggaren:** tiden från incheckning till utcheckning, med rättelserna i liggaren. Personen ska vara kopplad till sin anställning i liggaren, under **Anställd i lönen**.
- **Utan personalliggaren**, eller för en person som inte finns i den: den faktiska tiden är den planerade tills du ändrar den.
- **Din egen tid:** har du ändrat tiden under **Ändra** gäller den för dagen.

Personalliggaren ändras aldrig härifrån. En fel in- eller utcheckning rättas i liggaren. Se [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser).

Bara publicerade pass räknas som plan. Ett pass som ändrats men inte publicerats igen räknas som det publicerades. Dagar efter i dag kan inte attesteras.

## Så här attesterar du

1. Gå till **Planering → Tid att attestera**. Perioden är från början månaden för den öppna lönekörningen.
2. Välj **Från** och **Till**, högst två månader. Välj en **Person** eller **Alla**.
3. Under **Visa** kan du välja **Alla dagar**, **Med avvikelser**, **Att attestera** eller **Attesterade och skickade**.
4. Gå igenom dagarna. För varje dag ser du **Plan**, **Faktiskt**, **Skillnad**, **OB**, **Till lönen** och **Avvikelser**.
5. Attestera på ett av tre sätt:
   - **Attestera** på en dag.
   - **Attestera personens dagar** för allt en person har i perioden.
   - **Attestera alla utan avvikelser** för alla dagar som stämmer med schemat.
6. Klicka på **Skicka till lönen** och bekräfta.

Siffran på knapparna är antalet dagar de gäller.

## Avvikelser

Står det **Enligt schemat** stämmer dagen. Annars står avvikelsen i klartext, till exempel **Började 12 min sent (planerat 10:00, incheckad 10:12).**

Skillnader som är mindre än **Tolerans vid attest (minuter)** i inställningarna godkänns utan avvikelse, och då betalas den planerade tiden. Annars betalas den incheckade tiden. Med **Avrunda in- och utcheckning (minuter)** avrundas tiderna först.

Avvikelserna är av tre slag:

| Slag | Exempel | Vad det betyder |
|---|---|---|
| Stoppar attesten (röd) | Incheckad men aldrig utcheckad, ingen anställning gäller dagen, dagen finns redan i en annan tidrapport | Dagen kan inte attesteras förrän det är rättat. |
| Att titta på | Började sent, slutade tidigt, arbetade utan planerat pass, planerat men ingen arbetad tid, tid utöver schemat | Dagen kan attesteras, men inte med **Attestera alla utan avvikelser**. |
| Bara information (grå) | Frånvaro är registrerad i lönen, tiden är ändrad för hand, personen finns inte i personalliggaren, ingen OB räknas, veckan väntar på att bli hel | Räknas inte som avvikelse. |

En dag stoppas också om ett tidrapportförslag från personalliggaren väntar för samma dag. Avslå förslaget under **Personalliggare → Tidrapporter** först, så att ingen dag betalas två gånger.

## Så här ändrar du den faktiska tiden

1. Klicka på **Ändra** på dagen.
2. Fyll i **Från**, **Till** och **Rast (minuter)**. En sluttid före starttiden betyder nästa dag.
3. Arbetade personen i flera delar, till exempel med en rast på OB-tid, klicka på **Lägg till ett pass till (till exempel efter en rast på OB-tid)**.
4. Arbetade personen inte alls, kryssa för **Arbetade inte den här dagen**.
5. Skriv en **Anteckning** och klicka på **Spara**.

Tiden gäller bara attesten och lönen. Vill du gå tillbaka klickar du på **Använd personalliggaren igen**, eller **Använd schemat igen** när personalliggaren inte är på.

## Det här händer när du skickar till lönen

Varje attesterad dag blir godkända tidrapporter, en per löneart: timlön och OB. För månadsavlönade följer veckans övertid och mertid med veckans sista dag, när hela veckan är attesterad. Dagen låses och får statusen **Skickad till lönen**. Hur raderna räknas läser du i [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen).

Är månadens lönekörning redan beräknad går den tillbaka till utkast. Öppna den och klicka på **Beräkna** igen.

En dag skickas inte, och NONOX säger varför, om:

- lönen för månaden redan är attesterad,
- dagen redan finns i en annan tidrapport, till exempel godkänd från personalliggaren,
- en löneart på dagen inte finns i lönen.

## Öppna en dag igen

Klicka på **Öppna igen** på en attesterad eller skickad dag. Är dagen skickad tas dess tidrapporter bort ur lönen. Det går så länge lönekörningen som betalar dem inte är attesterad. Är den attesterad kan dagen inte öppnas igen; lägg i stället en rättelse i nästa lönekörning.

## Vanliga frågor

**Var är Föreslå tidrapport i personalliggaren?**
När Planering är på ersätter **Tid att attestera** förslagen i personalliggaren, så att tiden bara har en väg till lönen. Förslag som redan finns kan fortfarande godkännas eller avslås.

**Räknas rasten när personen checkar in och ut?**
Personalliggaren har inga raster. När ett pass i liggaren hör till ett planerat pass används den planerade rasten. Arbete utan planerat pass har ingen rast tills du anger den under **Ändra**.

**Kan jag se det här på översikten?**
Ja. Under Att göra står hur många dagar som är kvar att attestera före lönen för månaden.

## Läs också

- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Lönekörning](/manual/lon/lonekorning)
- [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser)
- [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter)
