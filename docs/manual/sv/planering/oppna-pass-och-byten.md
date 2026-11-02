---
beskrivning: Godkänn eller avslå när personalen vill ta ett öppet pass, byta pass med en kollega eller lämna ett pass, med vilotider och arbetstid kontrollerade igen.
ordning: 70
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.requests]
sokord: [öppet pass, byta pass, passbyte, lämna pass, ta pass, förfrågan, extrapass, godkänna byte]
---

# Öppna pass och byten

På Min sida kan personalen be om att ta ett öppet pass, byta ett pass med en kollega eller lämna ett pass. Förfrågningarna hamnar under **Planering → Öppna pass och byten**. Inget ändras i schemat förrän du godkänner.

Vad personalen får be om ställer du in under modulens inställningar, **Personalens egna val i Min sida**: **Får ta öppna pass** och **Får be om att byta pass (en chef godkänner alltid)**. Det senare gäller både byten och att lämna ett pass.

![Förfrågningar om öppna pass och byten med arbetstidsvarningarna, och ett byte som kollegan har sagt ja till.](/bilder/manual/planering/forfragningar.webp)

## Öppna pass

Ett öppet pass är ett publicerat pass utan person. Du lägger det i schemat genom att välja **Öppet pass (ingen ännu)** som person, eller genom att lägga det på raden **Öppna pass**. När schemat är publicerat syns det för personalen.

Personalen ser de öppna passen de närmaste fyra veckorna. Kräver passet en kompetens visas det bara för dem som har den.

Under **Öppna pass de närmaste fyra veckorna** ser du vilka öppna pass som är publicerade.

## Byten frågar kollegan först

Ett byte går först till kollegan. Kollegan ser frågan på Min sida under **Vill byta med dig** och svarar **Ja, byt** eller **Nej tack**. Först när kollegan har sagt ja kommer bytet till dig under **Väntar på svar**. Tackar kollegan nej får den som frågade besked, och bytet kommer aldrig till dig.

Byten som kollegan inte har svarat på än ser du under **Byten som väntar på kollegan**. Du kan inte godkänna dem därifrån.

## Så här svarar du på en förfrågan

1. Gå till **Planering → Öppna pass och byten**.
2. Under **Väntar på svar** står vem som frågar och vad: **Vill ta passet**, **Vill byta pass** eller **Vill lämna passet**, med passet och personens meddelande.
3. Läs varningarna. NONOX kontrollerar vilotider och arbetstid igen för dem som får passet, och varnar om personen har sagt att tiden inte passar.
4. Klicka på **Godkänn** och bekräfta. Eller klicka på **Avslå**, skriv ett **Meddelande till personen (frivilligt)** och klicka på **Avslå** igen.

Varningarna stoppar inte ett godkännande.

## Det här händer när du godkänner

Passet ändras och publiceras direkt, och de som berörs får besked i NONOX eller med e-post.

- **Vill ta passet:** personen får det öppna passet.
- **Vill byta pass:** de två passen byter person, och båda får besked.
- **Vill lämna passet:** passet blir ett öppet pass som andra kan ta. Tills du har godkänt är passet personens.

Andra förfrågningar om samma pass avslås automatiskt, med meddelandet **Passet har redan ändrats efter en annan förfrågan.**

Har passet ändrats eller redan börjat sedan förfrågan skickades kan den inte godkännas. Avslå den i stället.

Under **Besvarade de senaste två veckorna** ser du vad som har godkänts och avslagits.

## Bra att veta

- En förfrågan som väntar på svar syns också under Att göra på översikten.
- Personen kan återkalla sin förfrågan på Min sida så länge den väntar på svar.
- Ett byte kräver både kollegans ja och ditt godkännande.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Tillgänglighet](/manual/planering/tillganglighet)
- [Mitt schema](/manual/personal/mitt-schema) – så frågar personalen.
