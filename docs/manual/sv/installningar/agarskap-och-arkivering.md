---
beskrivning: Överlåt ägarskapet till någon annan i företaget, eller arkivera ett företag som är avslutat så att allt finns kvar och kan läsas men inget ändras.
ordning: 90
roller: [owner]
sokord: [arkivera, avsluta företag, lägga ner, ta bort företag, radera företag, överlåta, byta ägare, ny ägare, likvidation, sälja bolaget]
---

# Ägarskap och arkivering

Ett företag i NONOX har alltid en ägare. Ägaren kan lämna över ägarskapet till någon annan, och arkivera företaget när verksamheten är avslutad. Båda görs på fliken **Ägarskap och arkiv** under **Inställningar för företaget**, som bara ägaren ser.

![Överlåt ägarskapet eller arkivera företaget.](/bilder/manual/installningar/agarskap-och-arkivering.webp)

## Överlåt ägarskapet

Säljer du bolaget, eller ska någon annan ta över ansvaret i NONOX, överlåter du ägarskapet. Den nya ägaren måste redan vara med i företaget med en av de vanliga rollerna, till exempel administratör eller redovisningskonsult.

### Så här gör du

1. Bjud först in personen om hen inte redan är med; se [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare).
2. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
3. Öppna fliken **Ägarskap och arkiv**.
4. Välj personen under **Ny ägare**.
5. Klicka på **Överlåt ägarskapet** och bekräfta.

Den nya ägaren får göra allt i företaget, även arkivera det och ta bort dig. Du blir själv **administratör**.

> [!WARNING]
> Du kan inte ångra en överlåtelse själv. Bara den nya ägaren kan lämna tillbaka ägarskapet.

Ägarskapet kan inte ges till någon som bara har en modulroll, till exempel en anställd som bara använder Min sida. Överlåtelsen sparas i behandlingshistoriken.

## Arkivera företaget

Ett företag raderas aldrig i NONOX. När verksamheten är avslutad, till exempel efter en likvidation eller när du har sålt bolaget och flyttat bokföringen, **arkiverar** du det. Då finns allt kvar och kan läsas och exporteras, men ingen kan längre bokföra, fakturera, betala, köra lön eller ändra inställningar.

Det beror på bokföringslagen: räkenskapsinformationen ska sparas i sju år efter utgången av det kalenderår då räkenskapsåret avslutades.

### Arkivpaket först

Innan du arkiverar visar NONOX under **Arkivpaket per räkenskapsår** om varje år har ett aktuellt [arkivpaket](/manual/bokslut/arkivpaket): en ZIP-fil med årets SIE-fil, rapporter, underlag, fakturor och behandlingshistorik som går att läsa utan NONOX.

- **Saknas** – året har inget arkivpaket.
- **Inaktuellt, bokfört efter att paketet skapades** – något har bokförts efter att paketet gjordes.
- **Skapas** – paketet håller på att byggas.

Klicka på **Skapa de arkivpaket som saknas** och sedan **Ladda ner arkivpaketet** för varje år. Spara dem på ett säkert ställe.

### Så här arkiverar du

1. Öppna fliken **Ägarskap och arkiv**.
2. Kontrollera arkivpaketen enligt ovan.
3. Klicka på **Arkivera företaget** och bekräfta.

Saknar något år ett aktuellt arkivpaket måste du först kryssa i att du har sparat räkenskapsinformationen för de åren på annat sätt. Det sparas i behandlingshistoriken.

Efteråt står det på varje sida att företaget är arkiverat och bara kan läsas. I företagsväljaren och under Mina företag ligger det sist, märkt **Arkiverat**.

### Ta ur arkivet

Har du arkiverat av misstag, eller ska verksamheten börja igen, klickar du på **Ta ur arkivet**. Då kan företaget bokföras och ändras igen av alla med behörighet.

## Vad händer i ett arkiverat företag

- Allt kan läsas: verifikationer, fakturor, rapporter, lönebesked och behandlingshistoriken.
- SIE-filer och arkivpaket kan fortfarande skapas och laddas ner.
- Registerutdrag enligt GDPR fungerar som vanligt.
- Inget kan bokföras, ändras eller skickas. Avtalsfakturor skapas inte, och e-post till inkorgen tas inte emot.
- Personerna finns kvar i företaget och kan logga in och läsa.

## Vanliga frågor

**Kan jag ta bort ett företag helt?**
Nej. Ett företag med bokföring raderas aldrig, eftersom bokföringslagen kräver att räkenskapsinformationen sparas. Arkivera det i stället.

**Jag vill radera mitt konto men är ägare.**
Överlåt ägarskapet eller arkivera företaget först. Se [Radera ditt konto](/manual/installningar/radera-ditt-konto).

**Ska jag arkivera när jag flyttar till ett annat program?**
Exportera först allt: SIE-filer för varje år och arkivpaketen. Arkivera sedan, så finns allt kvar att läsa här också. Se [Arkivpaket](/manual/bokslut/arkivpaket).

## Läs också

- [Arkivpaket](/manual/bokslut/arkivpaket)
- [Radera ditt konto](/manual/installningar/radera-ditt-konto)
- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
