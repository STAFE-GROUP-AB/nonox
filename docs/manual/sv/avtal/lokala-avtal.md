---
beskrivning: Lägg in företagets lokala avtal som avvikelser från branschavtalet, för hand eller med NONOX AI, och kontrollera dem innan lönen använder dem.
ordning: 58
roller: [owner, payroll_admin]
modul: agreements
sokord: [lokalt avtal, lokal avvikelse, lokal överenskommelse, avvikelse från avtal, högre OB, beräkningsperiod, komptid lokalt, NONOX AI lokalt avtal]
---

# Lokala avtal

Ett lokalt avtal mellan företaget och facket kan ersätta delar av branschavtalet, till exempel en högre OB-sats eller en annan provision. I NONOX sparas sådana avvikelser för företagets avtal. Branschavtalets värden ändras aldrig.

## Lägg in en avvikelse för hand

1. Gå till **Lön → Avtal → Lokala avtal**.
2. Under **Lägg till avvikelse**: välj **Avtal** och **Vad avviker**.
3. Skriv **Värde enligt lokalt avtal**: kronor (till exempel 30), procent (35 %) eller delare (/600), som det lokala avtalet säger.
4. Skriv sidan i det lokala avtalet och en kort beskrivning.
5. Klicka på **Spara avvikelse**.

## Låt NONOX AI läsa det lokala avtalet

1. Gå till **Lön → Avtal → NONOX AI**.
2. Ladda upp det lokala avtalet med dokumenttypen **Lokalt avtal**.
3. Klicka på **Låt NONOX AI läsa**.
4. Granska varje förslag under **Förslag att granska**. Förslaget visar avvikelsen, citatet och sidan. Godkänn, ändra eller avvisa.

NONOX AI får bara läsa texten, med personnummer och andra personuppgifter dolda. Branschavtalets egna belopp skickas inte till AI:n.

## Kontrollera avvikelsen

En avvikelse används i lönekörningen först när en person med rätt att kontrollera avtal har jämfört den med det lokala avtalet.

1. Gå till **Lön → Avtal → Lokala avtal**.
2. Klicka på **Kontrollera** vid avvikelsen och bekräfta.

Från nästa beräkning använder lönen avvikelsen. Lönekörningen visar **Värdet kommer från företagets lokala avtal** under raden. Ändrar du en kontrollerad avvikelse måste den kontrolleras igen.

## Vad kan avvika

| Avvikelse | Räknas av NONOX |
|---|---|
| Sats för ett OB-, övertids- eller tilläggsslag, kostavdrag eller provision | ja |
| Garanti för provision | ja |
| Semesterlön i procent och semestertillägg per dag | ja |
| Beräkningsperiod för arbetstid, komptid per övertidstimme | sparas, räknas inte ännu |
| Annan avvikelse (fri text) | sparas, räknas inte |

Allt som sparas och kontrolleras står i behandlingshistoriken.

## Läs också

- [NONOX AI fyller i avtalet](/manual/avtal/nonox-ai)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Semester enligt avtalet](/manual/avtal/semester-enligt-avtal)
- [Versioner och kontroll av avtalets värden](/manual/avtal/versioner-och-kontroll)
