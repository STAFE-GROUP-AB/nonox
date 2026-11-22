---
beskrivning: Kontrollera på sidan /kontrollera att en PDF är oförändrad jämfört med ett signerat dokument som NONOX har sparat, utan att filen lämnar din dator, och slå på sidan för företaget.
ordning: 36
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: signing
sokord: [kontrollera, kontrollsida, verifiera, oförändrad, kontrollsumma, SHA-256, QR-kod, äkthet, signeringsbevis, jämföra fil]
---

# Kontrollera att ett signerat dokument är oförändrat

På sidan **Kontrollera ett signerat dokument** (adressen är `/kontrollera`) kan den som har en kopia av ett signerat dokument, utan konto, kontrollera att filen är exakt den fil som NONOX har sparat för signeringen. Det passar den som har fått ett signerat dokument och vill se att ingen har ändrat det, till exempel en revisor, en bank eller en motpart.

![Sidan Kontrollera ett signerat dokument med knappen Välj fil.](/bilder/manual/signering/kontrollera.webp)

> [!IMPORTANT]
> Sidan är avstängd tills den slås på, två gånger. Först för installationen: i den driftade tjänsten på nonox.app är den avstängd tills en jurist har svarat på frågan om betrodda tjänster enligt EU:s förordning om elektronisk identifiering (eIDAS), som för försegling. Kör du NONOX på egen server slår du på den under **HQ → Inställningar**, **Kontrollsidan för signerade dokument**. Sedan för varje företag, se nedan. Är något av de två avstängt svarar sidan som om filen inte fanns, och signeringsbevisen har ingen QR-kod.

## Slå på kontrollsidan för företaget

Bara den som har behörigheten att ändra signeringens inställningar kan göra det.

![Rutan Kontrollsidan för signerade dokument under Inställningar för företaget → Signering.](/bilder/manual/signering/kontrollera-installning.webp)

1. Öppna **Inställningar för företaget → Signering**.
2. I rutan **Kontrollsidan för signerade dokument** kryssar du i **Låt mottagare kontrollera filer på kontrollsidan**.
3. Tryck på **Spara**.

Inställningen gäller företagets alla signeringar som är klara, också tidigare. Slår du av den svarar sidan som om filerna inte fanns, och nya signeringsbevis får ingen QR-kod.

## Så här kontrollerar du en fil

1. Öppna sidan, eller skanna QR-koden på signeringsbeviset eller på sammanställningen.
2. Under **Välj fil** väljer du PDF-filen på din dator eller telefon.
3. Sidan svarar direkt: **Filen är oförändrad** eller **Filen finns inte hos NONOX**.

**Filen lämnar aldrig din enhet.** Din webbläsare räknar ut filens kontrollsumma (SHA-256), ett fingeravtryck på 64 tecken, och bara de tecknen skickas till NONOX. Inte filens namn eller innehåll. NONOX sparar inte kontrollsumman. Din IP-adress används i högst en minut för att begränsa antalet kontroller och finns annars bara i webbserverns vanliga åtkomstlogg.

QR-koden på signeringsbeviset och på sammanställningen innehåller kontrollsumman för dokumentet som skickades, den som står som **Kontrollsumma (SHA-256)** på samma sida, och inte kontrollsumman för den signerade eller förseglade filen du fick efter signeringen. När du skannar den visar sidan resultatet för just den kontrollsumman, utan att du har valt någon fil, och säger det: det betyder att NONOX känner igen kontrollsumman, inte att filen du har är oförändrad. Välj filen på sidan för att kontrollera den. Kontrollsumman i QR-koden ligger i adressens fragment (efter `#`), som webbläsaren aldrig skickar till NONOX.

## Vad sidan svarar

Sidan svarar bara för signeringar där alla har signerat, och bara när kontrollsumman finns i en enda signering. Då visar den:

- **Företag**: avsändarens namn.
- **Filen är**: originalet som skickades för signering, versionen efter att en undertecknare har signerat, den förseglade filen eller den omstämplade filen.
- **Läge**: signerat av alla, med tid.
- **Undertecknare**: hur många som har signerat av hur många, och **Kontroll av undertecknarna**: vilka kontroller de har gjort, till exempel engångskod till e-postadressen.
- **Förseglad av NONOX**: nivån, bara när filen är den förseglade filen, annars **Inte förseglad**. Sidan kontrollerar inte stämpeln i filen; öppna filen i en PDF-läsare för det.
- **Händelsekedjan**: om NONOX:s nattliga kontroll av signeringens händelsekedja har funnit något ändrat, och när kontrollen kördes. Har ingen kontroll körts efter att signeringen blev klar står det **Har inte kontrollerats än**.

När du har valt en fil som är byte för byte lika med en fil NONOX har sparat står det **Filen är oförändrad**, tillsammans med namnet på filen du valde.

Sidan visar **aldrig** dokumentets titel, undertecknarnas namn, e-postadresser eller IP-adresser, inga interna id:n och ingenting om vad dokumentet innehåller.

Har samma fil skickats för signering mer än en gång, av samma företag eller av olika, kan sidan inte säga vilken signering den hör till. Då svarar den bara att filen har skickats mer än en gång och att du i stället ska kontrollera den signerade eller förseglade filen du fick efter signeringen. Inget företag, ingen tid och inget antal visas.

När NONOX inte har någon sådan fil, eller företaget inte har slagit på sidan, står det **Filen finns inte hos NONOX**. Filen kan vara ändrad, vara en annan fil än den som signerades eller höra till en signering som inte längre sparas. Svaret säger inget om vilken av dem det är. Sidan svarar inte för dokument som fortfarande är ute för signering, som har avböjts, dragits tillbaka eller gått ut.

## Vad sidan inte gör

Sidan kontrollerar bara att en fil är **oförändrad**: att den är byte för byte lika med en fil som NONOX har sparat. Den kontrollerar inte vem som har skrivit under, om en underskrift är giltig eller om en elektronisk stämpel i filen är betrodd. Det gör PDF-läsaren för stämpeln, och signeringsbeviset visar hur varje underskrift gjordes.

Kontrollerar du en fil som har skrivits ut, skannats eller sparats om i ett annat program är den en annan fil, och sidan svarar att NONOX inte har den.

Du kan kontrollera högst 20 filer i minuten. Därefter får du vänta en minut.

## Läs också

- [Försegling av signerade dokument](/manual/signering/forsegling)
- [Alla signeringar](/manual/signering/alla-signeringar)
- [Bevarande och radering av signeringar](/manual/signering/bevarande-och-radering)
