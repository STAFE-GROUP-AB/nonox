---
beskrivning: Få en uppgift när en kund eller ett prospekt byter adress eller namn, går i konkurs eller likvidation eller avregistreras i företagsregistret.
ordning: 80
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
sokord: [bevakning, bevaka kund, konkurs, likvidation, flytt, ny adress, avregistrerad, kundbevakning, företagsbevakning]
---

# Bevakning av kunder

NONOX bevakar dina kunder och prospekt i företagsregistret. När SCB eller Bolagsverket har nya uppgifter om ett företag som har ett organisationsnummer i CRM får den som är ansvarig för kunden en uppgift, **Ändring hos kunden**, med vad som har ändrats.

## Det här bevakas

- Nytt namn
- Ny adress, nytt postnummer eller ny ort
- Ny bolagsform och ny bransch
- Om företaget slutar vara i drift (inte längre registrerat för moms, F-skatt eller som arbetsgivare)
- Avregistrering
- Konkurs, likvidation och företagsrekonstruktion

Har den som driftar NONOX läst in SCB:s API bevakas också ändrat antal anställda, ändrad omsättning (i klasser), ny kommun och ändrad status hos Bolagsverket.

> [!NOTE]
> Ny styrelse eller VD, betalningsanmärkningar och kreditbetyg bevakas inte. De uppgifterna finns inte i de öppna registren utan kräver en kreditupplysningstjänst, och NONOX har ingen ansluten än.

## Så här ser du ändringarna

1. Uppgiften **Ändring hos kunden** kommer under **CRM → Mina aktiviteter** för den som är ansvarig för kunden, klockan nio den dag ändringen upptäcktes. Har kunden ingen ansvarig får ägaren uppgiften.
2. Öppna uppgiften. Där står vad som har ändrats, till exempel "Ny adress: Exempelgatan 1 → Nya gatan 10".
3. Gör det som behövs, till exempel ändra adressen på kunden eller se över fakturorna som är obetalda, och klarmarkera uppgiften.

På kundkortet visar rutan **I företagsregistret** vad registret säger om företaget just nu: status, bolagsform, bransch, registreringsdatum och eventuella spärrar.

## Så här stänger du av bevakningen

1. Öppna **CRM → Inställningar**.
2. Kryssa ur **Bevaka kunder och prospekt i företagsregistret**.
3. Tryck på **Spara**.

## Bra att veta

- **Varje ändring rapporteras en gång.** Flera ändringar hos samma kund samma dag blir en uppgift.
- **Bara ändringar efter att kunden lades in** rapporteras.
- **Registret uppdateras en gång i veckan**, så en ändring kan komma några dagar efter att den registrerades hos Bolagsverket eller SCB.
- Uppgiften är en påminnelse och räknas inte som kontakt med kunden, så den påverkar inte när gamla prospekt gallras. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).

## Läs också

- [Prospektera i företagsregistret](/manual/crm/prospektera)
- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
- [Kundstatus](/manual/crm/kundstatus)
