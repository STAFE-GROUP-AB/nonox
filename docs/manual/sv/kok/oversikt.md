---
beskrivning: NONOX Kök – recept med portionskostnad och marginal, menyn, svinn, personalmat och avvikelsen mellan sålt och inventerat för restauranger.
ordning: 0
roller: [owner, admin, accountant, bookkeeper, auditor, kitchen_staff]
modul: kitchen
rutter: [kitchen.home, kitchen.settings]
sokord: [kök, restaurang, recept, svinn, matsvinn, personalmat, marginal, råvarukostnad]
---

# Kök

Med Kök ser du vad varje rätt kostar att laga med dagens råvarupriser, vilken marginal den ger, vad som slängs och varför, och hur mycket som försvinner utan förklaring mellan två inventeringar.

Kök är en modul som kräver Lager. Den är avstängd tills ägaren slår på den. Kök bokför ingenting: svinn och personalmat dras från lagret, och bokföringen möter lagret när du bokför lagervärdet vid bokslutet. Är Kassakopplingar påslaget läser Kök vad kassan har sålt. Är Lön påslaget kan personalmaten bli kostförmån i lönen.

## Kom igång

1. Slå på Lager och sedan Kök under **Inställningar för företaget → Moduler**. Se [Moduler](/manual/installningar/moduler).
2. Lägg upp råvarorna som lagerförda artiklar och ta emot dem med inköpspriset. Se [Inleverans](/manual/lager/inleverans).
3. Lägg upp rätterna på menyn som vanliga artiklar med menypriset exklusive moms.
4. Skriv recepten under **Kök → Recept**. Se [Recept](/manual/kok/recept).
5. Välj kökets lagerställe under **Kök → Inställningar**.

## Sidorna

- **Recept** – rätter och grundrecept med ingredienser och kostnad.
- **Meny** – varje rätt med portionskostnad, pris och marginal, och vad kassan har sålt.
- **Svinn** – det som slängs eller skänks, med orsak.
- **Personalmat** – måltider till personalen, vid representation och till ägaren.
- **Avvikelser** – sålt jämfört med inventerat.
- **Inställningar** – kökets lagerställe, målet för råvarukostnad, snittkostnaden för personalmat och om försäljningen ska dras från lagret.

## Inställningar

Under **Kök → Inställningar** väljer du:

- **Kökets lagerställe** – svinn och personalmat dras härifrån om inget annat väljs.
- **Mål för råvarukostnad i procent** – rätter över målet markeras på menyn. NONOX ändrar aldrig ett pris.
- **Snittkostnad för valfri rätt** – råvarukostnaden för en personalmåltid utan vald rätt.
- **Dra råvarorna från lagret när kassan säljer rätterna** – avstängt från början. Se [Avvikelser](/manual/kok/avvikelser).

## Vem får göra vad

- Ägare, administratör, redovisningskonsult och bokförare ser allt, ändrar recept och ser kostnader.
- Revisorn ser recepten och, med rätten att se rapporter, kostnaderna.
- **Kökspersonal** ser recepten och registrerar svinn och personalmat, men ser aldrig ett pris.
- Löneadministratören godkänner kostförmånerna i lönekörningen.
