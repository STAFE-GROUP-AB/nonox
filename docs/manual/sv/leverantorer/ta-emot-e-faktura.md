---
beskrivning: Ta emot leverantörsfakturor som e-faktura via Peppol. De hamnar i inkorgen som förslag som du attesterar, och originalfilen sparas som underlag.
ordning: 115
roller: [owner, admin, accountant, bookkeeper]
modul: peppol
sokord: [peppol, e-faktura, ta emot e-faktura, elektronisk faktura, leverantörsfaktura, Peppol-ID, registrera i Peppol]
---

# Ta emot e-fakturor via Peppol

Leverantörer som skickar e-fakturor via Peppol kan skicka dem direkt till företaget. E-fakturan hamnar i inkorgen, där NONOX har läst den till ett förslag till leverantörsfaktura: leverantör, rader, moms, belopp, förfallodag, bankgiro och OCR. Du granskar och godkänner som vanligt. Ingen AI behövs, eftersom e-fakturan redan är strukturerad.

För det behövs två moduler: **Peppol e-faktura** och **Inkorg**. Läs [Inkorgen](/manual/leverantorer/inkorgen) om du inte har använt den.

## Så här tar du emot e-fakturor

1. Slå på **Inkorg** och **Peppol e-faktura** under **Inställningar för företaget → Moduler**.
2. Kontrollera **Företagets Peppol-ID** i inställningarna för Peppol e-faktura.
3. Kryssa i **Ta emot leverantörsfakturor via Peppol** och tryck på **Spara**.
4. Under **Registrering i Peppol** kryssar du i att du får företräda företaget och godkänner registreringen.
5. Tryck på **Registrera som mottagare**.

Peppol-operatören kontrollerar först vem företaget är och registrerar det sedan i Peppols adressbok. Medan det pågår står det *Väntar på Peppol-operatören*. När det står *Registrerat i Peppol* kan leverantörer hitta företaget. Ge dem företagets Peppol-ID.

> [!IMPORTANT]
> Bara den som får företräda företaget ska godkänna registreringen. NONOX sparar vem som godkände och när.

## Så här godkänner du en e-faktura

1. Öppna **Inkorg** i menyn. E-fakturan heter *E-faktura … via Peppol* och avsändaren är leverantörens Peppol-ID.
2. Kontrollera förslaget bredvid fakturans PDF. Välj konto om raderna saknar ett.
3. Finns leverantören inte i registret trycker du på **Lägg till … i leverantörsregistret**.
4. Godkänn som vanligt. Leverantörsfakturan registreras och attesteras på samma sätt som när den kommer med e-post.

## Bra att veta

- E-fakturans fil sparas precis som den kom, som original och underlag till leverantörsfakturan. Du hämtar den med **Ladda ner originalet (.xml)** i inkorgen.
- Samma e-faktura två gånger tas emot en gång.
- En e-faktura till ett annat Peppol-ID än företagets tas aldrig emot.
- Varningarna i inkorgen gäller också e-fakturor: ändrat bankgiro eller annat konto än i leverantörsregistret, möjliga dubbletter och okänd leverantör.
- Listan med godkända avsändare och kontrollen av e-postens avsändare gäller inte Peppol. Där identifieras avsändaren av sin Peppol-operatör.

## Läs också

- [Inkorgen](/manual/leverantorer/inkorgen)
- [Attest](/manual/leverantorer/attest)
- [Skicka e-faktura via Peppol](/manual/fakturering/e-faktura-via-peppol)
