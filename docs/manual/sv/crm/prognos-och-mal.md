---
beskrivning: Vad de öppna affärerna väntas ge per månad, och hur långt varje säljare har kommit mot månadens mål för möten, offerter och ordervärde.
ordning: 24
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: crm
rutter: [crm.forecast]
sokord: [prognos, försäljningsprognos, viktat värde, mål, säljmål, budget, möten, offerter, ordervärde, säljare]
---

# Prognos och mål

Under **CRM → Prognos och mål** ser du vad de öppna affärerna väntas ge och hur det går mot målen. Allt räknas ur affärerna, aktiviteterna och faktureringen. Ingen behöver fylla i något.

![Prognos per månad med viktat värde, fullt värde och vunnet, och månadens mål per säljare.](/bilder/manual/crm/prognos-och-mal.webp)

## Prognosen

Tabellen visar en rad per månad, efter affärernas **väntade avslut**:

| Kolumn | Så räknas det |
|---|---|
| **Viktat** | Varje öppen affärs värde gånger sannolikheten för steget den står i, summerat för månaden. En affär på 100 000 kr i ett steg med 50 % räknas som 50 000 kr. |
| **Fullt värde** | Summan av de öppna affärernas värde, och hur många de är. |
| **Vunnet** | Värdet av de affärer som faktiskt vanns den månaden. |

Tabellen visar tre månader bakåt, så att du ser vad som vanns, och sex eller tolv månader framåt. Alla belopp är utan moms.

Under tabellen finns tre grupper som inte hör till någon månad:

- **Väntat avslut har passerat** – öppna affärer vars datum redan har varit. Flytta datumet eller avsluta affären.
- **Utan väntat avslut** – öppna affärer utan datum.
- **Senare** – affärer som väntas avslutas efter den sista månaden i tabellen.

Välj **Mina** för att se prognosen för dina egna affärer.

## Mål per säljare

Den som har behörighet att ändra inställningarna i CRM sätter mål per säljare och månad:

1. Välj månad med **Föregående** och **Nästa**.
2. Välj **Säljare** och vad målet gäller: **Möten**, **Offerter** eller **Ordervärde**.
3. Skriv målet: ett antal, eller kronor för ordervärde.
4. Tryck på **Spara mål**. Lämna fältet tomt och spara för att ta bort målet.

Hur långt säljaren har kommit räknas så här:

| Mål | Räknas från |
|---|---|
| **Möten** | Möten som säljaren är ansvarig för och som har klarmarkerats under månaden. |
| **Offerter** | Offerter i faktureringen som säljaren har skapat, med offertdatum i månaden. |
| **Ordervärde** | Värdet av de affärer säljaren är ansvarig för och som vanns under månaden. |

Under ordervärdet visas också **Fakturerat på kopplade affärer**: vad som har fakturerats under månaden på fakturor som hör till säljarens vunna affärer, antingen kopplade direkt eller via affärens offert eller order. En faktura räknas en gång.

En säljare ser sina egna mål. Den som sätter målen ser allas.

> [!IMPORTANT]
> Mål och utfall per säljare är personuppgifter om era anställda. Berätta för säljarna vad som mäts, varför och vem som ser det innan ni börjar använda målen (artikel 13 i dataskyddsförordningen). Har ni kollektivavtal kan ni behöva förhandla med facket innan ni inför uppföljningen (11 § medbestämmandelagen). Använd siffrorna för att följa upp försäljningen, inte som enda underlag för beslut om en enskild säljare.

> [!NOTE]
> Rollen **Säljare** kan inte skapa offerter. Vill ni ha mål för offerter ger ni säljaren en roll som får skapa fakturor och offerter.

## Läs också

- [Säljtratt och affärer](/manual/crm/saljtratt-och-affarer)
- [Översikt för försäljningen](/manual/crm/instrumentpanel)
