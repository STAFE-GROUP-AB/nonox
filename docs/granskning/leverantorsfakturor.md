# Granskningschecklista: Leverantörsfakturor och betalningar

| | |
|---|---|
| Modul | Leverantörsfakturor, attest, betalningar, betalfiler (pain.001), bankens svar (pain.002/camt.054), kreditnotor, inköp från utlandet och omvänd betalningsskyldighet |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `app/Actions/Payables/` (SaveSupplierInvoice, RegisterSupplierInvoice, ApproveSupplierInvoice, PaySupplierInvoice, OffsetSupplierCredit, CreatePaymentBatch, ApprovePaymentBatch, SettlePaymentBatch, ApplyPaymentResponses, SaveSupplier), `app/Domain/Payables/` (SupplierInvoiceBooking, VatTreatment, ForeignPayment, PaymentBank, Iban, OutgoingPaymentMatcher, BankReasonCode), `app/Policies/SupplierInvoicePolicy.php`, `app/Policies/SupplierPaymentBatchPolicy.php`, `app/Support/Pain001/`, `app/Support/Currency/ExchangeRate.php`, `docs/adr/ADR-013-supplier-invoices.md`, `docs/adr/ADR-014-payment-files.md`, `docs/adr/ADR-015-incoming-payment-files.md`, `docs/adr/ADR-016-supplier-credit-and-foreign-purchases.md`, `docs/adr/ADR-025-exchange-differences.md` |
| Manualavsnitt | `docs/manual/sv/leverantorer/` (registrera-en-leverantorsfaktura, attest, kreditnotor, inkop-fran-utlandet, registrera-en-betalning, betalfiler, bankens-svar, utlandsk-valuta, leverantorsregistret) |

## Förutsättningar

Gäller alla fall om inget annat anges.

- **Testbolag:** ett aktiebolag, "Testbolag LEV AB", reglering K2, momsregistrerat, kalenderår som räkenskapsår. Räkenskapsåret som pågår (2026) är öppet och ingen period är låst. Kontoplan enligt BAS-mallen som NONOX skapar. Använd inga verkliga uppgifter.
- **Bokföringsmetod:** fakturametoden, utom i fall som uttryckligen säger kontantmetoden (byt under företagets uppgifter, och byt tillbaka efteråt).
- **Användare** (tre olika personer, syntetiska):
  - A = Ägare (registrerar och betalar),
  - B = Attestant (rollen "Attestant", attesterar),
  - C = Bokförare.
- **Inställning:** "Kräv att någon annan attesterar" är påslagen (standard). Det är den som ska prövas i LEV-08.
- **Leverantörer** (lägg upp under **Register → Leverantörer → Ny leverantör**, alla uppgifter påhittade):
  - "Leverantör Inland AB": organisationsnummer 556036-0793, bankgiro 5402-9681, betalningsvillkor 30 dagar, konto för fakturorna 4010.
  - "Leverantör Plusgiro AB": plusgiro 1234567-4, betalningsvillkor 10 dagar (inget organisationsnummer).
  - "Lieferant GmbH": land DE, ort "Köln", IBAN DE89 3704 0044 0532 0130 00, BIC COBADEFFXXX, momsregistreringsnummer DE123456789, betalningsvillkor 30 dagar.
  - "Bygg Underentreprenör AB": organisationsnummer 556334-3689, bankgiro 999-9996.
  - "Cloud Inc": land US, ingen svensk betalningsuppgift.
- **Datum:** exemplen använder datum under oktober och november 2026. Är ett datum passerat när du kör fallet, byt till ett motsvarande datum inom det öppna året. **D** betyder dagens datum.
- **Bokföring att jämföra mot:** efter varje fall öppnar du verifikationen (länken på fakturan eller betalningen) och jämför rad för rad med "Förväntat resultat". Belopp i debet är positiva, i kredit negativa i kontrollsummorna.
- **Bank:** för betalfilsfallen (LEV-17 och framåt) fyll i **Inköp → Betalfiler → Bank för betalfiler**: Bank = Handelsbanken, Företagskontots IBAN = SE45 5000 0000 0583 9825 7466 (påhittat), Avtals-id = 1234567890. Testfiler från banken finns inte; konsulten får syntetiska pain.002- och camt.054-filer av NONOX-teamet för LEV-20, LEV-21 och LEV-25.

> Alla belopp nedan är påhittade. Kontrollera alltid antal verifikationer, serie och datum, inte bara beloppen.

---

### LEV-01 Leverantörsregistret: kontroller vid sparande

- Förutsättningar: inga leverantörer finns.
- Steg i appen:
  1. Gå till **Register → Leverantörer** och klicka på **Ny leverantör**.
  2. Skriv **Namn** "Kontrolltest AB". Skriv **Bankgiro** `5402-9682` (sista siffran ändrad) och klicka på **Spara**.
  3. Rätta till `5402-9681` och spara.
  4. Öppna leverantören med **Ändra**. Skriv **Plusgiro** `12345675` och spara. Rätta sedan till `1234567-4` och spara.
  5. Skriv **IBAN (utländsk leverantör)** `DE88 3704 0044 0532 0130 00` och spara. Skriv sedan `DE89 3704 0044 0532 0130 0` (en siffra för lite) och spara. Skriv sedan `DE89 3704 0044 0532 0130 00` och spara.
  6. Skriv **Land (landskod)** `SE` tillsammans med IBAN som börjar på DE och spara.
  7. Skriv **BIC** `ABC123` och spara. Skriv sedan `COBADEFF` och spara.
  8. Skriv **Konto för fakturorna** `9999` och spara.
- Förväntat resultat:
  - Steg 2: sparas inte. Meddelande: "Bankgironumret stämmer inte. Kontrollera siffrorna." Bankgiro 5402-9681 är giltigt enligt Luhn (kontrollsumma 0 mod 10), 5402-9682 är det inte.
  - Steg 3: sparas, visas som `5402-9681`.
  - Steg 4: `12345675` ger "Plusgironumret stämmer inte..." (exakt text: "Plusgironumret stämmer inte. Kontrollera siffrorna."); `1234567-4` sparas och visas som `1234567-4`.
  - Steg 5: `DE88...` ger "IBAN:s kontrollsiffror stämmer inte. Kontrollera numret." (mod 97-10 ger inte 1). `DE89 ... 0130 0` (21 tecken) ger "IBAN för DE ska vara 22 tecken men är 21. Kontrollera numret." Rätt IBAN (22 tecken, mod 97-10 = 1) sparas.
  - Steg 6: "IBAN börjar med DE men landet är SE. Kontrollera landet eller IBAN."
  - Steg 7: "BIC ska vara 8 eller 11 tecken, till exempel ESSESESS." för `ABC123`; `COBADEFF` (8 tecken) sparas.
  - Steg 8: "Konto 9999 finns inte i kontoplanen."
  - Konsulten verifierar: att Luhn-kontroll av bankgiro/plusgiro är rätt kontroll (Bankgirots egen regel är modulus 10) och att IBAN-längder per land stämmer mot SWIFT-registret (`app/Domain/Payables/Iban.php` listar 69 länder; ett land som saknas i listan nekas).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-02 Svensk faktura med 25 % moms, fakturametoden

