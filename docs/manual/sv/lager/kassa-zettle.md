---
beskrivning: Koppla Zettle till NONOX. Försäljningen dras från lagret, lagersaldot skickas till kassan som ändringar, artiklarna läggs upp i Zettle och varje dag blir ett verifikationsutkast i serie K.
ordning: 70
roller: [owner, admin, accountant, bookkeeper, auditor, stock_clerk]
modul: pos
rutter: [pos.index]
sokord: [Zettle, kassa, kassasystem, kortterminal, försäljning, dagsrapport, Z-rapport, serie K, omatchade, lagersaldo, webhook]
---

# Kassa: Zettle

> [!NOTE]
> Kopplingen är byggd men ännu inte öppen för alla. Den slås på när den har provats mot en riktig kassa, och då står det i [ändringsloggen](/andringslogg).

Med modulen **Kassakopplingar** kopplar du Zettle till NONOX. Modulen kräver Lager. Slå på den under **Inställningar → Moduler**. Du hittar kassan under **Kassa** i menyn.

## Det här händer när kassan är kopplad

- **Försäljningen kommer in direkt.** Zettle skickar varje köp till NONOX. Varje köp sparas en gång, även om Zettle skickar det igen.
- **Varorna dras från lagret.** Varje rad kopplas till en lagerförd artikel och dras från kassans lagerställe.
- **Lagersaldot skickas till Zettle.** När du tar emot en leverans, inventerar eller kasserar skickas ändringen till Zettle. NONOX skickar ändringen, inte hela saldot, så en försäljning i kassan under tiden skrivs aldrig över. Det som Zettle själv har sålt skickas inte tillbaka.
- **Artiklarna läggs upp i Zettle.** Nya och ändrade artiklar skickas med namn, pris inklusive moms, momssats, EAN och artikelnummer. Inget tas bort i Zettle.
- **Varje dag blir ett verifikationsutkast** i serie K. Det bokförs inte förrän du gör det.

## Koppla Zettle

1. Gå till **Kassa** och välj **Koppla** vid Zettle.
2. Logga in hos Zettle och godkänn att NONOX får läsa produkter, köp och avgifter och lägga upp produkter.
3. Du kommer tillbaka till Kassa. Försäljningen kommer in från och med nu.

Det krävs rollen Ägare eller Administratör. Står det **Inte konfigurerad på den här servern** har den som driftar NONOX inte registrerat en app hos Zettle ännu.

Ett Zettle-konto kan bara vara kopplat till ett företag i NONOX åt gången.

## Inställningar

Under **Inställningar** vid kassan väljer du:

- **Artiklar** – *Från NONOX till kassan*, eller *Båda hållen*. Båda hållen kopplar först kassans befintliga produkter till artiklar med samma EAN, streckkod, SKU eller artikelnummer, så att inget läggs upp två gånger.
- **Skicka nya och ändrade artiklar till kassan.**
- **Skicka lagerändringar till kassan.** Zettle räknar hela styck. En ändring på en halv enhet skickas inte och syns i loggen.
- **Lagerställe för kassan** – försäljningen dras härifrån, och bara ändringar här skickas till Zettle. Lämnar du det tomt används artikelns standardlagerställe.

## Omatchade rader

En rad som inte kunde kopplas till en lagerförd artikel hamnar under **Omatchade rader**. Det gäller också en rad där lagret inte räckte, om negativt lager är avstängt.

Skriv artikelnumret, EAN eller SKU och välj **Koppla**. Zettles produkt kopplas till artikeln, så nästa försäljning hittar den själv. Alla väntande rader med samma produkt dras från lagret på en gång. Välj **Undanta** för en rad som inte ska dra något från lagret.

Det krävs behörighet att hantera lagret. Lagerpersonal ser raderna men kopplar dem inte.

## Dagens verifikation

Under **Dagar att bokföra** står varje dag med försäljning som ännu inte finns i en verifikation. Välj **Skapa verifikationsutkast**. Varje morgon skapas utkastet för gårdagen av sig själv, om inställningen är på.

Utkastet har:

- försäljning per momssats och utgående moms per momssats, i kredit
- varje betalsätt i debet: kort, kontanter, Swish, faktura och presentkort på sina egna konton
- kortavgifterna som Zettle har dragit, i debet på bankkostnader och kredit på kortfordran
- dricks och öresutjämning

Går beloppen inte ihop skapas inget utkast, och du ser skillnaden. Kontrollera utkastet mot Zettles egen dagsrapport, bifoga den som underlag och bokför. En försäljning ingår bara i ett utkast. Tar du bort utkastet kan dagen föreslås igen.

Kontona ändrar du under **Inställningar → Moduler → Kassakopplingar**. Förslagen följer BAS. Stäm av dem med den som sköter bokföringen.

## Koppla bort

Välj **Koppla bort**. NONOX ber Zettle att glömma kopplingen och raderar nycklarna. Försäljningen som kommit in, kopplingarna till artiklar och loggen finns kvar.

## Logg

Under **Logg** vid kassan ser du vad som har hänt: när kassan kopplades, hur många köp som hämtades och vad som inte gick att skicka.

Se också [Kassa: Onslip](/manual/lager/kassa-onslip), [Artiklar i lager](/manual/lager/artiklar) och [NONOX Connect](/manual/installningar/nonox-connect).
