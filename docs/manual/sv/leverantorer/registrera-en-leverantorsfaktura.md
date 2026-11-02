---
beskrivning: Ladda upp fakturan, fyll i belopp och moms, kontera raderna och registrera den så att den bokförs och går vidare till attest.
ordning: 20
roller: [owner, admin, accountant, bookkeeper]
rutter: [payables.invoices.index, payables.invoices.create, payables.invoices.edit, payables.invoices.file]
sokord: [leverantörsfaktura, ny faktura, kostnadsfaktura, registrera, kontera, inköp, serie D, 2440, ingående moms]
---

# Registrera en leverantörsfaktura

När en faktura kommer från en leverantör registrerar du den i NONOX. Då bokförs inköpet, fakturan sparas som underlag och den hamnar i listan över det som ska attesteras och betalas. Fakturan ligger bredvid formuläret medan du fyller i, så att du kan skriva av den fält för fält.

Leverantören måste finnas i [leverantörsregistret](/manual/leverantorer/leverantorsregistret) innan fakturan kan sparas.

![Leverantörsfakturorna med vad som ska betalas, vad som väntar på attest och status per faktura.](/bilder/manual/leverantorer/registrera-en-leverantorsfaktura.webp)

## Så här gör du

1. Gå till **Inköp → Leverantörsfakturor** och klicka på **Ny leverantörsfaktura**.
2. Dra fakturan till rutan **Fakturan**, eller klicka på **välj fil**. Den får vara en PDF eller en bild (JPG, PNG eller WebP) på högst 10 MB. På mobilen kan du i stället trycka på **Fota fakturan**.
3. Välj **Leverantör**.
4. Välj under **Vem redovisar momsen?**. För en vanlig svensk faktura är det **Svensk faktura med moms**. De andra valen beskrivs i [Inköp från utlandet](/manual/leverantorer/inkop-fran-utlandet).
5. Fyll i **Leverantörens fakturanummer** och **OCR-nummer** om fakturan har ett. OCR-numret används när fakturan betalas.
6. Fyll i **Fakturadatum**. Fakturan bokförs på det datumet.
7. Fyll i **Förfallodag**, eller lämna den tom så räknas den fram från leverantörens betalningsvillkor.
8. Fyll i **Att betala, inkl. moms** och **Varav moms** – summan av momsen på fakturan, alla momssatser tillsammans.
9. Kontera under **Kontering**: en rad per kostnadskonto, med beloppet **utan moms**. Har leverantören ett förvalt konto räcker det ofta med en rad, och den fylls i av sig själv.
10. Kontrollera att det står **Raderna och momsen stämmer med fakturan.** under raderna.
11. Klicka på **Registrera fakturan** och sedan **Ja, registrera**.

Vill du göra klart senare klickar du på **Spara som utkast** i stället. Ett utkast kan du ändra eller ta bort med **Ta bort utkastet** så länge det inte är registrerat.

> [!IMPORTANT]
> När fakturan är registrerad får den ett löpnummer, bokförs och kan inte ändras. Har något blivit fel rättar du verifikationen, eller registrerar en kreditnota om leverantören skickar en.

## Kontera på flera rader

Gäller fakturan olika saker – varor och frakt, eller två restauranger – delar du upp den på flera rader med **+ Lägg till rad**.

- Under raderna visas **Kvar att fördela:** så länge raderna plus momsen inte är lika med beloppet att betala. Klicka på **Lägg på sista raden** för att lägga resten där.
- Har företaget kostnadsställen eller projekt visas kolumnerna **Kostnadsställe** och **Projekt**. Välj per rad.
- Fakturan går inte att spara om raderna och momsen inte blir exakt beloppet att betala. Felmeddelandet säger hur stor skillnaden är.

## Det här händer i bokföringen

Innan du registrerar visar rutan **Så här bokförs fakturan när du registrerar den** exakt vilken verifikation det blir.

Bokför företaget enligt **fakturametoden** blir det en verifikation i serie D på fakturadatumet:

| Konto | Debet | Kredit |
|---|---|---|
| Kostnadskontona på raderna | belopp utan moms | |
| 2641 Ingående moms | momsen | |
| 2440 Leverantörsskulder | | att betala |

Fakturafilen blir underlag till verifikationen. När fakturan sedan betalas bokförs betalningen i serie E, se [Registrera en betalning](/manual/leverantorer/registrera-en-betalning).

Bokför företaget enligt **kontantmetoden** bokförs ingenting när fakturan registreras. Kostnaden och momsen bokförs när fakturan betalas, och fakturafilen blir då underlag till betalningens verifikation.

## Listan över leverantörsfakturor

Under **Inköp → Leverantörsfakturor** kan du filtrera på **Alla**, **Utkast**, **Att attestera**, **Att betala**, **Förfallna** och **Betalda**. En faktura vars förfallodag har passerat utan att den är betald märks **Förfallen**. Klicka på numret för att öppna fakturan; ett utkast öppnas för redigering.

## Vanliga frågor

**Fakturan saknar fil – kan jag ändå registrera den?**
Ja. Då visas **Ingen fil** på fakturan. Spara pappersfakturan, eller lägg till den som underlag på verifikationen efteråt. Läs [Underlag](/manual/bokforing/underlag).

**Leverantören finns inte i listan.**
Lägg till den under **Register → Leverantörer** först. Läser NONOX AI fakturan kan du i stället kryssa i **Skapa leverantören med de här uppgifterna och koppla fakturan till den när du sparar**.

**Det är en anläggningstillgång, inte en kostnad.**
Konterar du en rad på ett anskaffningskonto, till exempel 1220 Inventarier, erbjuder formuläret att lägga in tillgången i anläggningsregistret. Den skapas när fakturan registreras. Läs [Anläggningstillgång direkt från inköpet](/manual/bokslut/tillgang-fran-inkop).

## Läs också

- [Attest](/manual/leverantorer/attest)
- [Fota fakturan och låt NONOX AI läsa den](/manual/leverantorer/fota-och-las-fakturan)
- [Kreditnotor från leverantörer](/manual/leverantorer/kreditnotor)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
