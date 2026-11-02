---
beskrivning: Låt NONOX räkna timmar per dag från personalliggarens avslutade pass och föra över dem till lönen som tidrapporter, efter att du godkänt.
ordning: 90
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.proposals]
sokord: [tidrapport, timmar, timlön, närvarotid, från liggaren till lön, arbetade timmar]
---

# Tidrapporter från liggaren

Har företaget även lönemodulen kan NONOX föreslå tidrapporter från personalliggaren. NONOX räknar timmar per dag från avslutade arbetspass, och du godkänner förslaget innan något förs över till lönen. Liggaren och tidrapporterna hålls isär: att godkänna ett förslag ändrar ingenting i liggaren.

Sidan **Tidrapporter** finns under **Personalliggare** när lönemodulen är påslagen och du får hantera personalliggaren.

> [!NOTE]
> Är modulen Planering på går det inte att föreslå tidrapporter här. Tiden jämförs då med schemat och attesteras under **Planering → Tid att attestera**, så att ingen dag rapporteras två gånger. Förslag som redan finns kan fortfarande godkännas eller avslås. Se [Tid att attestera](/manual/planering/tid-att-attestera).

![Föreslå tidrapport från liggarens pass, och godkänn förslagen.](/bilder/manual/lon/timlon-och-tidrapporter.webp)

## Innan du börjar

- Personen ska vara kopplad till sin anställning. Gå till **Personalliggare → Personal**, välj **Ändra** och välj under **Anställd i lönen**.
- Lönen ska ha lönearten **Timlön**.
- Rätta glömda utcheckningar först. Pass utan utcheckning tas inte med.

## Så här gör du

1. Gå till **Personalliggare → Tidrapporter**.
2. Välj period med **Från** och **Till**, högst två månader.
3. Välj **Föreslå**.
4. Under **Förslag** visas ett förslag per person, med antal timmar och dagar.
5. Välj **Godkänn** och bekräfta, så skrivs tidrapporterna till lönen. Eller välj **Avslå**.

Godkända tidrapporter används i nästa lönekörning för den som har timlön. Läs mer i [Lönekörning](/manual/lon/lonekorning).

## Vanliga frågor

**Ett pass saknas i förslaget.**
Passet har troligen ingen utcheckning, eller så är personen inte kopplad till en anställd. Rätta passet i liggaren, avslå förslaget och föreslå perioden igen.

**NONOX säger att några dagar redan finns i en annan tidrapport.**
En dag kan bara finnas i en godkänd tidrapport. Avslå förslaget och föreslå en period som inte överlappar.

**Räknas OB och övertid?**
Nej. Förslaget innehåller arbetade timmar per dag med lönearten Timlön. Med modulen Planering räknas OB enligt avtalet när tiden attesteras. Se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen).

## Läs också

- [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser)
- [Anställda](/manual/lon/anstallda)
- [Lönekörning](/manual/lon/lonekorning)
