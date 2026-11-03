---
beskrivning: Lägg till prospekt, gör dem till kunder och håll ordning på kontaktpersonerna och deras roller.
ordning: 10
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.accounts.show, crm.people]
sokord: [prospekt, kundkort, kontaktperson, beslutsfattare, roll, koncern, moderbolag, taggar, gör till kund]
---

# Företag och personer

Varje kund och prospekt har ett kort. Där finns uppgifterna om företaget, personerna du pratar med och allt som har hänt. Ett **prospekt** är någon du vill göra affärer med. Det får ett kundnummer först när det blir kund.

![Kundkortet med status, nyckeltal ur bokföringen, kontaktpersoner, nästa steg och tidslinjen.](/bilder/manual/crm/kundkortet.webp)

## Så här lägger du till ett prospekt

1. Gå till **Kunder** och tryck på **Nytt prospekt**.
2. Skriv namnet. Organisationsnummer, e-post, telefon och ort är frivilliga.
3. Välj **Ansvarig**. Det blir du om du inte väljer någon annan.
4. Skriv gärna **Källa**, till exempel mässa eller tips, och **Taggar** skilda med kommatecken.
5. Tryck på **Lägg till**. Kortet öppnas.

## Så här gör du ett prospekt till kund

1. Öppna prospektets kort.
2. Tryck på **Gör till kund** under **Nyckeltal**.
3. Bekräfta. Kunden får nästa nummer i kundregistret och kan faktureras.

Statusen blir **Aktiv kund** när den första fakturan har skapats. Från kortet kommer du sedan direkt till **Ny offert** och **Ny faktura** med kunden ifylld.

## Kontaktpersoner

En person kan höra till flera företag och ha olika roller hos dem: **Beslutsfattare**, **Ambassadör**, **Påverkare**, **Användare**, **Fakturamottagare** eller **Övrig**.

- **Ny person** på kortet lägger till en person och kopplar den till företaget med en roll.
- **Koppla befintlig** kopplar en person som redan finns, till exempel när någon byter jobb till en annan kund.
- Rollen ändrar du direkt i listan på kortet.
- **Ta bort från kunden** tar bort kopplingen. Personen finns kvar under **CRM → Personer**.

Varje person behöver en **rättslig grund** enligt GDPR. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).

## Ändra uppgifterna

Tryck på **Ändra uppgifter** på kortet. Där ändrar du ansvarig, typ, **Del av koncern (moderbolag)**, SNI-kod, webbplats, källa och taggar.

För en kund hör namn, nummer och adress till kundregistret. Ändrar du dem här ändras de också där, så de stämmer på nästa faktura. Det kräver att du får ändra kunder. En säljare ändrar bara det som hör till säljarbetet.

Längst ned på kortet finns **Kunduppgifter för fakturor**: betalningsvillkor, momsnummer, valuta och försäljning utomlands. Det är samma formulär som i kundregistret, och det visas för den som får ändra kunder. Se [Kunder](/manual/fakturering/kunder).

## Bra att veta

- CRM sparar inga personnummer. För en enskild firma lämnar du organisationsnumret tomt; när firman blir kund står numret i kundregistret.
- **Ny kund** (för den som får skapa kunder) lägger till en kund direkt, med nästa kundnummer. **Nytt prospekt** lägger till någon du ännu inte fakturerar.
- En person kan märkas **Kontakta inte**. Se [GDPR i CRM](/manual/crm/gdpr-i-crm#kontakta-inte).
- En kund som finns i bokföringen kan aldrig raderas från CRM. Bokföringslagen kräver att fakturorna och deras uppgifter sparas.
- Personer och prospekt som ingen har haft kontakt med på länge raderas enligt företagets gallringsregel. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).

## Läs också

- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
- [Kundstatus](/manual/crm/kundstatus)
- [Kunder](/manual/fakturering/kunder)
