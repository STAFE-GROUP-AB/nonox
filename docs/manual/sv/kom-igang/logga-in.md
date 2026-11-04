---
beskrivning: Så loggar du in i NONOX med en engångskod i mejlet, och när du dessutom bekräftar med en passkey. Inga lösenord att komma ihåg.
ordning: 10
rutter: [login, passkeys.challenge]
sokord: [inloggning, lösenord, kod, engångskod, skapa konto, registrera, kö, beta, passkey, fingeravtryck, Face ID, tvåstegsverifiering]
---

# Logga in

NONOX har inga lösenord. Du skriver din e-postadress, får en kod med åtta siffror i mejlet och skriver in den.

Koden skickas bara till den som har ett konto eller en inbjudan till ett företag. Under den öppna betan får du ett konto genom att [ställa dig i kö](/manual/kom-igang/tillgang-under-betan) och bli godkänd, eller genom att ett företag bjuder in dig.

![Inloggningen: skriv din e-postadress, så skickar NONOX en kod.](/bilder/manual/kom-igang/logga-in.webp)

## Så här gör du

1. Gå till inloggningssidan och klicka på **Logga in**.
2. Skriv din e-postadress och klicka på **Skicka kod**.
3. Öppna mejlet med koden. Det kommer inom någon minut.
4. Skriv de åtta siffrorna i fältet **Kod** och klicka på **Logga in**.
5. Är du inbjuden och loggar in för första gången frågar NONOX vad du heter, om inte inbjudan redan har ditt namn. Skriv ditt namn och klicka på **Skapa konto**.

Efter inloggningen hamnar du på **Översikt**. Har du inget företag ännu kommer du i stället till **Kom igång**, där du [skapar ett företag](/manual/kom-igang/skapa-ett-foretag) eller går med i ett som du har blivit inbjuden till.

> [!NOTE]
> Har du fått en inbjudan via e-post och den innehåller ditt namn, slipper du namnsteget. Kontot skapas med namnet från inbjudan.

## Om koden

- Koden gäller i tio minuter och kan bara användas en gång.
- Klickar du på **Skicka kod** flera gånger i rad skickas inte flera mejl. Inom en minut gäller samma kod, så det spelar ingen roll vilket mejl du öppnar.
- Det går bara ut ett fåtal koder till samma adress på tio minuter och per dygn. Har gränsen nåtts skickas ingen ny kod, men den senaste koden i mejlet gäller fortfarande i tio minuter från att den skickades. Använd den. Har den hunnit sluta gälla, vänta en stund och försök igen senare.
- Skriver du fel kod för många gånger spärras inloggningen för adressen i upp till en timme. Vänta och försök igen.
- Fel adress eller ingen kod? Klicka på **Fel adress eller ingen kod? Börja om**.

## Passkey som andra steg

En passkey är en inloggningsnyckel som sparas i din telefon eller dator och låses upp med fingeravtryck, ansiktsigenkänning eller skärmlås. När du har en passkey frågar NONOX efter den varje gång du loggar in, efter koden i mejlet. Då räcker det inte att någon kommer åt din e-post för att komma åt ditt konto.

Du **måste** använda en passkey om:

- du kan se känsliga personuppgifter, till exempel sjukfrånvaro, lönebesked eller fullständiga personnummer. Det gäller i praktiken den som har tillgång till lönen eller personalliggaren i ett företag där de modulerna används, eller
- ägaren till ett av dina företag har slagit på **Kräv passkey för alla i företaget**.

Gäller något av det och du saknar en passkey, får du lägga till en direkt efter koden: skriv ett namn på enheten, till exempel "iPhone" eller "MacBook", och klicka på **Skapa passkey och fortsätt**. Har du redan en klickar du på **Använd passkey**.

Du kan också lägga till en passkey frivilligt under din profil. Läs mer i [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar).

> [!TIP]
> Lägg till en passkey på mer än en enhet, till exempel både telefonen och datorn. Då kommer du in även om du tappar bort den ena.

## Vanliga frågor

**Mejlet med koden kommer inte fram.**
Koden skickas bara till en adress som har ett konto eller en inbjudan. Står du i kö och inte är godkänd än kommer ingen kod. Titta annars i skräpposten. Kontrollera att du skrev rätt adress; börja annars om och skicka en ny kod. Kör ditt företag NONOX på en egen server och inga mejl kommer fram alls, behöver den som sköter servern kontrollera e-postinställningarna.

**Kan jag logga in med lösenord?**
Nej. NONOX använder bara engångskoder, och passkeys som extra skydd.

**Jag har tappat bort alla enheter med min passkey.**
Be en ägare eller administratör i ditt företag att välja **Återställ passkeys** på dig under **Inställningar för företaget**. Dina passkeys tas bort och du får lägga till en ny nästa gång du loggar in. Se [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar#om-du-har-tappat-bort-dina-enheter).

**Hur länge är jag inloggad?**
En inloggning gäller i högst tolv timmar, och kortare om du inte använder NONOX. Du kan logga ut från alla andra enheter under din profil.

## Läs också

- [Tillgång under betan](/manual/kom-igang/tillgang-under-betan)
- [Skapa ett företag](/manual/kom-igang/skapa-ett-foretag)
- [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar)
- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
