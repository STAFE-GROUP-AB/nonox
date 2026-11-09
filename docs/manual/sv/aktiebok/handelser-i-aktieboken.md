---
beskrivning: Bildande, aktieboken som den ser ut i dag, överlåtelse, nyemission, split, sammanläggning, anteckningar och rättelser – en händelse i taget.
ordning: 20
roller: [owner, admin, accountant]
modul: boardroom
rutter: [share-register.events]
sokord: [överlåtelse, aktieöverlåtelse, nyemission, fondemission, split, sammanläggning, indragning, aktiebrev, förköp, rättelse, bildande]
---

# Händelser i aktieboken

Allt som ändrar aktieboken förs in som en händelse under **Aktiebok → Händelser**. En händelse kan inte ändras eller tas bort när den är införd. NONOX kontrollerar varje händelse mot hela historiken: ingen aktie kan ägas av två personer samtidigt, och ingen aktie kan finnas utanför de utgivna.

![Händelserna i aktieboken: bildandet, en nyemission och en överlåtelse.](/bilder/manual/aktiebok/handelser.webp)

## Så här startar du aktieboken

- **Nytt bolag:** välj **Nytt bolag: registrera bildandet** på **Aktiebok**. Som datum anger du dagen då alla stiftare skrev under stiftelseurkunden. Gör en rad per ägare med aktienumren och aktiekapitalet för aktierna.
- **Bolag som redan finns:** välj **Befintligt bolag: för in aktieboken** och för in aktieboken som den ser ut i dag, en rad per ägare och nummerserie.

> [!IMPORTANT]
> Spara den gamla aktieboken i minst tio år efter att alla aktier har förts in i NONOX (aktiebolagslagen 5 kap. 3 §).

## Så här för du in en överlåtelse

1. Välj **Ny händelse** och **Överlåtelse** under **Händelse**.
2. Fyll i **Gäller från**, **Från aktie nummer** och **Till aktie nummer**.
3. Välj **Från ägare** och **Till ägare**. Fyll gärna i **Pris eller teckningsbelopp (kr)** och **Underlag**.
4. Välj **För in i aktieboken** och bekräfta.

Säljaren måste äga alla aktier i nummerserien. Har aktierna en anteckning om rättighetshavare avslutar du den först med **Anteckning avslutad**.

## Andra händelser

| Händelse | Vad du fyller i |
|---|---|
| **Nyemission** | Nummerserie, ägare och aktiekapital. Aktierna förs in direkt men visas som *Nyemission, ej registrerad* tills du för in **Nyemission registrerad**. Faller emissionen för du in **Nyemission har fallit**. |
| **Fondemission**, **Split**, **Sammanläggning**, **Omvandling av aktieslag** | **Registrerad hos Bolagsverket**. Ändringen gäller från registreringen. Vid sammanläggning övergår överskjutande aktier till bolaget. Går de inte jämnt upp i nya aktier beskriver du under **Hur överskjutande aktier hanteras** vad som görs med resten, till exempel inlösen. |
| **Indragning** | Nummerserien och minskningen av aktiekapitalet som ett negativt belopp. |
| **Aktiebrev** | Om aktiebrev är utfärdat för nummerserien. |
| **Rättighetshavare**, **Anmälan om förköp**, **Begäran om förköp**, **Talan om förköp** | En anteckning som visas i aktieboken tills den avslutas med **Anteckning avslutad**. |

## Så här rättar du en händelse

1. Välj **Rätta** på händelsen i **Historik**.
2. Skriv **Skäl**, till exempel *Fel aktienummer*.
3. Välj **För in i aktieboken** och bekräfta.
4. För sedan in den rätta händelsen.

Rättelsen gör den gamla händelsen ogiltig, men den finns kvar och visas överstruken i historiken. En rättelse som skulle göra en senare händelse omöjlig godtas inte, och NONOX prövar aktieboken vid varje datum den ändras, så en händelse som skulle göra aktieboken omöjlig en tidigare dag godtas inte heller. Datum för beslut och registrering kan inte ligga i framtiden.
