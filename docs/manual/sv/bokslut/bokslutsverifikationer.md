---
beskrivning: Omvärdera fakturor i utländsk valuta, bokför årets skatt och boka årets resultat till eget kapital den sista dagen i räkenskapsåret.
ordning: 60
roller: [owner, admin, accountant]
sokord: [bokslutsverifikation, omvärdering, balansdagens kurs, valutakurs, 3960, 7960, årets skatt, inkomstskatt, bolagsskatt, 8910, 2512, årets resultat, 8999, 2099, 2019, boka resultatet]
---

# Skatt och årets resultat

När allt annat i året är bokfört återstår två bokslutsverifikationer: årets skatt och årets resultat. Båda bokförs den sista dagen i räkenskapsåret, från steg **2. Bokslutsverifikationer** under **Bokföring → Bokslut**.

Bokför först allt annat, sedan skatten och sist årets resultat.

Har företaget obetalda fakturor i utländsk valuta den sista dagen kommer en verifikation till före skatten: omvärderingen till kursen den dagen.

![Under Bokslut bokför du skatt och årets resultat.](/bilder/manual/bokslut/checklistan.webp)

## Omvärdering av fakturor i utländsk valuta

Kund- och leverantörsfakturor i euro, dollar eller annan valuta som är obetalda den sista dagen i räkenskapsåret värderas till Riksbankens kurs den dagen. Steget visas bara när sådana fakturor finns, och bara med fakturametoden.

### Så här bokför du omvärderingen

1. Lägg upp nästa räkenskapsår om det inte finns. Omvärderingen återförs på dess första dag.
2. Gå till **Bokföring → Bokslut** när årets sista dag har passerat.
3. Under **Omvärdering av valutafordringar och valutaskulder** visas varje obetald faktura med det som återstår i valutan, det bokförda värdet i kronor, värdet till dagens kurs och skillnaden.
4. Klicka på **Bokför omvärderingen** och bekräfta.

**I bokföringen:** en verifikation i serie A den sista dagen, med skillnaden på 1510 Kundfordringar och 2440 Leverantörsskulder mot 3960 (kursvinst) eller 7960 (kursförlust), och en omvänd verifikation den första dagen i nästa år. Återföringen gör att en betalning nästa år fortfarande räknas mot fakturans egen kurs.

Saknar Riksbanken kurs för en valuta den dagen bokför du omvärderingen för hand med en vanlig verifikation.

> [!NOTE]
> Att omvärderingen ska göras till Riksbankens mittkurs på årets sista dag och återföras på nästa års första dag är inte juridiskt kontrollerat i NONOX ännu. Stäm av med din redovisningskonsult.

## Årets skatt

Gäller aktiebolag, ekonomiska föreningar och andra företag som själva betalar inkomstskatt. För enskild firma, handelsbolag och kommanditbolag beskattas ägarna, och steget visas inte.

NONOX räknar inte fram skatten. Skatten räknas på det skattemässiga resultatet, som kan skilja sig från det bokförda – till exempel för kostnader som inte är avdragsgilla.

### Så här bokför du skatten

1. Räkna fram skatten, gärna med hjälp av [inkomstdeklarationen](/manual/bokslut/inkomstdeklaration) eller din redovisningskonsult.
2. Skriv beloppet i **Inkomstskatt, kr**. Blir det ingen skatt, skriv 0.
3. Klicka på **Bokför skatten**.

Som jämförelse visar fältet en procentsats av resultatet före skatt. Den är bara en fingervisning – den tar inte hänsyn till skattemässiga justeringar, och satsen är inte juridiskt kontrollerad i NONOX.

**I bokföringen:** 8910 Skatt som belastar årets resultat i debet och 2512 Beräknad inkomstskatt i kredit. Noll sparas utan verifikation.

## Årets resultat

Resultatet efter skatt flyttas från resultaträkningen till eget kapital, så att resultaträkningen går jämnt ut.

### Så här bokar du resultatet

1. Kontrollera att allt annat i året är bokfört, och att skatten är bokförd.
2. Sidan visar **Kvar att boka** med belopp och konton.
3. Klicka på **Boka årets resultat** och bekräfta.

**I bokföringen:** resultatet bokförs på **8999 Årets resultat** mot:

- **2099 Årets resultat** för aktiebolag och föreningar,
- **2019 Årets resultat** för enskild firma, handelsbolag och kommanditbolag.

Saknas något av kontona i kontoplanen läggs det till med sitt BAS-namn. Båda verifikationerna bokförs i serie A och kan inte ändras.

## Om du bokför något i året efteråt

Bokför du något mer i året efter att resultatet är bokat, visar sidan **Kvar att boka** igen med mellanskillnaden. Boka den på samma sätt. Därför är det bäst att vänta med resultatet tills allt annat är klart.

## Vanliga frågor

**Ska skatten bokföras innan inkomstdeklarationen är klar?**
Skatten bokförs i bokslutet, som en beräknad skatt. Inkomstdeklarationen visar det skattemässiga resultatet som skatten bygger på.

**Vi har förlust – vad gör jag?**
Skriv 0 i **Inkomstskatt, kr** och boka resultatet som vanligt. Ett underskott kan påverka kommande års deklarationer; fråga din redovisningskonsult.

**Kan jag ändra skatten efteråt?**
Inte här – skatten sparas en gång. Rätta verifikationen om beloppet blev fel. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

> [!NOTE]
> Hur ideella föreningar och stiftelser ska hantera skatt och konton i bokslutet är inte juridiskt kontrollerat i NONOX; de behandlas som aktiebolag. Fråga din redovisningskonsult.

## Läs också

- [Checklistan för bokslutet](/manual/bokslut/checklistan)
- [Stäng räkenskapsåret](/manual/bokslut/stang-aret)
- [Inkomstdeklaration 2 och SRU-filer](/manual/bokslut/inkomstdeklaration)
