---
beskrivning: NONOX Signering – skicka egna PDF-dokument för signering med flera undertecknare, kontroll av undertecknaren och påminnelser, och se alla signeringar på ett ställe.
ordning: 0
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: signing
sokord: [signering, signera dokument, e-signering, anställningsavtal, hyresavtal, sekretessavtal, pdf, elektronisk underskrift, konfidentiell, flera undertecknare]
---

# Signering

Med modulen Signering skickar du dina egna PDF-dokument för signering, till exempel ett anställningsavtal, ett hyresavtal eller ett sekretessavtal. Du bestämmer vilka som ska skriva under, i vilken ordning och hur du vill kontrollera att det är rätt person. Under **Signering** ser du också allt annat som har skickats för signering i företaget: offerter, avtal och ÄTA.

Själva signeringen, med flera undertecknare, kontroll av undertecknaren, påminnelser och bevis, finns i NONOX även utan modulen och fungerar för offerter, avtal och ÄTA. Modulen lägger till egna dokument, listan över alla signeringar, sidan för varje signering och guiden för att skicka. Den fungerar med eller utan CRM.

Signeringen är en **enkel elektronisk underskrift**: den är ett bevis på att någon som hade länken från mejlet, och klarade den kontroll du valde, skrev under just det dokumentet vid en viss tid. Den är inte BankID och identifierar inte personen. Vissa avtal måste skrivas under på papper eller på annat sätt som lagen kräver, till exempel köp och gåva av fast egendom, testamente, äktenskapsförord och framtidsfullmakt; sådana avtal signeras inte här. TODO(legal-verify): kontrollera listan över avtal med formkrav. Är du osäker, fråga din jurist.

## Kom igång

1. Slå på modulen under **Inställningar för företaget → Moduler**. Se [Moduler](/manual/installningar/moduler).
2. Öppna **Signering → Skicka dokument** och ladda upp en PDF. Se [Skicka ett dokument för signering](/manual/signering/skicka-dokument).
3. Följ signeringen under **Signering → Alla signeringar**. Se [Alla signeringar](/manual/signering/alla-signeringar).

## Vem får göra vad

| Behörighet | Vad den ger |
|---|---|
| Se signeringar | Listan, sidan för varje signering, filerna och signeringsbevisen |
| Skicka för signering | Ladda upp, skicka, påminna, ändra sista dag och dra tillbaka sina egna signeringar |
| Hantera signering | Det samma för allas signeringar, och att se konfidentiella signeringar |

- Ägare och administratörer får allt, och så redovisningskonsulten.
- Bokförare och säljare kan se och skicka, men bara röra sina egna signeringar.
- Revisorn kan bara se.
- Attestanten får ingenting av detta.

Offerter, avtal och ÄTA i listan syns bara för den som får se dem: offerter för den som får se fakturering, avtal för den som får se CRM, och ÄTA för den som får se arbetsordrar. Avtal och ÄTA syns bara medan CRM respektive Arbetsorder är påslagna.

## Är modulen avstängd

Då kan inga nya dokument skickas, och öppna länkar för egna dokument slutar fungera. Signerade egna dokument, med signeringsbevis, går fortfarande att läsa med behörigheten att se signeringar, under **Signering → Signeringsarkiv**. Signeringen av offerter, avtal och ÄTA påverkas inte.

## Läs också

- [Skicka ett dokument för signering](/manual/signering/skicka-dokument)
- [Alla signeringar](/manual/signering/alla-signeringar)
- [Bevarande och radering av signeringar](/manual/signering/bevarande-och-radering)
- [Signera offerter och avtal](/manual/crm/signering)
