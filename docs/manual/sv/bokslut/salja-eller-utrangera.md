---
beskrivning: Boka bort en anläggningstillgång som säljs eller kasseras, med vinst eller förlust mot det bokförda värdet.
ordning: 50
roller: [owner, admin, accountant]
sokord: [sälja inventarie, avyttra, avyttring, utrangera, utrangering, kassera, realisationsvinst, realisationsförlust, 3973, 7973]
---

# Sälja eller utrangera en tillgång

När en tillgång säljs eller slängs ska den bort ur bokföringen. Anläggningsregistret bokar bort anskaffningsvärdet och avskrivningarna, och skillnaden mot det du fick betalt blir en vinst eller en förlust.

## Så här gör du

1. Bokför först månadens avskrivning till och med månaden före försäljningen, eller den månad du och din redovisningskonsult har bestämt. Läs [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar).
2. Gå till **Bokföring → Anläggningar** och öppna tillgången.
3. Klicka på **Sälj eller utrangera**.
4. Fyll i **Datum**.
5. Fyll i **Pris exkl. moms, kr**. Skriv 0 om tillgången kasseras.
6. Välj **Betalning till konto** – kontot pengarna kom in på, normalt 1930.
7. Klicka på **Boka bort tillgången**.

Tillgången står därefter som **Avyttrad** med datumet, och inga fler avskrivningar bokförs.

## Det här händer i bokföringen

En verifikation på datumet för försäljningen:

| Konto | Debet | Kredit |
|---|---|---|
| Ackumulerade avskrivningar (t.ex. 1229) | det som är avskrivet | |
| Betalningskontot (t.ex. 1930) | priset exkl. moms | |
| Tillgångskontot (t.ex. 1220) | | anskaffningsvärdet |
| 3973 Vinst vid avyttring … | | om priset var högre än det bokförda värdet |
| 7973 Förlust vid avyttring … | om priset var lägre | |

> [!IMPORTANT]
> Momsen på försäljningen bokförs inte här. Säljer du tillgången med moms, bokför momsen med en egen verifikation eller en kundfaktura. Om försäljningen är momspliktig och om avyttringsmånaden ska skrivas av är frågor för din redovisningskonsult – NONOX har inte kontrollerat dem juridiskt.

## Vanliga frågor

**Jag sålde tillgången med en kundfaktura.**
Då bokför kundfakturan både försäljningen och momsen på de konton fakturan använder. Prata med din redovisningskonsult om hur fakturan och bortbokningen här ska samspela, så att försäljningen inte bokförs två gånger.

**Kan jag ångra en avyttring?**
Nej. Verifikationen är bokförd och kan bara rättas. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

## Läs också

- [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar)
- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
