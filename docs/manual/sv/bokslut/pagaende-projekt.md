---
beskrivning: Projekt till fast pris utan arbetsorder som pågår på balansdagen – upparbetat men ej fakturerat och fakturerat i förskott – med förslag till verifikationer som du själv bokför.
ordning: 135
roller: [owner, admin, accountant, bookkeeper]
rutter: [accounting.projectWip]
sokord: [pågående projekt, pågående arbeten, upparbetad intäkt, successiv vinstavräkning, färdigställandegrad, fastprisuppdrag, 1620, 2450, projektredovisning]
---

# Pågående projekt

Pågår ett projekt till fast pris när räkenskapsåret slutar ska bokföringen visa det som är gjort men inte fakturerat, och det som är fakturerat men inte gjort. Under **Pågående projekt** räknar NONOX fram förslaget för projekt som inte har någon arbetsorder. Ingenting bokförs automatiskt.

![Pågående projekt med färdigställandegrad, upparbetad intäkt och förslag till verifikation.](/bilder/manual/bokslut/pagaende-projekt.webp)

Du når sidan från punkten **Pågående projekt är granskade** i [checklistan för bokslutet](/manual/bokslut/checklistan). Projekt som har en arbetsorder som pågår på balansdagen räknas i stället under [Pågående arbeten](/manual/bokslut/pagaende-arbeten), så att inget räknas två gånger. En arbetsorder som skapades efter balansdagen eller avslutades före den räknas inte, och då visas projektet här.

## Så här gör du

1. Välj **Balansdag**.
2. Välj **Metod för fastprisuppdrag** och klicka på **Spara metod**, om den inte redan är vald.
3. Granska varje projekt. Kontraktssumman och **Beräknad total kostnad** hämtas från projektregistret under **Bokföring → Projekt**; ändra dem där om de inte stämmer.
4. Klicka på **Skapa verifikationsutkast**. Två utkast skapas: justeringen på balansdagen och återföringen dagen efter.
5. Granska utkasten och bokför dem, eller ta bort dem.

Förslaget görs bara av de siffror som sidan visade. Har något bokförts på ett projekt sedan dess får du ett meddelande om att granska förslaget igen. Finns justeringen eller återföringen från ett tidigare förslag för samma balansdag kvar, som utkast eller bokförd, får du meddelandet **Det finns redan ett förslag för datumet, bokfört eller som utkast.**

> [!IMPORTANT]
> Varje förslag återförs dagen efter sin balansdag. Gör du förslag för två balansdagar i samma år, till exempel ett vid ett delårsbokslut och ett vid årets slut, måste du bokföra båda återföringarna. Annars ligger det första förslaget kvar i bokföringen och räknas två gånger.

## Vad förslaget bygger på

- **Kostnader på projektet**: allt som är bokfört på projektet på konto 4000–7999 till och med balansdagen.
- **Fakturerat**: kundfakturor med projektet på raderna, med fakturadatum till och med balansdagen. Kreditfakturor dras av.
- **Färdigställandegrad**: kostnaderna delade med den beräknade totala kostnaden.
- **Metoden** gäller alla fastprisuppdrag i företaget, både projekt och arbetsorder:
  - **K2 huvudregeln (successiv vinstavräkning)** och **K3 successiv vinstavräkning**: den upparbetade intäkten är färdigställandegraden gånger kontraktssumman. Skillnaden mot det fakturerade föreslås på 1620 Upparbetad men ej fakturerad intäkt eller 2450 Fakturerad men ej upparbetad intäkt.
  - **K2 alternativregeln** och **K3 färdigställandemetoden**: det fakturerade flyttas till 2450 tills arbetet är klart.
  - Med **Inte valt** visas bara siffrorna.
- Projekt på **löpande räkning** utan arbetsorder får inget förslag. Sidan visar **Kräver arbetsorder för upparbetat värde**, eftersom NONOX inte har något registrerat arbete att räkna fram värdet ur.
- För K1 visas bara siffrorna.
- Är den beräknade totala kostnaden högre än kontraktssumman varnar sidan för en befarad förlust. Förslaget tar inte med den.

> [!WARNING]
> Kontona och vilka förslag som gäller för varje regelverk är inte slutligt kontrollerade av en revisor. Granska förslaget med din redovisningskonsult.

Byter du metod efter ett stängt räkenskapsår varnar NONOX: ett byte kräver särskilda skäl och ska förklaras i årsredovisningen.

## Vem kan göra vad

Sidan och **Skapa verifikationsutkast** kräver att du får se projekt och skapa verifikationer. Metoden väljs av den som hanterar räkenskapsåren: **Ägare**, **Administratör** och **Redovisningskonsult**. En medlem som är begränsad till vissa kostnadsställen eller projekt når inte sidan.
