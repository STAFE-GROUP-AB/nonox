---
beskrivning: Jämför vad kassans försäljning borde ha använt med vad två inventeringar visar, och se den oförklarade avvikelsen per råvara.
ordning: 50
roller: [owner, admin, accountant, bookkeeper]
modul: kitchen
rutter: [kitchen.variance]
sokord: [avvikelse, svinn, förbrukning, inventering, sålt mot inventerat, teoretisk förbrukning]
---

# Avvikelser

Avvikelserapporten svarar på frågan: vi sålde så här mycket, så hur mycket borde vi ha använt, och vart tog resten vägen?

## Så används den

1. Inventera kökets lagerställe, till exempel varje måndag. Se [Inventering](/manual/lager/inventering).
2. Gå till **Kök → Avvikelser** och välj **Från inventering** och **Till inventering**. Båda ska gälla samma lagerställe.

## Kolumnerna

För varje råvara som räknats i båda inventeringarna:

- **Ingående** och **Utgående** – det som räknades.
- **Inlevererat** – det som togs emot under perioden.
- **Sålt enligt recept** – vad kassans försäljning borde ha använt enligt recepten.
- **Svinn**, **Personalmat** och **Övrigt ut** – det som registrerats.
- **Oförklarat** – förbrukningen som inte förklaras. **Värde** räknas med lagervärdet per enhet vid den sista inventeringen, och **Av sålt** är avvikelsen i procent av det sålda.

Råvarorna står per lagerslag med det största värdet först.

På inventeringsdagarna avgör klockslaget när inventeringen slutfördes vad som hör till perioden. Det sålda räknas från kassor som drar från det inventerade lagerstället; kassor utan lagerställe räknas också med, och sidan säger det.

**Bokförd i efterhand** visar rörelser som är daterade i perioden men registrerades efter att inventeringen slutfördes, eller efter att lagret värderades. Det som registrerades efter inventeringen ingår inte i summorna.

**Sålt utan recept** visar rätter som sålts en dag de inte hade något recept. Deras råvaror räknas inte, och NONOX gissar dem inte. 

Det sålda kommer från kassans försäljningsrader, inte kassans Z-rapport. Tillval som extra bacon läses inte och kan ge avvikelse.

## Dra råvarorna när kassan säljer

Under **Kök → Inställningar** kan den som får ändra lagrets inställningar slå på **Dra råvarorna från lagret när kassan säljer rätterna**. Då dras gårdagens försäljning från kassans lagerställe varje natt, från och med dagen inställningen slogs på. Varje kassarad dras en gång. Returer lägger aldrig tillbaka något i lagret; de minskar det som dras senare. Slås inställningen eller modulen på igen dras bara försäljning från och med den dagen.

Skulle en råvara gå under noll står den under **Väntar på lager**. Den prövas igen varje natt. Välj **Dra nu** när lagret är rättat, eller **Lämna till inventeringen**.
