---
beskrivning: Plocka med mobilen – gå raderna i gångordning, skanna hyllan och varan och få ett tydligt fel om du tar fel vara.
ordning: 82
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.scanner]
sokord: [plocka, plocklista, mobil, skanna, fel vara, fattas, restnoterad]
---

# Plocka med mobilen

Skanna visar plocklistan en rad i taget, i den ordning du går genom lagret. Du skannar hyllan och varan, så att rätt vara hamnar i rätt order.

## Så här plockar du

1. Tryck på **Plocka** och välj plocklista.
2. Skanna visar **Gå till** med hyllans kod i stor stil, varan och hur många du ska ta.
3. Skanna hyllans etikett. Fel hylla ger ett rött fel.
4. Skanna varan. Fel vara ger rött blink, vibration och texten **Fel artikel!** med vilken vara som ska plockas.
5. Antalet är ifyllt med det du ska ta. Tryck på **Plockat**.

Nästa rad visas direkt.

## När det fattas

Finns det färre än plocklistan säger trycker du på **Fattas**. Ange hur många du fick med dig och välj varför resten fattas:

- Saknas på platsen
- Skadad vara
- Ligger på fel plats
- Annat

Orsaken sparas på raden i plocklistan.

## Klar

När alla rader är genomgångna ser du en sammanställning. Rader där det fattas är markerade i rött. Tryck på **Klar** och bekräfta. Det plockade dras från lagret och plocklistan stängs.

> [!NOTE]
> Plocklistan görs i NONOX, från en order, en faktura eller för hand. Se [Plocklista](/manual/lager/plocklista).

## Läs också

- [Skanna med mobilen](/manual/lager/skanna)
- [Utan nät](/manual/lager/skanna-utan-nat)
