# Tillgänglighetsredogörelse

Senast uppdaterad 2026-11-02.

Redogörelsen gäller tjänsten NONOX på {webbplats} och programmet NONOX som kan köras på egen server. Tjänsten tillhandahålls av {foretag}.

## Vårt mål

NONOX ska uppfylla **WCAG 2.2 på nivå AA**. Tjänsten ska gå att använda med tangentbord, med skärmläsare och med förstoring, ha tillräckliga kontraster och inte förmedla något enbart med färg.

## Hur tillgänglig är tjänsten?

**Tjänsten uppfyller delvis WCAG 2.2 AA.** Vi har granskat den själva. Ingen utomstående har granskat den, och vi har inte testat med personer som använder hjälpmedel. Vi kan därför inte säga att tjänsten uppfyller kraven fullt ut.

Granskningen gjordes 2026-11-02:
- En automatisk kontroll (axe-core) av 100 sidor: de publika sidorna, inloggningen och alla sidor i programmet som går att öppna utan att välja en enskild post.
- En manuell granskning av koden för de delade sidmallarna och komponenterna, och av flödena logga in, byta företag, bokföra en verifikation, skapa en faktura, attestera en leverantörsfaktura, lönekörning, schemat i Planering, personalliggarens kiosk, manualen och startsidan.
- Tangentbordstest i webbläsaren av hopplänken, företagsmenyn, felmeddelanden i formulär och att fokus inte hamnar under den fasta toppraden.

Efter granskningen rättade vi bland annat kontrasten i grå text och i fältens kanter, sidtitlar (varje sida heter nu som sin rubrik), hopplänk och landmärken, felmeddelanden som läses upp och kopplas till sitt fält, dialogrutor, menyn för att byta företag och sidnumreringen i listor. Hela listan står i granskningsrapporten i projektets dokumentation (`docs/tillganglighet.md`).

## Kända brister

- **PDF-filer** som tjänsten skapar (fakturor, lönebesked, rapporter, årsredovisning) är inte taggade för skärmläsare och har inte granskats.
- **Mörkt läge** finns på de publika sidorna och i inloggningen, men inte i själva programmet. Det är inget krav i WCAG, men ett mål vi har.
- **Personalliggarens kiosk** läser upp hela skärmen igen när den ritas om, vilket kan bli pratigt med skärmläsare.
- **Breda tabeller** i rapporterna rullas i sidled inne i sitt kort. På sidan Bemanning och kostnad i Planering går tabellen inte att rulla med tangentbordet.
- Vi har inte testat **textavstånd** (WCAG 1.4.12) eller tjänsten med **Windows högkontrastläge**.

Vi rättar bristerna i den ordningen. Den här sidan uppdateras när något ändras.

## Lagkrav

NONOX säljs till företag. Vi bedömer att lagen (2023:254) om vissa produkters och tjänsters tillgänglighet troligen inte gäller tjänsten, eftersom den gäller vissa tjänster till konsumenter, och att lagen (2018:1937) om tillgänglighet till digital offentlig service inte gäller oss eftersom vi inte är en offentlig aktör. Bedömningen är inte granskad av jurist. Vi strävar ändå efter samma nivå, eftersom anställda med olika behov använder Min sida och kiosken. Den här redogörelsen är ingen försäkran om att tjänsten uppfyller någon lag.

## Rapportera problem

Om något inte fungerar för dig, till exempel med skärmläsare, tangentbord eller förstoring, vill vi veta det. Beskriv vilken sida eller funktion det gäller, vad som hände och vilket hjälpmedel och vilken webbläsare du använder.

- **Den driftade tjänsten:** kontakta oss på {epost}.
- **Programmet:** skapa ett ärende på GitHub i projektets repository.
