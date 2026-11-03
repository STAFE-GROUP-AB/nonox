---
beskrivning: Prospekt, aktiv kund, inaktiv och förlorad – hur statusen följer fakturorna och när du sätter den själv.
ordning: 30
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.settings]
sokord: [status, aktiv kund, inaktiv kund, förlorad affär, prospekt, förlorad kund, kundstatus]
---

# Kundstatus

Varje kund och prospekt har en status. Den sätts för det mesta av sig själv, efter fakturorna i bokföringen.

| Status | Betyder |
|---|---|
| **Prospekt** | Har ännu inte fått någon faktura. |
| **Aktiv kund** | Har fått en faktura inom det antal månader som företaget har valt (12 om ni inte har ändrat det). |
| **Inaktiv** | Har inte fått någon faktura på så länge. |
| **Förlorad** | Sätts bara för hand, med en orsak. |

## Så räknas statusen

En gång om dygnet går NONOX igenom alla kunder. Ett prospekt som har fått sin första faktura blir **Aktiv kund**. En aktiv kund som inte har fått någon faktura på det inställda antalet månader blir **Inaktiv**. Får en inaktiv kund en ny faktura blir den aktiv igen. Kreditfakturor räknas inte som ny försäljning.

## Så här sätter du status själv

1. Öppna kundens kort och tryck på **Ändra status**.
2. Välj status.
3. Väljer du **Förlorad** skriver du varför under **Varför förlorad?**, till exempel att kunden valde en annan leverantör.
4. Tryck på **Spara**.

En status du sätter själv gäller tills nästa faktura skapas. Då tar reglerna över igen. En förlorad kund som du får tillbaka och fakturerar blir alltså aktiv av sig själv.

## Ändra antalet månader

Under **CRM → Inställningar** väljer du **Inaktiv efter, månader utan faktura**. Det är företagets egen regel och kan vara mellan 1 och 120 månader. Inställningen ändras av den som får ändra inställningar i CRM.

## Läs också

- [Nyckeltal från bokföringen](/manual/crm/nyckeltal)
- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
