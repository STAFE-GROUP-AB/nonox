---
beskrivning: Låt personalen checka in med ett personligt QR-kort, eller i sin egen mobil när de är på plats, och se vad det kräver.
ordning: 50
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
sokord: [QR-kod, QR-kort, mobil incheckning, checka in med mobilen, platskontroll, GPS, position, Min incheckning]
---

# QR-kort och mobil incheckning

Förutom namn och kod på kiosken finns två sätt att checka in: med ett personligt QR-kort som kioskens kamera läser, och i personens egen mobil när den är nära lokalen. Du väljer per lokal vilka sätt som gäller, under **Personalliggare → Lokaler och kiosker**.

## QR-kort

QR-kortet är snabbast: personen trycker på **Skanna QR-kod** på kiosken och håller upp kortet. Ingen kod behöver skrivas.

### Så här gör du

1. Kontrollera att **Personlig QR-kod som kioskens kamera läser** är ikryssat för lokalen. Det är förvalt.
2. Gå till **Personalliggare → Personal**.
3. Välj **QR-kort** på personens rad och **Skriv ut**.
4. Ge kortet till personen.

Kortet är personligt. Kommer det bort väljer du **Ny QR-kod**, så slutar det gamla kortet att fungera.

Kiosken behöver en kamera som pekar mot den som står framför den. Första gången frågar surfplattan om kiosken får använda kameran; svara ja.

## Mobil incheckning

Med mobil incheckning öppnar personen **Min incheckning** i NONOX i sin mobil och trycker **Checka in här**. NONOX kontrollerar att mobilen är inom lokalens radie.

### Så här gör du

1. Gå till **Personalliggare → Lokaler och kiosker** och välj **Ändra** på lokalen.
2. Kryssa i **Mobil med platskontroll (kräver koordinater)**.
3. Fyll i **Latitud**, **Longitud** och **Radie i meter**, och välj **Spara**.
4. Bjud in personen till företaget med rollen **Personal i personalliggaren**. Se [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare).
5. När personen har gått med: gå till **Personalliggare → Personal**, välj **Ändra** på personen och välj hens användare under **Inloggning (för mobil incheckning)**.
6. Välj **Spara**.

Personen ser nu **Min incheckning** i menyn, med en knapp per lokal och sin QR-kod, som också kan visas för kioskens kamera.

### Det här kontrollerar NONOX

- Lokalen ska ha mobil incheckning påslagen och koordinater.
- Telefonens position ska vara säker på 200 meter när. Annars ber NONOX personen gå utomhus eller slå på platstjänster.
- Personen ska vara inom radien. Annars visas hur långt bort hen är.
- Högst tio försök på tio minuter per person.

NONOX sparar aldrig var personen var, bara avståndet till lokalen i meter.

> [!IMPORTANT]
> Positionen kommer från personens egen telefon och kan inte kontrolleras av NONOX. Varje mobil incheckning märks därför **Position uppgiven av telefonen, ej verifierad** i liggaren och i exporterna till Skatteverket. Använd mobil incheckning där en kiosk inte är rimlig, och kiosken där den går.

## Vem kan använda vad

| Sätt | Kräver |
|---|---|
| Namn och kod på kiosken | En kod under **Personal**. |
| QR-kort på kiosken | Att QR är påslaget för lokalen. Kortet skrivs ut under **Personal**. |
| QR-kod i mobilen | En inloggning med rollen **Personal i personalliggaren**, kopplad till personen. |
| Mobil incheckning | Samma inloggning, och mobil incheckning påslagen för lokalen. |

> [!NOTE]
> Den som har rollen **Anställd** och använder Min sida för lönebesked kan inte öppna **Min incheckning**. En person har en roll per företag. Anställda med Min sida checkar därför in med kod eller utskrivet QR-kort på kiosken.

## Läs också

- [Lokaler](/manual/personalliggare/lokaler)
- [Lägg till personal](/manual/personalliggare/lagg-till-personal)
- [Kiosken på surfplattan](/manual/personalliggare/kiosken)
- [Checka in och ut](/manual/personal/checka-in-och-ut)
