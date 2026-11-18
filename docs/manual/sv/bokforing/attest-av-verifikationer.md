---
beskrivning: Låt någon annan godkänna verifikationer och kreditfakturor innan de bokförs. Utkastet väntar utan nummer tills det är attesterat, så serien förblir obruten.
ordning: 75
roller: [owner, admin, accountant, bookkeeper]
sokord: [attest verifikation, godkänna verifikation, fyraögonsprincipen, granskning före bokföring, attest kreditfaktura, kreditering attest]
---

# Attest av verifikationer och kreditfakturor

Vill ni att någon annan ska godkänna en verifikation innan den bokförs, eller en kreditfaktura innan den skapas, lägger en ägare eller administratör upp ett attestflöde för **Manuella verifikationer** eller **Kreditfakturor** under **Inköp → Attestflöden**. Utan ett sådant flöde ändras ingenting: du bokför och krediterar som vanligt.

![En verifikation i utkast som väntar på attest, med flödet och knappen Attestera verifikationen.](/bilder/manual/bokforing/attest-av-verifikationer.webp)

## Vad som omfattas

- **Manuella verifikationer** är verifikationer som någon för in: under **Bokföring → Verifikationer**, från inkorgen eller via API:t. Verifikationer som faktureringen, betalningar, lönen, momsen eller bokslutet bokför omfattas inte.
- **Kreditfakturor** är kreditfakturor till kunder.
- Villkoren kan vara belopp, kostnadsställe, projekt och kontointervall. För en verifikation är beloppet summan av debet. För en kreditfaktura är det summan med moms, utan minustecken.
- Den som godkänner måste själv ha rätt att bokföra (för verifikationer) eller att kreditera (för kreditfakturor), eller vara ägare eller administratör. Rollen **Attestant** räcker inte.

## Så här skickar du en verifikation till attest

1. Skriv verifikationen som vanligt under **Bokföring → Verifikationer**.
2. Klicka på **Bokför**. Gäller ett attestflöde sparas verifikationen som utkast och skickas till attest i stället, och du ser meddelandet "Verifikationen är skickad till attest".
3. När den är attesterad öppnar du verifikationen och klickar på **Bokför** i kortet **Attest**, och sedan **Ja, bokför**.

En kreditfaktura skickas till attest på samma sätt när du klickar på knappen som skapar den i fakturaredigeringen. Kortet **Attest** visas under kreditfakturan, och när den är attesterad skapar du den som vanligt.

> [!NOTE]
> Medan utkastet väntar har det inget nummer. Numret i verifikationsserien, eller fakturanumret, tas först när det bokförs eller skapas. Ett utkast som avvisas eller tas bort lämnar därför ingen lucka i serien.

## Attestera

Den som ska godkänna hittar utkastet under **Inköp → Att godkänna** (se [Att godkänna](/manual/leverantorer/att-godkanna)) eller öppnar det direkt. I kortet **Attest** klickar hen på **Attestera verifikationen** eller **Attestera kreditfakturan**. Har flödet flera steg heter knappen **Godkänn steg 1 av 2**.

Att attestera bokför ingenting. Verifikationen bokförs av en person, efteråt.

## Ändras utkastet behöver det attesteras på nytt

Attesten gäller utkastet så som det såg ut när det godkändes. Ändrar någon ett belopp, ett konto, datumet, texten eller underlagen efteråt behöver den nya versionen attesteras, och knappen **Skicka till attest** visas igen. Sparar du utan att ändra något gäller attesten fortfarande.

Har sidan varit öppen medan någon annan sparade utkastet står det att du behöver ladda om sidan. Det är så att ingen godkänner något hen inte har sett.

## Avvisa

Den som ska godkänna kan klicka på **Avvisa**, skriva ett skäl och klicka på **Ja, avvisa**. Utkastet går tillbaka till den som förberedde det. Inget bokförs och inget nummer tas. Rätta utkastet och klicka på **Bokför** igen, eller klicka på **Skicka till attest igen** om samma version ska prövas en gång till.

## Någon annan måste godkänna

Kräver företaget att någon annan attesterar räknas den som skapade utkastet och alla som har sparat det eller ändrat dess underlag som förberedare. De kan inte godkänna det.

## Vanliga frågor

**Vi har inget attestflöde. Måste vi göra något?**
Nej. Utan ett flöde för manuella verifikationer eller kreditfakturor bokför och krediterar ni precis som förut.

**Gäller det verifikationer från inkorgen?**
Ja. Gäller ett flöde verifikationen kan inkorgen inte bokföra den direkt. Välj att skapa ett utkast i stället, öppna verifikationen och skicka den till attest.

**Gäller det verifikationer via API:t?**
Ja. Ett anrop som bokför ett utkast som väntar på attest får ett felsvar, och utkastet ligger kvar utan nummer tills det är attesterat i NONOX.

## Läs också

- [Attestflöden](/manual/leverantorer/attestfloden)
- [Att godkänna](/manual/leverantorer/att-godkanna)
- [Utkast](/manual/bokforing/utkast)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
