---
beskrivning: Slå på och av moduler som Lön, Personalliggare, Inkorg, Bank, NONOX AI och NONOX Connect per företag – vad varje modul gör och vad som händer när du stänger av den.
ordning: 20
roller: [owner, admin]
sokord: [modul, tillägg, slå på, aktivera, stäng av, inaktivera, lön, personalliggare, inkorg, bank, ai, api, avtal, kollektivavtal]
---

# Moduler

Bokföring, fakturering, leverantörsfakturor, moms, rapporter och bokslut finns alltid i NONOX. Det som bara vissa företag behöver är **moduler**, som varje företag slår på när det behöver dem. En modul som är avstängd syns inte i menyn och påverkar inget.

Att stänga av en modul tar aldrig bort några uppgifter. De finns kvar så länge bokföringslagen kräver, och visas skrivskyddat för dem som har behörighet.

## Modulerna

| Modul | Vad den gör | Läs mer |
|---|---|---|
| **Lön** | Löner, lönebesked, semester, sjuklön, förmåner, utlägg och arbetsgivardeklaration. Ger de anställda Min sida. | [Lön](/manual/lon) |
| **Avtal** | Kollektivavtalets regler i lönen: minimilöner, OB och övertid enligt avtalet. Kräver att **Lön** är påslagen. | [Kollektivavtal](/manual/avtal) |
| **Personalliggare** | Elektronisk personalliggare med incheckning på en surfplatta, även utan internet, och en vy för Skatteverkets kontrollbesök. | [Personalliggare](/manual/personalliggare) |
| **Inkorg** | En egen e-postadress dit leverantörer, kassasystem och personal skickar fakturor, kvitton och dagsrapporter. Varje underlag blir ett förslag som någon godkänner. | [Inkorgen](/manual/leverantorer/inkorgen) |
| **Bank** | Kopplar företagets bankkonton med BankID och hämtar transaktioner och saldon automatiskt. NONOX kan bara läsa, aldrig betala. | [Bankmodulen](/manual/installningar/bankmodulen) |
| **NONOX AI** | Läser leverantörsfakturor, kvitton och andra underlag och fyller i ett förslag. Du kontrollerar och sparar. | [NONOX AI](/manual/installningar/nonox-ai) |
| **NONOX Connect** | Ett öppet API för andra program: anslutna appar, API-nycklar och webhooks. | [NONOX Connect](/manual/installningar/nonox-connect) |

Vilka moduler som finns kan skilja sig mellan installationer. Kör ditt företag NONOX själv kan vissa moduler kräva inställningar på servern innan de går att slå på.

## Så här slår du på en modul

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Öppna fliken **Moduler**.
3. Klicka på reglaget vid modulen. NONOX berättar vad som händer.
4. Klicka på **Slå på**.

Modulen är **Aktiv** direkt, och dess menyval och funktioner visas för dem som har behörighet. Har modulen egna inställningar finns knappen **Inställningar** på kortet.

Kan modulen inte slås på än står det varför under **Modulen kan inte slås på än:**, till exempel att en annan modul måste slås på först, eller att installationen saknar något som den som driftar NONOX behöver ordna.

## Så här stänger du av en modul

1. Öppna fliken **Moduler** på samma sätt.
2. Klicka på reglaget vid modulen.
3. Läs vad som händer. Finns det något du bör veta, till exempel att en inkorgsadress slutar ta emot e-post eller att anslutna appar kopplas bort, står det i en lista.
4. Skriv modulens namn i fältet för att bekräfta, om NONOX ber om det.
5. Klicka på **Stäng av**.

Modulen blir **Inaktiv**. Uppgifterna finns kvar och kan läsas av dem som har behörighet; lönen visas till exempel som **Lönearkiv** i menyn.

> [!IMPORTANT]
> Vissa moduler kan inte stängas av förrän något är avslutat. **Lön** kan till exempel inte stängas av medan det finns en lönekörning som inte är bokförd, en arbetsgivardeklaration som inte är inlämnad eller ett godkänt utlägg som inte är utbetalt. **Lön** kan inte heller stängas av medan **Avtal** är påslagen. NONOX visar vad som behöver göras först under **Modulen kan inte stängas av än:**.

## Inställningar i modulerna

Klicka på **Inställningar** på en påslagen modul för att ändra hur den fungerar. Några exempel:

- **Lön:** löneperiod, semesterregel, betalsätt för lönen, hur lönebesked lämnas och att den som beräknar lönen inte får attestera den.
- **Personalliggare:** när en glömd utcheckning ska påminnas, vad som räknas som ett långt arbetspass och hur mycket kioskens klocka får avvika.
- **Inkorg:** företagets adress, godkända avsändare, sparade mallar och e-post när något behöver granskas.
- **Bank:** hur många gånger per dag transaktioner hämtas och påminnelse innan ett bankmedgivande går ut.
- **NONOX AI:** AI-leverantör, modell och API-nyckel.
- **NONOX Connect:** vilka appar som får be om åtkomst och vilka IP-adresser API:t tar emot anrop från.
- **Avtal:** varning när en lön är under avtalets minimilön.

Varje modul beskrivs i detalj i sitt eget avsnitt i manualen.

## Bra att veta

- Bara ägare och administratörer kan slå på och av moduler.
- Varje gång en modul slås på eller av sparas det i behandlingshistoriken.
- Modulernas egna roller, till exempel **Löneadministratör** och **Personalansvarig**, går att välja först när modulen är på. Se [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter#roller-i-moduler).
- Slår du på en modul igen finns allt kvar som det var, till exempel lönens anställda eller AI:ns sparade nycklar.

## Läs också

- [NONOX AI](/manual/installningar/nonox-ai)
- [NONOX Connect](/manual/installningar/nonox-connect)
- [Bankmodulen](/manual/installningar/bankmodulen)
- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