- Förutsättningar: leverantören "Leverantör Inland AB". Användare A.
- Steg i appen:
  1. **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**. Dra en PDF (valfri, högst 10 MB) till rutan **Fakturan**.
  2. **Leverantör**: Leverantör Inland AB. **Vem redovisar momsen?**: Svensk faktura med moms.
  3. **Leverantörens fakturanummer** `F-1001`, **OCR-nummer** `1234567890`, **Fakturadatum** 2026-10-15, lämna **Förfallodag** tom.
  4. **Att betala, inkl. moms** 12 500,00. **Varav moms** 2 500,00.
  5. **Kontering**: en rad, konto 4010, belopp utan moms 10 000,00.
  6. Läs rutan **Så här bokförs fakturan när du registrerar den**. Klicka på **Registrera fakturan** och **Ja, registrera**.
- Förväntat resultat:
  - Under raderna: "Raderna och momsen stämmer med fakturan." (10 000,00 + 2 500,00 = 12 500,00).
  - Förfallodag blir 2026-11-14 (2026-10-15 + 30 dagar: 16 dagar kvar i oktober + 14 i november).
  - Verifikation i serie D, datum 2026-10-15: 4010 debet 10 000,00; 2641 debet 2 500,00; 2440 kredit 12 500,00. Summa debet 12 500,00 = summa kredit 12 500,00. Filen är underlag till verifikationen.
  - Fakturan får ett löpnummer och status "Registrerad, väntar på attest". Fakturan går inte att ändra.
  - Momsrapporten för perioden: ruta 48 (ingående moms) ökar med 2 500,00.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-03 Flera rader och flera momssatser på samma faktura

- Förutsättningar: som LEV-02.
- Steg i appen:
  1. Ny leverantörsfaktura, leverantör Leverantör Inland AB, fakturanummer `F-1002`, datum 2026-10-16.
  2. **Att betala, inkl. moms** 1 424,00. **Varav moms** 224,00.
  3. Rad 1: konto 4010, 800,00. Klicka på **+ Lägg till rad**. Rad 2: konto 6110, 400,00.
  4. Titta på **Kvar att fördela:**. Klicka vid behov på **Lägg på sista raden**.
  5. Registrera fakturan.
- Förväntat resultat:
  - Momsen på fakturan: 800,00 × 25 % = 200,00 plus 400,00 × 6 % = 24,00 = 224,00 (fakturan har alltså en rad med 25 % och en med 6 %). Totalt 800,00 + 400,00 + 224,00 = 1 424,00.
  - Medan raderna inte är klara visas **Kvar att fördela:** med skillnaden; efter rad 2 försvinner den.
  - Verifikation i serie D: 4010 debet 800,00; 6110 debet 400,00; 2641 debet 224,00; 2440 kredit 1 424,00.
  - Observation att anteckna: NONOX frågar bara efter total moms. Det kontrolleras inte att momsen stämmer med någon viss sats per rad. Konsulten bedömer om det är acceptabelt för attesten.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-04 Raderna och momsen stämmer inte med beloppet (validering)

- Förutsättningar: som LEV-02.
- Steg i appen:
  1. Ny leverantörsfaktura. **Att betala, inkl. moms** 12 400,00, **Varav moms** 2 500,00, en rad 4010 med 10 000,00.
  2. Försök registrera.
  3. Ändra **Varav moms** till 12 500,00 och **Att betala** till 12 500,00. Försök registrera.
  4. Ändra **Varav moms** till 0 och **Att betala** till 0.
  5. Skriv **Förfallodag** 2026-10-01 med **Fakturadatum** 2026-10-15.
  6. Skriv **OCR-nummer** `12AB`.
- Förväntat resultat:
  - Steg 2: kan inte sparas. Meddelande: "Raderna och momsen blir 12 500,00 kr, men fakturan ska betalas med 12 400,00 kr. Skillnaden är -100,00 kr." (10 000,00 + 2 500,00 = 12 500,00; 12 400,00 - 12 500,00 = -100,00).
  - Steg 3: momsen 12 500,00 är inte större än totalen men raderna (10 000,00) + moms (12 500,00) = 22 500,00 ≠ 12 500,00: "Raderna och momsen blir 22 500,00 kr, men fakturan ska betalas med 12 500,00 kr. Skillnaden är -10 000,00 kr."
  - Steg 4: "Beloppet att betala måste vara större än noll."
  - Steg 5: "Förfallodagen kan inte vara före fakturadatum."
  - Steg 6: "OCR-numret får bara innehålla siffror."
  - Observation: OCR-numret kontrolleras bara för siffror och längd (2-25), inte med kontrollsiffra (Luhn). Ett felskrivet OCR-nummer med bara siffror godtas och hamnar i betalfilen. Konsulten bedömer om det är en brist (se avsnittet Avvikelser, kandidat).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-05 Utkast, oföränderlighet och rättelse

- Förutsättningar: som LEV-02.
- Steg i appen:
  1. Fyll i en faktura och klicka på **Spara som utkast**. Öppna den igen från listan (filter **Utkast**), ändra beloppet och spara.
  2. Klicka på **Ta bort utkastet**.
  3. Skapa en ny faktura och registrera den (LEV-02). Försök öppna den för ändring via `/leverantorsfakturor/{id}/andra`.
  4. Rätta felet genom en rättelse av verifikationen (se manualen "Rätta en verifikation").
- Förväntat resultat:
  - Utkast får ändras och tas bort; ett utkast bokför ingenting och får inget löpnummer.
  - En registrerad faktura får inte ändras eller tas bort (policy: bara utkast). Sidan nekas.
  - Rättelsen skapar en ny verifikation som vänder den gamla (serie A) och länkar åt båda hållen. Den ursprungliga verifikationen förblir oförändrad.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-06 Kontantmetoden: ingenting vid registrering, delbetalning med avrundning

