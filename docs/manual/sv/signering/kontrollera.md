---
beskrivning: Kontrollera på sidan /kontrollera att en PDF är oförändrad jämfört med ett signerat dokument som NONOX har sparat, utan att filen lämnar din dator.
ordning: 36
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: signing
sokord: [kontrollera, kontrollsida, verifiera, oförändrad, kontrollsumma, SHA-256, QR-kod, äkthet, signeringsbevis, jämföra fil]
---

# Kontrollera att ett signerat dokument är oförändrat

På sidan **Kontrollera ett signerat dokument** (adressen är `/kontrollera`) kan vem som helst, utan konto, kontrollera att en PDF-fil är exakt den fil som NONOX har sparat för en signering. Det passar den som har fått ett signerat dokument och vill se att ingen har ändrat det, till exempel en revisor, en bank eller en motpart.

![Sidan Kontrollera ett signerat dokument med knappen Välj fil.](/bilder/manual/signering/kontrollera.webp)

> [!IMPORTANT]
> Sidan är avstängd tills den slås på för installationen. I den driftade tjänsten på nonox.app är den avstängd tills en jurist har svarat på frågan om betrodda tjänster enligt EU:s förordning om elektronisk identifiering (eIDAS), som för försegling. Kör du NONOX på egen server slår du på den under **HQ → Inställningar**, **Kontrollsidan för signerade dokument**. Är sidan avstängd finns den inte, och signeringsbevisen har ingen QR-kod.

## Så här kontrollerar du en fil

1. Öppna sidan, eller skanna QR-koden på signeringsbeviset eller på sammanställningen.
2. Under **Välj fil** väljer du PDF-filen på din dator eller telefon.
3. Sidan svarar direkt: **Filen är oförändrad** eller **Filen finns inte hos NONOX**.

**Filen lämnar aldrig din enhet.** Din webbläsare räknar ut filens kontrollsumma (SHA-256), ett fingeravtryck på 64 tecken, och bara de tecknen skickas till NONOX. Inte filens namn eller innehåll. NONOX sparar varken kontrollsumman eller din IP-adress från en kontroll; adressen används bara i en minut för att begränsa antalet kontroller.

QR-koden på signeringsbeviset och på sammanställningen innehåller kontrollsumman för dokumentet som skickades. När du skannar den visar sidan resultatet för just den kontrollsumman, utan att du har valt någon fil. Det betyder att NONOX känner igen kontrollsumman på beviset, inte att filen du har är oförändrad. Välj filen på sidan för att kontrollera den. Kontrollsumman i QR-koden skickas aldrig som en del av adressen till NONOX, utan läses av din webbläsare.

## Vad sidan svarar

När NONOX har en signering med exakt den filen visar sidan:

- **Företag** och **Dokument**, med dokumentets titel. Titeln visas inte för ett konfidentiellt dokument.
- **Filen är**: originalet som skickades för signering, versionen efter att en undertecknare har signerat, den förseglade filen eller den omstämplade filen.
- **Läge**: signerat av alla, avböjt eller tillbakadraget, med tid. Sidan svarar inte för ett dokument som fortfarande är ute för signering eller som har gått ut.
- **Undertecknare**: hur många som har signerat av hur många, och **Kontroll av undertecknarna**: vilka kontroller de har gjort, till exempel engångskod till e-postadressen.
- **Försegling**: vilken nivå filen har förseglats med, eller att den inte är förseglad.
- **Händelsekedjan**: om NONOX:s nattliga kontroll av signeringens händelsekedja har funnit något ändrat, och när kontrollen kördes.

Sidan visar **aldrig** undertecknarnas namn, e-postadresser eller IP-adresser, inga interna id:n och ingenting om vad dokumentet innehåller.

När NONOX inte har någon sådan fil står det **Filen finns inte hos NONOX**. Filen kan vara ändrad, vara en annan fil än den som signerades eller höra till en signering som inte längre sparas. Svaret säger inget om vilken av dem det är.

## Vad sidan inte gör

Sidan kontrollerar bara att en fil är **oförändrad**: att den är byte för byte lika med en fil som NONOX har sparat. Den kontrollerar inte vem som har skrivit under, om en underskrift är giltig eller om en elektronisk stämpel i filen är betrodd. Det gör PDF-läsaren för stämpeln, och signeringsbeviset visar hur varje underskrift gjordes.

Kontrollerar du en fil som har skrivits ut, skannats eller sparats om i ett annat program är den en annan fil, och sidan svarar att NONOX inte har den.

Du kan kontrollera högst 20 filer i minuten. Därefter får du vänta en minut.

## Läs också

- [Försegling av signerade dokument](/manual/signering/forsegling)
- [Alla signeringar](/manual/signering/alla-signeringar)
- [Bevarande och radering av signeringar](/manual/signering/bevarande-och-radering)
