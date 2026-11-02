---
beskrivning: Ange vilket kollektivavtal företaget är bundet av, om bindningen är som medlem eller genom hängavtal, och från vilket datum.
ordning: 20
roller: [owner, payroll_admin]
modul: agreements
rutter: [agreements.index]
sokord: [välja kollektivavtal, bunden av avtal, hängavtal, medlemskap, avtalsbindning]
---

# Välj avtal för företaget

Det första du gör i Avtal är att ange vilket kollektivavtal företaget är bundet av. Det görs under **Lön → Avtal**, på fliken **Företag och anställda**, i kortet **Avtal som företaget är bundet av**.

![Avtalet företaget är bundet av och anställdas avtal och lönegrupp.](/bilder/manual/avtal/valj-avtal.webp)

## Så här lägger du till ett avtal

1. Öppna **Lön → Avtal**.
2. Klicka på **Lägg till avtal**.
3. Välj **Avtal** i listan.
4. Välj **Bundet som**: **Medlem i arbetsgivarorganisationen** eller **Hängavtal**.
5. Välj **Bundet från**, den dag företaget blev bundet.
6. Lämna **Bundet till (om bindningen har upphört)** tomt om avtalet gäller i dag.
7. Klicka på **Spara avtalet**.

Är företaget bundet av flera avtal lägger du till vart och ett.

> [!TIP]
> Vet du inte vilket avtal eller vilken variant som gäller? Titta i medlemsbekräftelsen från arbetsgivarorganisationen eller i hängavtalet från facket. Med NONOX AI påslaget kan du ladda upp dokumentet och få ett förslag. Se [NONOX AI föreslår avtal och lönegrupp](/manual/avtal/nonox-ai).

## Det här händer när du sparar

- Avtalets egna lönearter läggs till, till exempel **OB-tillägg (Gröna Riks)** och **Övertid, två första timmarna i anslutning (Gröna Riks)**. De har samma skatt, arbetsgivaravgift, semestergrundande lön och konton som systemets OB-tillägg och Övertid. Du använder dem i lönekörningen. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).
- Avtalet kan väljas på anställningarna längre ner på sidan. Se [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld).
- Inget används i lönen förrän avtalets version är kontrollerad. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

Bindningen sparas med datum, och varje ändring skrivs i behandlingshistoriken.

## När bindningen upphör

Lämnar företaget arbetsgivarorganisationen eller sägs hängavtalet upp: klicka på **Ändra** och fyll i **Bundet till**. Lönekörningar för perioder efter det datumet stoppas för anställda som fortfarande är knutna till avtalet, så att du inte betalar efter ett avtal som inte längre gäller. Flytta dem till ett annat avtal eller till **Inte omfattad av avtal**.

## Ta bort ett avtal

Klicka på **Ta bort** och bekräfta. Det går bara när inga anställningar är knutna till avtalet. Är anställningar knutna, ange ett slutdatum för bindningen i stället, eller flytta anställningarna först.

## Vanliga frågor

**Jag får beskedet att det inte finns någon data för avtalet som hängavtal.**
NONOX har bara data för hängavtalsvarianten av Gröna Riks än så länge. För övriga avtal finns medlemsvarianten. Kontakta oss om ni är bundna genom hängavtal till ett annat avtal.

**Vårt avtal finns inte i listan.**
Då har NONOX ingen data för det ännu. Lönen fungerar som vanligt utan Avtal: lägg in OB och övertid med belopp under **Timmar och tillägg** och kontrollera minimilönen själv.

**Vi har ett lokalt avtal som avviker från branschavtalet.**
Lokala avtal stöds inte ännu. Se [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu).

## Läs också

- [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld)
- [Versioner och kontroll av avtalets värden](/manual/avtal/versioner-och-kontroll)
- [Kollektivavtal i lönen](/manual/avtal/kollektivavtal-i-lonen)
