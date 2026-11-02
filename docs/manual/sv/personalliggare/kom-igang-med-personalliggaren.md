---
beskrivning: Slå på personalliggaren, ställ in påminnelser om glömda utcheckningar och se vem i företaget som får göra vad.
ordning: 10
roller: [owner, admin]
modul: staff_ledger
sokord: [slå på personalliggare, aktivera personalliggare, personalansvarig, inställningar personalliggare, påminnelse]
---

# Kom igång med personalliggaren

Personalliggaren är en modul som du slår på för det företag som behöver den. Det tar en minut. Sedan lägger du till lokalen, personalen och en surfplatta som kiosk, i den ordningen.

![Personalliggarens översikt när lokalen och personalen är upplagda.](/bilder/manual/personalliggare/oversikten-och-avvikelser.webp)

## Så här gör du

1. Öppna företagsmenyn högst upp i sidomenyn och välj **Inställningar för företaget**.
2. Välj fliken **Moduler**.
3. Slå på **Personalliggare** med reglaget och bekräfta med **Slå på**.
4. Nu finns **Personalliggare** i sidomenyn. Fortsätt med [Lokaler](/manual/personalliggare/lokaler).

Bara den som får hantera moduler, i praktiken ägaren och administratörer, ser fliken **Moduler**. Läs mer i [Moduler](/manual/installningar/moduler).

## Ställ in påminnelser och gränser

När modulen är på finns knappen **Inställningar** bredvid den under **Moduler**. Där ställer du in tre saker för hela företaget:

| Inställning | Förval | Vad den gör |
|---|---|---|
| **Påminn om glömd utcheckning efter (timmar)** | 12 | När någon har varit incheckad så här länge får personen ett mejl (om e-post finns) och alla som hanterar personalliggaren får en lista. Ingen checkas ut automatiskt. |
| **Långt arbetspass efter (timmar)** | 12 | Pass som är så här långa eller längre visas som avvikelse i översikten. |
| **Tillåten skillnad i kioskens klocka (minuter)** | 5 | Går kioskens klocka fel mer än så markeras posten. Tiden ändras aldrig. |

Värdena kan vara mellan 1 och 48 timmar, och mellan 1 och 120 minuter för klockan. Vilka sätt personalen får checka in på väljer du inte här utan per lokal, under **Personalliggare → Lokaler och kiosker**.

## Vem får göra vad

Personalliggaren har två egna roller som du kan ge när du bjuder in någon: **Personalansvarig** och **Personal i personalliggaren**. Så här fördelas det:

| Roll | Kan |
|---|---|
| Ägare och Administratör | Allt, även inställningarna. |
| Personalansvarig | Lägga till lokaler, personal och kiosker, registrera manuellt, rätta, se fullständiga personnummer och öppna kontrollbesöket. Inte inställningarna. |
| Redovisningskonsult | Se liggaren och öppna kontrollbesöket. |
| Revisor | Se liggaren. |
| Personal i personalliggaren | Bara sin egen sida, **Min incheckning**, med QR-kod och mobil incheckning. |

Övriga roller, till exempel Bokförare och Attestant, ser inte personalliggaren.

> [!IMPORTANT]
> Den som kan öppna kontrollbesöket eller se fullständiga personnummer måste använda en passkey när hen loggar in. Har du inte lagt till någon passkey ännu ber NONOX dig om det nästa gång du loggar in.

## Bra att veta

- **Bara vissa branscher har krav på personalliggare.** NONOX har lokaltyperna Restaurang, Kropps- och skönhetsvård (även frisör), Fordonsservice, Tvätteri och Livsmedels- och tobaksgrossist. Byggbranschen ingår inte än. Är du osäker på om din verksamhet omfattas: fråga din redovisningskonsult eller Skatteverket.
- **Dela lokal med ett annat företag?** Varje företag för sin egen liggare i NONOX, och varje kiosk hör till ett enda företag. Två företag i samma lokal behöver alltså var sin kiosk.
- **Att stänga av modulen tar inte bort något.** Liggaren går fortfarande att visa och exportera vid kontrollbesök. Se [Bevarande och personuppgifter](/manual/personalliggare/bevarande-och-personuppgifter).

## Läs också

- [Lokaler](/manual/personalliggare/lokaler)
- [Lägg till personal](/manual/personalliggare/lagg-till-personal)
- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
