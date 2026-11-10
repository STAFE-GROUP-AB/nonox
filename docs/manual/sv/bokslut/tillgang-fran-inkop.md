---
beskrivning: Skapa anläggningstillgången direkt när inköpet bokförs, från en verifikation eller en leverantörsfaktura, och ställ in gränsen för inventarier av mindre värde.
ordning: 40
roller: [owner, admin, accountant]
sokord: [skapa tillgång, lägg till i befintlig tillgång, flera fakturor, inköp av inventarier, anskaffningskonto, inventarier av mindre värde, korttidsinventarier, direktavdrag, halva prisbasbeloppet, gräns]
---

# Anläggningstillgång direkt från inköpet

Köper du en dator eller en maskin behöver du inte först bokföra inköpet och sedan lägga in tillgången i registret för sig. När en rad i en verifikation eller en leverantörsfaktura bokförs på ett anskaffningskonto erbjuder NONOX att skapa tillgången samtidigt. Den skapas i samma ögonblick som inköpet bokförs och länkas till verifikationen.

![En leverantörsfaktura vars rad på konto 1250 blev en anläggningstillgång.](/bilder/manual/bokforing/anlaggningstillgang-fran-inkop.webp)

## Så här gör du

1. Registrera leverantörsfakturan eller skriv verifikationen som vanligt.
2. Kontera inköpet på ett anskaffningskonto, till exempel 1220 Inventarier och verktyg eller 1250 Datorer.
3. Under raderna visas **Anläggningstillgångar**: *Raden bokförs på ett anskaffningskonto. Lägg in inköpet i anläggningsregistret …*
4. Klicka på **Skapa anläggningstillgång**.
5. Kontrollera uppgifterna:
   - **Benämning** – radens text.
   - **Slag** – styr kontot för avskrivningar och ackumulerade avskrivningar.
   - **Nyttjandeperiod, månader**.
   - **Anskaffningsvärde, kr** – tomt betyder radens belopp utan moms.
   - **Anskaffningsdag** – tomt betyder fakturadatumet eller verifikationens datum.
   - **Första avskrivningsmånad** – tomt betyder anskaffningsmånaden.
6. Registrera fakturan eller bokför verifikationen.

Ska raden inte bli en tillgång, klicka på **Skapa ingen tillgång**.

### Lägga till inköpet i en tillgång som redan finns

Kommer en investering på flera fakturor – en maskin som betalas i delar, eller en ombyggnad med en faktura i månaden – ska den vara en enda tillgång. Lägg då nästa inköp till i tillgången i stället för att skapa en ny:

1. Klicka på **Lägg till i befintlig tillgång** i stället för **Skapa anläggningstillgång**. Knappen visas när registret har en tillgång på samma konto som raden.
2. Välj **Tillgång**. Bara tillgångar på radens konto finns i listan.
3. Lämna **Anskaffningsvärde, kr** tomt för radens belopp utan moms, eller skriv ett eget belopp.
4. Registrera fakturan eller bokför verifikationen.

Tillgångens anskaffningsvärde ökar med beloppet när inköpet bokförs. Vill du ångra innan dess, klicka på **Lägg inte till**. Ett inköp som redan är bokfört lägger du till under **Bokföring → Anläggningar** med **Lägg till inköp** – läs [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar#en-tillgang-av-flera-inkop).

## Det här händer

- Tillgången skapas i registret i samma steg som inköpet bokförs. Misslyckas bokföringen skapas ingen tillgång, och går tillgången inte att skapa bokförs inget.
- Radens kostnadsställe följer med till tillgången.
- I registret står **Anskaffad med verifikation** med en länk, och verifikationen visar vilka tillgångar som skapades. En tillgång av flera inköp visar **Anskaffad med flera inköp:** och en rad per verifikation.
- Avskrivningarna bokförs sedan från registret varje månad. Läs [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar).

## Vilka konton räknas som anskaffningskonton?

Utan eget val gäller registrets tillgångskonton: 1210, 1220, 1240 och 1250. Byggnader (1110) och mark (1130) är inte med; lägg till dem i fältet om företaget vill. Kontona för ackumulerade avskrivningar räknas inte, eftersom en debet där är en försäljning eller utrangering.

### Så här väljer du egna konton

1. Gå till **Bokföring → Anläggningar**.
2. Under **Tillgångar från inköp**, skriv kontona i **Anskaffningskonton**, till exempel `1220, 1221-1227`.
3. Klicka på **Spara**.

## Gränsen för inventarier av mindre värde

Billiga inventarier får i vissa fall dras av direkt i stället för att skrivas av över flera år. Var gränsen går bestäms av skattereglerna. Den ändras över tid, och inventarier som köps samtidigt kan behöva räknas ihop.

NONOX föreslår därför **ingen** gräns. Företaget anger sin egen:

1. Gå till **Bokföring → Anläggningar**.
2. Under **Tillgångar från inköp**, fyll i **Gräns för inventarier av mindre värde, kr**.
3. Klicka på **Spara**.

Ligger en rad under gränsen föreslås ingen tillgång, bara en upplysning om att beloppet är under gränsen. Lämnar du fältet tomt finns ingen gräns, och varje rad på ett anskaffningskonto får förslaget.

> [!IMPORTANT]
> Kontrollera med din redovisningskonsult eller hos Skatteverket vilken gräns som gäller för företaget, och om den gäller med eller utan moms. NONOX har inte juridiskt kontrollerat beloppet och sätter det aldrig åt dig.

## Det här går inte

- Bara den som får använda anläggningsregistret – ägare, administratör och redovisningskonsult – kan lägga till förslaget. En bokförare kan sedan bokföra utkastet.
- Kreditnotor och inköp där du själv redovisar momsen (till exempel från andra EU-länder) får inget förslag.
- Ändrar du raden så att den inte längre bokförs på ett anskaffningskonto stoppas bokföringen med ett fel på raden. Ta bort förslaget eller rätta kontot.

## Kontantmetoden

Bokför företaget enligt kontantmetoden bokförs leverantörsfakturan först vid betalning. Tillgången skapas ändå när fakturan registreras och länkas då bara till fakturan. Vilken anskaffningsdag som ska gälla i det fallet är inte juridiskt kontrollerat i NONOX – fråga din redovisningskonsult.

## Läs också

- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar)
