# Tillgänglighet i NONOX – granskning mot WCAG 2.2 AA

Senast uppdaterad 2026-11-02. Den publika redogörelsen, kortare och skriven för användare, ligger i [juridik/tillganglighet.md](juridik/tillganglighet.md) och visas på /tillganglighet.

## Sammanfattning

NONOX **uppfyller delvis** WCAG 2.2 AA. Granskningen är vår egen; ingen utomstående har granskat tjänsten och den är inte testad med användare av hjälpmedel. Förra versionen av den här filen sa att ingen granskning var gjord.

| | Antal kriterier (A + AA, 55 st) |
|---|---|
| Uppfylls (kontrollerat) | 34 |
| Rättat i den här granskningen | 12 |
| Brist kvar, med plan | 2 (2.1.1, 4.1.3) |
| Inte testat | 1 (1.4.12) |
| Inte tillämpligt | 6 (1.2.1–1.2.5, 1.4.2) |

"Uppfylls" betyder att vi inte hittade något fel med metoden nedan, inte att det är bevisat för varje sida. PDF-filerna som tjänsten skapar ingår inte (se Kvar att göra).

## Metod

1. **Automatisk kontroll** med axe-core 4.10 (regler för WCAG 2.0/2.1/2.2 A och AA plus best practice) i Chrome utan fönster, styrd över DevTools-protokollet. Körd på 100 sidor: 22 publika sidor (startsidan, inloggningen, manualen, utvecklarportalen, branschsidor, juridiska sidor) och 78 sidor i programmet, inloggad som en påhittad demoanvändare i en demodatabas, i ljust och (för de publika sidorna) mörkt läge. axe-core lades inte till i projektet; den laddades ned till en tillfällig mapp bara för körningen.
2. **Kodgranskning** av de delade sidmallarna (`resources/views/layouts`, `components/start-layout`), komponenterna (`resources/views/components`), navigeringen och huvudflödena: logga in med kod, byta företag, verifikationsregistreringen, ny faktura, attest av leverantörsfaktura, lönekörning, schemat i Planering, personalliggarens kiosk, manualen och startsidan.
3. **Tangentbordstest** i Chrome: första Tab ger hopplänken, Enter flyttar fokus till `main`; företagsmenyn sätter `aria-expanded` och stängs med Esc med fokus tillbaka på knappen; ett tomt formulär (Kostnadsställen) ger `aria-invalid` och `aria-describedby` på båda fälten, fokus på första fältet och uppläsningen "Formuläret har 2 fel"; 40 Tab-steg i Ny faktura utan att fokus hamnar under den fasta toppraden.

Före åtgärderna gav axe fel på 76 av 78 programsidor (nästan alla kontrast). Efter åtgärderna återstår i programmet bara felsidan för `/anslut` i demomiljön (Laravels egen felsida, inte NONOX) och schemats ARIA-rutnät, som rättades samtidigt i Planering-modulen (se 1.3.1). De publika sidorna har bara kvar två tomma tabellrubriker i Markdown-tabeller och en rubriknivå som hoppar på /flytta-hit (best practice, inte WCAG-fel).

## Det här gjordes

