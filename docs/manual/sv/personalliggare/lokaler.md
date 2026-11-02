---
beskrivning: Lägg till lokalen där personalen arbetar, välj verksamhet och bestäm hur personalen får checka in där.
ordning: 20
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.locations]
sokord: [lokal, arbetsplats, verksamhetslokal, restaurang, salong, adress, koordinater]
---

# Lokaler

En lokal är platsen där personalen arbetar och där liggaren förs, till exempel restaurangen eller salongen. Varje lokal har en egen adress, en verksamhet och egna regler för hur personalen checkar in. Kiosker kopplas alltid till en lokal.

![En lokal med sina sätt att checka in och sina kiosker.](/bilder/manual/personalliggare/lokaler.webp)

## Så här gör du

1. Gå till **Personalliggare → Lokaler och kiosker**.
2. Välj **Lägg till lokal**.
3. Fyll i **Namn**, till exempel *Café Hörnet*, och **Adress**, **Postnummer** och **Ort**. Postnumret skriver du med fem siffror.
4. Välj **Verksamhet**: Restaurang, Kropps- och skönhetsvård (även frisör), Fordonsservice, Tvätteri, Livsmedels- och tobaksgrossist eller Övrig verksamhet (bara tidsredovisning).
5. Under **Sätt att checka in**, kryssa i det som ska gälla i lokalen (se nedan).
6. Ska personalen kunna checka in i mobilen: fyll i **Latitud**, **Longitud** och **Radie i meter**.
7. Välj **Spara**.

Nästa steg är att [lägga till personalen](/manual/personalliggare/lagg-till-personal) och sedan [koppla en kiosk](/manual/personalliggare/kiosken) till lokalen.

## Sätt att checka in

| Val | Förval | Vad det betyder |
|---|---|---|
| **Kiosk med namn och kod** | På | Personen trycker på sitt namn på surfplattan och skriver sin kod med fyra siffror. |
| **Personlig QR-kod som kioskens kamera läser** | På | Personen visar sitt QR-kort eller sin mobil för kioskens kamera. |
| **Mobil med platskontroll (kräver koordinater)** | Av | Personen checkar in i sin egen mobil när den är inom radien från lokalen. |

Du måste välja minst ett av kiosk eller mobil. Mer om QR-kod och mobil finns i [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning).

### Koordinater och radie

Koordinaterna behövs bara för mobil incheckning. Skriv dem med punkt som decimaltecken, till exempel *59.3293* för latitud. Du hittar dem i en kartapp genom att trycka länge på lokalen. **Radie i meter** är hur långt från lokalen en mobil incheckning godtas, mellan 25 och 2 000 meter. Förvalt är 150 meter.

## Ändra eller arkivera en lokal

- Välj **Ändra** på lokalen för att ändra uppgifterna eller sätten att checka in.
- Välj **Arkivera** när lokalen inte används längre. Kiosker slutar visa den, men liggaren för lokalen finns kvar. **Återställ** gör den aktiv igen.

En lokal tas aldrig bort, eftersom liggaren för den ska finnas kvar.

## Bra att veta

- **Övrig verksamhet** är för en lokal utan krav på personalliggare, där du vill använda in- och utcheckningen som tidsredovisning. Liggaren fungerar likadant.
- **Flera företag i samma lokal** för var sin liggare. Varje företag lägger upp lokalen i sitt eget NONOX-företag och kopplar sin egen kiosk.
- På kortet för varje lokal ser du vilka kiosker som är kopplade och när de senast hade kontakt med NONOX.

## Läs också

- [Kiosken på surfplattan](/manual/personalliggare/kiosken)
- [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning)
- [Lägg till personal](/manual/personalliggare/lagg-till-personal)
