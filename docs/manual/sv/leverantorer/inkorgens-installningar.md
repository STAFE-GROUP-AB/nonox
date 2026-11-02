---
beskrivning: Sätt upp inkorgen – företagets e-postadress, listan över godkända avsändare, aviseringar, sparade mallar med automatisk bokföring och en egen brevlåda.
ordning: 120
roller: [owner, admin, accountant]
modul: inbox
sokord: [inkorgens adress, e-postadress, godkända avsändare, vitlista, allowlist, karantän, mallar, automatisk bokföring, imap, brevlåda]
---

# Inkorgens adress och godkända avsändare

Innan inkorgen tar emot något behöver två saker vara klara: du behöver ge ut företagets adress, och du behöver tala om vilka som får skicka till den. Bara post från godkända avsändare tolkas; allt annat väntar i karantän.

Inställningarna finns under **Företagsinställningar → Moduler**. Klicka på **Inställningar** vid modulen **Inkorg**. Är modulen inte påslagen slår en ägare eller administratör på den där först. Läs [Moduler](/manual/installningar/moduler).

![Företagets egen adress för inkorgen står överst.](/bilder/manual/leverantorer/inkorgen.webp)

## Företagets adress

Under **Företagets adress** står adressen. Klicka på **Kopiera** och ge den till leverantörer och till ditt kassasystem.

Med ett tillägg efter plustecknet berättar avsändaren vad som skickas: `+faktura`, `+kvitto` eller `+kassa`. Det hjälper NONOX att tolka rätt, men all post till adressen kommer fram även utan tillägget.

### Byt adress

Har adressen spridits till fel personer kan företagets ägare byta den med **Byt till en ny adress** och **Ja, byt adress**.

> [!WARNING]
> Den gamla adressen slutar ta emot e-post direkt. Alla som skickar dit måste få den nya.

### Den gemensamma adressen

Det finns också en gemensam adress för det du vidarebefordrar från din egen e-post. Den tar bara emot post från adressen du loggar in i NONOX med, och den adressen måste stå på listan över godkända avsändare. Leverantörer och kassasystem ska alltid använda företagets egen adress.

## Godkända avsändare

Listan avgör vilken post som tolkas.

### Så här lägger du till en avsändare

1. Under **Godkända avsändare**, skriv en e-postadress eller en hel domän i **Adress eller domän**.
2. Skriv gärna en **Anteckning**, till exempel vilken leverantör det är.
3. Klicka på **Lägg till**.

En domän godkänner alla adresser på den domänen. Använd domäner för leverantörer du litar på, och enskilda adresser för gratistjänster som Gmail.

Du kan också godkänna en avsändare direkt från ett underlag i karantän med **Godkänn avsändaren**. Då släpps dess väntande post igenom.

> [!NOTE]
> Även en godkänd avsändare hamnar i karantän om mejlet inte klarar kontrollen av avsändaren (SPF, DKIM och DMARC). En avsändaradress är lätt att förfalska; kontrollerna är det som visar att mejlet verkligen kommer därifrån.

## Tolkning och aviseringar

Under **Tolkning och aviseringar** väljer du:

- **Låt NONOX AI läsa underlag som ingen mall känner igen** – kräver att modulen NONOX AI är påslagen. Underlaget skickas då till er AI-leverantör.
- **Skicka e-post när något behöver granskas (högst en gång i timmen)**.
- **Skicka en daglig sammanfattning på morgonen**.

Klicka på **Spara**.

## Sparade mallar och automatisk bokföring

Varje gång du godkänner ett underlag sparas hur det tolkades som en mall för avsändaren och formatet. Under **Sparade mallar** ser du dem och hur många gånger de har använts.

För en mall du litar på kan du klicka på **Bokför automatiskt**. Då bokförs nästa underlag från avsändaren utan att någon granskar det – men bara när allt detta stämmer:

- underlaget matchar mallen exakt,
- verifikationen balanserar,
- avsändaren är godkänd och klarade kontrollen,
- det finns inga varningar,
- du som slog på det får fortfarande bokföra.

Det bokförs i ditt namn, och behandlingshistoriken visar att det skett automatiskt. AI bokför aldrig själv. Stäng av med **Stäng av automatisk bokföring**.

## Egen brevlåda (IMAP)

Kör ni NONOX själva kan NONOX i stället hämta e-post från en egen brevlåda. Använd en brevlåda som bara används för underlag.

1. Kryssa i **Hämta e-post från en egen brevlåda**.
2. Fyll i **Server**, **Port** (993 för SSL/TLS, 143 för STARTTLS), **Kryptering**, **Mapp**, **Användarnamn** och **Lösenord**.
3. Klicka på **Spara brevlådan**.

Lösenordet sparas krypterat och visas aldrig igen. NONOX hämtar ny post var femte minut och markerar den inte som läst. Under formuläret står när det senast hämtades, eller att den senaste hämtningen misslyckades.

## Vanliga frågor

**Ett mejl kom aldrig fram.**
Kontrollera att det skickades till rätt adress. Post från en avsändare som inte är godkänd ligger i karantän under **Att granska**. Skräppost från okända avsändare sparas aldrig.

**Vem får ändra inställningarna?**
Ägare, administratörer och redovisningskonsulter. Bara ägaren kan byta adressen. Bokförare kan granska och bokföra i inkorgen men inte ändra inställningarna.

## Läs också

- [Inkorgen](/manual/leverantorer/inkorgen)
- [Dagsrapporter från kassasystemet](/manual/leverantorer/dagsrapporter)
- [NONOX AI](/manual/installningar/nonox-ai)
