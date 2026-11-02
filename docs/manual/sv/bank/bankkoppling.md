---
beskrivning: Koppla företagets bankkonton med BankID genom öppen bankdata. NONOX hämtar transaktioner och saldon automatiskt – och kan bara läsa, aldrig betala.
ordning: 10
roller: [owner, admin, accountant]
modul: banking
rutter: [banking.index, banking.callback]
sokord: [bankkoppling, koppla bank, BankID, öppen bankdata, open banking, PSD2, bankflöde, automatisk import, Enable Banking]
---

# Bankkoppling

Med modulen **Bank** kopplar du företagets bankkonton till NONOX med BankID. Därefter hämtar NONOX transaktioner och saldon några gånger om dagen, i stället för att du laddar upp filer. Transaktionerna föreslås mot bokföringen, kundbetalningar med OCR-nummer föreslås mot fakturorna, och bankens saldo kan fyllas i direkt i bankavstämningen.

> [!IMPORTANT]
> NONOX kan bara **läsa** från banken. Ingenting kan betalas eller flyttas från NONOX, och NONOX bokför aldrig något själv – en person bekräftar alltid. Leverantörsbetalningar görs fortfarande med betalfiler som du godkänner i banken.

![Kopplade banker med konton och vilket bokföringskonto de hör till.](/bilder/manual/bank/bankkoppling.webp)

## Innan du börjar

- **Modulen måste vara påslagen.** En ägare eller administratör slår på **Bank** under **Inställningar för företaget → Moduler**. Läs [Moduler](/manual/installningar/moduler).
- **Installationen måste ha en leverantör av öppen bankdata.** NONOX hämtar bankdata genom en licensierad leverantör (Enable Banking). Saknas avtalet säger modulsidan *Bankkopplingen är inte inställd på den här installationen* och modulen går inte att slå på.
- **På nonox.app** erbjuds modulen inte än. Den slås på när de juridiska frågorna kring tjänsten är besvarade. Kör ni NONOX själva behöver ni ett eget avtal med leverantören.
- Kontoplanen behöver ett konto för banken mellan 1900 och 1999, till exempel 1930.
- Du behöver rollen ägare, administratör eller redovisningskonsult för att koppla banker. Bokförare och revisorer kan se transaktionerna.

## Så här kopplar du banken

1. Välj **Bank** i menyn och tryck på **Koppla en bank**.
2. Välj din bank i listan **Bank**.
3. Välj **Typ av konto**: **Företagskonto**, eller **Privatkonto (till exempel enskild firma)** om företaget använder ett privat konto.
4. Tryck på **Fortsätt till banken**.
5. Du kommer till bankens egen sida. Godkänn med BankID där. Det är banken, inte NONOX, som frågar vilka konton som får läsas.
6. Du kommer tillbaka till NONOX och ser *… är kopplad. Välj vilket konto i bokföringen varje bankkonto hör till, så hämtas transaktionerna.*
7. Under banken listas bankkontona med namn och de fyra sista siffrorna. Välj för varje konto vilket konto i bokföringen det hör till i kolumnen **Konto i bokföringen**, till exempel *1930 Företagskonto/checkkonto/affärskonto*.

Klart. Transaktionerna hämtas vid nästa hämtning, eller direkt om du trycker på **Hämta nu**.

> [!TIP]
> Bara konton som du kopplar till ett konto i bokföringen hämtas. Ett privat sparkonto som råkade komma med i bankens lista lämnar du på **Hämtas inte**, så läser NONOX aldrig det.

## Vad som hämtas, och när

- Första gången hämtas de senaste 90 dagarna. Sedan hämtas från fem dagar före förra hämtningen, så att sena bokningar hos banken kommer med.
- Hämtningen sker automatiskt en till fyra gånger per dygn, två från början. Läs [Förnya eller koppla bort banken](/manual/bank/forny-eller-koppla-bort).
- **Hämta nu** hämtar direkt, högst en gång i kvarten.
- Samma transaktion sparas aldrig två gånger, hur många gånger den än hämtas.
- Transaktioner och saldon sparas och finns kvar även om du kopplar bort banken eller stänger av modulen.

## Banker med egna krav

- **Handelsbanken** kräver en fullmakt för öppen bankdata för företagskonton. Den ordnar du i banken.
- **Nordea** har olika val för Nordea Business och Corporate; välj det som stämmer med hur företaget loggar in i banken.
- **Länsförsäkringar** vill ha personnummer innan godkännandet, och NONOX skickar det inte än. Kopplingen kan därför misslyckas.

## Vanliga frågor

**Listan över banker är tom.**
Leverantören går inte att nå just nu. Försök igen om en stund. Står det samma sak länge, kontakta den som driftar NONOX.

**Jag fick "Banken gav inte tillgång. Ingenting har hämtats."**
Godkännandet avbröts eller nekades i banken. Börja om med **Koppla en bank**.

**Kan jag koppla ett konto i euro?**
Inte än. Bara konton i företagets valuta (kronor) kan kopplas till bokföringen.

**Kan två bankkonton höra till samma konto i bokföringen?**
Nej. Ett konto i bokföringen hör till ett bankkonto, så att varje avstämning jämför ett kontoutdrag med ett konto. Lägg upp till exempel 1940 för ett andra bankkonto.

**Kan NONOX se mitt lösenord eller göra betalningar?**
Nej. Du loggar in med BankID hos banken, och NONOX får bara läsrätt till de konton du godkänner, under den tid banken bestämmer.

## Läs också

- [Matcha banktransaktioner](/manual/bank/matcha-transaktioner)
- [Förnya eller koppla bort banken](/manual/bank/forny-eller-koppla-bort)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Inbetalningar](/manual/fakturering/inbetalningar)
