---
beskrivning: Se vem som är på plats i varje lokal just nu, och åtgärda glömda utcheckningar, långa pass och utcheckningar utan incheckning.
ordning: 60
roller: [owner, admin, staff_ledger_manager, accountant, auditor]
modul: staff_ledger
rutter: [staff-ledger.overview]
sokord: [på plats, närvarande, avvikelser, glömd utcheckning, långt pass, påminnelse, hashkedja]
---

# Översikten och avvikelser

**Personalliggare → Översikt** är startsidan för personalliggaren. Den visar vem som är incheckad i varje lokal just nu och samlar det som en chef bör se över. Sidan uppdateras av sig själv var femtonde sekund.

![Personalliggarens översikt med lokalen och avvikelserna att se över.](/bilder/manual/personalliggare/oversikten-och-avvikelser.webp)

## Vem är på plats

Varje lokal har ett eget kort med hur många som är på plats och en rad per person: namn, arbetsgivare för inhyrd personal och sedan när personen är incheckad. Saknar lokalen en kopplad kiosk står det **Ingen kiosk är kopplad till lokalen.** med en länk för att koppla en surfplatta.

## Avvikelser

Under **Avvikelser** listar NONOX tre saker:

| Avvikelse | Vad det betyder |
|---|---|
| **Glömda utcheckningar** | Personer som har varit incheckade längre än du har ställt in för påminnelser (förval 12 timmar). |
| **Långa arbetspass** | Avslutade pass den senaste veckan som är lika långa som eller längre än din gräns (förval 12 timmar). |
| **Utcheckning utan incheckning** | Någon har checkat ut den senaste veckan utan att ha checkat in först. |

Inget ändras automatiskt. Du rättar i liggaren, så att det syns vem som rättade och varför. Se [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser).

### Så här rättar du en glömd utcheckning

1. Fråga personen när hen gick.
2. Gå till **Personalliggare → Liggaren** och välj **Registrera manuellt**.
3. Välj personen, lokalen och **Utcheckning**, fyll i rätt **Tid** och skriv en **Orsak**, till exempel *Glömde checka ut vid stängning*.
4. Välj **Spara i liggaren** och bekräfta.

Glömda utcheckningar visas också under **Att göra** på företagets översikt.

## Påminnelser med e-post

NONOX kontrollerar var femtonde minut om någon har varit incheckad längre än gränsen. Då skickas en påminnelse, en gång per incheckning:

- till personen, om du har fyllt i en e-postadress för hen
- till alla i företaget som får hantera personalliggaren, med en lista över alla som fortfarande är incheckade.

Ingen checkas ut automatiskt. Gränsen ändrar du i modulens inställningar, se [Kom igång med personalliggaren](/manual/personalliggare/kom-igang-med-personalliggaren).

## Kontrollen av liggaren

Längst ned på sidan ser du när liggaren senast kontrollerades. Varje post i liggaren har en kontrollsumma som bygger på den förra, en så kallad hashkedja. Varje natt räknar NONOX om hela kedjan. Står det **inga avvikelser** är ingenting ändrat eller borttaget. Visar sidan att avvikelser hittades: kontakta den som driftar NONOX.

## Läs också

- [Liggaren och rättelser](/manual/personalliggare/liggaren-och-rattelser)
- [Kom igång med personalliggaren](/manual/personalliggare/kom-igang-med-personalliggaren)
- [Kontrollbesök](/manual/personalliggare/kontrollbesok)
