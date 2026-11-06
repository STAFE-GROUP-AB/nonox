---
beskrivning: Lägg till och ta bort passkeys, kräv passkey för alla i företaget, återställ passkeys för någon som tappat sin telefon och logga ut från andra enheter.
ordning: 70
sokord: [passkey, säkerhet, tvåstegsverifiering, 2FA, fingeravtryck, Face ID, Touch ID, Windows Hello, inloggning, logga ut, sessioner, borttappad telefon, återställ]
---

# Passkeys och inloggningar

Du loggar in i NONOX med en kod i mejlet. En **passkey** gör inloggningen säkrare: efter koden bekräftar du med fingeravtryck, ansiktsigenkänning eller skärmlåset på din telefon eller dator. Den som kommer åt din e-post kommer då ändå inte åt ditt konto.

En passkey sparas i din enhet, inte i NONOX. NONOX sparar bara en offentlig nyckel som inte kan användas för att logga in, och enhetens namn.

![Passkeys och utloggning från andra enheter under din profil.](/bilder/manual/installningar/passkeys-och-inloggningar.webp)

## Vem måste ha en passkey

- Den som kan se sjukfrånvaro, lönebesked eller fullständiga personnummer, i något av sina företag. Det följer av rollen; se [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter#passkey-for-kansliga-uppgifter).
- Alla i ett företag där ägaren har slagit på **Kräv passkey för alla i företaget**.
- Den som sköter installationen i HQ, eftersom HQ visar alla företag och användare.

Alla andra kan lägga till en passkey frivilligt. När du har en frågar NONOX efter den varje gång du loggar in.

## Så här lägger du till en passkey

1. Klicka på **Profil** längst ner i menyn.
2. Under **Passkeys** skriver du ett **Namn på enheten**, till exempel "iPhone" eller "Jobbdatorn".
3. Klicka på **Lägg till passkey**.
4. Bekräfta med fingeravtryck, ansiktsigenkänning eller skärmlås när enheten frågar.

Passkeyn visas i listan med namn, typ, när den lades till och när den senast användes. Du får ett mejl varje gång en passkey läggs till på ditt konto. Har du inte lagt till den själv, kontakta en ägare eller administratör direkt.

> [!TIP]
> Lägg till passkeys på minst två enheter, till exempel telefonen och datorn. Då kommer du in även om du tappar bort den ena.

## Ta bort en passkey

Klicka på **Ta bort** bredvid passkeyn. Måste du ha en passkey går det inte att ta bort den sista; lägg först till en ny.

## Kräv passkey för alla i företaget

Är du ägare kan du kräva att alla i företaget bekräftar inloggningen med passkey, inte bara de som ser känsliga uppgifter.

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Kryssa i **Kräv passkey för alla i företaget** under **Inloggning**.

Den som saknar en passkey får lägga till en vid nästa inloggning. Kravet gäller hela personens konto, även i andra företag hen är med i, eftersom passkeys hör till personen och inte till företaget. Bara ägaren kan ändra inställningen, och varje ändring sparas i behandlingshistoriken.

## Om du har tappat bort dina enheter

Har du tappat bort alla enheter med dina passkeys kommer du inte in. Be en ägare eller administratör i ett av dina företag att återställa dem:

1. Hen öppnar **Inställningar för företaget**.
2. Under **Personer i företaget** klickar hen på **Återställ passkeys** bredvid ditt namn och bekräftar.

Alla dina passkeys tas bort, du loggas ut överallt och du får ett mejl om det. Nästa gång du loggar in lägger du till en ny.

> [!IMPORTANT]
> Återställ bara när du är säker på att det verkligen är personen själv som ber om det, till exempel genom att ringa hen. Den som återställer måste själv ha loggat in med passkey. Ingen kan återställa sig själv, och en administratör kan inte återställa ägarens passkeys. Återställningen sparas i företagets behandlingshistorik.

Är du ägare och det inte finns någon annan i företaget som kan hjälpa dig, kan den som driftar NONOX återställa dina passkeys.

## Logga ut från andra enheter

Har du tappat bort telefonen eller loggat in på en dator som inte är din?

1. Klicka på **Profil** längst ner i menyn.
2. Under **Inloggningar** klickar du på **Logga ut från andra enheter** och bekräftar.

Du är kvar inloggad där du är; alla andra inloggningar avslutas. En inloggning gäller annars i högst tolv timmar, och kortare om den inte används.

API-nycklar och anslutna appar påverkas inte: de fortsätter fungera tills du tar bort dem. Det gör du under [Anslutningar](/manual/installningar/nonox-connect), via länken **Hantera dem under Anslutningar** bredvid knappen.

## Vanliga frågor

**Vad är skillnaden mot ett lösenord?**
En passkey går inte att gissa, läcka eller lura av dig på en falsk webbplats, eftersom den bara fungerar på NONOX egen adress och bara i din enhet.

**Fungerar passkeys på alla enheter?**
På alla aktuella telefoner, surfplattor och datorer. Säger webbläsaren att passkeys inte stöds, ladda om sidan eller prova en annan webbläsare.

**Behövs passkey för API-nycklar?**
Du behöver en passkey för att godkänna appar och skapa API-nycklar i [NONOX API](/manual/installningar/nonox-connect). Själva nycklarna används sedan utan passkey.

## Läs också

- [Logga in](/manual/kom-igang/logga-in)
- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
- [Din profil](/manual/kom-igang/din-profil)
