---
beskrivning: Medgivandet till banken gäller en begränsad tid. Så förnyar du det med BankID, kopplar bort en bank och ställer in hämtningar och påminnelser.
ordning: 30
roller: [owner, admin, accountant]
modul: banking
sokord: [medgivande, samtycke, förnya bankkoppling, koppla igen, koppla bort, återkalla, 180 dagar, 90 dagar, hämtningar per dag]
---

# Förnya eller koppla bort banken

När du kopplar banken ger du ett **medgivande** i banken med BankID: NONOX får läsa de konton du godkänner, under en begränsad tid. Banken bestämmer hur länge, oftast 90 eller 180 dagar. NONOX ber aldrig om mer än 180 dagar. När tiden har gått ut måste medgivandet förnyas med BankID.

![De kopplade bankerna och hur länge kopplingen gäller.](/bilder/manual/bank/bankkoppling.webp)

## Se hur länge medgivandet gäller

Välj **Bank** i menyn. Under **Kopplade banker** står varje bank med status och *Medgivandet gäller till och med … (… dagar kvar).* och när den senast hämtades.

| Status | Betyder |
|---|---|
| **Aktiv** | NONOX hämtar transaktioner. |
| **Medgivandet har gått ut** | Inget hämtas förrän banken kopplas igen. |
| **Bortkopplad** | Någon har kopplat bort banken. |

## Påminnelser

Innan medgivandet går ut får alla som får hantera Bank ett mejl, en gång per medgivande, och på Översikt under **Att göra** står **Förnya bankkopplingen till …** med datumet. När det har gått ut står det **Koppla … igen**.

Dagen efter slutdatumet markerar NONOX kopplingen som **Medgivandet har gått ut** av sig själv, utan att fråga banken, och slutar hämta. Det som redan hämtats finns kvar.

## Så här förnyar du

1. Välj **Bank** i menyn.
2. Tryck på **Koppla igen med BankID** vid banken. Knappen syns när det är högst 30 dagar kvar, eller när medgivandet har gått ut.
3. Välj bank och typ av konto, och tryck på **Fortsätt till banken**.
4. Godkänn med BankID i banken.

När du kommer tillbaka tar det nya medgivandet över: bankkonton som redan var kopplade behåller sitt konto i bokföringen och fortsätter hämta där de slutade. Den gamla kopplingen kopplas bort av sig själv. Kontrollera att varje konto har rätt **Konto i bokföringen**.

## Så här kopplar du bort en bank

1. Välj **Bank** i menyn.
2. Tryck på **Koppla bort** vid banken.
3. Läs texten: kopplingen tas bort och banken ombeds avsluta medgivandet. Inga fler transaktioner hämtas. Det som redan hämtats och avstämts finns kvar.
4. Tryck på **Koppla bort** igen för att bekräfta.

NONOX glömmer kopplingen även om banken inte gick att nå. Vill du vara säker på att medgivandet är avslutat hos banken kan du också återkalla det i internetbanken.

## Inställningar

Under **Inställningar för företaget → Moduler** finns inställningarna för Bank:

- **Hämtningar per dag** – 1 till 4, två från början. En bank får begränsa hämtningar som sker utan att du är inloggad till fyra per dag. Du kan alltid hämta för hand med **Hämta nu**.
- **Påminn med e-post när ett medgivande snart går ut** – på från början.
- **Påminn så här många dagar före** – 1 till 60, fjorton från början.

Tryck på **Spara**.

## Stänga av modulen

Stänger du av modulen Bank hämtas inget mer och sidan Bank försvinner ur menyn. Ingenting raderas. Är någon bank fortfarande kopplad varnar NONOX om det: koppla bort den under **Bank** först om banken inte längre ska lämna ut uppgifter. Läs [Moduler](/manual/installningar/moduler).

## Vanliga frågor

**Vad händer med transaktionerna om jag kopplar bort banken?**
De finns kvar, liksom matchningar och avstämningar.

**Medgivandet gick ut för en vecka sedan. Har jag missat transaktioner?**
Nej, så länge banken har kvar dem. När du kopplar igen hämtar NONOX från fem dagar före förra hämtningen och framåt.

**Varför står det "Senaste hämtningen misslyckades"?**
Banken eller leverantören svarade inte som väntat. NONOX försöker igen vid nästa hämtning. Står det kvar länge, prova **Koppla igen med BankID**.

## Läs också

- [Bankkoppling](/manual/bank/bankkoppling)
- [Moduler](/manual/installningar/moduler)
- [Matcha banktransaktioner](/manual/bank/matcha-transaktioner)
