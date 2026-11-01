---
beskrivning: Koppla en surfplatta som kiosk vid entrén, lägg den på hemskärmen så att den fungerar utan internet, och spärra den om den kommer bort.
ordning: 40
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.kiosk]
sokord: [kiosk, surfplatta, iPad, läsplatta, stämpelklocka, koppla kiosk, kopplingskod, offline, utan internet, spärra kiosk]
---

# Kiosken på surfplattan

Kiosken är den skärm personalen checkar in och ut på. Det är en vanlig surfplatta, dator eller mobil vid entrén som du kopplar till en lokal i NONOX. Kiosken fungerar även när internet ligger nere: allt sparas på enheten och skickas när nätet är tillbaka.

Innan du börjar behöver du en [lokal](/manual/personalliggare/lokaler) där **Kiosk med namn och kod** är ikryssat, och gärna [personalen](/manual/personalliggare/lagg-till-personal) inlagd.

## Så här kopplar du kiosken

1. Gå till **Personalliggare → Lokaler och kiosker**.
2. Välj **Lägg till kiosk** under lokalen.
3. Ge kiosken ett namn, till exempel *Entrén*, och välj **Lägg till kiosk**.
4. NONOX visar en **Kopplingskod**, en länk och en QR-kod. Koden gäller i 15 minuter och kan bara användas en gång.
5. Öppna länken på surfplattan, eller skanna QR-koden med surfplattans kamera. Koden är redan ifylld.
6. Välj **Koppla**. Kiosken hämtar personalen och visar **Tryck på ditt namn**.
7. Lägg till kiosken på hemskärmen (se nedan), så öppnas den i helskärm och startar även utan internet.

### På iPad och iPhone: hemskärmen först

En app på hemskärmen har en egen lagring på iPad och iPhone, så en koppling som görs i en vanlig Safari-flik följer inte med. Gör så här i stället:

1. Öppna länken i Safari.
2. Tryck på **Dela** och sedan **Lägg till på hemskärmen**.
3. Öppna kiosken från hemskärmen.
4. Skriv kopplingskoden där och tryck **Koppla**.

Kiosken påminner om det här själv när den öppnas i Safari på en iPad eller iPhone.

### På Android och dator

Öppna länken i Chrome och koppla kiosken. Välj sedan **Installera app** i webbläsarens meny, så öppnas kiosken som en egen app i helskärm.

## Ställ upp surfplattan

- Låt surfplattan sitta i laddaren och ställ in att skärmen aldrig släcks.
- På iPad kan du använda **Guidad åtkomst** i inställningarna för tillgänglighet, så att bara kiosken går att använda.
- Ställ kiosken där alla passerar när de kommer och går.

## Det här ser personalen

- **Tryck på ditt namn**, med foto eller initialer. Den som är incheckad har texten *Inne sedan* och tiden under namnet.
- En knappsats för koden. När koden stämmer visar kiosken *Välkommen* eller *Hej då* och tiden.
- **Skanna QR-kod**, om lokalen tillåter QR-kort.
- **Skatteverket är här**, som öppnas med en chefs kod. Se [Kontrollbesök](/manual/personalliggare/kontrollbesok).
- En rad med språk högst upp. Den som trycker på sitt namn får sina egna skärmar på det språk som är valt för personen: svenska, engelska, arabiska, persiska, somaliska, spanska, thailändska eller tigrinja.

Kiosken vet själv om personen ska checka in eller ut. Hur det ser ut för personalen står i [Checka in och ut](/manual/personal/checka-in-och-ut).

## När internet ligger nere

Kiosken fortsätter att fungera. Den visar **Ingen internetanslutning. Allt sparas här och skickas senare.** och hur många registreringar som väntar. När nätet är tillbaka skickas de i rätt ordning, och kiosken visar **Allt är skickat**.

En registrering som kommer fram mer än 15 minuter efter att den gjordes märks **Skickad i efterhand** i liggaren. Går kioskens klocka fel mer än du har tillåtit i inställningarna märks posten **Enhetens klocka gick fel**. Tiden ändras aldrig.

## Om kiosken kommer bort eller byts ut

Under kiosken i **Lokaler och kiosker** finns:

| Knapp | Vad den gör |
|---|---|
| **Spärra** | Kiosken kan inte längre checka in någon. Registreringar som inte hunnit skickas från den tas inte emot. |
| **Koppla igen** | Den gamla kopplingen spärras och du får en ny kopplingskod, till exempel när kiosken har nollställts. |
| **Ny kopplingskod** | Visas så länge kiosken inte har kopplats, till exempel om koden hann gå ut. Du får en ny kod. |
| **Ta bort** | Kiosken spärras och försvinner ur listan. Registreringar den redan skickat finns kvar i liggaren. |

En ny surfplatta kopplas som en ny kiosk.

> [!WARNING]
> Har surfplattan kommit bort: välj **Spärra** direkt. Kiosken sparar personnummer och den senaste månadens pass krypterade, och de kan bara öppnas med en chefs kod. Byt därför också chefernas koder om du misstänker att någon kommit åt surfplattan.

## Vad kiosken sparar

För att fungera utan internet sparar kiosken en kopia av personalen: namn, foto om personen har samtyckt och kontrollvärden för koderna, aldrig själva koderna. Personnummer och de senaste 31 dagarnas pass sparas bara krypterade, för kontrollbesök utan internet.

## Vanliga frågor

**Kiosken säger att koden inte stämmer eller har gått ut.**
Kopplingskoden gäller i 15 minuter och bara en gång. Välj **Ny kopplingskod** eller **Koppla igen** och försök igen.

**En person syns inte på kiosken.**
Personen behöver en kod. Välj **Välj kod** under **Personalliggare → Personal**. Arkiverade personer visas inte heller.

**Kiosken säger att den är spärrad.**
Någon har valt **Spärra** eller **Koppla igen**. Skriv den nya kopplingskoden som visades vid **Koppla igen**.

## Läs också

- [Lokaler](/manual/personalliggare/lokaler)
- [Lägg till personal](/manual/personalliggare/lagg-till-personal)
- [Kontrollbesök](/manual/personalliggare/kontrollbesok)
- [Checka in och ut](/manual/personal/checka-in-och-ut)
