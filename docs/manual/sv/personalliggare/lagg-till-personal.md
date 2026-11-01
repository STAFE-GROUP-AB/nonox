---
beskrivning: Lägg till alla som arbetar i lokalen, även inhyrd personal, och ge dem en kod, ett QR-kort och vid behov ett foto för kiosken.
ordning: 30
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.people, staff-ledger.people.card]
sokord: [personal, anställd, inhyrd personal, bemanning, pinkod, kod, QR-kort, foto, chef, samordningsnummer]
---

# Lägg till personal

Alla som arbetar i lokalen ska finnas i personalliggaren, även inhyrd personal och de som bara jobbar extra ibland. Varje person får en egen kod med fyra siffror för kiosken och ett personligt QR-kort. Personalen läggs upp en gång för hela företaget och kan checka in i alla företagets lokaler.

## Så här gör du

1. Gå till **Personalliggare → Personal**.
2. Välj **Lägg till person**.
3. Fyll i **Förnamn**, **Efternamn** och **Person- eller samordningsnummer** med tolv siffror, ÅÅÅÅMMDD-NNNN.
4. Fyll gärna i **E-post (frivilligt)**. Då får personen en påminnelse om hen glömmer att checka ut.
5. Välj **Språk på kiosken**. Kiosken visar personens egna skärmar på det språket.
6. Är personen chef: kryssa i **Chef: kan öppna kontrollbesöksvyn på kiosken med sin kod**.
7. Är personen anställd av ett annat företag: kryssa i **Inhyrd personal (anställd av ett annat företag)** och fyll i **Arbetsgivarens namn** och **Arbetsgivarens organisationsnummer**.
8. Välj **Spara**.
9. Välj **Välj kod** på personens rad, skriv koden som personen har valt och välj **Spara koden**.

> [!IMPORTANT]
> En person syns i namnlistan på kiosken först när hen har en kod. Utan kod kan hen bara checka in med QR-kort eller i mobilen.

## Koden

Koden är fyra siffror som personen väljer själv. Enkla koder som 1234 och 0000 godtas inte. NONOX sparar aldrig själva koden, bara ett kontrollvärde som kiosken kan pröva koden mot. Därför kan ingen, inte heller du, se en persons kod i efterhand. Har någon glömt sin kod väljer du **Ny kod** och skriver in en ny.

Skriver någon fel kod fem gånger i rad låses personen på kiosken i fem minuter.

## QR-kort

Välj **QR-kort** på personens rad. Kortet öppnas i en ny flik och du väljer **Skriv ut**. Personen visar kortet för kioskens kamera för att checka in eller ut, utan att skriva någon kod.

Har kortet kommit bort: välj **Ny QR-kod**. Det gamla kortet slutar då att fungera, och du skriver ut ett nytt.

## Foto på kiosken

Ett foto gör det lättare att hitta sitt namn på kiosken, men det är helt frivilligt.

1. Välj **Lägg till foto** på personens rad.
2. Välj en bild i JPG, PNG eller WebP på högst 5 MB.
3. Kryssa i att personen har samtyckt till att fotot visas på kiosken och vet att samtycket kan återkallas.
4. Välj **Spara fotot**.

Fotot visas bara på kiosken. Vill personen inte längre ha det: välj **Ta bort foto**, så raderas filen direkt.

## Koppla personen till en inloggning eller till lönen

- **Inloggning (för mobil incheckning)**: välj personens användare om hen ska kunna checka in i mobilen eller visa sin QR-kod i mobilen. Användaren ska ha rollen **Personal i personalliggaren**. Se [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning).
- **Anställd i lönen**: visas när lönemodulen är påslagen. Koppla personen till sin anställning, så kan NONOX föreslå tidrapporter från liggaren. Se [Tidrapporter från liggaren](/manual/personalliggare/tidrapporter-fran-liggaren).

## När någon slutar

Välj **Arkivera**. Personen försvinner från kiosken men finns kvar i liggaren. Kryssa i **Visa arkiverade** för att se arkiverade personer, och välj **Återställ** om hen börjar igen.

## Vanliga frågor

**Varför slutar personnumret på XXXX?**
Personnummer visas med de fyra sista siffrorna dolda i listan. Har du behörighet att se dem i sin helhet finns knappen **Visa** i kolumnrubriken. Varje gång någon visar dem sparas det i behandlingshistoriken.

**Kan två personer ha samma personnummer?**
Nej. NONOX säger till om personen redan finns i personalliggaren.

**Kan jag ändra personnumret?**
Ja, under **Ändra**. Lämna fältet tomt för att behålla det sparade numret.

## Läs också

- [Kiosken på surfplattan](/manual/personalliggare/kiosken)
- [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning)
- [Checka in och ut](/manual/personal/checka-in-och-ut) – det din personal läser
