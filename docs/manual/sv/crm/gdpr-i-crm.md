---
beskrivning: Rättslig grund för varje kontaktperson, intresseavvägningen, registerutdrag, radering och gallring av gamla kontakter.
ordning: 50
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
sokord: [gdpr, dataskydd, personuppgifter, rättslig grund, berättigat intresse, intresseavvägning, samtycke, radera person, registerutdrag, gallring]
---

# GDPR i CRM

Kontaktpersonerna i CRM är personuppgifter. Företaget är personuppgiftsansvarigt och behöver ha en rättslig grund för varje person, spara uppgifterna så länge de behövs och inte längre, och kunna lämna ut och radera dem när någon ber om det. NONOX hjälper dig med alla fyra.

> [!IMPORTANT]
> Texten om intresseavvägningen och den förvalda gallringstiden är inte granskade av en jurist än. Stäm av dem med den som ansvarar för dataskyddet hos er, eller med Integritetsskyddsmyndigheten (IMY).

## Rättslig grund för varje person

När du lägger till en person väljer du **Rättslig grund (GDPR)**:

| Grund | Passar när |
|---|---|
| **Avtal** | Personen eller dess arbetsgivare har ett avtal med er, och uppgifterna behövs för avtalet. |
| **Berättigat intresse** | Det vanliga för en kontaktperson på ett företag: ni vill kontakta personen i dess yrkesroll. |
| **Samtycke** | Personen har själv sagt ja. Ange datumet. Ett samtycke kan återkallas när som helst. |

Under **Anteckning om grunden** kan du skriva varför, till exempel var ni träffades.

## Intresseavvägningen

Berättigat intresse kräver att ert intresse väger tyngre än personens intresse av att slippa. För en kontaktperson på ett företag brukar avvägningen se ut så här:

1. **Ert intresse** – att sälja till och ha kontakt med företaget personen arbetar på.
2. **Nödvändigt** – ni sparar bara namn, titel och kontaktuppgifter i jobbet, och vad ni har pratat om.
3. **Personens intresse** – uppgifterna gäller yrkesrollen, inte privatlivet, och personen kan räkna med att bli kontaktad i den rollen.
4. **Slutsats** – ert intresse väger tyngre, så länge ni slutar höra av er när personen ber om det, och gallrar kontakter ni inte längre har nytta av.

Skriv aldrig känsliga uppgifter om en person i en anteckning, till exempel om hälsa eller familj.

## Registerutdrag

- **En kontaktperson**: öppna personen och tryck på **Ladda ner registerutdrag (JSON)**. Utdraget innehåller personens uppgifter, den rättsliga grunden, vilka företag personen hör till och aktiviteterna om personen.
- **En kund**: registerutdraget för kunden i kundregistret innehåller nu också kundens kort och aktiviteter i CRM.

## Radera på begäran

- **En person**: öppna personen och tryck på **Radera enligt GDPR**. Personen tas bort från alla företag. Aktiviteterna om personen finns kvar på kundens tidslinje, men utan personens namn, rubrik och text.
- **Ett prospekt**: tryck på **Ändra uppgifter** och sedan **Radera enligt GDPR**. Prospektet och dess aktiviteter tas bort.
- **En kund** som finns i bokföringen raderas aldrig från CRM. Fakturorna är räkenskapsinformation som ska sparas i sju år enligt Bokföringslagen. Radera eller anonymisera kunden i kundregistret i stället. Se [Personuppgifter](/manual/installningar/personuppgifter).

## Gallring av gamla kontakter

Personer och prospekt som ingen har haft någon aktivitet med på 24 månader raderas av sig själva varje natt. Tiden ändrar du under **CRM → Inställningar** (**Gallra efter, månader utan aktivitet**).

30 dagar innan visas de under **Att göra** på översikten och under **CRM → Personer → Gallras snart**. Vill ni behålla en kontakt räcker det att logga en aktivitet.

Följande gallras aldrig:

- ett prospekt som har blivit kund, eftersom det finns i kundregistret,
- en person som är **Fakturamottagare** hos en kund, eftersom namnet kan stå på fakturorna.

## Läs också

- [Företag och personer](/manual/crm/foretag-och-personer)
- [Personuppgifter](/manual/installningar/personuppgifter)
