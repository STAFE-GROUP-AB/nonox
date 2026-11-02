---
beskrivning: Planerad och faktisk tid med uppskattad personalkostnad mot försäljningen i bokföringen, per dag och vecka, jämfört med förra året och som CSV.
ordning: 100
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.staffing, planning.staffing.csv]
sokord: [personalkostnad, lönekostnad i procent, personalkostnad av omsättning, bemanning, försäljning per dag, CSV, Excel, jämför förra året]
---

# Bemanning och kostnad

Här ser du vad personalen kostar i förhållande till vad ni säljer. Eftersom bokföringen finns i samma system hämtar NONOX försäljningen direkt från de bokförda verifikationerna, dag för dag, och jämför den med passen i schemat och tiden som faktiskt arbetades.

Sidan finns under **Planering → Bemanning och kostnad**.

![Planerad och faktisk tid och lönekostnad mot försäljningen ur bokföringen.](/bilder/manual/planering/bemanning.webp)

## Så här gör du

1. Gå till **Planering → Bemanning och kostnad**.
2. Välj period: **Denna vecka**, **Förra veckan**, **Fyra veckor**, **Denna månad** eller **Förra månaden**, eller välj **Från** och **Till** själv. En period kan vara högst 93 dagar. Från början visas fyra veckor.
3. Välj en **Avdelning**, eller **Alla avdelningar**.
4. Bläddra till föregående eller nästa period med pilarna.
5. Klicka på **Ladda ner CSV** om du vill ha siffrorna i ett kalkylark.

## Det här ser du

Överst står summan för perioden:

- **Planerad tid** och **Faktisk tid**.
- **Planerad kostnad (uppskattning)** och den faktiska kostnaden.
- **Försäljning i bokföringen**, och förra årets försäljning.
- **Personalkostnad av försäljningen**, planerad och faktisk, och förra årets.

Under summan finns ett diagram och en tabell per dag, eller per vecka när perioden är längre.

### Varifrån siffrorna kommer

- **Planerad tid och kostnad** kommer från passen i schemat. Öppna pass räknas i timmarna men har ingen kostnad förrän någon har dem.
- **Faktisk tid** kommer från attesterad tid där den finns, annars från personalliggaren. Personalliggaren räknar tiden från incheckning till utcheckning, utan att dra av rast. Finns ingen av dem för perioden visas ingen faktisk tid.
- **Försäljning** är bokförda verifikationer på försäljningskontona per verifikationsdatum, kredit minus debet. Standard är konto 3000–3799. Kontona ändrar du i modulens inställningar.
- **Förra året** är samma veckor ett år tidigare, 52 veckor bakåt, så att veckodagarna stämmer. Det visas när bokföringen eller schemat har något för de veckorna.

> [!NOTE]
> Försäljningen gäller alltid hela företaget, även när du har valt en avdelning. Personalkostnaden i procent för en avdelning är alltså avdelningens kostnad mot företagets försäljning.

## Kostnaderna är uppskattningar

Kostnaderna räknas från passens klockslag och de anställdas löner. OB, semesterersättning och arbetsgivaravgifter tas med bara där reglerna är kontrollerade. En stjärna betyder att någon del saknas. Läs mer i [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar#vad-kostnaden-raknas-pa). Den riktiga lönen räknas alltid i lönekörningen.

## CSV-filen

Filen öppnas direkt i Excel: semikolon mellan fälten och decimalkomma. Den har en rad per dag, sedan en rad per vecka och till sist summan. Att kostnaderna är uppskattningar står i rubrikraden.

## Läs också

- [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar)
- [Tid att attestera](/manual/planering/tid-att-attestera)
- [Resultatrapport](/manual/rapporter/resultatrapport) – hela årets intäkter och kostnader.
