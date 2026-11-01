---
beskrivning: Skicka en skapad faktura som PDF med e-post, se OCR-numret och följ fakturans status, betalningar och verifikation på fakturans sida.
ordning: 40
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.invoices.show, invoicing.invoices.pdf]
sokord: [skicka faktura, mejla faktura, e-faktura, PDF, OCR, OCR-nummer, fakturakopia, skriva ut faktura]
---

# Skicka fakturan och öppna PDF:en

När du har skapat en faktura kommer du till fakturans sida. Till vänster ser du fakturan precis som kunden ser den. Till höger finns allt du kan göra med den: skicka den, registrera betalningar, skicka påminnelser och kreditera.

## Så här skickar du fakturan med e-post

1. Öppna fakturan under **Försäljning → Fakturor**.
2. Under **Skicka med e-post** står kundens adress från **E-post för fakturor** redan ifylld. Ändra den om fakturan ska till någon annan.
3. Tryck på **Skicka**.

Du ser meddelandet *Faktura 1029 är skickad till …* och under **Skickad** står datum, tid och adress. Behöver kunden fakturan igen trycker du på **Skicka igen**.

Kunden får ett mejl med ämnet *Faktura 1029 från Exempelbolaget AB* och fakturan som PDF. Svarar kunden på mejlet går svaret till företagets e-postadress från **Inställningar för företaget**.

> [!TIP]
> Vill du skicka fakturan på papper eller i ett eget mejl? Tryck på **Öppna PDF**, spara filen och skicka den som du vill. Fakturan är densamma.

## Det här står på fakturan

- Fakturanummer, fakturadatum, förfallodag och leveransdatum.
- Kundens namn och adress som de var när fakturan skapades. Ändrar du kunden senare påverkas inte fakturan.
- Raderna med antal, à-pris, rabatt och belopp, summan utan moms, momsen per momssats, öresavrundning och vad kunden ska betala.
- Rutan **Betalning** med företagets bankgiro eller plusgiro, *Ange OCR-nummer …* och sista betaldag.
- Längst ned: företagets namn, organisationsnummer, momsregistreringsnummer, *Godkänd för F-skatt* om företaget har F-skatt, e-post och telefon.
- Företagets logga och färger, om du har lagt in dem. Läs [Logga och färger på fakturan](/manual/fakturering/logga-och-farger).

Bankgiro, plusgiro, e-post och F-skatt fyller du i under **Inställningar för företaget** i företagsmenyn. Saknas bankgiro och plusgiro får kunden inget kontonummer att betala till – kontrollera det innan du skickar din första faktura.

## OCR-numret

Varje faktura får ett OCR-nummer när den skapas: fakturanumret följt av en kontrollsiffra. Kontrollsiffran gör att ett felskrivet nummer upptäcks. När kunden betalar med OCR-numret kan NONOX koppla betalningen till rätt faktura av sig själv. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

## Fakturans status

| Status | Betyder |
|---|---|
| **Utkast** | Inte skapad än. Har inget nummer och är inte bokförd. |
| **Obetald** | Skapad och bokförd, ingen eller bara en del av betalningen har kommit. |
| **Förfallen** | Obetald och förfallodagen har passerat. |
| **Betald** | Hela beloppet är betalt. |
| **Krediterad** | En kreditfaktura har tagit ut fakturan. |

Under **Bokförd** finns en länk till fakturans verifikation i serie B. Bokförs företaget med kontantmetoden står det *Vid betalning (kontantmetoden)* i stället.

## Bra att veta

- I ett testföretag skickas inga mejl. Fakturan märks som skickad men lämnar aldrig NONOX.
- NONOX skickar fakturor som PDF med e-post. Peppol och e-faktura till banken går inte att skicka från NONOX.
- Varje utskick sparas i behandlingshistoriken med mottagarens adress.

## Vanliga frågor

**Kunden säger att mejlet aldrig kom.**
Kontrollera adressen under **Skickad**. Be kunden titta i skräpposten, eller skicka PDF:en själv från **Öppna PDF**.

**Kan jag skicka fakturan till flera adresser?**
En adress per utskick. Tryck på **Skicka igen** med en annan adress om fler ska ha den.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Påminnelser](/manual/fakturering/paminnelser)
- [Kreditera en faktura](/manual/fakturering/kreditfaktura)
