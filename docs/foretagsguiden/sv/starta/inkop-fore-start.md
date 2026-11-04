---
beskrivning: Du har köpt dator, verktyg eller annat till företaget med egna pengar innan det fanns. Så förs inköpen in i företaget och bokförs, med eller utan avdrag för momsen.
ordning: 70
rutter: [accounting.vouchers.create]
sokord: [inköp före start, köpt innan företaget startade, utlägg före registrering, skuld till ägaren, skuld till grundaren, egen insättning, 2893, 2018, moms före registrering, avdrag ingående moms, startkostnader, stiftelseurkund]
kallor:
  - titel: Aktiebolagslag (2005:551), 2 kap. 4, 25 och 26 §§
    url: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/aktiebolagslag-2005551_sfs-2005-551/
  - titel: Skatteverket – Köpa varor eller tjänster till företaget
    url: https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/kopavarorellertjanstertillforetaget.4.7459477810df5bccdd480005156.html
  - titel: Bokföringslag (1999:1078)
    url: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
granskad: 2026-11-04
mansklig_granskning:
legal_verify:
  - Aktiebolag – får bolaget dra av kostnader och ingående moms för inköp som grundaren gjorde innan stiftelseurkunden skrevs under, när bolaget tar över dem efter registreringen? Vilka villkor gäller?
  - Aktiebolag – räcker ett kvitto i grundarens namn som underlag för bolagets avdrag för ingående moms, eller krävs en faktura ställd till bolaget?
  - Vilket verifikationsdatum ska inköp före räkenskapsårets första dag få – inköpsdagen, dagen då bolaget tog över inköpet eller räkenskapsårets första dag?
  - Finns en tidsgräns för hur långt före starten ett inköp får vara gjort för att momsen ska få dras av?
  - Gränsen för när en inventarie får kostnadsföras direkt i stället för att skrivas av (inventarier av mindre värde) anges inte här; behöver ett belopp med årtal och källa.
---

# Bokför inköp du gjort innan företaget startade

Det är vanligt att köpa saker till företaget innan det finns: en dator, verktyg, en domän, en mässplats. Du har betalat med egna pengar och sparat kvittot. När företaget väl finns ska de inköpen in i bokföringen, så att företaget får kostnaden, du får tillbaka pengarna (i ett aktiebolag) och momsen kan dras av när reglerna tillåter det.

Hur det går till skiljer sig mellan aktiebolag och enskild firma.

## Det här säger reglerna

### Aktiebolag: bolaget och du är två olika personer

Ett aktiebolag bildas när alla stiftare har skrivit under stiftelseurkunden (aktiebolagslagen 2 kap. 4 §), men det kan inte förvärva rättigheter eller ta på sig skyldigheter förrän det är registrerat (2 kap. 25 §). Den som handlar i bolagets namn före registreringen svarar själv för det, tillsammans med de andra som deltog (2 kap. 26 §). När bolaget har registrerats går ansvaret över på bolaget, om åtagandet följer av stiftelseurkunden eller har uppkommit efter att bolaget bildades.

För dig betyder det:

- **Köp efter att stiftelseurkunden skrevs under**, som gjordes för bolagets räkning, blir bolagets när det registreras.
- **Köp innan bolaget bildades** blir inte bolagets av sig självt. Vill bolaget ta över dem behöver det bestämmas, till exempel genom att bolaget köper saken av dig eller ersätter dig för utgiften. Om bolaget då får dra av kostnaden och momsen är inte självklart. Fråga din redovisningskonsult innan du bokför sådana köp.

När bolaget tar över ett inköp du har betalat har bolaget en **skuld till dig**. Den bokförs på ett skuldkonto, till exempel 2893 Skulder till närstående personer, kortfristig del, tills bolaget betalar tillbaka.

### Enskild firma: allt är du

En enskild firma är du själv, så det finns ingen skuld mellan dig och firman. Det du har betalat privat för firmans räkning bokförs som en **egen insättning**, till exempel på konto 2018 Övriga egna insättningar. Tar du in något du redan ägde privat, till exempel en kamera, gör du enligt Skatteverket det som en egen insättning till **marknadsvärdet**, alltså vad saken är värd i dag, inte vad du en gång betalade.

### Momsen på inköp före registreringen

Enligt Skatteverket kan du i vissa fall dra av momsen på inköp som du gjorde innan du startade verksamheten. Villkoren:

1. Du köpte saken eller tjänsten **med avsikten att bara använda den i den momspliktiga verksamheten**. Köpte du den för privat bruk får du inte dra av momsen, även om du senare använder den i företaget. Kameran i exemplet ovan ger alltså inget momsavdrag.
2. Du har ett **underlag där momsen är specificerad**, normalt en faktura eller ett kvitto.
3. I företagsregistreringen hos Skatteverket anger du **datumet för ditt första inköp** som startdatum för momsen.