- Förutsättningar: byt bokföringsmetod till **Kontantmetoden** under företagets uppgifter. Leverantör Inland AB. Användare A registrerar, B attesterar.
- Steg i appen:
  1. Registrera faktura `K-1`: fakturadatum 2026-10-20, **Att betala** 100,00, **Varav moms** 20,00, rad 4010 80,00.
  2. Kontrollera att ingen verifikation skapas. B attesterar.
  3. A registrerar betalning 1: **Betaldag** D, **Belopp** 33,33.
  4. A registrerar betalning 2: **Belopp** 66,67 (resten).
- Förväntat resultat:
  - Steg 2: ingen verifikation i serie D. Status blir "Attesterad, att betala".
  - Betalning 1 (33,33 av 100,00): varje rads andel avrundas halv upp. 80,00 × 33,33 / 100,00 = 26,664 → 26,66. Momsen 20,00 × 33,33 / 100,00 = 6,666 → 6,67. 26,66 + 6,67 = 33,33. Serie E: 4010 debet 26,66; 2641 debet 6,67; 1930 kredit 33,33.
  - Betalning 2 (66,67 = resten): resten av varje rad, så att summan blir exakt fakturan. 4010: 80,00 - 26,66 = 53,34; 2641: 20,00 - 6,67 = 13,33; 53,34 + 13,33 = 66,67. Serie E: 4010 debet 53,34; 2641 debet 13,33; 1930 kredit 66,67.
  - Sammanlagt bokfört: 4010 = 26,66 + 53,34 = 80,00; 2641 = 6,67 + 13,33 = 20,00; 1930 = 100,00. Fakturafilen är underlag till betalning 1.
  - Konsulten verifierar: att kostnad och moms bokförs vid betalning enligt mervärdesskattelagen (2023:200) 7 kap. 33 § och att resten tas på sista betalningen (markerat "juristgranskning återstår", AUD-FAKT-3, #52 i `SupplierInvoiceBooking.php`).
  - Stäng: byt tillbaka till fakturametoden efter fallet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-07 Kontantmetoden: obetalda leverantörsfakturor vid räkenskapsårets slut

- Förutsättningar: kontantmetoden. Faktura `K-2` på 12 500,00 (rad 4010 10 000,00, moms 2 500,00) daterad 2026-12-15, attesterad, obetald. Fallet görs när bokslutssteget finns tillgängligt (efter 2026-12-31) eller på ett avslutat år.
- Steg i appen:
  1. Gå till bokslutets checklista och steget för obetalda fakturor ("Obetalda fakturor vid årets slut är bokförda").
  2. Bokför steget. Betala sedan fakturan i januari 2027.
- Förväntat resultat:
  - Sista dagen i räkenskapsåret bokförs: 4010 debet 10 000,00; 2641 debet 2 500,00; 2440 kredit 12 500,00, med återföring första dagen i nästa år. Betalningen i januari ger 2440-flödet utan att kostnaden dubbelräknas: nettot över båda åren för 4010 är 10 000,00 i resultatet för 2026 och 0,00 i 2027.
  - Konsulten verifierar: Bokföringslagen 5 kap. 2 §, BFNAR 2006:1 punkt 4.2 c, om återföring första dagen är en godtagbar metod, och i vilken momsperiod momsen redovisas (öppna frågor i ADR-013, "Kontantmetoden at year-end"). Kunde inte beräknas: exakta verifikationsrader är beroende av bokslutsmodulen (granskas där).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-08 Attest: samma person får inte attestera (åtskillnad av arbetsuppgifter)

- Förutsättningar: fakturan från LEV-02 är registrerad av A och väntar på attest. "Kräv att någon annan attesterar" är påslagen.
- Steg i appen:
  1. Som A: öppna fakturan under **Inköp → Leverantörsfakturor → Att attestera**. Försök klicka på **Attestera fakturan**.
  2. Som C (Bokförare): öppna samma faktura.
  3. Som B (Attestant): klicka på **Attestera fakturan**.
  4. Som A: gå till **Inköp → Leverantörsfakturor**, längst ned under **Attest**, klicka på **Tillåt att samma person registrerar och attesterar (för företag med en person)**. Registrera en ny faktura som A och attestera den som A. Klicka sedan på **Kräv att någon annan attesterar**.
  5. Som C: försök ändra attestinställningen.
- Förväntat resultat:
  - Steg 1: A kan inte attestera. Texten: "Du registrerade fakturan och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det." Ingen attest sparas. (Spärren gäller den som registrerat eller skapat utkastet.)
  - Steg 2: C har inte rätt att attestera (rollen saknas); knappen finns inte.
  - Steg 3: fakturan blir "Attesterad, att betala", "Attesterad av" visar B:s namn och tid. Ingenting bokförs. Attesten kan inte tas tillbaka. Behandlingshistoriken visar `supplier_invoice.approved`.
  - Steg 4: efter att kravet stängts av får A attestera egen faktura; ändringen och återställningen syns i behandlingshistoriken.
  - Steg 5: bara ägare och administratör ändrar inställningen.
  - Konsulten verifierar: att kontrollmiljön är godtagbar för bolag med flera personer (standard på) och att undantaget för enmansbolag är rimligt.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-09 Betalning i internetbanken: full betalning, fakturametoden

- Förutsättningar: fakturan från LEV-02 är attesterad (12 500,00, fakturametoden). Användare A.
- Steg i appen:
  1. Öppna fakturan under **Att betala**. Läs **Betala till**.
  2. Under **Registrera betalning**: **Betaldag** 2026-11-10, **Belopp** 12 500,00 (förifyllt). Klicka på **Registrera betalningen**.
- Förväntat resultat:
  - **Betala till** visar bankgiro 5402-9681 och OCR-nummer 1234567890.
  - Verifikation i serie E, datum 2026-11-10: 2440 debet 12 500,00; 1930 kredit 12 500,00. Fakturan blir **Betald**; **Kvar att betala** 0,00. Skuldkontot 2440 för fakturan går ut på 0,00 (kredit 12 500,00 från D, debet 12 500,00 från E).
  - Inga kostnads- eller momsrader (de bokfördes redan i D).
  - Obs att anteckna: appen kontrollerar inte att betaldagen är på eller efter fakturadatum (ingen sådan kontroll hittad i `PaySupplierInvoice.php`); testa betaldag 2026-10-01 och notera utfallet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-10 Delbetalning och för stort belopp

- Förutsättningar: en attesterad faktura på 12 500,00 (ny, fakturametoden).
- Steg i appen:
  1. Registrera betalning med **Belopp** 5 000,00.
  2. Registrera betalning med **Belopp** 7 600,00.
  3. Registrera betalning med **Belopp** 0,00 och sedan med -100,00.
  4. Registrera betalning med **Belopp** 7 500,00.
- Förväntat resultat:
  - Steg 1: verifikation E: 2440 debet 5 000,00; 1930 kredit 5 000,00. Fakturan står kvar under **Att betala** med **Kvar att betala** 7 500,00 (12 500,00 - 5 000,00). Status oförändrad (Attesterad).
  - Steg 2: nekas: "Beloppet är större än det som återstår, 7 500,00 kr."
  - Steg 3: nekas: "Skriv ett belopp större än noll."
  - Steg 4: verifikation E: 2440 debet 7 500,00; 1930 kredit 7 500,00. Fakturan blir Betald. Summa betalat 5 000,00 + 7 500,00 = 12 500,00.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-11 Kreditnota och kvittning mot faktura

- Förutsättningar: fakturan från LEV-02 (12 500,00) är attesterad och obetald. Leverantör Inland AB.
- Steg i appen:
  1. Ny leverantörsfaktura. Kryssa i **Det här är en kreditnota**. Fakturanummer `K-F1001`, datum 2026-10-25. **Att betala** 2 500,00 (utan minustecken), **Varav moms** 500,00, rad 4010 2 000,00. Registrera.
  2. Som B: attestera kreditnotan.
  3. Öppna kreditnotan. Under **Kvitta mot en faktura**: välj fakturan F-1001 och klicka på **Kvitta**.
  4. Öppna fakturan F-1001.
- Förväntat resultat:
  - Kreditnotan registreras med omvända tecken. Verifikation D: 2440 debet 2 500,00; 4010 kredit 2 000,00; 2641 kredit 500,00. Summa debet 2 500,00 = summa kredit 2 500,00. Totalt på fakturan: -2 500,00.
  - Kvittningen, serie E: två rader på 2440, en debet 2 500,00 (fakturan) och en kredit 2 500,00 (kreditnotan). Ingen bankrad. Nettoeffekt på 2440 = 0,00.
  - Kreditnotan blir Betald/reglerad. Fakturan F-1001 har **Kvar att betala** 10 000,00 (12 500,00 - 2 500,00).
  - Kreditnota kan inte kvittas om den inte är attesterad, om leverantören är en annan eller om någon av dem är i utländsk valuta; inget av dessa fall ska ge en verifikation.
  - Mervärdesskatt: ingående moms (ruta 48) minskar med 500,00 totalt.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-12 Kreditnota: leverantören betalar tillbaka pengarna

- Förutsättningar: en ny attesterad kreditnota på 1 250,00 (rad 4010 1 000,00, moms 250,00) från Leverantör Inland AB.
- Steg i appen:
  1. Öppna kreditnotan. Under **Registrera återbetalning**: **Betaldag** 2026-11-12, **Belopp** 1 250,00. Klicka på **Registrera betalningen**.
  2. Försök lägga kreditnotan i en betalfil (LEV-18).
- Förväntat resultat:
  - Verifikation E: 1930 debet 1 250,00; 2440 kredit 1 250,00. Kreditnotans verifikation D var 2440 debet 1 250,00; 4010 kredit 1 000,00; 2641 kredit 250,00. Efter återbetalningen är 2440 för kreditnotan 0,00.
  - Kreditnotan kan inte tas med i en betalfil.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-13 Inköp av tjänster från annat EU-land (omvänd betalningsskyldighet), 25 %

- Förutsättningar: Lieferant GmbH. Fakturametoden.
- Steg i appen:
  1. Ny leverantörsfaktura, Lieferant GmbH, fakturanummer `DE-77`, datum 2026-10-18.
  2. **Vem redovisar momsen?**: Tjänster från ett annat EU-land.
  3. **Att betala** 10 000,00 (fältet **Varav moms** försvinner). Rad: **Momssats** 25 %, belopp 10 000,00.
  4. Läs **Moms du redovisar själv**. Registrera.
- Förväntat resultat:
  - Beräknad moms: 10 000,00 × 25 % = 2 500,00.
  - Verifikation D: 4535 debet 10 000,00; 2645 debet 2 500,00; 2614 kredit 2 500,00; 2440 kredit 10 000,00. Debet 12 500,00 = kredit 12 500,00.
  - Momsdeklarationen för perioden: ruta 21 = 10 000,00; ruta 30 = 2 500,00; ruta 48 = 2 500,00. Effekten på ruta 49 (moms att betala) = 2 500,00 - 2 500,00 = 0,00 från denna faktura.
  - Saknas konto 4535, 2645 eller 2614 i kontoplanen läggs det till med sitt BAS-namn och en post skrivs i behandlingshistoriken.
  - Konsulten verifierar: att 4535-4537 och ruta 21 är rätt för tjänster från annat EU-land, och att Skatteverkets "Fylla i momsdeklarationen" stämmer med boxarna (ADR-016: `TODO(legal-verify)` vilka rutor och konton för varje inköpstyp).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-14 Inköp av varor från annat EU-land med kontantmetoden (undantaget)

- Förutsättningar: kontantmetoden. Lieferant GmbH. Faktura `DE-78` daterad 2026-10-19.
- Steg i appen:
  1. **Vem redovisar momsen?**: Varor från ett annat EU-land. **Att betala** 20 000,00. Rad: 25 %, belopp 20 000,00. Registrera.
  2. Kontrollera verifikationen. Attestera (B) och betala 20 000,00 på betaldag 2026-11-12.
- Förväntat resultat:
  - Beräknad moms: 20 000,00 × 25 % = 5 000,00.
  - Fakturan bokförs vid registreringen även med kontantmetoden. Verifikation D: 4515 debet 20 000,00; 2645 debet 5 000,00; 2614 kredit 5 000,00; 2440 kredit 20 000,00.
  - Momsdeklarationen: ruta 20 = 20 000,00; ruta 30 = 5 000,00; ruta 48 = 5 000,00 för den månad fakturan är utfärdad.
  - Betalningen (E) flyttar bara beloppet: 2440 debet 20 000,00; 1930 kredit 20 000,00.
  - Jämför: en tjänstefaktura från samma leverantör (LEV-13) bokför ingenting vid registreringen med kontantmetoden.
  - Konsulten verifierar: att varuförvärv inom EU redovisas när fakturan utfärdas även med kontantmetoden (mervärdesskattelagen 7 kap. 26 och 40 §§; markerat "juristgranskning återstår", AUD-FAKT-4, #50).
  - Byt tillbaka till fakturametoden efter fallet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-15 Omvänd betalningsskyldighet i Sverige (bygg), två momssatser

- Förutsättningar: en svensk leverantör (Bygg Underentreprenör AB, valfritt organisationsnummer som klarar kontrollsiffran). Fakturametoden.
- Steg i appen:
  1. Ny leverantörsfaktura, datum 2026-10-22. **Vem redovisar momsen?**: Tjänster i Sverige, omvänd betalningsskyldighet (till exempel bygg).
  2. **Att betala** 15 000,00. Rad 1: 25 %, 10 000,00. Rad 2: 12 %, 5 000,00. Registrera.
- Förväntat resultat:
  - Beräknad moms per sats: 10 000,00 × 25 % = 2 500,00; 5 000,00 × 12 % = 600,00; totalt 3 100,00.
  - Verifikation D: 4425 debet 10 000,00; 4426 debet 5 000,00; 2647 debet 2 500,00; 2614 kredit 2 500,00; 2647 debet 600,00; 2624 kredit 600,00; 2440 kredit 15 000,00. Debet 18 100,00 = kredit 18 100,00.
  - Momsdeklarationen: ruta 24 = 15 000,00; ruta 30 = 2 500,00; ruta 31 = 600,00; ruta 48 = 3 100,00.
  - Konsulten verifierar: att 2647 och ruta 24 gäller för tjänster med omvänd betalningsskyldighet i Sverige.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-16 Varor från land utanför EU (import): ingen moms på fakturan

- Förutsättningar: Cloud Inc (land US).
- Steg i appen:
  1. Ny leverantörsfaktura, datum 2026-10-23. **Vem redovisar momsen?**: Varor från ett land utanför EU (import). **Att betala** 8 000,00. Rad: konto 4085, 8 000,00. Försök registrera.
  2. Ändra kontot till 4010 och registrera.
- Förväntat resultat:
  - Steg 1: nekas. Konto 4085 (4085-4087 och 4545-4547) är bara för verifikationen från tullräkningen och ger ruta 50. Meddelandet säger att varorna ska konteras på till exempel 4080 eller 4010.
  - Steg 2: verifikation D: 4010 debet 8 000,00; 2440 kredit 8 000,00. Ingen moms räknas fram på fakturan (importmomsen bokförs från tullräkningen, se momsmodulen, ADR-057).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-17 Betalfil i kronor: skapa, godkänn, ladda ned

- Förutsättningar: bankinställningarna är ifyllda (se Förutsättningar). Två attesterade fakturor till bankgiro/plusgiro: F-1001 (12 500,00, förfallodag 2026-11-14, OCR 1234567890, bankgiro) och en faktura `P-1` från Leverantör Plusgiro AB på 3 750,50, utan OCR (fakturanummer P-1). Båda är obetalda. A skapar, B godkänner.
- Steg i appen:
  1. Som A: **Inköp → Betalfiler**. Under **Att betala**: kryssa i båda. **Betaldag** D. Kontrollera antalet och summan och klicka på **Skapa betalfil**.
  2. Som A: försök godkänna filen med **Godkänn betalningen**.
  3. Som B: **Godkänn betalningen**, läs sammanfattningen, **Ja, godkänn**.
  4. Som B: **Ladda ned fil**.
  5. Prova **Betaldag** igår på en ny fil.
- Förväntat resultat:
  - Filen får status "Skapad, väntar på banken" med 2 betalningar och summa 12 500,00 + 3 750,50 = 16 250,50 kr. I XML-filen: `NbOfTxs` = 2, `CtrlSum` = 16250.50, format pain.001.001.03. Ingenting bokförs när filen skapas eller godkänns.
  - Steg 2: nekas: "Du skapade betalfilen och kan därför inte godkänna den. Någon annan med rätt att godkänna betalningar behöver göra det."
  - Steg 3: godkännandet sparas med namn och tid. Först nu går filen att ladda ned (steg 4).
  - Betalningarna i filen: bankgiro 5402-9681 med OCR-referens 1234567890; plusgiro 12345674 med meddelande "Faktura P-1" (saknar OCR). Varje betalning har en unik referens "LF<löpnummer>-<filnummer>".
  - Steg 5: "Betaldagen kan inte vara passerad."
  - Filens profil följer vald bank (Handelsbanken sätter bland annat kategorin SUPP och mottagarens clearing 9900 för bankgiro och 9500 för plusgiro, se `PaymentBank.php`). Konsulten kontrollerar att summor, antal och referenser stämmer; filformatet provas i bankens testtjänst (se TODO i `PaymentBank.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-18 Betalfil: fakturor som inte kan tas med

- Förutsättningar: bankinställningar ifyllda.
- Steg i appen:
  1. Försök lägga följande i en betalfil och läs felmeddelandena: (a) en kreditnota; (b) en registrerad men inte attesterad faktura; (c) en faktura som redan ligger i en skapad betalfil som väntar på banken; (d) en faktura från en leverantör som saknade bankgiro/plusgiro när fakturan registrerades.
  2. Skapa en fil med 0 kryssade fakturor.
  3. Ta bort IBAN eller avtals-id under **Bank för betalfiler** och försök skapa fil.
- Förväntat resultat:
  - (a) ":title är en kreditnota. Kvitta den mot en faktura i stället." (b) ":title är inte attesterad och obetald." (c) ":title finns redan i en betalfil som väntar på banken." (d) ":title: leverantören hade inget bankgiro eller plusgiro när fakturan registrerades. Betala den i internetbanken." Ingen fil skapas om någon av fakturorna ger fel, och fakturornas nummer räknas inte upp.
  - Steg 2: "Välj minst en faktura att betala."
  - Steg 3: "Fyll i bank, IBAN och avtals-id under Betalfiler innan du skapar en betalfil."
  - Betalningsuppgifterna tas från leverantören som den var när fakturan registrerades. Ändra bankgiro i leverantörsregistret efter registreringen och skapa en fil: filen ska fortfarande använda det gamla bankgirot (skydd mot omdirigering av en redan attesterad faktura).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-19 Markera filen som betald och makulera

- Förutsättningar: en godkänd betalfil med två fakturor (LEV-17).
- Steg i appen:
  1. Klicka på **Banken har betalat** och **Ja, bokför**.
  2. Skapa en ny fil och godkänn den. Klicka på **Makulera** och **Ja, makulera**.
  3. Försök makulera en fil där en betalning redan är bokförd.
- Förväntat resultat:
  - Steg 1: varje faktura får en egen betalning i serie E på filens betaldag: F-1001: 2440 debet 12 500,00 / 1930 kredit 12 500,00; P-1: 2440 debet 3 750,50 / 1930 kredit 3 750,50. Filen blir **Betald**. Summa 1930 = 16 250,50.
  - Steg 2: filen blir Makulerad och fakturorna kan tas med i en ny fil.
  - Steg 3: nekas: "Banken har redan betalat en del av filen, och de betalningarna är bokförda..."
  - Är fakturan under tiden betald på annat sätt: "...har betalats på annat sätt sedan filen skapades. Makulera filen och gör en ny."
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-20 Bankens svar: avvisad betalning (pain.002)

- Förutsättningar: en godkänd, nedladdad betalfil med två fakturor (referenser "LF1-<n>" och "LF2-<n>"). Konsulten har en syntetisk pain.002 från NONOX-teamet som avvisar betalning 1 med orsakskod AC03 och godtar betalning 2.
- Steg i appen:
  1. **Inköp → Betalfiler**. Under **Läs in bankens svar**: dra pain.002-filen eller klicka på **välj fil**.
  2. Läs resultatet under **Svar i** och filens namn.
  3. Läs in samma fil en gång till.
- Förväntat resultat:
  - Betalning 1: "Avvisad av banken", orsak "Mottagarens konto är fel. (AC03: ...)", fakturan har aldrig bokförts som betald och ligger kvar under **Att betala**. En punkt dyker upp under **Att göra** på översikten.
  - Betalning 2: markeras som accepterad av banken; ingen bokföring.
  - Svarsstatus: **Matchad automatiskt** för båda (matchning på NONOX egna referenser).
  - Steg 3: "Redan hanterad"; inget nytt görs (samma svar läses inte två gånger).
  - Är alla betalningar avvisade blir filen **Makulerad**. Är bara en avvisad står filen kvar öppen tills resten är betald.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-21 Bankens svar: debiteringsavisering (camt.054), belopp som avviker, dubblett

- Förutsättningar: godkänd betalfil med F-1001 (12 500,00) och P-1 (3 750,50). Syntetiska camt.054-filer: (a) debitering av 12 500,00 på bokföringsdag 2026-11-13 med NONOX referens; (b) debitering 12 400,00 för F-1001 med referens; (c) debitering av 12 500,00 utan referens, rätt bankgiro, datum nära; (d) samma fil som (a) en gång till.
- Steg i appen:
  1. Läs in (a).
  2. Läs in (d).
  3. På en ny fil: läs in (b).
  4. På en ny fil: läs in (c). Klicka på **Bekräfta** om förslaget är rimligt.
  5. På en ny, ogodkänd fil: läs in (a).
- Förväntat resultat:
  - Steg 1: **Matchad automatiskt**. Betalning på bankens bokföringsdag 2026-11-13, serie E: 2440 debet 12 500,00; 1930 kredit 12 500,00. Fakturan blir Betald.
  - Steg 2: **Redan hanterad**. Ingen ny verifikation.
  - Steg 3: **Okänd**: banken drog 12 400,00 men filen sade 12 500,00 (12 400,00 ≠ 12 500,00). Inget bokförs. Texten anger att beloppet skiljer sig.
  - Steg 4: matchas på belopp och bankgiro: **Behöver bekräftas**. NONOX bokför aldrig ett sådant förslag själv. Efter **Bekräfta** bokförs som steg 1.
  - Steg 5: filen är inte godkänd i NONOX: **Behöver bekräftas** (godkänn filen och bekräfta svaret). Inget bokförs automatiskt.
  - När alla betalningar i filen är betalda eller avvisade får filen status Betald.
  - Konsulten bedömer att bokföring via bankens avisering (kundens betaldag = bankens bokföringsdag) är rätt betaldag.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-22 Faktura i utländsk valuta (EUR): registrering och betalning i två delar med kursdifferens

- Förutsättningar: Lieferant GmbH. Fakturametoden. Riksbankens kurs får inte hämtas automatiskt: skriv kursen själv (11,2345) så att fallet blir reproducerbart.
- Steg i appen:
  1. Ny leverantörsfaktura, Lieferant GmbH, datum 2026-10-20. **Vem redovisar momsen?**: Tjänster från ett annat EU-land.
  2. **Valuta** EUR. **Att betala i EUR** 1 000,00. **Kurs, kronor per EUR** 11,2345. **Att betala i kronor** 11 234,50. Rad: 25 %, 11 234,50. Registrera.
  3. Försök i stället skriva **Att betala i kronor** 11 235,51 med samma EUR och kurs. Skriv sedan 11 235,50.
  4. Attestera fakturan (B). Betala: **Belopp i EUR** 400,00, **Kronor som banken drog** 4 560,00.
  5. Betala: **Belopp i EUR** 600,00 (resten), **Kronor som banken drog** 6 600,00.
- Förväntat resultat:
  - Kurskontroll: 1 000,00 EUR × 11,2345 = 11 234,50 kr. Steg 3: 11 235,51 nekas med "1 000,00 EUR till kursen 11,234500 blir 11 234,50 kr, men beloppet i kronor är 11 235,51 kr." (avvikelsen 1,01 kr är över gränsen 1,00 kr). 11 235,50 godtas (avvikelse exakt 1,00 kr).
  - Verifikation D (steg 2): 4535 debet 11 234,50; 2645 debet 2 808,63 (25 % av 11 234,50 = 2 808,625, avrundat halv upp till 2 808,63); 2614 kredit 2 808,63; 2440 kredit 11 234,50.
  - Betalning 1 (400,00 EUR): bokförd andel = 11 234,50 × 400 / 1 000 = 4 493,80. Banken drog 4 560,00. Differens 4 560,00 - 4 493,80 = 66,20 (förlust). Serie E: 2440 debet 4 493,80; 7960 debet 66,20; 1930 kredit 4 560,00. Debet 4 560,00 = kredit 4 560,00.
  - Betalning 2 (600,00 EUR, rest): den sista betalningen tar allt som återstår: 11 234,50 - 4 493,80 = 6 740,70 (= 11 234,50 × 600 / 1 000 = 6 740,70). Banken drog 6 600,00: differens 6 600,00 - 6 740,70 = -140,70 (vinst). Serie E: 2440 debet 6 740,70; 1930 kredit 6 600,00; 3960 kredit 140,70. Debet 6 740,70 = kredit 6 740,70.
  - Skuldkontot 2440 går ut på 0,00. Sammanlagt kursresultat: förlust 66,20 - vinst 140,70 = nettovinst 74,50.
  - Betalar du samma kurs (kronor som banken drog = bokförd andel) bokförs ingen differensrad.
  - Konsulten verifierar: att Riksbankens mittkurs på fakturadatumet är en godtagbar bokföringskurs och att momsens beskattningsunderlag får räknas om med samma kurs (mervärdesskattelagen 8 kap. 21 §; `TODO(legal-verify)` i `RiksbankRates.php` och ADR-025), och att kursdifferens på leverantörsskulder hör hemma på 3960/7960.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-23 Kreditnota i utländsk valuta: återbetalning med kursvinst

- Förutsättningar: Lieferant GmbH. Attesterad kreditnota på 200,00 EUR, kurs 11,2345, i kronor 2 246,90 (200 × 11,2345 = 2 246,90), samma behandling som LEV-22.
- Steg i appen:
  1. Registrera kreditnotan (kryssa i **Det här är en kreditnota**) och attestera den.
  2. Under **Registrera återbetalning**: belopp i EUR 200,00, kronor som banken satte in 2 300,00.
  3. Försök kvitta kreditnotan mot en faktura i EUR.
- Förväntat resultat:
  - Återbetalning: kronor in 2 300,00 mot bokförda 2 246,90: 2 300,00 - 2 246,90 = 53,10 mer än bokfört = kursvinst. Serie E: 1930 debet 2 300,00; 2440 kredit 2 246,90; 3960 kredit 53,10. Debet 2 300,00 = kredit 2 300,00.
  - Steg 3: nekas: "Kreditnotor och fakturor i utländsk valuta kan inte kvittas ännu. Registrera betalningarna var för sig."
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-24 Betalfil för utlandsbetalning (Handelsbanken eller Nordea), IBAN och rapporteringsgräns

- Förutsättningar: bank = Handelsbanken (alternativt Nordea). Lieferant GmbH med IBAN, BIC, land DE, ort Köln, adress. Attesterade fakturor i EUR enligt stegen nedan (en ny faktura per steg). Betalningskod är tom på leverantören utom där det anges.
- Steg i appen:
  1. Skapa en faktura i EUR till kurs 12,0000 och 10 000,00 EUR, attestera, lägg i en betalfil.
  2. Samma med 10 000,01 EUR.
  3. Fyll i **Betalningskod för utlandsbetalning** `410` på leverantören och försök igen med faktura 2 (registrera en ny faktura, eftersom uppgifterna hämtas från registreringsdagen).
  4. Lägg en EUR-faktura och en SEK-faktura i samma fil.
  5. Skapa en faktura i JPY.
  6. Registrera en leverantör i Sverige (land SE, IBAN `SE45 5000 0000 0583 9825 7466`, ort Stockholm) och en EUR-faktura från den.
- Förväntat resultat:
  - Steg 1: beräknad betalning i kronor = 10 000,00 × 12,0000 = 120 000,00 kr. Gränsen för att kräva betalningskod är att beloppet gånger 125 % är större än 150 000 kr: 120 000,00 × 1,25 = 150 000,00, inte större. Filen skapas utan betalningskod. 
  - Steg 2: 10 000,01 EUR × 12,0000 = 120 000,12 kr; × 1,25 = 150 000,15 kr > 150 000 kr. Filen nekas: "...betalningen kan komma att överstiga 150 000 kronor (den är över 120 000 kronor till fakturans kurs) och ska då rapporteras till Skatteverket. Fyll i leverantörens betalningskod (tre siffror, till exempel 410 för datatjänster)..."
  - Steg 3: filen skapas med koden 410 (`RgltryRptg/Dtls/Cd`); Nordea får dessutom landet SE.
  - Steg 4: nekas: "En betalfil kan bara ha betalningar i en valuta. Skapa en betalfil för kronor och en för varje annan valuta."
  - Steg 5: JPY (och andra valutor utanför listan AUD, CAD, CHF, CZK, DKK, EUR, GBP, NOK, PLN, USD) kan inte betalas via fil: "...som inte kan betalas via betalfil. Betala den i internetbanken och registrera betalningen på fakturan."
  - Steg 6: "leverantören är registrerad i Sverige men fakturan är i EUR. Betala den i internetbanken."
  - EUR till IBAN i SEPA-land: avgiftsfördelning SLEV; övriga SHAR. Å, ä, ö, ü skrivs som a, a, o, u i filen ("Koln"). Beräknat belopp i kronor i filen är en uppskattning; kronorna bokförs först av bankens camt.054.
  - Banker som inte är Handelsbanken eller Nordea: utlandsbetalning nekas med att betalfil för utlandsbetalningar saknas för banken.
  - Konsulten verifierar: att gränsen 150 000 kr gäller på beloppet i kronor (Skatteverkets KU80/KU81-vägledning, `TODO(legal-verify)` i `ForeignPayment.php`) och att marginalen 125 % är rimlig. Kunde inte beräknas utan bankens syntetiska fil: filens fullständiga XML-innehåll (granskas av NONOX-teamet mot bankens guide).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LEV-25 Bankens svar för utlandsbetalning: toleransen mot Riksbankens kurs

- Förutsättningar: en godkänd betalfil med en EUR-betalning (EUR 1 000,00; fakturakurs 11,2345). Syntetisk camt.054 med `InstdAmt` EUR 1 000,00 och debiterade kronor enligt tabellen. Riksbankens kurs på bokföringsdagen sätts av NONOX-teamet till 11,2000 (annars gäller fakturans kurs 11,2345).
- Steg i appen:
  1. Läs in aviseringar där banken drog 11 700,00; 11 800,00; sedan en där EUR-beloppet inte stämmer (EUR 999,00).
  2. Upprepa med EUR 100,00 (kurs 11,2000) och 100 000,00 EUR.
- Förväntat resultat (referenskurs 11,2000):
  - EUR 1 000,00: referens 11 200,00 kr; tillåten avvikelse 5 % = 560,00 kr (mellan 500 och 5 000 kr). 11 700,00 kr: avvikelse 500,00 ≤ 560,00 → bokförs automatiskt. 11 800,00 kr: avvikelse 600,00 > 560,00 → **Behöver bekräftas** (kursavvikelse).
  - EUR 100,00: referens 1 120,00 kr; 5 % = 56,00 kr, men minst 500,00 kr gäller → tillåten avvikelse 500,00 kr. Banken drar 1 620,00 → avvikelse 500,00 → automatiskt; 1 621,00 → bekräfta.
  - EUR 100 000,00: referens 1 120 000,00 kr; 5 % = 56 000,00 kr, men högst 5 000,00 kr gäller → tillåten avvikelse 5 000,00 kr.
  - EUR 999,00 i aviseringen mot 1 000,00 i filen: **Okänd**/bekräfta, bokförs inte automatiskt.
  - Bokförd kursdifferens vid automatisk bokföring (11 700,00): bokförd andel 11 234,50 (fakturan, 1 000,00 EUR) → differens 11 700,00 - 11 234,50 = 465,50 förlust (7960 debet 465,50).
  - Konsulten verifierar: gränserna 5 % / 500 kr / 5 000 kr är NONOX egna (inte bankens) och är avsedda som säkerhetsspärr; bedöm om nivåerna är rimliga för bolagets storlek.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Hämtat med `grep -rn "TODO(legal-verify)"` över `app/Actions/Payables`, `app/Domain/Payables`, `app/Support/Pain001`, `app/Support/Currency` och ADR-013/014/016/025. Övriga markeringar ("juristgranskning återstår", "provider-verify", "verify-with-bank") tas med så att inget faller bort.

| Nr | Fil:rad | Frågan | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/Payables/SupplierInvoiceBooking.php:45 | Under kontantmetoden: leverantörsfakturor som är obetalda vid räkenskapsårets slut ska bokföras som skulder; det hör till bokslutet (som för kundfakturor). | | |
| 2 | app/Domain/Payables/ForeignPayment.php:52 | Gränsen 150 000 kr för betalningskod vid utlandsbetalning, och om den bedöms på kronorna banken tar eller på fakturabeloppet. | | |
| 3 | app/Support/Currency/RiksbankRates.php:25 | Vilken kurs en utländsk leverantörsfaktura ska bokföras till (Riksbankens mittkurs på fakturadatumet används), och att kursdifferenser vid betalning hör hemma på 3960/7960. | | |
| 4 | docs/adr/ADR-013-supplier-invoices.md:61 | Vilka rutor och konton som gäller för varje sorts utländskt inköp och omvänd betalningsskyldighet. | | |
| 5 | docs/adr/ADR-013-supplier-invoices.md:108 | Hur årsbokslutsposten för obetalda fakturor under kontantmetoden återförs (återföring första dagen nästa år); varken BFL eller BFNAR 2006:1 anger det. | | |
| 6 | docs/adr/ADR-013-supplier-invoices.md:119 | I vilken momsperiod ett bolag med kontantmetoden redovisar momsen på fakturor som är obetalda vid årsskiftet (ML 2023:200 7 kap.). | | |
| 7 | docs/adr/ADR-016-supplier-credit-and-foreign-purchases.md:49 | Vilket datum som gäller vid omräkning för varor från annat EU-land (leverans- eller fakturadatum, ML 13 kap. 12 §). | | |
| 8 | docs/adr/ADR-016-supplier-credit-and-foreign-purchases.md:51 | Om varje leverantör utanför EU måste visa momsregistreringsnummer; systemet kräver inget. | | |
| 9 | docs/adr/ADR-014-payment-files.md:239 | Om gränsen för betalningskod bedöms på bankens kronor eller på bokföringskursen; en betalning nära gränsen kan kräva koden fast kontrollen passerade. | | |
| 10 | docs/adr/ADR-014-payment-files.md:274 | Gränsen och dess grund för betalningskod (125 % marginal, från 120 000 kr). | | |
| 11 | docs/adr/ADR-025-exchange-differences.md:208 | För kundfakturor: vilken paragraf i ML 17 kap. som motsvarar direktivets artikel 230 (faktura i annan valuta) och om beloppet i kronor också måste stå på fakturan. | | |
| 12 | docs/adr/ADR-025-exchange-differences.md:213 | Riksbankens mittkurs som balansdagskurs, och återföringen första dagen nästa år. | | |
| 13 | docs/adr/ADR-025-exchange-differences.md:224 | Om Riksbankens mittkurs räknas som "senast fastställda genomsnittskurs på den mest representativa valutamarknaden i Sverige" (ML 8 kap. 21 §). | | |
| 14 | docs/adr/ADR-025-exchange-differences.md:230 | Förskottsfakturor (momsen uppstår vid betalning, 7 kap. 7 §) och byggtjänster med omvänd betalningsskyldighet (fakturadatum, 7 kap. 8 §) använder samma datum som övriga fakturor. | | |
| 15 | docs/adr/ADR-025-exchange-differences.md:234 | Att realiserade kursdifferenser på leverantörsskulder hör hemma på 3960/7960 ("av rörelsekaraktär") och inte bland finansiella poster i klass 8. | | |
| 16 | app/Domain/Payables/SupplierInvoiceBooking.php:34 | (Markering "juristgranskning återstår", AUD-FAKT-3, #52) Kontantmetoden: sista betalningen bokför det som återstår av varje rad så att betalningarna tillsammans bokför fakturans kostnader och moms (ML 2023:200 7 kap. 33 §). | | |
| 17 | app/Domain/Payables/SupplierInvoiceBooking.php:39 och app/Domain/Payables/VatTreatment.php:74 | (Markering "juristgranskning återstår", AUD-FAKT-4, #50) Gemenskapsinternt förvärv av varor bokförs vid registreringen även med kontantmetoden (ML 7 kap. 26 och 40 §§; 7 kap. 16 § andra stycket). | | |
| 18 | docs/adr/ADR-013-supplier-invoices.md:71 | (Markering "AI-utredning, juristgranskning återstår") BFL 5 kap. 2 §: samtliga obetalda skulder ska bokföras vid räkenskapsårets utgång; BFNAR 2006:1 punkt 4.2 c. | | |
| 19 | app/Domain/Payables/PaymentBank.php:12 | TODO(verify-with-bank): varje bolag ska skicka en testfil genom bankens testtjänst före första skarpa betalfil. | | |
| 20 | app/Domain/Payables/PaymentBank.php:150 | TODO(provider-verify): Danske Bank, SEB, Swedbank och Länsförsäkringar: bankernas guider för utlandsbetalning är inte lästa. | | |
| 21 | app/Domain/Payables/ForeignPayment.php:29 | TODO(provider-verify): listan över valutor (AUD, CAD, CHF, CZK, DKK, EUR, GBP, NOK, PLN, USD) per bank. | | |
| 22 | app/Domain/Payables/Iban.php:16 | TODO(provider-verify): IBAN-längder för fler länder; ett land som inte finns i listan nekas. | | |
| 23 | app/Actions/Payables/CreatePaymentBatch.php:100 | TODO(provider-verify): tillåt blandade valutor i en fil först efter test hos varje bank. | | |

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen **Leverantörsfakturor och betalningar** är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor eller skäl: ____________________________________________

Godkännandet gäller enbart den här modulen.
