---
beskrivning: Skriv protokollet, markera det som justerat och godkänn det i NONOX, så låses det som PDF med kontrollsumma. Ett fel rättas med ett nytt protokoll.
ordning: 50
roller: [owner, admin, accountant]
modul: boardroom
rutter: [boardroom.meetings.show, boardroom.minutes.pdf]
sokord: [protokoll, justera protokoll, justerare, styrelseprotokoll, stämmoprotokoll, reservation, signera protokoll]
---

# Protokoll

Protokollet skrivs på mötets sida i **Styrelserum**, under **Protokoll**. Det går igenom tre steg: utkast, justerat och godkänt i NONOX.

## Så här skriver du protokollet

1. Välj **Skriv protokoll**. Utkastet får mötets ärenden med samma numrering som dagordningen.
2. Skriv beslutet under varje ärende. I ett styrelseprotokoll kan du också skriva en **Reservation**.
3. Välj **Spara utkastet**.

## Så här justerar du protokollet

1. Fyll i **Ordförande**, **Protokollförare** och **Justerare**.
2. Välj **Markera som justerat**.

Ett stämmoprotokoll justeras av ordföranden och minst en justerare som stämman utsett, om inte ordföranden och protokollföraren ensamma företräder alla aktier. Ett styrelseprotokoll justeras av ordföranden och, om styrelsen har flera ledamöter, av en ledamot som styrelsen utsett. NONOX kontrollerar det från aktieboken och de närvarande.

## Så här godkänner du protokollet

Välj **Godkänn i NONOX** och bekräfta. Protokollet låses och sparas som PDF med en kontrollsumma (SHA-256). Styrelseprotokoll får ett nummer i ordning när de godkänns. Öppna PDF:en med **Öppna protokollet**.

> [!IMPORTANT]
> Godkänt i NONOX visar vem som godkände protokollet och när. Det är inte en underskrift. Skriv under protokollet för hand eller med en elektronisk underskrift när lagen kräver det.

## Så här rättar du ett godkänt protokoll

Ett godkänt protokoll kan inte ändras. Välj **Rätta protokollet** och bekräfta: ett nytt protokoll skapas som pekar på det godkända. Skriv rättelsen, justera och godkänn det på samma sätt. Det första protokollet finns kvar.

Godkända protokoll från årets möten och årsstämman följer med i arkivpaketet.
