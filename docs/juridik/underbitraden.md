# Underbiträden

Version {version} · gäller från {datum}

De här underbiträdena behandlar personuppgifter för {foretag} när vi driftar NONOX på {webbplats}. Vi meddelar ändringar minst 30 dagar i förväg.

| Underbiträde | Vad | Var |
|---|---|---|
| Vultr Holdings | Server och databas som tjänsten körs på, och krypterade säkerhetskopior | Stockholm, Sverige |
| Laravel Holdings (Laravel Forge) | Administration av servern och driftsättning; har teknisk åtkomst till servern men lagrar inga uppgifter ur tjänsten | USA (EU–US Data Privacy Framework) |
| Mailgun Technologies (EU-region) | Utskick av e-post: inloggningskoder, fakturor, påminnelser, lönebesked; mottagning av e-post till inkorgen (in.nonox.app) när modulen Inkorg är påslagen | EU |
| Europeiska kommissionen (VIES) | Kontroll av momsregistreringsnummer, bara när du begär det | EU |
| Bolagsverket | Hämtning av namn, adress och företagsform med ett organisationsnummer, bara när du begär det eller när du fyller i numret i ett tomt formulär. För en enskild firma är numret ägarens personnummer. Bolagsverket är en myndighet och källa, inte vårt underbiträde | Sverige |

**Bara om företaget själv slår på det:**

| Tjänst | Vad | Var |
|---|---|---|
| OpenAI, Anthropic eller Google (företagets eget val och egen nyckel) | Tolkning av kvitton och leverantörsfakturor i AI-modulen | Kan vara utanför EU/EES |
| Fortnox | Källa vid flytt från Fortnox; vi läser bara | Sverige |
| Enable Banking Oy (modulen Bank) | Läser saldon och transaktioner på de bankkonton företaget kopplar med BankID; NONOX kan inte betala | Finland (EU) |

<!-- TODO(legal-verify): Enable Banking som underbiträde eller företagets egen leverantör, DPA, och om Stafe behöver registreras hos Finansinspektionen (ADR-034, frågor till jurist 30–31). Modulen Bank erbjuds inte i den driftade tjänsten förrän det är klart. -->
<!-- TODO(legal-verify): Bolagsverket som mottagare av personnummer vid hämtning av företagsuppgifter, och villkoren för vidareanvändning (ADR-035, frågor till jurist 32–34). -->
<!-- TODO(legal-verify): bekräfta Laravel Forges certifiering under EU–US Data Privacy Framework och avtal (DPA) med varje underbiträde. -->
