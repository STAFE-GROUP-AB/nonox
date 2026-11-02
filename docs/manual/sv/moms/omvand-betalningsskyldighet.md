---
beskrivning: Så hanterar NONOX omvänd betalningsskyldighet – när du säljer byggtjänster till ett byggföretag och när du köper tjänster eller varor där du själv ska redovisa momsen.
ordning: 40
roller: [owner, admin, accountant, bookkeeper]
sokord: [omvänd betalningsskyldighet, omvänd skattskyldighet, omvänd moms, byggmoms, reverse charge, ruta 41, ruta 24, underentreprenör, 3231, 2647]
---

# Omvänd betalningsskyldighet

Vid omvänd betalningsskyldighet är det köparen, inte säljaren, som redovisar momsen. Säljaren fakturerar utan moms, och köparen räknar fram momsen och redovisar den själv – oftast med samma belopp som avdrag, så att den inte kostar något. I Sverige gäller det bland annat byggtjänster mellan byggföretag. Vid köp från andra EU-länder gäller samma princip.

Vilka affärer som omfattas avgör Mervärdesskattelagen, inte NONOX. NONOX bokför rätt när du har talat om vad det är.

![Momsdeklarationen för ett byggföretag, med försäljning där köparen är betalningsskyldig i ruta 41.](/bilder/manual/moms/omvand-betalningsskyldighet.webp)

## När du säljer byggtjänster

### Så här markerar du kunden

1. Gå till **Register → Kunder** och öppna kunden.
2. Kryssa i **Omvänd betalningsskyldighet för byggtjänster**.
3. Spara.

Nya fakturor till kunden blir utan moms. Du kan ändra det på en enskild faktura, och en kreditfaktura behåller originalets val.

### På fakturan

Fakturan får texten "Omvänd betalningsskyldighet för byggtjänster" och både ditt och kundens momsregistreringsnummer. Saknas något av numren går fakturan inte att skapa. Läs [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

### I bokföringen

Försäljningen bokförs på **3231** i stället för radernas intäktskonton, utan moms, och redovisas i **ruta 41** i momsdeklarationen. Saknas 3231 i kontoplanen läggs kontot till.

Omvänd betalningsskyldighet för byggtjänster kan inte kombineras med ROT- eller RUT-avdrag eller med fakturor till andra länder.

## När du köper

Köper du byggtjänster från en underentreprenör, varor i Sverige med omvänd betalningsskyldighet, eller varor och tjänster från ett annat land, väljer du det under **Vem redovisar momsen?** när du registrerar leverantörsfakturan. NONOX bokför då både utgående och ingående moms:

- Kostnaden på inköpskontot för typen och momssatsen – rutorna 20–24.
- Utgående moms på 2614, 2624 eller 2634 – rutorna 30–32.
- Ingående moms på **2647** för omvänd betalningsskyldighet i Sverige, och på **2645** för inköp från utlandet – ruta 48.

Steg för steg finns i [Inköp från utlandet och omvänd betalningsskyldighet](/manual/leverantorer/inkop-fran-utlandet).

## Vanliga frågor

**Hur vet jag att en tjänst är en byggtjänst?**
Det avgör NONOX inte. Läs Skatteverkets vägledning om omvänd betalningsskyldighet inom byggsektorn eller fråga din redovisningskonsult.

**Momsen på inköpet syns inte som en kostnad – är det rätt?**
Ja. Med full avdragsrätt tar den utgående och den ingående momsen ut varandra i ruta 49. Har företaget inte full avdragsrätt behöver en del av momsen hanteras annorlunda; fråga din redovisningskonsult.

> [!NOTE]
> Fakturatexten, kravet på båda momsregistreringsnumren, konto 3231 och ruta 41 är ännu inte juridiskt kontrollerade i NONOX. Kontrollera mot Mervärdesskattelagen och Skatteverkets vägledning.

## Läs också

- [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton)
- [Kunder](/manual/fakturering/kunder)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
