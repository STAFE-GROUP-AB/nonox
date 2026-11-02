---
beskrivning: Koppla Onslip 360 till NONOX. Kvittona hämtas in och dras från lagret, artiklarna läggs upp i rätt varugrupp och varje dag blir ett verifikationsutkast i serie K.
ordning: 90
roller: [owner, admin, accountant, bookkeeper, auditor, stock_clerk]
modul: pos
rutter: [pos.index]
sokord: [Onslip, Onslip 360, kassa, kassasystem, kvitto, varugrupp, försäljning, dagsrapport, Z-rapport, serie K, omatchade]
---

# Kassa: Onslip

Med modulen **Kassakopplingar** kopplar du Onslip 360 till NONOX. Modulen kräver Lager. Slå på den under **Inställningar → Moduler**. Du hittar kassan under **Kassa** i menyn.

## Det här händer när kassan är kopplad

- **Kvittona hämtas in** varje timme, och en gång per natt för den senaste veckan. Du kan också välja **Hämta försäljning nu**. Varje kvitto sparas en gång.
- **Varorna dras från lagret**, precis som för Zettle.
- **Artiklarna läggs upp i Onslip**, i den varugrupp som hör till artikelns momssats.
- **Varje dag blir ett verifikationsutkast** i serie K.

Onslip har inget öppet lager-API. NONOX skickar därför inget lagersaldo till Onslip. Saldot i NONOX ändras av försäljningen, och det är NONOX som gäller när du inventerar.

Kreditkvitton är inte försäljning och hämtas inte. Ett returkvitto läggs tillbaka i lagret.

## Koppla Onslip

1. Gå till **Kassa** och välj **Koppla** vid Onslip.
2. Logga in hos Onslip och godkänn kopplingen.
3. Du kommer tillbaka till Kassa.

Det krävs rollen Ägare eller Administratör. Står det **Inte konfigurerad på den här servern** har den som driftar NONOX inte registrerat en integration hos Onslip ännu.

NONOX ber bara om att få läsa journalen, aldrig om att ändra kvitton.

## Varugrupper

Onslip har momsen på varugruppen, inte på produkten. Öppna **Inställningar** vid kassan, välj **Hämta varugrupper från Onslip** och välj en varugrupp för varje momssats. En artikel vars momssats saknar varugrupp skickas inte, och det syns i loggen.

## Omatchade rader och dagens verifikation

Det fungerar som för Zettle. Se [Kassa: Zettle](/manual/lager/kassa-zettle#omatchade-rader).

Onslip räknar inte avgifter för kort, eftersom kortinlösen sköts av en annan leverantör. Kortbetalningarna hamnar på kontot för kort och kortinlösen och stäms av mot utbetalningen från kortinlösaren under Bank.

## Koppla bort

Välj **Koppla bort**. NONOX raderar nycklarna. Onslip har inget sätt för NONOX att avsluta kopplingen hos dem, så ta också bort integrationen i Onslips Backoffice.

Se också [Kassa: Zettle](/manual/lager/kassa-zettle) och [Lagerställen](/manual/lager/lagerstallen).
