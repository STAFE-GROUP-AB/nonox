# Tillgänglighetsredogörelse för NONOX

Senast uppdaterad 2026-11-01.

Redogörelsen gäller tjänsten NONOX på nonox.app och programmet NONOX som kan köras på egen server. Tjänsten utvecklas av Stafe Development AB.

## Vårt mål

NONOX ska uppfylla **WCAG 2.2 på nivå AA** (AGENTS.md §11.1). Det betyder att tjänsten ska gå att använda helt med tangentbord, fungera med skärmläsare och förstoring, ha tillräckliga kontraster i ljust och mörkt läge och inte förmedla information enbart med färg. Ett exempel är att negativa belopp visas både i en egen färg och med minustecken.

## Hur tillgänglig är tjänsten?

**Vi vet inte ännu.** Ingen granskning mot WCAG har gjorts, varken av oss eller av någon utomstående. Vi kan därför inte säga att tjänsten uppfyller kraven, och vi kan inte räkna upp kända brister.

Vi har byggt med tillgänglighet som mål:
- Konteringen av verifikationer och arbetet i inkorgen går att göra med tangentbordet. Kortkommandon visas med `?`.
- Gränssnittet har ljust och mörkt läge.
- Varje oåterkallelig åtgärd har en bekräftelse med en förklaring på svenska.

Det är ingen granskning.

Delar där vi särskilt behöver granska tillgängligheten:
- Kiosken för personalliggaren, som är gjord för surfplattor och pekskärm.
- PDF-filer som tjänsten skapar: fakturor, lönebesked, rapporter och årsredovisning.
- Tabeller med många kolumner i rapporterna.
- Dra-och-släpp för underlag.

## Planerat

| Åtgärd | Status |
|---|---|
| Automatisk kontroll av tillgänglighet i testerna | Inte påbörjad |
| Manuell granskning mot WCAG 2.2 AA av de viktigaste flödena: logga in, kontera och bokföra, fakturera, lön och Min sida, personalliggarens kiosk | Inte påbörjad |
| Ny redogörelse med kända brister efter granskningen | Inte påbörjad |

## Lagkrav

NONOX säljs till företag. Vi bedömer att lagen (2023:254) om vissa produkters och tjänsters tillgänglighet troligen inte gäller tjänsten, eftersom den gäller vissa tjänster till konsumenter. Bedömningen är inte granskad, se [regelverk](juridik/regelverk.md). Vi strävar ändå efter samma nivå, eftersom anställda med olika behov använder Min sida och kiosken.

## Rapportera problem

Om något inte fungerar för dig, till exempel med skärmläsare, tangentbord eller förstoring, vill vi veta det. Beskriv vilken sida eller funktion det gäller, vad som hände och vilket hjälpmedel och vilken webbläsare du använder.

- **Den driftade tjänsten:** kontakta oss via kontaktuppgifterna på nonox.app.
- **Programmet:** skapa ett ärende på GitHub i projektets repository.

TODO: en fast kontaktadress för tillgänglighet och en tid inom vilken vi svarar.
