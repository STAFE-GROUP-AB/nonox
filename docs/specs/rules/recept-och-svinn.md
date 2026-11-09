Status: draft (not yet independently quote-checked)
Date: 2026-11-08
Item: roadmap `recept-och-svinn` (Recept och svinn för restauranger)
Author role: Researcher (ADR-073)

# Rule sheet: recipes and waste for restaurants (recept och svinn)

This sheet is the Researcher → Architect hand-off for ADR-073. It covers the rules that
govern what a recipe and waste feature may compute, store and book: valuation of food
stock, waste, staff meals, the VAT rates that reach a recipe's margin, and the till. Every
rule names its primary source, quotes it in Swedish and gives a confidence level. Nothing
here is legal advice. A human accountant checks the fixtures, and the lawyer answers the
L-questions in §9, before anything that books a voucher or feeds payroll is built.

## 0. Sources actually read (all on 2026-11-08)

| Short name | Source | Amendment level |
|---|---|---|
| ÅRL | Årsredovisningslag (1995:1554), data.riksdagen.se: https://data.riksdagen.se/dokument/sfs-1995-1554.text | The paragraphs used here (4 kap. 9, 11, 12 §§, 3 § andra–tredje st.) are quoted from the K2 vägledning's Lagtext boxes and match the riksdagen text |
| BFL | Bokföringslag (1999:1078): https://data.riksdagen.se/dokument/sfs-1999-1078.text | t.o.m. SFS 2024:342 |
| K2 | BFNAR 2016:10 Årsredovisning i mindre företag, BFN's consolidated vägledning: https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf | "Uppdaterad 2025-06-16" (t.o.m. BFNAR 2025:2). Kapitel 12 Varulager, pp. 232–243; kapitel 4 kommentarer to the income statement and balance sheet items, pp. 58 and 85 |
| K1 (EF) | BFN vägledning "Enskilda näringsidkare som upprättar förenklat årsbokslut" (BFNAR 2006:1): https://www.bfn.se/wp-content/uploads/vagledningen-k1.pdf | The file read is a **change-marked** version ("Uppdaterad 2013-12-05" struck for "2024-12-13", point 6.47 marked "(BFNAR 2025:X)"). Used only where IL confirms the same rule |
| IL | Inkomstskattelag (1999:1229): https://data.riksdagen.se/dokument/sfs-1999-1229.text | t.o.m. SFS 2026:1393 |
| ML | Mervärdesskattelag (2023:200): https://data.riksdagen.se/dokument/sfs-2023-200.text | t.o.m. SFS 2026:1025 |
| KRL | Lag (2007:592) om kassaregister m.m.: https://data.riksdagen.se/dokument/sfs-2007-592.text | t.o.m. SFS 2011:796 per the riksdagen header |
| BAS | BAS-kontogruppen, "Kontoplan BAS 2026" v 1.1: https://www.bas.se/wp-content/uploads/2026/04/BAS_kontoplan_2026_v2.pdf | v 1.1 (file dated April 2026) |
| SKV kost | Skatteverket, "Kostförmån": https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/kostforman.4.3016b5d91791bf546791816.html | No date on the page as read |
| SKV repr | Skatteverket, "Avdrag för moms vid representation": https://www.skatteverket.se/4.15532c7b1442f256baec84b.html | No date on the page as read |
| RÅ 2010:76 | Regeringsrätten, RÅ 2010 ref. 76 (personalmåltider, McDonald's), via lagen.nu: https://lagen.nu/dom/ra/2010:76 | Judgment; read as a secondary rendering |
| LR 2026 | Lagrådsremiss "Enklare mervärdesskatteregler vid försäljning av begagnade varor och gåva av livsmedel", 2 juli 2026: https://www.regeringen.se/contentassets/cd6d9d9d98a04562a3631d310d6f7454/lagradsremiss-enklare-mervardesskatteregler-vid-forsaljning-av-begagnade-varor-och-gava-av-livsmedel.pdf | Proposal, not law. Proposed in force 2028-01-01 |
| NONOX | `app/Modules/Payroll/database/data/2026/rules.json` (prisbasbelopp 59 200 kr, kostförmån 310/124/62 kr, each with its Skatteverket source quote) | Already verified data in the repository |

**Not read** (and therefore not relied on): K3 (BFNAR 2012:1) kapitel 13 Varulager;
SKVFS 2014:9 (Skatteverkets föreskrifter om kassaregister); Skatteverket's rättsliga
vägledning pages on uttag of personalmåltider and on livsmedelsdonationer (the latter is
known here only through LR 2026's summary of it); IL 22 kap. (uttag ur näringsverksamhet).

Confidence: **high** = the text says it directly; **medium** = follows from combining
texts, or the text leaves a judgment; **low** = sources are silent or disagree, a human
must decide.

### Corrections to earlier ADRs found while reading

- ADR-039 and ADR-071 cite "BFNAR 2016:10 (K2) kap. 11" for inventory. K2's inventory
  chapter is **kapitel 12 Varulager**; kapitel 11 is Finansiella anläggningstillgångar.
- ADR-039 left the 97 % rule out because "whether K2 allows a schablon in the accounts was
  not verified". K2 punkt 12.5 allows it (RS-R5). Whether NONOX offers it is still an owner
  decision, not a legal block.
- ADR-071 §8 asks whether K2 allows direct labour in the cost of own-manufactured goods. K2
  punkt 12.10 says it **must** be included (quoted in RS-R8). This answers part of ADR-071's
  first `TODO(legal-verify)`.
- The BAS 2026 name of 4010 is "Inköp av handelsvaror i Sverige"; NONOX's BAS 2026 templates
  (`database/data/bas/2026/*.json`) still call it "Inköp material och varor". Purchases of
  raw materials have their own group 43 in BAS 2026 (4310). Not fixed here; reported.

---

## 1. Food stock in the balance sheet

### RS-R1 Food bought to cook with is varulager, item Råvaror och förnödenheter

**Statement.** Ingredients a restaurant buys to prepare meals are inventory (varulager)
because they are current assets that "ska användas … vid tillhandahållandet av tjänster",
and a restaurant meal is a service (restaurangtjänst, ML 9 kap. 5 §, see RS-R14). They belong
in the balance sheet item **Råvaror och förnödenheter**, not Handelsvaror. Goods bought to be
sold on unchanged (for example bottled drinks sold over the counter to take away) are
Handelsvaror. BAS 2026 accounts: 1410 Lager av råvaror with 4910 Förändring av lager av
råvaror; 1460 Lager av handelsvaror with 4960 Förändring av lager av handelsvaror.

**Source.** K2 punkt 12.2 and kommentar; K2 kapitel 4 kommentar to "Varulager m.m."; BAS 2026
group 14 and 49.

**Quotes.**
- K2 12.2: "Varulager utgörs av omsättningstillgångar som a) är avsedda att säljas i den normala verksamheten (färdiga varor), b) är under tillverkning för att bli färdiga varor, eller c) ska användas i tillverkningen av färdiga varor eller vid tillhandahållandet av tjänster."
- K2 kommentar: "Punkt 12.2 c innebär att råvaror, halvfabrikat och förnödenheter anses vara varulager."
- K2 kap. 4 (Råvaror och förnödenheter): "I posten redovisas råvaror, material och förnödenheter som har inköpts för att bearbetas eller för att vara komponenter i den egna tillverkningen av varor eller vid tillhandahållandet av tjänster"
- K2 kap. 4: "Lager av varor som inköpts för att vara komponenter i den egna tillverkningen eller vid tillhandahållande av tjänster redovisas i posten Råvaror och förnödenheter."
- BAS 2026: "1410 Lager av råvaror", "4910 Förändring av lager av råvaror", "1460 Lager av handelsvaror", "4960 Förändring av lager av handelsvaror".

**Confidence.** High for food used in cooking. **Low** for wine, beer and spirits poured at
the table: they are served as part of the restaurant service (so 12.2 c and Råvaror) but are
not processed; practice varies. A human decides (L1).

**Consequence for NONOX.** ADR-039's year-end step books one account pair per company
(1460/4960 by default). A restaurant needs one pair per kind of stock (food on 1410/4910,
take-away goods on 1460/4960). See ADR-073 §9.

### RS-R2 Lowest value principle, item by item

**Statement.** Stock is valued at the lower of cost and net realisable value on the balance
day, item by item (or per homogeneous group). A loss on one item may not be set off against
another. If the net realisable value is obviously higher than cost it need not be computed.

**Source.** ÅRL 4 kap. 9 § första stycket; K2 12.3, 12.4 and kommentar.

**Quotes.**
- ÅRL 4:9 första st.: "Omsättningstillgångar ska, om inte annat följer av 10, 12, 13 a, 14 a eller 14 e §, tas upp till det lägsta av anskaffningsvärdet och nettoförsäljningsvärdet på balansdagen."
- K2 12.3: "Kollektiv värdering får endast användas a) för homogena varugrupper, eller b) om en individuell värdering inte kan motiveras av kostnadsskäl."
- K2 12.4 andra st.: "Är det uppenbart att det bedömda nettoförsäljningsvärdet för respektive vara eller varugrupp är högre än anskaffningsvärdet behöver nettoförsäljningsvärdet inte beräknas."
- K2 kommentar: "Ett sänkt värde på en varugrupp får inte heller kvittas mot ett höjt värde på en annan varugrupp."

**Confidence.** High.

### RS-R3 Net realisable value for perishable food; replacement cost for raw materials

**Statement.** Spoiled, damaged or surplus goods are worth what they can be sold for, and
inkurans must be considered. For raw materials whose sale is not part of the normal business
(a restaurant does not sell raw fish), the replacement cost (återanskaffningsvärde) may be
used instead of net realisable value when there are "särskilda skäl", which K2 says can be
exactly that. Food that has spoiled on the balance day and can neither be served nor sold has,
in practice, no value.

**Source.** ÅRL 4 kap. 9 § tredje och fjärde st.; K2 12.14, 12.16 and kommentarer.

**Quotes.**
- ÅRL 4:9 tredje st.: "Med nettoförsäljningsvärde avses försäljningsvärdet efter avdrag för beräknad försäljningskostnad. Om det finns särskilda skäl, får återanskaffningsvärdet, i förekommande fall med avdrag för inkurans, eller något annat värde som är förenligt med 2 kap. 2 och 3 §§ användas i stället för nettoförsäljningsvärdet."
- K2 12.14: "Med försäljningsvärde … avses det pris som varan kan säljas för enligt villkor som är normala i verksamheten. Priset ska fastställas utifrån de förhållanden som råder på balansdagen. Hänsyn ska tas till inkurans."
- K2 kommentar: "Inkurans innebär att varornas värde har minskat t.ex. till följd av att varorna har skadats, blivit omoderna eller är övertaliga."
- K2 12.16: "Sådana särskilda skäl … kan för råvaror och halvfabrikat vara att försäljning av dessa inte ingår i företagets normala verksamhet eller att en värdering till nettoförsäljningsvärde inte kan motiveras av kostnadsskäl."

**Confidence.** High for the rules. **Medium** for "spoiled food = 0": it follows from 12.14,
but no source says it in those words. NONOX should not decide it; the person counting marks
an item as not usable, and the valuation shows it at 0 with the reason (ADR-039's manual net
realisable value per article already exists).

### RS-R4 Cost: purchase price plus direct costs, minus discounts; FIFO or average

**Statement.** The cost of bought goods is the purchase price plus costs directly
attributable to the purchase (freight, import charges, customs) **minus** discounts, bonuses
and similar reductions, even when confirmed after the balance day but before the annual
report is made. Cost of interchangeable goods may be calculated FIFO or by weighted average;
LIFO is not allowed.

**Source.** ÅRL 4 kap. 3 § andra st., 4 kap. 9 § andra st., 4 kap. 11 §; K2 12.7 and kommentar.

**Quotes.**
- ÅRL 4:3 andra st.: "I anskaffningsvärdet för en förvärvad tillgång ska, utöver inköpspriset, utgifter som är direkt hänförliga till förvärvet räknas in."
- ÅRL 4:11: "Anskaffningsvärdet för varulager av likartade tillgångar får beräknas enligt först-in-först-ut-principen, enligt vägda genomsnittspriser eller enligt någon annan liknande princip. Sist-in-först-ut-principen får inte tillämpas."
- K2 12.7: "… är bl.a. utgifter för a) frakt, b) importavgifter, och c) tull. Avdrag ska göras för varurabatter, bonus och andra liknande prisavdrag."

**Confidence.** High.

**Consequence for NONOX.** The ingredient price a recipe uses is the stock cost per unit
from receipts (`stock_receipt_lines.unit_cost_ore`), not the gross invoice line. A supplier's
year-end bonus lowers cost; NONOX cannot see it unless a person enters it. Recipe costing is
a management figure and may use the latest price; the year-end valuation must use the method
the company chose (ADR-039). The two must not be confused on screen.

### RS-R5 The 97 % rule, and why discarded goods must leave the shelf

**Statement.** Under K2 the whole stock may be taken up at no less than 97 % of its total
cost, provided this is not higher than the lowest-value valuation. If any item or group is
valued at net realisable value, the 97 % rule may not be used for any part of the stock.
Goods count as discarded only when they are **physically separated from the stock with the
intention of being thrown away**. If goods to be discarded are still in stock and valued at
zero, that counts as an inkurans assessment and the 97 % rule cannot be used at all.

**Source.** K2 12.5 and kommentar; K2 12.18 kommentar; IL 17 kap. 4 §.

**Quotes.**
- K2 12.5: "Varulagret får tas upp till lägst 97 procent av lagertillgångarnas sammanlagda anskaffningsvärde under förutsättning att detta värde inte är högre än det värde som en värdering enligt lägsta värdets princip ger."
- K2 kommentar: "Värderas någon vara eller varugrupp till nettoförsäljningsvärde får inte 97-procentsregeln tillämpas på någon del av varulagret."
- K2 12.18 kommentar: "För att varor ska anses kasserade ska de fysiskt ha avskilts från lagret med avsikt att slängas. … Finns däremot varorna som ska kasseras fysiskt kvar i lagret och är värderade till noll kronor, anses det som om en inkuransbedömning gjorts av varulagret. Det innebär att den så kallade 97-procentsregeln inte får användas för några varor i lagret."
- IL 17:4: "Lagret får tas upp till lägst 97 procent av lagertillgångarnas sammanlagda anskaffningsvärde."

**Confidence.** High.

**Consequence for NONOX.** A waste registration is a statement that the goods have left the
stock. The waste screen must say so ("Registrera bara det som har slängts eller ska slängas
nu"), and a stock count line with a "not usable" mark must not silently coexist with a 97 %
valuation. If the owner chooses to offer the 97 % rule (Owner question O3), the valuation
refuses it when any article in the valuation carries a manual net realisable value below cost.

### RS-R6 Fixed quantity and value for minor raw materials

**Statement.** Raw materials and supplies that are turned over, whose total value is of minor
importance and whose quantity, value and composition do not vary significantly, may be
carried at a fixed quantity and value without counting. K2 treats a variation within ±20 % of
the recorded value as not significant. In a kitchen this could fit spices, oils and dry
goods, never fresh produce.

**Source.** ÅRL 4 kap. 12 §; K2 12.6 and kommentar.

**Quotes.**
- ÅRL 4:12: "Materiella anläggningstillgångar, råvaror och förnödenheter som omsätts och vilkas sammantagna värde är av underordnad betydelse för företaget, får tas upp till en bestämd mängd och ett fast värde, om deras kvantitet, värde och sammansättning inte varierar väsentligt."
- K2 12.6: "… anses inte variera väsentligt om postens sammantagna värde kan antas ligga i intervallet plus/minus 20 procent av det redovisade värdet."

**Confidence.** High for the rule; whether a given kitchen meets "underordnad betydelse" is a
judgment (L2). NONOX does not build this in v1; it is listed so that nobody builds it as a
default.

### RS-R7 Sole traders with simplified annual accounts: a small stock may be left out

**Statement.** A sole trader (EF) who prepares a förenklat årsbokslut need not take up any
value for the stock if its total value is at most half a prisbasbelopp. For 2026 that is
29 600 kr (prisbasbelopp 59 200 kr). In the same framework, the value of the owner's and the
employees' own work is not included in the cost of stock.

**Source.** IL 17 kap. 4 a §; K1 vägledning punkt 6.47 and 6.48 (change-marked file);
prisbasbelopp from NONOX `rules.json` 2026 (Skatteverket "Belopp och procent 2026").

**Quotes.**
- IL 17:4 a: "Om en enskild näringsidkare upprättar ett förenklat årsbokslut enligt 6 kap. 6 § bokföringslagen (1999:1078), behöver något värde på lagertillgångarna inte tas upp om lagrets sammanlagda värde uppgår till högst ett halvt prisbasbelopp. Lag (2024:1131)."
- K1 6.47 (new text in the change-marked file): "Ett lager som har ett sammanlagt värde som uppgår till högst … ett halvt prisbasbelopp behöver inte tas upp i balansräkningen utan får redovisas som kostnad."
- K1 6.48: "Värdet av eget och anställdas arbete ska inte tas med i anskaffningsvärdet."

**Confidence.** High for IL. **Medium** for the K1 wording, because the file read is a draft
with change marks ("BFNAR 2025:X"); the final BFNAR number and date must be checked (L3).

### RS-R8 Prepared food in the fridge is own-manufactured stock

**Statement.** Stocks, sauces, doughs and other prepared components on hand at the balance
day are goods under manufacture or own-manufactured goods. Under K2 their cost **must**
include the purchase price of materials **and** wages and employer contributions of the staff
who made them, plus the 12.7 items; storage, administration and selling costs may never be
included. Under K2 the change in such stock goes to the item Förändring av lager av produkter
i arbete, färdiga varor och pågående arbete för annans räkning (BAS 4940/4950 with 1440/1450).

**Source.** ÅRL 4 kap. 3 § tredje st.; K2 12.10, 12.12, 12.13.

**Quotes.**
- K2 12.10: "I anskaffningsvärdet för egentillverkade varor ska följande direkta utgifter räknas in a) inköpspriset för material, b) utgifter för lön till och arbetsgivaravgifter för anställda och utgifter för inhyrd personal som arbetar med att tillverka varorna, och c) sådana utgifter och avdrag som anges i punkt 12.7."
- K2 12.12: "I anskaffningsvärdet för en egentillverkad vara får inte räknas in a) ränteutgifter, b) lagerhållningskostnader, c) administrationsomkostnader, d) försäljningsomkostnader, eller e) forsknings- och utvecklingskostnader."
- K2 12.13 andra st.: "Räkenskapsårets förändring av lager av egentillverkade varor ska redovisas i resultaträkningen i posten Förändring av lager av produkter i arbete, färdiga varor och pågående arbete för annans räkning."

**Confidence.** High for the rule. **Low** for what it means in a restaurant: whether a few
litres of stock in the fridge are material at all, and whether valuing them at ingredient
cost only is acceptable under the materiality principle (L4). NONOX v1 values sub-recipes on
hand at ingredient cost and says so on the valuation, with `TODO(legal-verify)`.

---

## 2. Waste (svinn)

### RS-R9 Discarded goods are a cost of the period they are discarded

**Statement.** The cost of discarded goods is recognised in the period they are discarded.
For raw materials it sits in the income statement item Råvaror och förnödenheter; for goods
for resale in Handelsvaror. BAS 2026 has **no separate account for waste**: in the periodic
method NONOX uses (ADR-039: no booking per movement), the purchase is already a cost on the
purchase account, and the waste shows up as a lower stock at the count, which the year-end
stock change books to 4910/4960.

**Source.** K2 12.18; K2 kapitel 4 kommentar to Råvaror och förnödenheter and to Handelsvaror;
BAS 2026 groups 40–49 (no waste account found).

**Quotes.**
- K2 12.18: "Kostnad för kasserade varor ska redovisas i den period varorna kasseras."
- K2 kap. 4 (Råvaror och förnödenheter): "Tillverkande företag redovisar anskaffningsutgiften för de råvaror och förnödenheter som gått till produktion för vidareförädling eller av annan anledning inte längre finns i lagret, t.ex. svinn och kassationer, i posten."
- K2 kap. 4 (Handelsvaror): "Även inkurans och svinn redovisas i posten."

**Confidence.** High that the periodic method meets 12.18 when the period is the fiscal year
and the closing count excludes discarded goods. **Medium** for interim reports: a monthly
income statement without a monthly count shows waste as purchases, not as waste. That is
acceptable for management reports but must be labelled (no legal requirement for interim
stock change found).

### RS-R10 A waste registration is not an affärshändelse and needs no verifikation of its own; the waste log is underlag

**Statement.** BFL requires a verifikation for every affärshändelse, and defines
affärshändelse as a change in the company's assets that arises from its **economic relations
with the outside world**. Throwing away food bought earlier is an internal event: no voucher
and no verifikation is required per waste registration. What is booked is the year-end stock
change, which is a "vidtagen justering i bokföringen" and needs a verifikation; its underlag
is the stock valuation and the count, and the waste log explains the difference. The
specification of the stock item (BFL 6:8) and the information needed to follow how an entry
was treated are räkenskapsinformation and are kept for seven years after the calendar year in
which the fiscal year ended.

**Source.** BFL 1 kap. 2 § första st. 7, 8, 9 c; 5 kap. 6 §; 6 kap. 8 §; 7 kap. 2 §.

**Quotes.**
- BFL 1:2 p. 7: "affärshändelser: alla förändringar i storleken och sammansättningen av ett företags förmögenhet som beror på företagets ekonomiska relationer med omvärlden, såsom in- och utbetalningar, uppkomna fordringar och skulder samt egna tillskott till och uttag ur verksamheten av pengar, varor eller annat,"
- BFL 1:2 p. 8: "verifikation: de uppgifter som dokumenterar en affärshändelse eller en vidtagen justering i bokföringen,"
- BFL 1:2 p. 9 c: "sådana uppgifter i övrigt som är av betydelse för att det ska gå att följa och förstå de enskilda bokföringsposternas behandling i bokföringen,"
- BFL 5:6: "För varje affärshändelse ska det finnas en verifikation."
- BFL 6:8: "För varje sammandragen post i den balansräkning som skall ingå i årsredovisningen eller årsbokslutet skall, om inte postens sammansättning klart framgår av bokföringen i övrigt, de belopp som ingår i posten specificeras i en särskild förteckning"
- BFL 7:2: "De ska bevaras fram till och med det sjunde året efter utgången av det kalenderår då räkenskapsåret avslutades (bevarandetid)."

**Confidence.** **Medium.** The "omvärlden" reading is the plain text; no BFN or court source
was read that applies it to waste. Whether the waste log is räkenskapsinformation under p. 9 c
is a judgment (L5). Safe design either way: the waste log is append-only, corrected only by a
new entry, kept as long as the stock movements, and included in the archive package.

### RS-R11 Food given away is uttag; near-expiry donations normally have no tax base

**Statement.** Food given away without payment (to a charity, to staff to take home, to the
owner) is uttag av varor for VAT, taxed as a supply for consideration. The tax base is the
purchase price of the goods or similar goods at the time of the uttag. Skatteverket, as
summarised by the government in LR 2026, considers that food still fit to eat but close to
its best-before or use-by date, which would otherwise be discarded, normally has no tax base,
so no VAT is due on the donation. A government proposal would exempt donations to approved
recipients from 2028-01-01. It is not law. Samples and gifts of small value given within the
business are not uttag.

**Source.** ML 5 kap. 8 § och 9 §; ML 8 kap. 5 §; LR 2026 avsnitt 5.1.2 and the summary on p. 1.

**Quotes.**
- ML 5:8: "Uttag av varor likställs med leverans av varor mot ersättning."
- ML 5:9: "Med uttag av varor avses att en beskattningsbar person tar ut en vara ur sin rörelse 1. för sitt eget eller personalens privata bruk, 2. för överlåtelse av varan utan ersättning, eller 3. annars för användning för andra ändamål än den egna rörelsen."
- ML 5:9 andra st.: "Överlåtelse av varor utan ersättning anses inte som uttag, om varorna är gåvor av mindre värde eller varuprover och ges inom ramen för den beskattningsbara personens rörelse."
- ML 8:5: "Vid uttag av varor utgörs beskattningsunderlaget av varornas eller liknande varors inköpspris eller, om ett sådant pris saknas, av självkostnadspriset, vid tidpunkten för uttaget."
- LR 2026: "För en donation av livsmedel som fortfarande är tjänliga som föda, men där bäst-före-datum eller sista förbrukningsdag närmar sig, och där alternativet är att varorna annars skulle kasseras, anser Skatteverket att det normalt inte finns något beskattningsunderlag och att någon beskattning av uttaget därmed inte ska ske."
- LR 2026: "Lagändringarna föreslås träda i kraft den 1 januari 2028."

**Confidence.** High for ML 5:9 and 8:5. **Medium** for the Skatteverket view: read through
the government's summary, not on Skatteverket's own page (L6).

**Consequence for NONOX.** Waste reason "Skänkt" is kept apart from discarded waste, and a
donation is recorded with the recipient's organisation name (an organisation, not a person).
NONOX proposes **no** VAT for a donation; it shows the cost value as the possible tax base and
the text "Skatteverket anser att skänkt mat nära bäst före-datum normalt inte har något
beskattningsunderlag" with `TODO(legal-verify)`.

---

## 3. Staff meals (personalmåltider)

### RS-R12 Income tax: kostförmån at Skatteverket's values, reduced by what the employee pays

**Statement.** A free or subsidised meal to an employee is a taxable benefit, valued for a
full day at 0,52 % of the prisbasbelopp rounded to the nearest five kronor; lunch or dinner is
40 % and breakfast 20 % of the full-day value. For 2026: 310 kr per day, 124 kr per lunch or
dinner, 62 kr per breakfast. If the employee pays, the value is reduced by what was paid.
Skatteverket says it does not matter in principle that the meal is given at the ordinary
workplace, so restaurant staff eating at work are not exempt. Meals at internal representation
(for example a staff party) and at temporary short gatherings are tax-free. The taxable value
is reported in the AGI in box 012.

**Source.** IL 61 kap. 3 § och 4 §; SKV kost; NONOX `rules.json` 2026 keys
`benefit.meal.free_day`, `benefit.meal.lunch_or_dinner`, `benefit.meal.breakfast`.

**Quotes.**
- IL 61:3: "Kostförmån ska för en hel dag beräknas till ett belopp - avrundat till närmaste femtal kronor - som motsvarar 0,52 procent av prisbasbeloppet. Förmånsvärdet för lunch eller middag är 40 procent av värdet för kostförmån för en hel dag. Förmånsvärdet för frukost är 20 procent av värdet för kostförmån för en hel dag."
- IL 61:3 andra st.: "Om en anställds kostnader för en måltid är lägre än det belopp som motsvarar förmånsvärdet enligt första stycket på grund av att arbetsgivaren bidrar till kostnaderna för denna, ska förmånen behandlas som en kostförmån med ett värde som motsvarar mellanskillnaden."
- IL 61:4: "Om den skattskyldige lämnar ersättning för kostförmånen eller bostadsförmånen, ska förmånsvärdet beräknas till värdet för förmånen enligt 3 eller 3 a § minskat med vad han har betalat."
- SKV kost: "Helt fri kost (minst tre måltider): 310 kronor per dag." "Fri lunch eller middag: 124 kronor per dag." "Fri frukost: 62 kronor per dag."
- SKV kost: "Det saknar i princip betydelse om anställda får fri kost på den ordinarie arbetsplatsen."
- SKV kost: "Fri kost vid intern representation är en skattefri förmån"

**Confidence.** High. The 2027 values are not published yet (`rules.json` 2027 lists them as
expected December 2026); NONOX already blocks payroll on an unverified rule.

**Consequence for NONOX.** The payroll module already has the pay codes `KOSTFORMAN_HEL`,
`KOSTFORMAN_LUNCH` and `KOSTFORMAN_FRUKOST` with the 2026 values (`Allowances::MEALS`). A
staff-meal log can produce their quantities per employee and month. What it cannot do yet is
carry "what the employee paid" into the benefit value (IL 61:4); see ADR-073 §7 and O5.

### RS-R13 VAT on staff meals: uttag when free, the price paid when subsidised

**Statement.** A restaurant that serves its staff a meal **without payment** makes an uttag
av tjänster (a restaurant service for the staff's private use), taxed as a supply for
consideration. The tax base is the cost of providing the service at the time of the uttag,
meaning the share of the business's fixed and running costs that belongs to the service — not
only the ingredients. When staff **pay** a price, that is an ordinary supply with the price as
the base. The base is revalued to market value only when the price is lower than market value
**and** the buyer lacks full deduction right **and** the parties are connected (employment is
a connection) **and** the seller cannot show the price is commercially justified. In RÅ 2010
ref. 76 a staff price that covered the company's costs was held to be commercially justified,
so the price paid was the base. The rate is the restaurant rate (RS-R14).

**Source.** ML 5 kap. 28 § och 29 §; ML 8 kap. 6 §, 17 §, 19 §; RÅ 2010 ref. 76.

**Quotes.**
- ML 5:28: "Uttag av tjänster likställs med tillhandahållande av tjänster mot ersättning."
- ML 5:29: "Med uttag av tjänster avses att en beskattningsbar person utan ersättning utför eller på annat sätt tillhandahåller en tjänst för sitt eget eller personalens privata bruk eller annars för annat ändamål än den egna rörelsen."
- ML 8:6: "Vid uttag av tjänster utgörs beskattningsunderlaget av kostnaden vid tidpunkten för uttaget för att utföra eller på annat sätt tillhandahålla tjänsterna … Med kostnad för att utföra eller på annat sätt tillhandahålla tjänsterna avses den del av de fasta och löpande kostnaderna i rörelsen som hör till tjänsterna."
- ML 8:17: "… utgörs beskattningsunderlaget av marknadsvärdet … om 1. ersättningen är lägre än marknadsvärdet, 2. mervärdesskatten inte är fullt avdragsgill för förvärvaren, 3. leverantören eller tillhandahållaren och förvärvaren är förbundna med varandra, och 4. den som är skyldig att betala mervärdesskatten inte kan göra sannolikt att ersättningen är marknadsmässigt betingad."
- ML 8:19: "… band på grund av anställning eller andra juridiska band."
- RÅ 2010 ref. 76 (as rendered by lagen.nu): "beskattningsunderlaget ska enligt huvudregeln bestämmas till den ersättning som bolaget erhåller från de anställda."

**Confidence.** High for the statutory rules. **Low** for how to compute "kostnaden" under
8:6 for a free staff meal: the raw-material cost from a recipe is only part of it, and
Skatteverket's rättsliga vägledning on personalmåltider was not read (L7). Whether Skatteverket
accepts the kostförmån value as the VAT base was **not** verified and must not be assumed.

**Consequence for NONOX.** v1 produces the **underlag** only: number of free and subsidised
meals per month, what staff paid, and the raw-material cost from the recipes. It books
nothing for VAT on staff meals and proposes no amount until L7 is answered.

### RS-R14 VAT rates that reach a recipe's margin

**Statement.** Restaurant and catering services: 12 %, except the part that is spirits, wine
and strong beer, which takes the standard 25 %. Food (livsmedel) as goods, for example
ingredients bought from a wholesaler or food sold to take away as goods: 6 % from 2026-04-01
to 2027-12-31, returning to 12 % from 2028-01-01. Spirits, wine and strong beer are excluded
from the food rate. A recipe's margin is computed on the price **net of output VAT** at the
rate of the menu item; ingredient costs are net of deductible input VAT, so the input rate
does not change the recipe cost for a fully VAT-registered restaurant.

**Source.** ML 9 kap. 2 §, 5 §, 19 § (in force 2026-04-01 by Lag 2026:118) and 3 § (in force
2028-01-01 by Lag 2026:119); transitional rules to Lag 2026:118.

**Quotes.**
- ML 9:5: "Skatt tas ut med 12 procent av beskattningsunderlaget för restaurang- och cateringtjänster, med undantag för den del av tjänsten som avser spritdrycker, vin och starköl."
- ML 9:19: "Skatt tas ut med 6 procent av beskattningsunderlaget för leverans, unionsinternt förvärv och import av sådana livsmedel som avses i artikel 2 i EU:s livsmedelsförordning, med undantag för 1. vatten som avses i artikel 6 i dricksvattendirektivet, och 2. spritdrycker, vin och starköl."
- Lag 2026:118 övergångsbestämmelse: "1. Denna lag träder i kraft den 1 april 2026."
- ML 9:3 (I:2028-01-01): "Skatt tas ut med 12 procent av beskattningsunderlaget för leverans, unionsinternt förvärv och import av sådana livsmedel som avses i artikel 2 i EU:s livsmedelsförordning"

**Confidence.** High for the rates. Which rate a given sale takes (on-site restaurant service
or take-away food as goods) is decided by the till's VAT rate on the sold item, not by NONOX.
NONOX reads the rate from the sale row (`pos_sale_rows.vat_rate`) and the article; it never
infers it from the recipe.

### RS-R15 Representation with own food

**Statement.** A staff party is internal representation. Input VAT on food and drink for
representation is deductible on a base of at most 300 kr excluding VAT per person and
occasion. When a restaurant serves its own food at its own staff party, the ingredients were
bought with full deduction; whether an uttag or a deduction limit applies to that internal
use was not found in the sources read.

**Source.** SKV repr.

**Quotes.**
- SKV repr: "300 kronor exklusive moms per person och tillfälle"
- SKV repr: "Personalfest är en intern representation."

**Confidence.** Low for own food at own party (L8). NONOX v1 records it as reason
"Representation" in the staff-meal log, with no tax treatment proposed.

### RS-R16 The owner's own meals in a sole trader's restaurant

**Statement.** A sole trader who takes food from the business makes an uttag ur verksamheten
of goods. That is an affärshändelse under BFL and needs a verifikation; for VAT it is uttag av
varor with the purchase price as the base (ML 5:9 p. 1, 8:5). The income tax valuation of the
owner's uttag (IL 22 kap.) was not read.

**Source.** BFL 1:2 p. 7 ("egna tillskott till och uttag ur verksamheten av pengar, varor
eller annat"); ML 5:9, 8:5 (quoted in RS-R10, RS-R11).

**Confidence.** High that a verifikation is needed; low for the income tax value (L9).
NONOX v1: a staff-meal entry marked "Ägaren" in an EF produces a **draft** voucher only when
the owner asks for it, with BAS 3400-group accounts (3401–3404 "Egna uttag momspliktiga …")
proposed and `TODO(legal-verify)`; nothing is posted automatically.

---

## 4. The till (kassaregister)

### RS-R17 Every sale goes through the certified till; NONOX is not a till

**Statement.** Whoever sells goods or services against cash or card payment in a business
must register all sales in a certified cash register. NONOX reads sales from the till's API
(ADR-042); it does not register sales and must never be presented as a substitute for the
till. A staff meal **sold** at a staff price and paid by card or cash is a sale and must go
through the till. Whether a staff meal paid by salary deduction is "kontant betalning" was
not found (L10). The daily verifikation may be built from the till's summary of the day's
payments.

**Source.** KRL 2 §, 6 §, 9 §; BFL 5 kap. 6 § tredje st.

**Quotes.**
- KRL 2 §: "Den som i näringsverksamhet säljer varor eller tjänster mot kontant betalning omfattas av skyldigheter enligt denna lag, om inte annat följer av 3 §."
- KRL 6 §: "Med kontant betalning likställs betalning med kontokort."
- KRL 9 §: "All försäljning och annan löpande användning av ett kassaregister skall registreras i ett kassaregister."
- BFL 5:6 tredje st.: "Vid försäljning av varor och tjänster mot kontant betalning får även inbetalningarna under en dags försäljning dokumenteras genom en gemensam verifikation. Den gemensamma verifikationen får då utgöras av uppgifter från en kassaapparat, kassarapport eller annan uppgift som anger summan av mottagna betalningar."

**Confidence.** High for KRL 2, 6, 9 §§. SKVFS 2014:9 (the technical requirements) was not
read; ADR-042's open question about the Z-report as underlag stays open.

**Consequence for NONOX.** Theoretical consumption is computed from `pos_sale_rows`, which
come from the till's API and may differ from the Z-report (modifiers, open amounts, voids).
The variance report states its source ("enligt kassans försäljningsrader") and never claims
to reconcile the Z-report.

---

## 5. Personal data

### RS-R18 Waste registration and staff meals touch personal data

**Statement.** A waste entry carries the user who registered it (already the pattern for
stock movements). A staff-meal entry per employee is personal data and is processed because
the employer must report the benefit (IL 61 kap.; AGI box 012 per SKV kost). Recipes, prices
and sales rows carry no personal data. No new category of personal data arises beyond
"employee, date, meal, amount paid", which feeds payroll's existing benefit processing.

**Source.** SKV kost (AGI box 012); IL 61:3–4.

**Confidence.** High that it is personal data; the legal basis (legal obligation for the
benefit, legitimate interest for a meal log of non-employees such as the owner) is for the
GDPR register (L11).

---

## 6. Calculation rules NONOX defines (not law; recorded so builder and tests agree)

- **RS-C1 Quantities** are thousandths of the article's unit (`Quantity`, ADR-039). A recipe
  line may be entered in a smaller unit of the same dimension (g for kg, ml/cl/dl for l), with
  fixed factors; anything else (a bottle of 70 cl, a box of 12) needs an explicit conversion
  on the article.
- **RS-C2 Loss on a line.** A line states the **net** quantity that goes into the dish and a
  loss in basis points of the **gross** quantity (peel, bone, trim). Gross =
  net × 10 000 / (10 000 − loss_bp), rounded half up to whole thousandths. Loss is 0–9 999 bp.
- **RS-C3 Line cost** = gross quantity × unit cost / 1000, rounded half up to öre (`Quantity::value`).
- **RS-C4 Batch cost** = sum of line costs. **Portion cost** = batch cost × 1000 / yield_milli,
  rounded half up to öre, once.
- **RS-C5 Sub-recipes.** A line may use another recipe that yields a stock unit (kg, l). Its
  unit cost is that recipe's batch cost × 1000 / yield_milli, rounded half up, before it is used.
  Loops are refused.
- **RS-C6 Margin** against the menu item's net price: contribution = net price − portion cost;
  food cost % = portion cost / net price; both in basis points, rounded half up. The net price
  is the article's price excluding VAT (articles store prices excluding VAT).
- **RS-C7 Theoretical consumption** of an ingredient over a period = Σ over dishes of (gross
  quantity per batch × sold portions_milli / yield_milli), rounded half up once per ingredient
  and period. Sold portions are the signed `pos_sale_rows.quantity_milli` of rows linked to the
  dish article (returns reduce).
- **RS-C8 Variance** per ingredient over a counted period = (opening count + receipts −
  closing count) − (theoretical consumption + recorded waste + staff meals + other recorded
  outs). Valued at the closing unit cost of the valuation method. A positive figure is
  unexplained usage.

## 7. Fixtures (synthetic; no real company)

All in öre and thousandths. Company "Exempelkrogen AB" (K2, VAT-registered, fiscal year =
calendar year). Prices are invented.

### F-RS-1 Sub-recipe: Vitlökssmör (yield 1,000 kg)

| Ingredient | Unit | Unit cost | Net | Loss | Gross (milli) | Line cost |
|---|---|---|---|---|---|---|
| Smör | kg | 11 000 | 0,900 | 0 | 900 | 9 900 |
| Vitlök | kg | 5 000 | 0,150 | 2 000 bp | 150 × 10 000 / 8 000 = 187,5 → **188** | 188 × 5 000 / 1000 = **940** |

Batch cost 10 840. Yield 1 000 milli → unit cost **10 840 öre per kg**.

### F-RS-2 Dish: Pasta med svamp (yield 10 portions = 10 000 milli), menu price 189,00 kr incl. 12 %

| Ingredient | Unit | Unit cost | Net | Loss | Gross (milli) | Line cost |
|---|---|---|---|---|---|---|
| Pasta, torr | kg | 3 200 | 1,000 | 0 | 1 000 | 3 200 |
| Champinjoner | kg | 6 000 | 1,200 | 1 000 bp | 1 200 × 10 000 / 9 000 = 1 333,3 → **1 333** | **7 998** |
| Vispgrädde | l | 4 500 | 0,800 | 0 | 800 | 3 600 |
| Parmesan | kg | 28 000 | 0,250 | 400 bp | 250 × 10 000 / 9 600 = 260,4 → **260** | **7 280** |
| Gul lök | kg | 1 500 | 0,400 | 1 200 bp | 400 × 10 000 / 8 800 = 454,5 → **455** | 682,5 → **683** |
| Vitlökssmör (F-RS-1) | kg | 10 840 | 0,100 | 0 | 100 | **1 084** |

- Batch cost = 3 200 + 7 998 + 3 600 + 7 280 + 683 + 1 084 = **23 845**.
- Portion cost = 23 845 × 1000 / 10 000 = 2 384,5 → **2 385**.
- Net menu price = 18 900 / 1,12 = **16 875** (exact). The article stores 16 875 excl. VAT.
- Contribution = 16 875 − 2 385 = **14 490**; margin = 14 490 / 16 875 = 0,85867 → **8 587 bp**.
- Food cost % = 2 385 / 16 875 = 0,14133 → **1 413 bp**.

### F-RS-3 Variance for Champinjoner, week 2026-11-02 – 2026-11-08

- Opening count 2026-11-01: 6 000 milli. Receipts in the week: 20 000 milli.
- Till: Pasta med svamp sold 140, returned 2 → net **138** portions (138 000 milli).
- Theoretical = 1 333 × 138 000 / 10 000 = 18 395,4 → **18 395** milli.
- Waste registered (reason UTGANGET): **1 500** milli. Staff meals: 6 portions of the dish →
  1 333 × 6 000 / 10 000 = 799,8 → **800** milli.
- Closing count 2026-11-08: 4 800 milli.
- Actual usage = 6 000 + 20 000 − 4 800 = **21 200**. Explained = 18 395 + 1 500 + 800 = **20 695**.
- Unexplained variance = **505** milli = 0,505 kg; value at 6 000 öre/kg = **3 030** öre;
  share of theoretical = 505 / 18 395 = 0,02745 → **275 bp**.
- Waste value = 1 500 × 6 000 / 1000 = **9 000** öre.

### F-RS-4 Staff meals, November 2026 (payroll inputs only; no VAT asserted)

- Employee A: 18 lunches, paid 0 → `KOSTFORMAN_LUNCH` quantity 18; benefit 18 × 124 = **2 232 kr**.
- Employee B: 10 lunches, paid 60 kr each → benefit (124 − 60) × 10 = **640 kr** (IL 61:4).
- Employee C: 5 lunches, paid 130 kr each → benefit **0** (payment exceeds the value; no
  negative benefit).
- Raw-material cost of the 33 meals if all were Pasta med svamp: 33 × 2 385 = **78 705** öre.
  This is underlag for L7, **not** a VAT base.

### F-RS-5 Negative tests

- Loss 10 000 bp → refused. Yield 0 → refused. A recipe that uses itself through a sub-recipe → refused.
- A sale row of a dish article with no active recipe on the sale date → counted under
  "Sålt utan recept", never guessed.
- A waste entry with a date in a locked period → allowed (no voucher), but the variance report
  of a closed count period shows it as "registrerad efter inventeringen".

## 8. Where NONOX must not go further than the sources

- Do not book a VAT uttag for free staff meals or donations (RS-R11, RS-R13) until L6 and L7
  are answered.
- Do not apply the 97 % rule automatically (RS-R5) or a fixed value (RS-R6).
- Do not include labour in the value of prepared food (RS-R8) until L4 is answered; show that
  it is excluded.
- Do not call a recipe's cost the stock valuation (RS-R4).

## 9. Open questions for a human (lawyer or authorised accountant)

- **L1** Wine, beer and spirits served at the table: Råvaror (1410/4910) or Handelsvaror
  (1460/4960) in a K2 restaurant? Is the choice free if applied consistently?
- **L2** Can a restaurant's dry goods and spices meet "underordnad betydelse" in ÅRL 4:12, and
  does BFN accept counting only fresh goods?
- **L3** The final number and date of the BFNAR amending K1 punkt 6.47 (half a prisbasbelopp),
  and from which fiscal year it applies.
- **L4** Prepared components in the fridge at the balance day: must direct labour be included
  under K2 12.10, or is ingredient cost acceptable under materiality (K2 kapitel 2)?
- **L5** Is a kitchen waste log räkenskapsinformation under BFL 1:2 p. 9 c (seven-year
  retention), or only internal control data?
- **L6** Confirm Skatteverket's position on the tax base for donated near-expiry food from its
  own rättsliga vägledning (read here only through LR 2026), and whether it covers donation to
  staff.
- **L7** VAT on free staff meals: how is "kostnaden … som hör till tjänsterna" (ML 8:6)
  computed in practice for a restaurant; does Skatteverket accept a simplified method (for
  example the kostförmån value); and is a staff price at or above ingredient cost but below
  full cost commercially justified under ML 8:17 p. 4 in light of RÅ 2010 ref. 76?
- **L8** Own food served at the restaurant's own staff party: uttag, deduction limit, or
  neither?
- **L9** Income tax value of a sole trader's own meals from the restaurant (IL 22 kap.), and
  whether Skatteverket publishes a schablon for it.
- **L10** Staff meals paid by salary deduction: is that "kontant betalning" under KRL 2 § so
  that the meal must be registered in the till?
- **L11** Legal basis in the GDPR register for the staff-meal log (employees and, in an EF, the
  owner), and its retention (payroll's or the stock ledger's).
