---
beskrivning: Varje natt räknar NONOX om hela bokföringen och kontrollerar att inget har ändrats i efterhand. Så läser du resultatet och kör kontrollen själv.
ordning: 150
sokord: [kontrollsumma, integritet, hash, kedja, manipulering, ändrad i efterhand, revision, verifiera bokföringen, intakt]
---

# Kontroll av bokföringen

En bokförd verifikation kan inte ändras i NONOX. Men hur vet du att ingen har ändrat något ändå, till exempel direkt i databasen? Varje natt räknar NONOX om hela bokföringen och kontrollerar att allt är precis som när det bokfördes. Resultatet står överst under **Bokföring → Verifikationer**, och du kan köra kontrollen själv när du vill.

![Resultatet av den senaste kontrollen står överst bland verifikationerna.](/bilder/manual/bokforing/kontroll-av-bokforingen.webp)

## Så läser du resultatet

Överst på sidan **Verifikationer** står ett av tre besked:

- **✓ Bokföringen är intakt.** – med när den senast kontrollerades och **Inget har ändrats i efterhand.** Så ska det se ut.
- **Kontrollen hittade avvikelser** – följt av en lista med vad som inte stämmer, verifikation för verifikation.
- **Bokföringen har inte kontrollerats ännu.** – företaget är nytt och den första nattliga kontrollen har inte körts.

## Så här kör du kontrollen nu

1. Gå till **Bokföring → Verifikationer**.
2. Välj **Kontrollera nu** i rutan överst.

Efter en stund får du beskedet **Bokföringen är kontrollerad: … verifikationer, allt stämmer.**, eller att kontrollen hittade avvikelser. Kontrollen räknar om hela bokföringen och kan därför bara köras några gånger i minuten. Kör du den för ofta står det **Kontrollen kördes nyss. Vänta en minut och försök igen.**

## Vad som kontrolleras

För varje räkenskapsår och varje bokförd verifikation:

- **Kontrollsummorna.** När en verifikation bokförs räknas en kontrollsumma ut av dess innehåll tillsammans med den förra verifikationens kontrollsumma. Verifikationerna bildar på så sätt en kedja. Ändras ett enda öre, ett datum eller en text i efterhand stämmer inte summan längre, och inte heller någon av dem som kommer efter.
- **Att varje verifikation går ihop** – debet lika med kredit, och minst två rader.
- **Att numreringen är obruten** – från 1 och uppåt i varje serie och år, utan hål.
- **Att underlaget är orört** – varje fil finns kvar och har samma kontrollsumma som när den sparades.

Varje verifikations kontrollsumma står längst ned på dess sida som **Kontrollsumma**, och varje underlags under förhandsvisningen.

Varje kontroll, både den nattliga och de du kör själv, skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

## Om kontrollen hittar avvikelser

En avvikelse ska inte kunna uppstå genom att man använder NONOX, eftersom inget bokfört går att ändra i appen. Den tyder på att något har ändrats utanför programmet, eller på ett tekniskt fel.

1. Ändra ingenting och bokför inte mer på det som berörs.
2. Notera vad listan säger.
3. Kontakta den som driftar er NONOX – er egen tekniker om ni kör NONOX själva. Berätta också för din redovisningskonsult eller revisor.

> [!IMPORTANT]
> Kontrollen visar *att* något har ändrats, inte vad det var från början. Därför är det viktigt att spara [arkivpaketet](/manual/bokslut/arkivpaket) för varje avslutat år på ett säkert ställe – det är en kopia som inte påverkas av vad som händer i databasen senare.

## Vanliga frågor

**Vem kan se kontrollen?**
Alla som kan se verifikationerna, även revisorer och attestanter. Alla dem kan också köra den.

**Gör kontrollen något med bokföringen?**
Nej. Den läser bara och ändrar ingenting.

## Läs också

- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Behandlingshistorik](/manual/bokforing/behandlingshistorik)
- [Arkivpaket](/manual/bokslut/arkivpaket)
- [Säkerhet och regelverk](/sakerhet)
