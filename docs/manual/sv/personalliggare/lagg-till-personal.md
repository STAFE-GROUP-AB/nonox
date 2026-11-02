---
beskrivning: Lägg till alla som arbetar i lokalen, även inhyrd personal, och ge dem en kod, ett QR-kort och vid behov ett foto för kiosken.
ordning: 30
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.people, staff-ledger.people.card]
sokord: [personal, anställd, inhyrd personal, bemanning, pinkod, kod, QR-kort, foto, chef, samordningsnummer, utländskt ID-nummer, utländsk arbetsgivare]
---

# Lägg till personal

Alla som arbetar i lokalen ska finnas i personalliggaren, även inhyrd personal och de som bara jobbar extra ibland. Varje person får en egen kod med fyra siffror för kiosken och ett personligt QR-kort. Personalen läggs upp en gång för hela företaget och kan checka in i alla företagets lokaler.

![Personalen i personalliggaren med kod, QR-kort och foto för kiosken.](/bilder/manual/personalliggare/lagg-till-personal.webp)

## Så här gör du

1. Gå till **Personalliggare → Personal**.
2. Välj **Lägg till person**.
3. Fyll i **Förnamn** och **Efternamn**.
4. Under **Identitet** är **Svenskt person- eller samordningsnummer** förvalt. Fyll i **Person- eller samordningsnummer** med tolv siffror, ÅÅÅÅMMDD-NNNN. Saknar personen svenskt nummer: se [Utländskt ID-nummer](#utlandskt-id-nummer) nedan.
5. Fyll gärna i **E-post (frivilligt)**. Då får personen en påminnelse om hen glömmer att checka ut.
6. Välj **Språk på kiosken**. Kiosken visar personens egna skärmar på det språket.
7. Är personen chef: kryssa i **Chef: kan öppna kontrollbesöksvyn på kiosken med sin kod**.
8. Är personen anställd av ett annat företag: kryssa i **Inhyrd personal (anställd av ett annat företag)** och fyll i **Arbetsgivarens namn** och **Arbetsgivarens organisationsnummer**. Är arbetsgivaren registrerad i ett annat land: se [Utländsk arbetsgivare](#utlandsk-arbetsgivare).
9. Välj **Spara**.
10. Välj **Välj kod** på personens rad, skriv koden som personen har valt och välj **Spara koden**.

> [!IMPORTANT]
> En person syns i namnlistan på kiosken först när hen har en kod. Utan kod kan hen bara checka in med QR-kort eller i mobilen.

## Utländskt ID-nummer

Alla har inte ett svenskt person- eller samordningsnummer, till exempel den som just har kommit till Sverige för att arbeta. Då registrerar du personen med numret från hemlandet.

1. Välj **Utländskt identitetsnummer** under **Identitet**.
2. Skriv numret i **Utländskt identitetsnummer** som det står i den utländska handlingen, till exempel ett nationellt ID-nummer. Högst 30 tecken.
3. Välj landet i **Land som utfärdat numret**.
4. Välj **Spara**.

NONOX kontrollerar inte ett utländskt nummer med kontrollsiffra, eftersom länderna har olika format. Kontrollera därför numret mot handlingen. Numret sparas krypterat, precis som ett personnummer, och visas som "Utländskt nummer (landskod), dolt" i listorna. På kontrollbesöket och i exporterna visas det i sin helhet med landskoden.

Får personen ett svenskt samordningsnummer eller personnummer senare: välj **Ändra**, byt till **Svenskt person- eller samordningsnummer** och skriv det nya numret. Posterna som redan finns i liggaren behåller numret som gällde när de skrevs.

> [!NOTE]
> Personalliggaren ska innehålla personnummer, samordningsnummer eller motsvarande utländska nummer (9 kap. 5 § skatteförfarandeförordningen). Bedömningen bygger på en AI-utredning 2026-11-02 och är inte granskad av jurist.

## Utländsk arbetsgivare

Är inhyrd personal anställd av ett företag i ett annat land: kryssa i **Arbetsgivaren är registrerad i ett annat land**. Fyll sedan i **Arbetsgivarens utländska nummer** (registreringsnumret i det landet, högst 30 tecken) och välj **Arbetsgivarens land**. Ett svenskt organisationsnummer kontrolleras med kontrollsiffran, ett utländskt gör det inte.

> [!NOTE]
> Riksdagen ska besluta om nya regler för personalliggare (prop. 2025/26:282, beslut planerat 11 november 2026). Enligt förslaget ska arbetsgivaren dokumenteras för alla som har en annan arbetsgivare från 1 juli 2027, och vilka uppgifter som krävs ska stå i en förordning som inte är klar. NONOX ändrar inte formuläret förrän reglerna är beslutade.

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
Personnummer visas med de fyra sista siffrorna dolda i listan. Ett utländskt nummer döljs helt. Har du behörighet att se dem i sin helhet finns knappen **Visa** i kolumnrubriken. Varje gång någon visar dem sparas det i behandlingshistoriken.

**Kan två personer ha samma personnummer?**
Nej. NONOX säger till om personen redan finns i personalliggaren. Det gäller också ett utländskt nummer från samma land, även om det är skrivet med andra mellanslag eller bindestreck.

**Kan jag ändra personnumret?**
Ja, under **Ändra**. Lämna fältet tomt för att behålla det sparade numret. Byter du mellan svenskt och utländskt nummer, eller byter land, måste du skriva numret igen.

## Läs också

- [Kiosken på surfplattan](/manual/personalliggare/kiosken)
- [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning)
- [Checka in och ut](/manual/personal/checka-in-och-ut) – det din personal läser