**Sidmallar och komponenter** (rättar alla sidor på en gång):
- Hopplänk "Hoppa till innehållet" först på varje inloggad sida, `main` med `id="main"` runt sidans rubrik och innehåll, `header`/`main` i inloggningens mall.
- Sidtitel: varje inloggad sida heter som sin första `h1` följt av företaget och NONOX (`App\Support\Accessibility\PageTitle`), till exempel "Ny faktura – Exempel AB – NONOX". Jetstreams sidor (Profil, Företagsinställningar, Nytt företag, API) har nu `h1`.
- Kontrast: designtokens `gray-400` (#716a64) och `gray-500` (#6b645e) klarar 4,5:1 mot vitt, papper och accenttoningar; fältkanter #8a837d (3,7:1, WCAG 1.4.11) i programmet och i inloggningen; sökfältets platshållartext.
- Fokus: synlig fokusmarkering på allt som kan få fokus (`:focus-visible` i bas-lagret), även dolda filfält (rutan runt markeras), rullningsmarginal och ett skript som håller fokuserade element fria från de fasta topparna.
- Fel i formulär: `<x-input-error>` har `role="alert"` och ett id, och `resources/js/accessibility.js` sätter `aria-invalid` och `aria-describedby` på fältet; hjälptexten i `<x-ui.field hint>` kopplas på samma sätt. Efter ett skickat formulär med fel flyttas fokus till första felet och antalet fel läses upp. Felmeddelanden som skrivs direkt i vyerna fick `role="alert"`.
- Dialogrutor (`x-modal`, `x-dialog-modal`, `x-confirmation-modal`): `role="dialog"`, `aria-modal`, namn från rubriken; fokus hålls i rutan och går tillbaka när den stängs (Alpines `x-trap`), Esc stänger.
- Menyer (`x-dropdown`, företagsbytet): `aria-haspopup`, `aria-expanded`, Esc stänger och ger fokus tillbaka, menyn stängs när fokus lämnar den. Valt företag sägs i text, sökfältet har en etikett.
- Notiser (toasts): pausar medan musen eller fokus är på dem (2.2.1), stängknappen 24 px.
- Sidnumrering: egen vy för Livewire (`resources/views/vendor/livewire/tailwind.blade.php`) på svenska, utan `aria-label` på element utan roll, knappar på minst 40 px.
- Mindre rörelse när systemet ber om det (`prefers-reduced-motion`).
- Knappar för att ta bort rader i verifikation, faktura och leverantörsfaktura är 32 px; länkarna längst ned i sidomenyn minst 24 px höga.

## Kriterium för kriterium

Status: **Uppfylls** (kontrollerat), **Rättat** (i den här granskningen), **Brist** (kvar, med plan), **Ej testat**, **Ej tillämpligt**.

### 1 Möjlig att uppfatta

| Kriterium | Nivå | Status | Kommentar |
|---|---|---|---|
| 1.1.1 Icke-textuellt innehåll | A | Uppfylls | Ikoner är `aria-hidden` med text eller `aria-label` på knappen; logotyper har alt; diagrammen i Översikt har en sammanfattning och "Visa som tabell". |
| 1.2.1–1.2.5 Tidsberoende media | A/AA | Ej tillämpligt | Ingen video eller ljud i tjänsten. |
| 1.3.1 Information och relationer | A | Rättat | Landmärken, `h1` på alla sidor, fel kopplade till fält, tomma tabellrubriker namngivna. Schemats rutnät i Planering hade ogiltiga ARIA-roller (rader saknades); rättat i Planering-modulen samtidigt (commit 76fd829 på main). Tabeller använder `th`; bara 4 av 122 har `caption`, men alla ligger under en kortrubrik. |
| 1.3.2 Meningsfull ordningsföljd | A | Uppfylls | DOM-ordningen följer den visuella; radkorten på mobil är samma rader. |
| 1.3.3 Sensoriska egenskaper | A | Uppfylls | Instruktioner hänvisar till namn ("Bokför"), inte till form eller plats. |
| 1.3.4 Visningsriktning | AA | Uppfylls | Ingen låsning till stående eller liggande, inte heller i kiosken. |
| 1.3.5 Identifiera syftet med inmatning | AA | Uppfylls | `autocomplete` på e-post, namn och engångskod; uppgifter om användaren själv finns bara där. |
| 1.4.1 Användning av färg | A | Uppfylls | Negativa belopp har minustecken, status har text, valt företag sägs i text. |
| 1.4.2 Ljudkontroll | A | Ej tillämpligt | Inget ljud. |
| 1.4.3 Kontrast (minimum) | AA | Rättat | Grå text (`gray-400`, `gray-500`) föll under 4,5:1 på 75 sidor; nya tokenvärden. Mörkt läge finns bara på publika sidor och inloggning, där axe inte hittar kontrastfel. Ett företags egen färg (Varumärke) räknas om till läsbara nyanser i `Brand::palette`. |
| 1.4.4 Ändra textstorlek | AA | Uppfylls | Relativa enheter, zoom tillåts (ingen `user-scalable=no`). |
| 1.4.5 Bilder av text | AA | Uppfylls | Ingen text i bilder utom logotyper. |
| 1.4.10 Omflöde | AA | Uppfylls | Sidorna är byggda för 320 px; breda datatabeller rullar inne i sitt kort, vilket kriteriet tillåter. Inte omtestat i den här granskningen. |
| 1.4.11 Kontrast för icke-textuellt innehåll | AA | Rättat | Fältkanter var 1,5:1; nu 3,7:1. Fokusringar i accentfärg är minst 3:1 mot vitt och papper. |
| 1.4.12 Textavstånd | AA | Ej testat | Plan: testa med ett bokmärke som sätter WCAG:s avstånd. |
| 1.4.13 Innehåll vid hovring eller fokus | AA | Uppfylls | Menyer stannar tills de stängs och kan stängas med Esc; tips är `title`-attribut. |

### 2 Hanterbar

| Kriterium | Nivå | Status | Kommentar |
|---|---|---|---|
| 2.1.1 Tangentbord | A | Brist | Allt i de granskade flödena går med tangentbord (konteringen och schemat har egna tangenter, `?` visar dem). Kvar: tabellen på Planering → Bemanning och kostnad rullar i sidled men kan inte få fokus. Plan: gör rullytan fokuserbar med en etikett. |
| 2.1.2 Ingen tangentbordsfälla | A | Uppfylls | Dialogrutor fångar fokus avsiktligt och släpper det med Esc. |
| 2.1.4 Kortkommandon med tecken | A | Uppfylls | "/" och "?" fungerar bara när man inte skriver i ett fält; schemats c/v bara när schemat har fokus. |
| 2.2.1 Justerbar tidsgräns | A | Rättat | Notiser pausar vid hovring och fokus. Inloggningskoden har en giltighetstid som är en säkerhetsfunktion; en ny kod kan begäras. |
| 2.2.2 Pausa, stoppa, dölj | A | Uppfylls | Inget rörligt innehåll som varar över 5 s; laddningssnurran stannar med minskad rörelse. |
| 2.3.1 Tre blinkningar | A | Uppfylls | Inget blinkar. |
| 2.4.1 Förbigå block | A | Rättat | Hopplänk till `main`. |
| 2.4.2 Sidan har titel | A | Rättat | Alla inloggade sidor hette "NONOX Ekonomisystem"; nu sidans rubrik. |
| 2.4.3 Fokusordning | A | Uppfylls | Följer DOM; dialogrutor tar fokus och ger tillbaka det. |
| 2.4.4 Länkens syfte | A | Uppfylls | "Gör det nu →" har uppgiftens titel som tillgängligt namn; ikonlänkar har namn. |
| 2.4.5 Flera sätt | AA | Uppfylls | Sidomeny, sökningen (⌘K) och listor. |
| 2.4.6 Rubriker och etiketter | AA | Uppfylls | Etiketter på alla fält i de granskade flödena; radfälten i konteringen har "Konto, rad 2". |
| 2.4.7 Synligt fokus | AA | Rättat | Fokusmarkering i bas-lagret för allt; några knappar och flikar som tog bort den har fått den tillbaka; dolda filfält markerar sin ruta. |
| 2.4.11 Fokus inte dolt (minimum) | AA | Rättat | Rullningsmarginal och skript mot de fasta topparna; testat med 40 Tab-steg. |
| 2.5.1 Pekgester | A | Uppfylls | Inga flerfingergester. |
| 2.5.2 Avbryta pekning | A | Uppfylls | Knappar verkställer vid släpp (vanliga `click`). |
| 2.5.3 Etikett i namn | A | Uppfylls | Synlig text ingår i namnen. |
| 2.5.4 Rörelseaktivering | A | Uppfylls | Ingen. |
| 2.5.7 Dragrörelser | AA | Uppfylls | Uppladdning: "välj filer" bredvid dra-och-släpp. Schemat: Enter på ett pass öppnar redigeringen där person, datum och tider väljs, och c/v kopierar; nya pass med knappen i rutan. |
| 2.5.8 Målstorlek (minimum) | AA | Rättat | Ta bort-knappar på rader 32 px, sidnumreringen 40 px, notisernas stängknapp 24 px, sidomenyns länkar 24 px höga. Inte mätt på varje sida; små textlänkar i löptext är undantagna. |

### 3 Begriplig

| Kriterium | Nivå | Status | Kommentar |
|---|---|---|---|
| 3.1.1 Sidans språk | A | Uppfylls | `lang="sv"` (eller användarens språk) på alla sidor; kiosken byter `lang` med valt språk. |
| 3.1.2 Språk för delar | AA | Rättat | Sidnumreringen och "Saved." visades på engelska; nu svenska. Språkknapparna i kiosken har `lang`. |
| 3.2.1 Fokus | A | Uppfylls | Fokus startar inget. |
| 3.2.2 Inmatning | A | Uppfylls | Val i listor ändrar innehåll på sidan men byter inte sida; företagsbyte sker med ett eget klick. |
| 3.2.3 Konsekvent navigering | AA | Uppfylls | En navigering för alla sidor (`App\Support\Navigation`). |
| 3.2.4 Konsekvent identifiering | AA | Uppfylls | Samma komponenter och ord överallt. |
| 3.2.6 Konsekvent hjälp | A | Uppfylls | "Hjälp" (manualens artikel om sidan) på samma plats på varje sida: längst ned i sidomenyn på dator, i den mörka raden på mobil. |
| 3.3.1 Identifiering av fel | A | Rättat | Fel läses upp, kopplas till fältet och fältet markeras `aria-invalid`. |
| 3.3.2 Etiketter eller instruktioner | A | Uppfylls | Etiketter och hjälptexter; hjälptexter kopplas nu till fältet. |
| 3.3.3 Förslag vid fel | AA | Uppfylls | Felen säger vad som ska skrivas ("Skriv till exempel 1 234,50"). |
| 3.3.4 Förhindra fel (juridik, ekonomi, data) | AA | Uppfylls | Bokföring, låsning, bokslut och betalningar har en bekräftelse som säger följden; bokförda verifikationer rättas med en ny verifikation. |
| 3.3.7 Överflödig inmatning | A | Uppfylls | I de granskade flödena: kunduppgifter, konton och mallar fylls i från register; organisationsnummer slås upp. Inte kontrollerat i varje flöde. |
| 3.3.8 Tillgänglig autentisering (minimum) | AA | Uppfylls | Inloggning med kod via e-post (eller passkey): kodfältet har `autocomplete="one-time-code"`, klistra in fungerar, inget lösenord att minnas och inget kognitivt test. |

### 4 Robust

| Kriterium | Nivå | Status | Kommentar |
|---|---|---|---|
| 4.1.2 Namn, roll, värde | A | Rättat | Dialogrutor, menyer, sidnumrering och valt företag. Kvar i kiosken: rubriken med `role="status"` tappar sin rubrikroll; PIN-prickarna har fått en roll så att "2 / 4" läses. |
| 4.1.3 Statusmeddelanden | AA | Brist | Notiser, saldot i konteringen, Översiktens "Uppdaterad" och felantalet läses upp. Kvar: kiosken har hela skärmen som levande region och läser upp allt vid varje omritning. Plan: en fast statusyta i kiosken och `aria-live` bort från behållaren. |

(4.1.1 Parsning är borttaget i WCAG 2.2.)

## Kvar att göra

| Brist | Kriterium | Plan |
|---|---|---|
| PDF-filer (fakturor, lönebesked, rapporter, årsredovisning) är inte taggade | 1.3.1, 1.1.1 m.fl. för dokument | Granska och tagga PDF-mallarna; fakturan först, eftersom kunder läser den. |
| Kiosken läser upp hela skärmen | 4.1.3, 4.1.2 | Fast statusyta, ta bort `aria-live` från `#kiosk`, `role="status"` från rubriken. |
| Bemanning och kostnad: rullytan når inte tangentbordet | 2.1.1 | `tabindex="0"`, `role="region"` och en etikett på rullytan. |
| Mörkt läge saknas i programmet | (AGENTS.md §11.1, inte WCAG) | Tokens för mörkt läge i `app.css`; kräver genomgång av alla vyer. |
| Textavstånd och Windows högkontrastläge | 1.4.12 | Testa med bokmärke och i Windows. |
| Formulär med många fält saknar en felsammanfattning överst | (best practice till 3.3.1) | Fokus flyttas till första felet och antalet läses upp; en synlig sammanfattning kan läggas till i de längsta formulären (lön, avtal). |
| Utomstående granskning och test med hjälpmedelsanvändare | alla | Före version 1.0. |

## Så hålls det på plats

- `tests/Feature/AccessibilityTest.php`: hopplänk, `main`, uppläsningsytan och sidtiteln på en riktig sida; `h1` i Jetstreams sidor; felkoppling i `<x-ui.field>`; dialogroller på Profil-sidans dialogrutor; menyns ARIA; notisernas levande region; inloggningens fel; sidnumreringen; redogörelsen på /tillganglighet; kontrasttokens.
- `tests/js/accessibility.test.mjs`: hur ett fel hittar sitt fält och hur `aria-describedby` byggs.
- Nya vyer: använd `<x-ui.field>` (eller `<x-input-error :field="id">`) för fält, `<x-ui.page-heading>` för sidans `h1`, `x-dialog-modal` för dialogrutor, och `text-gray-500` eller mörkare för liten text.
