---
beskrivning: Begränsa en bokförare, attestant eller enhetschef till vissa kostnadsställen och projekt, så att hen bara ser verifikationer, leverantörsfakturor och rapporter för dem.
ordning: 75
roller: [owner, admin]
sokord: [behörighet, begränsa, enhet, enhetschef, kostnadsställe, projekt, butikschef, avdelning, filial, platschef, åtkomst]
---

# Behörighet per enhet

Ett företag med flera butiker, avdelningar eller projekt vill ofta att den som ansvarar för en enhet bara ser sin egen del av bokföringen. I NONOX är en enhet ett **kostnadsställe** eller ett **projekt**. Under **Behörighet per enhet** begränsar ägaren eller en administratör en person till de kostnadsställen och projekt hen ska nå.

![Behörighet per enhet under företagets inställningar, med en enhetschef som når ett kostnadsställe och ett projekt.](/bilder/manual/installningar/behorighet-per-enhet.webp)

En begränsning gör aldrig att någon får göra mer än rollen tillåter, bara mindre.

## Vem som kan begränsas

- **Bokförare**, **Attestant** och rollerna i modulerna kan begränsas. En begränsad person med en roll i en modul, till exempel Lagerpersonal eller Schemaläggare, når ingenting i modulen. Hen ser bara verifikationer, leverantörsfakturor och rapporter, och bara om rollen redan ger dem.
- **Enhetschef** är en roll som alltid är begränsad. Hen ser rapporterna för sina kostnadsställen och projekt och inget annat. En enhetschef utan kostnadsställen eller projekt når ingenting.
- **Ägaren**, **Administratör**, **Redovisningskonsult** och **Revisor** kan aldrig begränsas. De ansvarar för hela bokföringen, och revisorn har rätt att se allt.

Byter du en begränsad persons roll till Administratör, Redovisningskonsult eller Revisor tas begränsningen bort.

## Vad en begränsad person ser

- **Verifikationer**: bara de där varje rad med kostnadsställe eller projekt hör till personens enheter, och minst en rad gör det. En verifikation som också har en rad på en annan enhet syns inte. Rader utan kostnadsställe och projekt, som bank, moms och leverantörsskuld, avgör inget. En verifikation med en rad på personens enhet visas därför i sin helhet, med alla sina rader.
- **Lönens verifikationer** visas aldrig för en begränsad person, varken i listan, på verifikationens sida eller i rapporterna, inte heller rättelser av dem. Lönekostnaden för en liten enhet kan vara en enda persons lön.
- **Leverantörsfakturor**: på samma sätt, efter fakturans rader. Leverantörsregistret är gemensamt för företaget, så den som registrerar en faktura ser och väljer bland alla leverantörer.
- **Rapporter**: **Resultatrapport** och **Huvudbok**, alltid för ett av personens kostnadsställen eller projekt i taget. Rapporter för hela företaget, som **Saldobalans**, **Balansrapport** och rapporten **Verifikationslista**, visas inte. I huvudboken visas bara radens egen text för en verifikation som personen inte kan öppna, och verifikationens nummer utan länk.
- **Ny verifikation** och **Ny leverantörsfaktura**: varje rad med kostnadsställe eller projekt måste höra till personens enheter, och minst en rad måste ha ett av dem. Kontosaldot för hela företaget visas inte.

Allt annat, till exempel moms, bokslut, SIE-filer, kundfakturor, betalfiler, sökningen, översikten, notiserna och kalenderlänken till Mina datum, är stängt. En sida eller ett underlag utanför personens enheter svarar som om det inte fanns. Det gäller också NONOX API: en API-nyckel som en begränsad person har skapat når ingenting.

> [!NOTE]
> Rapporterna räknar per rad. Har en verifikation en rad på personens kostnadsställe och en på ett annat syns raden i personens resultatrapport, men inte verifikationen som helhet.

## Så här begränsar du en person

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Välj fliken **Behörighet per enhet**.
3. Klicka på **Begränsa** bredvid personen, eller **Ändra** om hen redan är begränsad.
4. Kryssa i kostnadsställena under **Kostnadsställen** och projekten under **Projekt**.
5. Klicka på **Spara behörighet**.

Ändringen gäller direkt. Har personen en sida öppen måste hen ladda om den. Varje ändring sparas i behandlingshistoriken.

## Så här tar du bort en begränsning

Klicka på **Ta bort begränsningen** bredvid personen och bekräfta. Personen når då hela företaget enligt sin roll. För en enhetschef tas kostnadsställena och projekten bort, och hen når ingenting förrän du väljer nya.

## Vanliga frågor

**Kan en butikschef se lönerna i sin butik?**
Nej. Lönens verifikationer visas inte för en begränsad person, inte heller som rader i resultatrapporten eller huvudboken. Lön, schema, lager och de andra modulerna är också stängda för den som är begränsad.

**Vad händer om personen slutar och bjuds in igen?**
Begränsningen hör till medlemskapet. Den som bjuds in igen börjar utan den gamla begränsningen och får en ny om du väljer det.

## Läs också

- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