Avdraget redovisas som ingående moms i ruta 48 i momsdeklarationen. Är företaget inte momsregistrerat, eller undantaget från moms, finns inget avdrag; då är hela beloppet kostnad.

### Underlaget

Enligt bokföringslagen ska varje verifikation ha ett underlag. Spara kvittot eller fakturan och lägg till en kort sammanställning: vad du köpte, när, vad det kostade och att företaget har tagit över det. I ett aktiebolag är det klokt att styrelsen skriver under sammanställningen.

## Exempel: en dator för 12 500 kronor

Sara startar Kafé Linden. Innan företaget är registrerat köper hon en dator för 12 500 kronor, varav 2 500 kronor moms, som bara ska användas i kaféet. Hon betalar med sitt privata kort och sparar kvittot.

**Om Kafé Linden är ett aktiebolag**, och bolaget får dra av momsen, bokförs övertagandet till exempel så här:

| Konto | Debet | Kredit |
|---|---:|---:|
| 5410 Förbrukningsinventarier | 10 000,00 | |
| 2640 Ingående moms | 2 500,00 | |
| 2893 Skulder till närstående personer, kortfristig del | | 12 500,00 |

När bolaget betalar tillbaka till Sara:

| Konto | Debet | Kredit |
|---|---:|---:|
| 2893 Skulder till närstående personer, kortfristig del | 12 500,00 | |
| 1930 Företagskonto/checkkonto/affärskonto | | 12 500,00 |

**Om Kafé Linden är en enskild firma**, och momsen får dras av:

| Konto | Debet | Kredit |
|---|---:|---:|
| 5410 Förbrukningsinventarier | 10 000,00 | |
| 2640 Ingående moms | 2 500,00 | |
| 2018 Övriga egna insättningar | | 12 500,00 |

Här finns ingen återbetalning. Tar Sara senare ut pengar ur firman bokförs det som ett eget uttag.

**Om momsen inte får dras av**, till exempel för att företaget inte är momsregistrerat, bokförs hela beloppet, 12 500 kronor, som kostnad, och raden för ingående moms utgår.

> [!NOTE]
> Kontona är exempel ur BAS-kontoplanen. En dyrare dator som ska användas i flera år kan i stället vara en anläggningstillgång som skrivs av, till exempel på konto 1250 Datorer. Var gränsen går, fråga din redovisningskonsult. Hur du lägger in en anläggningstillgång i NONOX står i [Skapa en anläggningstillgång från ett inköp](/manual/bokforing/anlaggningstillgang-fran-inkop).

## Så här gör du i NONOX

1. Kontrollera under **Bokföring → Räkenskapsår** att det första räkenskapsåret börjar där du vill. NONOX tar bara emot verifikationer med ett datum i ett öppet räkenskapsår. Det första årets datum kan ändras innan du har bokfört något; se [Räkenskapsår](/manual/bokforing/rakenskapsar). Är du osäker på vilket datum inköpen ska ha, fråga din redovisningskonsult innan du bokför.
2. Kontrollera att kontona finns i kontoplanen, till exempel 2893 eller 2018. Saknas ett konto lägger du till det; se [Kontoplan](/manual/bokforing/kontoplan).
3. Gå till **Bokföring → Verifikationer** och välj **Ny verifikation**.
4. Skriv i **Vad gäller det?** vad inköpet var och när det gjordes, till exempel "Dator köpt privat 2026-09-14, övertagen av bolaget".
5. Fyll i raderna enligt exemplet ovan. Varje inköp kan få en egen verifikation, eller så samlar du flera kvitton i en verifikation med en rad per kostnad.
6. Lägg till kvittona och din sammanställning under **Underlag**; se [Underlag](/manual/bokforing/underlag).
7. Välj **Bokför**. Hur verifikationen fungerar i övrigt står i [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).
8. Aktiebolag: när bolaget betalar tillbaka, bokför betalningen mot skuldkontot.

## Vanliga misstag

- Att dra av moms på saker du köpte för privat bruk och först senare började använda i företaget.
- Att bokföra privata saker till inköpspriset i stället för marknadsvärdet.
- Att aktiebolaget betalar tillbaka mer än skulden. Det överskjutande är då ett lån till dig, som är förbjudet; se [Företagskonto och pengar](/foretagsguiden/starta/foretagskonto-och-pengar).
- Att glömma att ange det första inköpets datum som startdatum för momsen i registreringen hos Skatteverket.
