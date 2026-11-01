# Branschsidor

De publika sidorna per bransch på nonox.app. Varje bransch är en JSON-fil i
den här mappen, versionerad och granskad som jämförelsen i
[docs/migration](../migration/jamforelse.json). Sidorna byggs av
`App\Http\Controllers\IndustryController` med vyerna
`resources/views/start/industries.blade.php` (navet) och
`resources/views/start/industry.blade.php` (en bransch).

## Adresser

Alla adresser är svenska, gemena och utan å, ä och ö (AGENTS.md §11.4).
Företagsbranscher heter `/bokforing-for-…`, sidor för den som för bokföringen
åt andra heter `/for-…`. Filen heter som adressen.

| Adress | Fil | Målgrupp |
|---|---|---|
| `/branscher` | – | Navet med alla branscher |
| `/bokforing-for-cafeer` | `bokforing-for-cafeer.json` | Caféer |
| `/bokforing-for-restauranger` | `bokforing-for-restauranger.json` | Restauranger |
| `/bokforing-for-hantverkare` | `bokforing-for-hantverkare.json` | Bygg, el och VVS |
| `/bokforing-for-konsulter` | `bokforing-for-konsulter.json` | Konsultbolag |
| `/bokforing-for-butiker` | `bokforing-for-butiker.json` | Butiker och handel |
| `/bokforing-for-frisorer` | `bokforing-for-frisorer.json` | Frisörer och skönhetssalonger |
| `/for-redovisningsbyraer` | `for-redovisningsbyraer.json` | Redovisningsbyråer |
| `/for-ekonomikonsulter` | `for-ekonomikonsulter.json` | Ekonomikonsulter och egna redovisare |

En ny fil blir en ny sida, kommer med i menyn, sidfoten, navet och
sitemap.xml, och testas av `tests/Feature/IndustryPagesTest.php`. Lägg också
till adressen i tabellen ovan; testet kontrollerar det.

## Regler för innehållet

- **Inga priser i filerna.** Skriv `{pris}` för driftpriset och `{max}` för
  antalet anställda det gäller (från `LandingPage::PLANS`). Konkurrenternas
  priser räknas fram ur `price_model` i jämförelsefilen för det antal
  anställda som står under `pricing.employees`. Ett pris som källan inte
  anger (till exempel ett fast paketpris för ett annat antal anställda) visas
  som "Anges ej", aldrig som en gissning.
- **Bara funktioner som finns.** Funktioner anges med nycklar i
  `App\Support\Landing\Features` (`features` på punkterna under `month` och
  `solutions`). Varje nyckel måste vara byggd enligt README:s "Funktioner".
  Det som är på väg får bara nämnas under `limits`, med sin nyckel i
  `feature`.
- **Inga påhittade fakta.** Inga kundcitat, betyg, antal kunder eller loggor.
  Lagkrav skrivs försiktigt och hänvisar till Skatteverket; det som är osäkert
  listas i `legal_verify` (visas inte på sidan) och tas upp med jurist innan
  sidan marknadsförs brett.
- **Andra program** nämns bara som ADR-020 tillåter: i prisjämförelsen och
  i flyttavsnittet, med källa och tidpunkt.
- `title` högst 60 tecken och `description` högst 155, efter att `{pris}`
  ersatts. Varje sida har 5–8 vanliga frågor.

## Fälten

| Fält | Innehåll |
|---|---|
| `slug`, `order`, `group` | Adress, ordning i menyn, `foretag` eller `byra` |
| `name`, `menu_text`, `icon` | Namn och text i menyn, ikonens SVG-bana (24×24) |
| `title`, `description` | Sidans titel och metabeskrivning |
| `eyebrow`, `h1`, `h1_accent`, `lead` | Sidans rubrik och ingress |
| `month` | Så ser en månad ut i branschen: rubrik, ingress, punkter |
| `solutions` | Det NONOX gör: punkter med `features` |
| `pos` | Valfritt: kassasystemet och NONOX, hur försäljningen kommer in (`heading`, `intro`, punkter med `features`, `tip`). Bara det som finns; en kommande koppling står under `limits` |
| `limits` | Det NONOX inte gör, ärligt redovisat |
| `pricing` | Räkneexemplets antal anställda och text |
| `comparison_features` | Rader ur jämförelsefilens funktionstabell som visas |
| `faqs`, `related` | Vanliga frågor (blir FAQPage i JSON-LD), andra branscher |
| `legal_verify` | Juridiska frågor att kontrollera; visas inte |
