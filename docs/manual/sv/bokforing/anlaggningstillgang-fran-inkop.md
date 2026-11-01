---
beskrivning: Bokför inköpet av en dator, en maskin eller inventarier och lägg in tillgången i anläggningsregistret i samma steg.
ordning: 80
roller: [owner, admin, accountant]
sokord: [anläggningstillgång, inventarier, investering, avskrivning, dator, maskin, anskaffning, anläggningsregister]
---

# Skapa en anläggningstillgång från ett inköp

När du köper något som ska användas i flera år – en dator, en maskin, inventarier – bokförs inköpet på ett anskaffningskonto och skrivs av över tiden. NONOX känner igen raden när du bokför och erbjuder att lägga in tillgången i anläggningsregistret direkt, så att avskrivningarna sedan kan bokföras därifrån varje månad.

## Så här gör du

1. Bokför inköpet som vanligt under **Bokföring → Verifikationer → Ny verifikation**, med anskaffningen i debet på ett anskaffningskonto, till exempel 1220 Inventarier och verktyg eller 1250 Datorer.
2. Under raderna dyker rutan **Anläggningstillgångar** upp, med raden och beloppet.
3. Välj **Skapa anläggningstillgång**.
4. Fyll i eller kontrollera fälten:
   - **Benämning** – vad det är, till exempel "Bärbar dator till Anna".
   - **Slag** – till exempel **Datorer** eller **Inventarier och verktyg**. Slaget föreslår nyttjandeperioden och avskrivningskontona.
   - **Nyttjandeperiod, månader** – hur länge tillgången skrivs av.
   - **Anskaffningsvärde, kr** – lämnas det tomt används radens belopp, som är utan moms.
   - **Anskaffningsdag** – lämnas den tom används verifikationens datum.
   - **Första avskrivningsmånad** – lämnas den tom används anskaffningsmånaden.
5. Bokför verifikationen.

Tillgången skapas i samma ögonblick som verifikationen bokförs. På verifikationens sida står sedan **Anläggningstillgångar som skapades när verifikationen bokfördes**, med en länk till registret.

Ångrar du dig innan du bokför väljer du **Skapa ingen tillgång**. Sparar du verifikationen som utkast sparas förslaget med utkastet.

## Det här händer i bokföringen

Inköpet bokförs precis som du skrev det, och tillgången läggs in i anläggningsregistret kopplad till verifikationen och raden. Kunde tillgången inte skapas bokförs inte heller verifikationen; du får se vad som behöver rättas.

Avskrivningarna bokförs inte här, utan från registret under **Bokföring → Anläggningar**. Där väljer du också vilka konton som räknas som anskaffningskonton. Se [Anläggningstillgångar](/manual/bokslut/anlaggningstillgangar).

## Bra att veta

- Rutan visas bara för rader med ett belopp i debet på ett av företagets anskaffningskonton. Från början är det tillgångskontona för slagen i registret: 1210, 1220, 1240 och 1250.
- Har företaget angett en gräns för inventarier av mindre värde visas ingen knapp för belopp under gränsen, bara en förklaring. Gränsen ändrar du under **Anläggningar**. Vilken gräns som är rätt för ditt företag är en redovisningsfråga; fråga din redovisningskonsult.
- Rutan syns bara för den som sköter anläggningsregistret: ägare, administratörer och redovisningskonsulter. En bokförare kan bokföra inköpet, och tillgången läggs sedan in under **Anläggningar**.
- Samma ruta finns när du registrerar en leverantörsfaktura med en rad på ett anskaffningskonto. Då skapas tillgången när fakturan registreras.

## Läs också

- [Anläggningstillgångar](/manual/bokslut/anlaggningstillgangar)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
