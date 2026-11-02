---
beskrivning: Ta fram Inkomstdeklaration 2 för aktiebolaget ur bokföringen, lägg till skattemässiga justeringar och ladda ner SRU-filerna till Skatteverkets Filöverföring.
ordning: 100
roller: [owner, admin, accountant]
rutter: [accounting.incomeTax, accounting.incomeTax.sru]
sokord: [inkomstdeklaration, ink2, ink2r, ink2s, sru, sru-fil, deklaration aktiebolag, skattemässigt resultat, filöverföring, skattemässiga justeringar, schablonintäkt, periodiseringsfond, statslåneränta, särskild löneskatt, pensionskostnader, tjänstepension]
---

# Inkomstdeklaration 2 och SRU-filer

Aktiebolag och ekonomiska föreningar lämnar Inkomstdeklaration 2 (INK2) med räkenskapsschemat (INK2R) och skattemässiga justeringar (INK2S). NONOX fyller i räkenskapsschemat fält för fält ur bokföringen, räknar fram det skattemässiga resultatet med de justeringar du anger och gör SRU-filerna som du lämnar i Skatteverkets e-tjänst Filöverföring.

Sidan finns under **Bokföring → Inkomstdeklaration**.

![Inkomstdeklarationen med skattemässiga justeringar och deklarationen fält för fält.](/bilder/manual/bokslut/inkomstdeklaration.webp)

## Så här gör du

1. Gör bokslutet först. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).
2. Gå till **Bokföring → Inkomstdeklaration** och välj räkenskapsår.
3. Kontrollera **Bokfört resultat** och **Bokförd skatt**.
4. Under **Skattemässiga justeringar**, fyll i de belopp som gäller företaget. Belopp i hela kronor, utan minustecken:
   - **Andra bokförda kostnader som inte ska dras av**
   - **Nedskrivning av finansiella tillgångar som inte ska dras av**
   - **Utdelning som inte ska tas upp**
   - **Andra bokförda intäkter som inte ska tas upp**
   - **Beräknad schablonintäkt på periodiseringsfonder** – har företaget periodiseringsfonder står ett förslag under fältet. Lämna fältet tomt för att använda förslaget. Skriv ett annat belopp om förslaget inte stämmer, eller 0 om fältet inte ska fyllas i.
   - **Andra ej bokförda intäkter som ska tas upp**
   - **Andra ej bokförda kostnader som ska dras av**
   - **Outnyttjat underskott från föregående år**
5. Har företaget tjänstepension? Kontrollera **Underlag för särskild löneskatt på pensionskostnader** och **Pensionskostnader (som ingår i p. 3.8)**. NONOX föreslår beloppen från kontona 7400–7499. Ändra underlaget om något inte ska ingå, till exempel en gruppsjukförsäkring. Blir underlaget negativt fyller du i **Negativt underlag för särskild löneskatt på pensionskostnader** i stället – aldrig båda.
6. Svara på **Har en konsult hjälpt till med årsredovisningen?** och **Har årsredovisningen reviderats?**
7. Klicka på **Spara**. Sidan visar **Överskott av näringsverksamhet** eller **Underskott av näringsverksamhet**.
8. Granska **Deklarationen, fält för fält**.
9. Klicka på **Ladda ner SRU-filer**.
10. Packa upp ZIP-filen och lämna båda filerna, INFO.SRU och BLANKETTER.SRU, i Skatteverkets e-tjänst Filöverföring.
11. Kontrollera uppgifterna i e-tjänsten innan du skriver under.

Skatten på årets resultat läggs tillbaka av sig själv – den är inte avdragsgill.

## Det här gör NONOX

- **INK2R** fylls fält för fält från BAS-kontointervall, i hela kronor. Årets resultat är resultaträkningens, även om det inte är bokat på 2099.
- **INK2S** börjar med det bokförda resultatet, lägger tillbaka den bokförda skatten och räknar in dina justeringar. Överskott eller underskott förs till INK2 punkt 1.1 eller 1.2.
- **Schablonintäkt på periodiseringsfonder** (punkt 4.6 a) föreslås ur bokföringen: periodiseringsfonderna vid årets början (konto 2110–2149) gånger statslåneräntan vid utgången av november året innan. Räntan är lägst 0,5 %. Är räkenskapsåret kortare eller längre än tolv månader räknas beloppet om i samma mån. Exempel: fonder på 100 000 kr och räntan 2,55 % ger 2 550 kr för ett år som slutar 2026. Beloppet ökar överskottet och följer med i SRU-filen. Finns inte räntan för året i NONOX ser du en varning, och då fyller du i beloppet själv.
- **Särskild löneskatt på pensionskostnader**: underlaget på sidan 1 (punkt 1.4, eller 1.5 om det är negativt) och pensionskostnaderna i punkt 4.21 föreslås från kontona 7400–7499. Den särskilda löneskatten själv räknas inte med. Fälten ändrar inte överskottet; Skatteverket tar ut skatten på underlaget. Står det pensionskostnader i 4.21 men inget underlag visar sidan en varning.
- **Justeringarna** sparas för året, så att du kan komma tillbaka och ändra dem.
- Varje nedladdning av SRU-filerna skrivs i behandlingshistoriken.

## Det här fyller du i själv i e-tjänsten

Övriga punkter på INK2S – till exempel delägarrätter, andelar i handelsbolag och fastigheter – fyller NONOX inte i. Gäller de företaget lägger du in dem i Skatteverkets e-tjänst. Fråga din redovisningskonsult.

## När knappen inte finns

Fältkoderna i SRU-filerna gäller en viss beskattningsperiod och kontrolleras för varje ny period. För en period vars koder inte är kontrollerade än står det att **fältkoderna … inte är kontrollerade ännu**, och inga filer kan laddas ner. Använd då siffrorna på sidan och fyll i deklarationen direkt i Skatteverkets e-tjänst.

Företagets organisationsnummer behövs för filerna.

> [!NOTE]
> Kopplingen mellan BAS-konton och deklarationens fält är ännu inte juridiskt kontrollerad mot den officiella kopplingen, särskilt för konton i 4000–4999, koncernposter och bokslutsdispositioner. Låt din redovisningskonsult granska deklarationen. Förslagen till schablonintäkt och till underlaget för särskild löneskatt bygger på en granskning 2026-11-02; juristgranskning återstår. Underlaget enligt lagen om särskild löneskatt är inte alltid detsamma som den bokförda kostnaden.

## Vanliga frågor

**Sidan säger "Inte för den här företagsformen".**
Inkomstdeklaration 2 gäller aktiebolag och ekonomiska föreningar. En enskild firma deklarerar på NE-bilagan, som inte görs i NONOX.

**Påverkar justeringarna bokföringen?**
Nej. De är bara skattemässiga och sparas med deklarationen. Den skatt du bokför i bokslutet anger du själv.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Årsredovisning enligt K2](/manual/bokslut/arsredovisning)
- [Arkivpaketet](/manual/bokslut/arkivpaket)
