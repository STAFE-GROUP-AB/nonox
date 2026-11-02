---
beskrivning: Övertid och mertid som ledighet i stället för pengar – välj per tillfälle, se saldot, registrera uttag och utbetalning, och hur det syns på lönespecen.
ordning: 85
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.compTime]
sokord: [komptid, kompledighet, kompensationsledighet, övertid som ledighet, mertid som ledighet, komptidssaldo, saldo, uttag, utbetalning komptid, timbank]
---

# Komptid

Komptid är övertid eller mertid som den anställda tar ut som ledighet i stället för pengar. I NONOX tjänas komptiden in när du attesterar tid i [Planering](/manual/planering), och saldot förs i lönen.

## Så tjänas komptid in

Komptid kan tjänas in för månadsavlönade, för övertid och mertid som Planering räknar per vecka eller per beräkningsperiod. Se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen).

1. Attestera alla dagar i veckan eller perioden under **Planering → Tid att attestera**.
2. På periodens sista dag visas rutan **Ersättning för övertid och mertid**. Välj **Betala ut** eller **Komptid**.
3. Klicka på **Skicka till lönen**. Med komptid blir tiden ett saldo i stället för en tidrapport.

Valet gäller just det tillfället. Från början är valet det som står på anställningen under **Lön → Avtal**, i fältet **Övertid**: **Tas ut som komptid** ger komptid, annars betalas tiden ut.

### Hur många timmar komptid

Varje övertidstimme ger så många timmar ledighet som avtalet anger för lönearten. I Gröna Riks ger till exempel en timme övertid i anslutning till ordinarie tid 1,5 timmar komptid. Bredvid valet ser du minuterna, faktorn och källan i avtalet.

Har avtalet ingen faktor för lönearten, eller har ni inget avtal i NONOX, används företagets egen faktor under **Planering → Inställningar → Komptid per timme övertid eller mertid**. 1 betyder timme mot timme.

> [!IMPORTANT]
> I de flesta avtal kräver komptid att arbetsgivaren och den anställda är överens. Avtalens faktorer finns i NONOX som data med källa och är okontrollerade tills någon har jämfört dem med avtalet. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

## Saldot

Under **Lön → Komptid** ser du saldot för alla som har komptid. Klicka på **Visa rader** för att se vad saldot består av: intjänad tid, uttag, utbetalningar och justeringar.

Saldot syns också:

- på lönespecen, under **Komptid**, per sista dagen i löneperioden,
- för den anställda på **Min sida → Lönebesked**.

## Uttag, utbetalning och justering

Under **Registrera** på **Lön → Komptid** väljer du anställd, typ, datum och timmar.

| Typ | Vad händer |
|---|---|
| Uttag som ledighet | Saldot minskar. Månadsavlönade får sin vanliga lön för dagen. Timavlönade får timmarna betalda på lönearten **Komptid**. |
| Utbetalning | Saldot minskar och timmarna betalas på lönearten **Komptid** i lönekörningen för datumets månad. |
| Justering | Saldot ändras med timmarna, plus eller minus. Använd det för ett ingående saldo när ni börjar med NONOX, eller för att rätta. |

Timvärdet är månadslönen gånger 12, delat med 52 veckor och veckoarbetstiden, eller timlönen.

- Saldot kan inte bli negativt. NONOX säger hur mycket som finns kvar.
- Ett datum i en månad där lönen redan är attesterad går inte att välja.
- En beräknad lönekörning för månaden går tillbaka till utkast, så att du räknar om den.

## Ta bort och ändra

En rad du har registrerat kan tas bort med **Ta bort** så länge månadens lön inte är attesterad.

Intjänad komptid tar du bort genom att öppna dagen igen under **Planering → Tid att attestera**. Det går inte om komptiden redan är uttagen eller utbetald. Registrera då en justering i stället.

## Bra att veta

- Ska outtagen komptid betalas ut när anställningen slutar, registrera en utbetalning före slutlönen. NONOX gör det inte av sig själv.
- Komptid för timavlönades övertid räknas inte ännu. Deras tid betalas som timlön.

## Läs också

- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Rullande schema och beräkningsperiod](/manual/planering/rullande-schema)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Min sida för anställda](/manual/lon/min-sida-for-anstallda)
