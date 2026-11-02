---
beskrivning: Sjukanmälan, vård av barn och ledighet – godkänn ansökningar från Min sida, registrera frånvaro och se hur sjuklön och karensavdrag räknas.
ordning: 80
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.requests]
sokord: [sjuk, sjukfrånvaro, sjuklön, karensavdrag, karensdag, VAB, vård av barn, föräldraledig, tjänstledig, frånvaroavdrag, sjukanmälan, ansökan]
---

# Frånvaro, sjuklön och karensavdrag

När en anställd är sjuk, vårdar sitt sjuka barn eller är ledig påverkar det lönen. I NONOX kommer frånvaron in på två sätt:

- Den anställda anmäler den själv på **Min sida**, och du godkänner den under **Lön → Ansökningar**.
- Du registrerar den direkt i lönekörningen.

När frånvaron är godkänd räknar NONOX sjuklön, karensavdrag och avdrag i den lönekörning frånvaron hör till.

![En lönekörning med sjukfrånvaro och karensavdrag. Beloppen är demodata.](/bilder/manual/lon/franvaro-och-sjuklon.webp)

## Så här godkänner du en ansökan från Min sida

Anmälningar och ansökningar från de anställda samlas under **Lön → Ansökningar**. Antalet som väntar visas i menyn, till exempel **Ansökningar (2)**.

1. Öppna **Lön → Ansökningar**. Under **Väntar på svar** ser du vem, datumen och eventuellt meddelande.
2. Kontrollera **Timmar per dag**. NONOX föreslår veckoarbetstiden delad med fem; ändra om personen har ett annat schema.
3. Klicka på **Godkänn**.
4. Ska ansökan inte godkännas: klicka på **Avslå**, skriv **Skäl, som den anställde ser** och klicka på **Avslå ansökan**.

Den anställda får besked. Under **Senast besvarade** ser du det du nyss har svarat på.

> [!NOTE]
> Du kan inte godkänna din egen ansökan när **Den som beräknar får inte attestera** är på i löneinställningarna. Och frånvaro kan inte läggas in i en månad vars lönekörning redan är attesterad; en beräknad lönekörning går tillbaka till utkast.

## Så här registrerar du frånvaro i lönekörningen

Fick du en sjukanmälan på telefon, registrera den direkt i lönekörningen under **Frånvaro**. Välj anställd, **Orsak**, datum och **Timmar per dag**, och klicka på **Registrera**. Kontrollera uppgifterna noga innan du registrerar: frånvaron kan inte ändras eller tas bort i efterhand. Datumen måste ligga i lönekörningens månad; frånvaro över ett månadsskifte registreras i båda månadernas lönekörningar. Se [Gör en lönekörning](/manual/lon/lonekorning).

## Frånvaroorsaken är en känslig uppgift

Att någon är sjuk är en känslig personuppgift. Orsaken visas därför bara för den som har behörighet att se känsliga löneuppgifter, normalt löneadministratören. Andra, även ägaren, ser bara **Frånvaro**. Ett meddelande från den anställda visas också bara för den med behörigheten.

Vill ägaren se orsakerna behöver ägaren få rollen löneadministratör. Se [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter).

## Så här räknas sjuklön

Sjuklön betalas av arbetsgivaren enligt sjuklönelagen under arbetsgivarens sjuklöneperiod. NONOX räknar så här:

- **Månadslön**: lönen för de sjuka timmarna dras av (**Sjukavdrag**), och personen får **Sjuklön** med sjuklönens procentsats för samma timmar.
- **Karensavdrag**: för varje ny sjukperiod görs ett karensavdrag, beräknat på en genomsnittlig veckas sjuklön enligt sjuklönelagen.
- **Återinsjuknande**: börjar en ny sjukperiod kort efter den förra räknas den som en fortsättning, och då görs inget nytt karensavdrag.
- **Efter arbetsgivarperioden** betalar arbetsgivaren ingen sjuklön. Lönebeskedet får varningen **Sjukperioden är längre än arbetsgivarperioden. Anmäl sjukfallet till Försäkringskassan.** Anmälan till Försäkringskassan gör du själv.

Procentsatser, antal dagar och tidsgränser kommer från reglerna under **Lön → Regler**, med länk till lagtexten. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

> [!IMPORTANT]
> Sjuklönereglerna har några specialfall som NONOX ännu inte hanterar fullt ut: ett karensavdrag som är större än sjuklönen i månaden begränsas till sjuklönen, och resten förs inte vidare. Ett kollektivavtal kan också ge andra regler än lagen. Kontrollera sådana fall med er redovisningskonsult eller Försäkringskassan.

## Så här räknas annan frånvaro

| Orsak | Månadslön | Timlön |
|---|---|---|
| **Vård av barn**, **Föräldraledig**, **Tjänstledig utan lön**, **Annan frånvaro** | Avdrag för de timmar personen var borta (**Avdrag för frånvaro**) | Ingen lön för timmar som inte arbetades |
| **Ledig med lön** | Inget avdrag | – |
| **Semester** | Se [Semester](/manual/lon/semester) | Se [Semester](/manual/lon/semester) |

Frånvaro räknas bara på dagar då personen skulle ha arbetat enligt schemat och som inte är helgdagar. Ersättning från Försäkringskassan, till exempel för vård av barn, söker den anställda själv.

## Vanliga frågor

**Den anställda vet inte hur länge hen blir sjuk.**
På Min sida anger den anställda en ungefärlig sista dag. Godkänd eller registrerad frånvaro går ännu inte att ändra eller ta bort i NONOX. Vänta därför med att godkänna tills du vet hur länge sjukdomen varade, eller be den anställda att återkalla anmälan och skicka en ny med rätt datum. Är frånvaron redan godkänd och fel: rätta lönen med en rad i lönekörningen och kontakta er redovisningskonsult.

**Behöver vi läkarintyg?**
Regeln för när ett läkarintyg behövs finns under **Lön → Regler**. NONOX varnar inte för saknat intyg ännu, så håll koll på det själv.

**Kan en anställd återkalla en ansökan?**
Ja, så länge den väntar på svar.

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Semester](/manual/lon/semester)
- [Min sida för de anställda](/manual/lon/min-sida-for-anstallda)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
