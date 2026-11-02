---
beskrivning: Betala många attesterade leverantörsfakturor på en gång med en betalfil (pain.001) som du läser in i internetbanken, och bokför betalningarna när banken har betalat.
ordning: 60
roller: [owner, admin, accountant, bookkeeper, approver]
rutter: [payables.batches.index, payables.batches.file]
sokord: [betalfil, pain.001, iso 20022, filöverföring, betalningsförslag, leverantörsbetalningar, bankfil, lb-fil, godkänna betalning]
---

# Betalfiler till banken

I stället för att skriva in varje faktura i internetbanken kan du samla de attesterade fakturorna i en betalfil. Filen läses in i banken, där du signerar betalningarna som vanligt. NONOX kan aldrig betala något själv – det är alltid ni som skickar iväg pengarna i banken.

Betalfilerna följer standarden ISO 20022 (pain.001) och fungerar för Danske Bank, Handelsbanken, Länsförsäkringar, Nordea, SEB, Swedbank och sparbankerna. Du hittar dem under **Inköp → Betalfiler**.

Flödet har fyra steg, och två personer:

1. Någon **skapar** betalfilen.
2. Någon annan **godkänner** den.
3. Filen **laddas ned** och läses in i internetbanken, där den signeras.
4. När banken har betalat **markeras** filen som betald, och betalningarna bokförs.

![Betalfilerna med bankuppgifterna, det som ska betalas och en godkänd betalfil.](/bilder/manual/leverantorer/betalfiler.webp)

## Innan du börjar: bankuppgifterna

En ägare eller administratör fyller i uppgifterna en gång under **Bank för betalfiler**:

1. Välj **Bank**.
2. Fyll i **Företagskontots IBAN**. Den finns i internetbanken.
3. Fyll i **Avtals-id för filöverföring**. Det får ni av banken när filöverföring kopplas på; under fältet står vad banken kallar det.
4. Klicka på **Spara**.

Swedbank och sparbankerna betalar från företagets bankgiro. Har ni valt dem måste bankgiro vara ifyllt under företagets uppgifter.

> [!TIP]
> Skicka en första fil till bankens testtjänst innan du betalar på riktigt. Banken hjälper dig att koppla på filöverföring och testa.

## Så här skapar du en betalfil

1. Gå till **Inköp → Betalfiler**. Under **Att betala** visas attesterade fakturor som inte redan finns i en betalfil. Fakturor som förfaller inom en vecka är förvalda.
2. Kryssa i de fakturor du vill betala.
3. Välj **Betaldag**. Den kan inte vara passerad.
4. Kontrollera antalet och summan och klicka på **Skapa betalfil**.

Filen får status **Skapad, väntar på banken** och syns under **Betalfiler**.

## Så här godkänner du betalfilen

Den som godkänner måste ha rätt att godkänna betalningar: **Ägare**, **Administratör** eller **Attestant**. Som standard får den som skapade filen inte godkänna den.

1. Gå till **Inköp → Betalfiler**.
2. Klicka på **Godkänn betalningen** vid filen.
3. Läs sammanfattningen – antal betalningar, summa och betaldag – och klicka på **Ja, godkänn**.

Godkännandet sparas med namn och tid och kan inte ångras. Först nu går filen att ladda ned.

## Så här skickar du filen till banken

1. Klicka på **Ladda ned fil** vid den godkända filen.
2. Logga in i internetbanken och läs in filen där filöverföring finns.
3. Kontrollera och signera betalningarna i banken.

## När banken har betalat

1. Kontrollera på kontoutdraget att betalningarna är gjorda.
2. Klicka på **Banken har betalat** vid filen och sedan **Ja, bokför**.

Filen får status **Betald** och varje faktura blir betald.

Betalade banken inte filen, klicka på **Makulera** och **Ja, makulera**. Fakturorna kan då tas med i en ny fil. En fil som inte är godkänd kan också makuleras.

## Det här händer i bokföringen

Ingenting bokförs när filen skapas eller godkänns. När du markerar filen som betald bokförs varje faktura i filen som en egen betalning i serie E på betaldagen: 2440 Leverantörsskulder i debet och 1930 Företagskonto i kredit (enligt kontantmetoden kostnaden och momsen i stället för 2440). Läs [Registrera en betalning](/manual/leverantorer/registrera-en-betalning).

Själva filen sparas som den skapades, med en kontrollsumma, och kan inte ändras.

## Fakturor som inte kan tas med

- **Kreditnotor** – kvitta dem mot en faktura i stället. Läs [Kreditnotor](/manual/leverantorer/kreditnotor).
- **Fakturor i utländsk valuta** – betala dem i internetbanken och registrera betalningen på fakturan.
- **Fakturor utan bankgiro eller plusgiro** – betalningsuppgiften hämtas från leverantören som den var när fakturan registrerades. Fanns den inte då, betala i internetbanken.
- **Fakturor som redan finns i en betalfil** som väntar på banken.

## Vanliga frågor

**En faktura i filen har betalats på annat sätt.**
Då går filen inte att markera som betald. Makulera filen och gör en ny utan den fakturan.

**Kan NONOX läsa bankens svar och bokföra automatiskt?**
Inte än. Automatisk avstämning av betalfiler mot bankens svar är på väg. Tills vidare bekräftar du själv att banken har betalat.

**Vi är bara en person.**
Då kan en ägare stänga av kravet på att någon annan godkänner. Läs [Attest](/manual/leverantorer/attest).

## Läs också

- [Attest](/manual/leverantorer/attest)
- [Leverantörsregistret](/manual/leverantorer/leverantorsregistret)
- [Bankkoppling](/manual/bank/bankkoppling)
