---
beskrivning: Surfplattor i verkstaden där var och en trycker på sin bild, skriver sin kod och rapporterar tid på öppna tillverkningsorder. Så kopplar du en surfplatta och lägger till personerna.
ordning: 60
roller: [owner, admin]
modul: manufacturing
rutter: [manufacturing.terminal.admin]
sokord: [verkstadsterminal, surfplatta, tidrapportering, produktionstid, läsplatta, kod, bild, verkstad, avvikelse, väntar]
---

# Verkstadsterminal

Verkstadsterminalen är en sida på en surfplatta i verkstaden. Var och en trycker på sin bild, skriver sin kod, väljer en öppen tillverkningsorder och ett arbetssteg och anger hur lång tid de arbetat med det i dag. Tiden blir orderns verkliga arbetskostnad och visar i [efterkalkylen](/manual/tillverkning/efterkalkyl) var arbetet tar längre tid än beräknat.

Terminalen kräver modulen Tillverkning och är avstängd tills du slår på den. De som rapporterar behöver inget konto i NONOX.

![Verkstadsterminalens inställningssida med surfplattor och personer.](/bilder/manual/tillverkning/verkstadsterminal.webp)

> [!IMPORTANT]
> Tiden på terminalen är **produktionstid**: tid på en tillverkningsorder. Det är inte närvaro, och den ersätter inte [personalliggaren](/manual/personalliggare/oversikt). Den är inte lönetid och går inte till lönen; terminalen och lönen är inte kopplade. Det står också på terminalens skärm.

## Så här kommer du igång

1. Gå till **Tillverkning → Verkstadsterminal** och välj **Slå på verkstadsterminalen**.
2. Under **Surfplattor** skriver du ett namn, till exempel Snickeriet, och väljer **Lägg till surfplatta**. NONOX visar en kopplingskod och en QR-kod.
3. Öppna länken i surfplattans webbläsare, eller skanna QR-koden, och skriv koden om den inte fylls i. Koden gäller i 15 minuter och kan bara användas en gång. Lägg gärna sidan på hemskärmen så blir den en egen app i helskärm.
4. Under **Personer på surfplattan** skriver du namnet och en kod på fyra siffror och väljer **Lägg till person**. Den som saknar kod syns inte på surfplattan.
5. Välj **Foto** om personen vill ha sin bild på plattan. Ett foto sparas bara om personen har samtyckt, och du kan ta bort det när som helst med **Ta bort foto**.

## Så här ser det ut för den som rapporterar

1. Plattan visar bilderna på alla. Tryck på din bild.
2. Skriv din kod med de stora knapparna.
3. Du ser alla öppna order, alltså frisläppta och startade, med produkt, kund, antal och datum. Välj en order och sedan ett arbetssteg.
4. Välj **Rapportera tid** och lägg till tiden med knapparna för timmar och minuter. Tiden gäller i dag. Välj **Rapportera**.
5. Plattan loggar ut dig själv efter en minut utan beröring, eller med **Logga ut**.

Var och en ser bara sina egna rapporter, under **Mina tider**. Ser något fel ut väljer du **Rätta**. En rapport ändras eller tas aldrig bort: rättelsen blir en ny rapport som tar ut den gamla, och sedan rapporterar du rätt tid igen. Det går så länge ordern är öppen.

## Väntar

Står ett arbetssteg still kan den som arbetar vid det markera det. Välj **Väntar** vid arbetssteget, välj **Väntar på material** eller **Väntar på arbete** och skriv en kort orsak, till exempel att skivorna inte är levererade. Skriv inga namn.

- **Väntar på material** betyder att något inte har kommit. Det rättas av inköp och leveranser.
- **Väntar på arbete** betyder att materialet finns men steget väntar på arbete eller kapacitet. Det rättas av planering och bemanning.

Markeringen försvinner när någon rapporterar tid på steget eller väljer **Det väntar inte längre**. Den visar vilken surfplatta som skickade den, aldrig vem som tryckte. Efterkalkylen visar ordrarna i två listor så att de inte blandas ihop.

## Det här händer i bokföringen

Ingenting bokförs när tid rapporteras. Tiden blir arbetskostnad på ordern, med arbetsstegets timkostnad som den är när tiden rapporteras. När ordern färdigmeldas räknas den rapporterade tiden som verklig tid; ett steg som ingen rapporterat på får den planerade tiden.

## Personuppgifter

Terminalen sparar namnet på den som rapporterat, tillsammans med order, arbetssteg, dag, minuter och surfplatta.

- Namnet tas bort ur rapporten 24 månader efter inmatningen. Minuterna finns kvar som kostnad på ordern.
- Koden sparas aldrig, bara ett kontrollvärde. Fem felaktiga koder i rad spärrar personen i fem minuter, och en ny kod häver spärren.
- Under **Personer på surfplattan** hämtar du ett **registerutdrag** för en person som begär ut sina uppgifter.
- Rapporterna används till efterkalkylen per produkt och arbetssteg. Det finns ingen uppföljning per person.

> [!NOTE]
> Ägaren ansvarar för att personalen vet vad terminalen sparar och varför. Fråga en jurist om vad som gäller hos er, till exempel om ni har kollektivavtal.

## Bra att veta

- Terminalen behöver internet. En rapport som inte kunde skickas sparas inte, och plattan säger det. Den visar aldrig något som sparat när det inte är det.
- En spärrad surfplatta kan inte rapportera och måste kopplas på nytt. Under **Surfplattor** väljer du **Spärra**.
- Slår du av terminalen raderas inget, men plattorna kan inte rapportera förrän du slår på den igen.
- En arkiverad person syns inte längre på plattan, men rapporterad tid finns kvar tills den ska tas bort.

## Läs också

- [Efterkalkyl](/manual/tillverkning/efterkalkyl)
- [Arbetssteg](/manual/tillverkning/arbetssteg)
- [Personalliggare](/manual/personalliggare/oversikt)
