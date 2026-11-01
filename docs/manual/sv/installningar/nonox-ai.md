---
beskrivning: NONOX AI läser leverantörsfakturor, kvitton och underlag och fyller i ett förslag med konton och belopp. Så slår du på den med företagets egen API-nyckel, och varför en person alltid bestämmer.
ordning: 30
roller: [owner, admin, accountant, bookkeeper]
modul: ai
sokord: [AI, artificiell intelligens, ChatGPT, OpenAI, Claude, Anthropic, Gemini, tolka faktura, läsa kvitto, OCR-tolkning, API-nyckel, automatisk kontering]
---

# NONOX AI

NONOX AI läser ett underlag, till exempel en leverantörsfaktura eller ett kvitto, och fyller i formuläret åt dig: datum, belopp, moms, konton och leverantör. Du kontrollerar förslaget mot underlaget och sparar. **AI:n sparar och bokför aldrig något själv.**

Modulen använder företagets eget konto hos en AI-leverantör: OpenAI (ChatGPT), Anthropic (Claude) eller Google (Gemini). Ni betalar leverantören direkt för varje analys; NONOX tar inget betalt och har ingen del i kostnaden.

## Det här innebär det att använda NONOX AI

- **Underlaget lämnar NONOX.** Varje underlag som analyseras skickas till den AI-leverantör ni väljer. Hur leverantören hanterar data från API-anrop står i leverantörens villkor. Läs dem innan ni slår på modulen.
- **Ert konto och er kostnad.** Med den billigaste modellen kostar en faktura normalt en bråkdel av en krona, men det är leverantörens priser som gäller.
- **Ett förslag, inget beslut.** Ingenting sparas eller bokförs förrän någon hos er har kontrollerat och sparat. Företaget ansvarar för varje verifikation, precis som när den skrivs för hand.
- **Behandlingshistoriken** sparar att en analys gjordes, med vilken leverantör och modell, men aldrig underlaget eller dess innehåll.

## Så här kommer du igång

1. Slå på modulen **NONOX AI** under **Inställningar för företaget → Moduler**. Se [Moduler](/manual/installningar/moduler).
2. Klicka på **Inställningar** på modulens kort.
3. Välj **Leverantör av AI**.
4. Skaffa en API-nyckel hos leverantören. Under **Så skaffar du en nyckel från** finns en länk och stegen: logga in eller skapa ett konto, lägg in ett betalkort och skapa en ny API-nyckel.
5. Klistra in nyckeln i fältet **API-nyckel**.
6. Välj **Modell**. **Billigaste lämpliga modellen (rekommenderas)** räcker för fakturor och kvitton.
7. Klicka på **Spara**.

NONOX kontrollerar nyckeln hos leverantören när du sparar. Den sparas krypterad och visas aldrig igen; efteråt står bara de sista tecknen. Vill du byta nyckel fyller du i fältet på nytt.

> [!NOTE]
> Ett vanligt abonnemang som ChatGPT Plus, Claude Pro eller Gemini Advanced går inte att använda av andra program. Det krävs en API-nyckel från leverantörens utvecklarkonto.

### Välja modell

- **Billigaste lämpliga modellen (rekommenderas)** – räcker för de flesta underlag.
- **Den bästa modellen** – för svårlästa underlag. Kostar mer per analys.
- **En modell jag väljer själv** – skriv modellens namn hos leverantören.

Vilken modell som är billigast och bäst följer med när NONOX uppdateras, så ni får nyare modeller utan att ändra något.

## Där NONOX AI hjälper till

- **Leverantörsfakturor:** när du registrerar en leverantörsfaktura och laddar upp fakturan läses den direkt, och formuläret fylls i. Se [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).
- **Verifikationer:** när du bokför ett kvitto eller annat underlag läses det så fort du har laddat upp det. Du kan också klicka på **Analysera** för att läsa det igen. Ser underlaget ut som en obetald leverantörsfaktura föreslår NONOX att du registrerar den som leverantörsfaktura i stället. Se [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).
- **Inkorgen:** underlag som ingen sparad mall känner igen kan läsas av AI:n, om ni väljer det i inkorgens inställningar. Se [Inkorgen](/manual/leverantorer/inkorgen).
- **Anställdas utlägg:** bara om ni kryssar i **Låt AI läsa de anställdas kvitton för utlägg**. Se nedan.
- **Kollektivavtal:** med modulen Avtal kan AI:n läsa hängavtal och anställningsavtal och föreslå inställningarna. Se [Kollektivavtal](/manual/avtal).

Förslaget visar hur säker AI:n är (**Säkerhet:** i procent). Kontrollera alltid varje uppgift mot underlaget innan du sparar. AI:n väljer bara konton som finns i er kontoplan, och en leverantör bara om den finns i registret; finns den inte står det, och du kan lägga till den.

## Anställdas kvitton

Kryssar du i **Låt AI läsa de anställdas kvitton för utlägg** skickas de kvitton som anställda laddar upp på Min sida till AI-leverantören, som kan finnas utanför EU. Kvittona läses bara; AI:n bedömer aldrig inköpet eller personen. Företaget ansvarar för att informera de anställda, och det står också på deras Min sida. Utan det här valet granskar ni kvittona själva.

## Vanliga frågor

**Vem kan använda AI:n?**
Ägare, administratörer, redovisningskonsulter och bokförare, när modulen är på. Bara ägare och administratörer kan ändra inställningarna.

**Leverantören godtog inte nyckeln.**
Kontrollera att du har kopierat hela nyckeln och att kontot hos leverantören har ett betalkort inlagt.

**Kan NONOX tillhandahålla en nyckel åt oss?**
Nej. Varje företag använder sin egen nyckel, även på den driftade tjänsten. Det gör att ni själva bestämmer vilken leverantör som får se era underlag.

**Vad händer med nyckeln om vi stänger av modulen?**
Den finns kvar krypterad, och används igen om ni slår på modulen.

## Läs också

- [Moduler](/manual/installningar/moduler)
- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [Inkorgen](/manual/leverantorer/inkorgen)
- [Personuppgifter](/manual/installningar/personuppgifter)
