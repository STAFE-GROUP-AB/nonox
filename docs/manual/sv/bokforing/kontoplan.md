---
beskrivning: Kontoplanen enligt BAS – hitta ett konto, lägg till ett nytt, byt namn och inaktivera konton du inte vill bokföra på.
ordning: 100
rutter: [accounting.accounts]
sokord: [kontoplan, BAS, konto, kontonummer, kontoklass, nytt konto, inaktivera konto, ta bort konto, momsruta, ruta 37]
---

# Kontoplan

Kontoplanen är listan över de konton du bokför på, till exempel 1930 för företagskontot och 6110 för kontorsmaterial. I NONOX är den förifylld med de vanligaste kontona enligt BAS, den kontoplan som nästan alla svenska företag använder, och du kan lägga till de konton du behöver utöver dem. Du hittar den under **Bokföring → Kontoplan**.

![Kontoplanen, där du lägger till, byter namn på och inaktiverar konton.](/bilder/manual/bokforing/kontoplan.webp)

## Vilka konton du får från början

När företaget skapas får det de vanligaste kontona ur BAS 2026, cirka hundra stycken. Vilka det blir beror på företagets redovisningsregelverk: ett aktiebolag med K2 eller K3 får aktiebolagets konton för eget kapital (till exempel 2081 Aktiekapital och 2099 Årets resultat), en enskild firma med K1 får eget kapital för en ägare (till exempel 2010 Eget kapital och 2019 Årets resultat).

> [!NOTE]
> NONOX har ännu inte kontrollerat varje kontonummer och namn i den förifyllda kontoplanen mot den officiella BAS-kontoplanen. Är du osäker på vilket konto som är rätt, fråga din redovisningskonsult.

## Hitta ett konto

- Skriv i sökfältet. Du kan söka på början av numret (`19` visar alla konton som börjar på 19) eller på en del av namnet ("moms").
- Välj en kontoklass, till exempel **3 – Rörelsens intäkter**, för att bara se den klassen.
- Kryssa i **Visa inaktiva konton** för att se de konton som är avstängda.

Kolumnen **Typ** visar om kontot är en **Tillgång**, **Eget kapital och skulder**, en **Intäkt** eller en **Kostnad**. Typen styr var kontot hamnar i [resultat- och balansrapporten](/manual/rapporter) och på vilken sida dess saldo visas. Ett konto som har bokföring eller ingående balanser kan inte byta mellan balanskonto (tillgång, eget kapital och skulder) och resultatkonto (intäkt, kostnad), eftersom det skulle ändra saldon och resultat för år som redan är bokförda. Lägg upp ett nytt konto i stället.

## Så här lägger du till ett konto

1. Gå till **Bokföring → Kontoplan**.
2. Under **Lägg till ett konto**: skriv **Kontonummer** (fyra siffror, mellan 1000 och 9999) och **Namn**.
3. Välj **Lägg till**.

Typen väljs av den första siffran: 1 tillgångar, 2 eget kapital och skulder, 3 intäkter, 4–8 kostnader. Kontot kan användas direkt när du bokför.

> [!TIP]
> Följ BAS när du lägger till ett konto, så blir rapporterna, SIE-filen och årsredovisningen rätt grupperade. Ett konto som 5420 hamnar bland övriga externa kostnader för att det börjar på 5.

## Byt namn på ett konto

Välj **Byt namn** på kontots rad, skriv det nya namnet och välj **Spara**. Numret går aldrig att ändra, eftersom bokförda verifikationer pekar på det. Det nya namnet syns överallt, även i gamla rapporter.

## Koppla ett konto till en momsruta

Kolumnen **Momsruta** visar vilken ruta i momsdeklarationen kontot räknas till, till exempel 05 för 3001 och 48 för 2641. Rutan följer kontonumret enligt BAS. Läs [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton).

Har du ett eget intäkts- eller kostnadskonto som ska till en viss ruta väljer du **Momsruta** på kontots rad, väljer rutan och **Spara**. Det behövs till exempel för ruta 37, mellanmans inköp vid trepartshandel, som inte har något konto i BAS. Välj **Efter kontonumret** för att gå tillbaka. Det går bara för konton mellan 3000 och 8999 och bara för rutor med försäljning eller inköp. Momskontona följer alltid BAS. Ändringen gäller alla perioder som inte redan är deklarerade, och den skrivs i behandlingshistoriken.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-7, #64).

## Inaktivera ett konto

Ett konto kan aldrig tas bort, men du kan stänga av det:

1. Välj **Inaktivera** på kontots rad.

Ett inaktiverat konto syns inte längre i listan när du bokför, och en verifikation med kontot kan inte bokföras. Det som redan är bokfört på kontot finns kvar och syns i rapporterna. Vill du använda kontot igen väljer du **Aktivera** – kryssa först i **Visa inaktiva konton** så att det syns.

## Inköp av handelsvaror

Inköpskonton (4000–4899) räknas som råvaror och förnödenheter i årsredovisningen och inkomstdeklarationen. Köper företaget varor för att sälja dem vidare, välj **Räkna som handelsvaror** på kontots rad. Då hamnar inköpen under Handelsvaror i resultaträkningen och i punkt 3.6 i räkenskapsschemat (INK2R). Kontot får märket **Handelsvaror**. Välj **Räkna som råvaror** för att ändra tillbaka.

Läser du in en SIE-fil och ett nytt inköpskonto läggs till med SRU-kod 7512, blir kontot markerat på samma sätt.

## Vem får göra vad

Alla som arbetar i bokföringen kan se kontoplanen. Ägare, administratörer och redovisningskonsulter kan lägga till, byta namn på och inaktivera konton. Ändringarna skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

## Vanliga frågor

**Kontot jag behöver finns inte. Varför är inte hela BAS-kontoplanen med?**
För att listan ska vara lätt att hitta i. De flesta företag använder bara en liten del av BAS. Lägg till de konton du saknar.

**Jag läser in en SIE-fil med konton som inte finns här.**
De konton som används i filen läggs till automatiskt, och rapporten före inläsningen visar vilka. Se [SIE-filer](/manual/bokforing/sie).

**Kan jag ta bort ett konto jag lagt till av misstag?**
Nej, men inaktivera det. Då syns det inte när du bokför.

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
- [Rapporter](/manual/rapporter)
- [Ordlista](/manual/ordlista/ordlista)
