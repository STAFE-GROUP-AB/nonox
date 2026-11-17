---
beskrivning: Lägg återkommande uppgifter på passen efter avdelning, kompetens, veckodag och klockslag, och se på schemat vad personalen har bockat av.
ordning: 35
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.tasks]
sokord: [uppgifter, att göra, checklista, öppningsrutin, stängningsrutin, bocka av, uppgifter på pass, rutiner, uppgiftsmall]
---

# Uppgifter på passen

Ett pass kan ha uppgifter, till exempel "Kontrollera kylen och frysen" på morgonpasset i köket eller "Räkna kassan" på fredagskvällen. Du skriver uppgiften en gång som en mall, och NONOX lägger den på de pass den passar. Den som jobbar passet bockar av uppgiften på Min sida, och du ser på schemat hur långt de har kommit.

Uppgifterna är ett stöd i arbetet. De är inte egenkontroll enligt livsmedelslagstiftningen och inte en del av personalliggaren. Behöver du dokumentera egenkontroll gör du det på det sätt som lagstiftningen kräver.

![Uppgiftsmallarna: vilka uppgifter som följer med vilka pass.](/bilder/manual/planering/uppgifter.webp)

## Så här skapar du en uppgift som återkommer

1. Gå till **Planering → Uppgifter** och klicka på **Ny uppgift**.
2. Skriv **Uppgift**, till exempel "Kontrollera kylen och frysen". Skriv inga personuppgifter här, bara vad som ska göras.
3. Skriv en **Instruktion** om du vill.
4. Kryssa för **Veckodagar**. Uppgiften läggs bara på pass som börjar de dagarna.
5. Välj **Avdelning** och **Kompetens** om uppgiften bara hör till vissa pass. Lämna dem tomma för att få uppgiften på alla pass.
6. Fyll i **Klockslag** om uppgiften ska göras en viss tid. Den läggs då bara på pass som pågår den tiden. Ett pass som går över midnatt räknas också.
7. Fyll i **Gäller från** och **Gäller till** om uppgiften bara ska gälla en period.
8. Låt **Aktiv** vara ikryssad och klicka på **Spara**.

Beskedet säger på hur många pass uppgiften lades.

## När uppgifterna hamnar på passen

- När du sparar ett pass, och när du lägger ut en mall eller kopierar en vecka och sedan publicerar schemat, får passet de uppgifter som passar.
- När du ändrar eller tar bort en uppgiftsmall följer passen som inte har börjat med, upp till ett år framåt. Är något redan avbockat på ett pass lämnas det passet som det är.
- Publicerade pass som redan har varit ändras aldrig i efterhand.
- Ändrar du ett publicerat pass får det nya uppgifter först när du publicerar igen, så att personalen ser samma version av passet och uppgifterna. Det gäller också när du ändrar en uppgiftsmall under tiden. En uppgift som du skriver direkt på passet syns däremot för personalen på en gång.
- Flyttar du ett pass till en annan avdelning byts uppgifterna ut mot dem som passar den nya avdelningen.

## En uppgift bara för ett pass

1. Öppna passet i **Planering → Schema**.
2. Skriv under **Uppgifter** en ny uppgift och klicka på **Lägg till**.
3. Vill du ta bort en uppgift du själv har lagt dit klickar du på **Ta bort** vid den.

Uppgifter som kommer från en mall ändrar eller stänger du av under **Planering → Uppgifter**. Ett pass kan ha högst 30 uppgifter.

![Veckans schema där passen visar hur många uppgifter som är avbockade, till exempel ✓ 1/1.](/bilder/manual/planering/schema-uppgifter.webp)

## Så ser du vad som är gjort

På passet i schemat visas ett litet märke, till exempel **✓ 2/3**: två av tre uppgifter är avbockade. Öppnar du passet ser du varje uppgift och klockan den bockades av. Du ser inte vem som bockade av den, men eftersom passet tillhör en person ser du vad som är avbockat på just hens pass.

Du kan själv bocka av en uppgift i passet, om personen inte kan göra det. Du kan ångra en avbockning samma dag som den gjordes.

## Så använder personalen uppgifterna

Personalen ser uppgifterna på passet på Min sida och bockar av dem där. Se [Mitt schema](/manual/personal/mitt-schema#uppgifter-pa-passet). En uppgift kan bockas av från passets dag och till dagen efter att passet slutat.

## Personuppgifter och förhandling

- En avbockning är en uppgift om hur arbetet utförts: vilken tid den gjordes och vilket konto som gjorde den. Den visas på personens pass utan namnet på den som bockade av, används inte till något annat och skrivs inte i behandlingshistoriken. NONOX har ingen rapport som räknar ihop eller jämför avbockningar per person.
- Uppgifter och avbockningar tas bort automatiskt tolv månader efter att passet slutade.
- Skriv inga personuppgifter och inget om hälsa i uppgifterna.
- Har ni kollektivavtal kan det kräva att ni förhandlar med den fackliga organisationen innan ni inför uppgifter på passen (11 § medbestämmandelagen). NONOX avgör inget av det åt er.

## Vanliga frågor

**Varför fick inte passet min uppgift?**
Kontrollera att passet börjar en av uppgiftens veckodagar, att avdelning och kompetens stämmer, att klockslaget ligger inom passet och att uppgiften är **Aktiv** och gäller den dagen.

**Jag har ändrat en uppgift men passet har kvar den gamla.**
Då är något redan avbockat på passet, eller så har passet redan varit. Det lämnas som det är.

**Kan jag se hur många uppgifter varje person har gjort?**
Du ser vad som är avbockat på varje pass, men det finns ingen sammanställning per person. Uppgifterna är ett stöd för passet och ska inte användas för att jämföra personal.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Publicera schemat](/manual/planering/publicera-schemat)
- [Mitt schema](/manual/personal/mitt-schema)
