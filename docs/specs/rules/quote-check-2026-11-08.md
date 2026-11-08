# Quote check of four rule sheets, 2026-11-08

Independent check of the rule sheets for ADR-072 (korjournal), ADR-073 (recept-och-svinn), ADR-074 (arbetsorder-och-ata) and ADR-075 (kundportal). Read cold from the four branches; the sheets were not edited.

## How the check was done

- Law texts were downloaded as consolidated text from `data.riksdagen.se/dokument/sfs-<yr>-<nr>.text` and compared paragraph by paragraph: IL (t.o.m. SFS 2026:1393), SFL (t.o.m. 2026:1305), SAL, ML (t.o.m. 2026:1025), BFL (t.o.m. 2024:342), ÅRL, KRL (t.o.m. 2011:796), KtjL (t.o.m. 2010:1006), HFL (t.o.m. 2025:324), DAL (t.o.m. 2026:1018). Transitional provisions were read for SFS 2025:322, 2025:324, 2026:118, 2026:119 and 2026:464.
- BFN PDFs (K2 `vl16-10-k2ar-kons2025.pdf`, K3 `vl12-1-k3-kons20251215.pdf`, K1 `vagledningen-k1.pdf`), BAS 2026 v 1.1, the IMY red-green list PDF, the Byggföretagen HF17 fill-in guide and ROT checklist, and the government's lagrådsremiss of 2 July 2026 were converted to text and string-matched.
- GDPR text was fetched from EUR-Lex and string-matched.
- Skatteverket's servers reset direct HTTP connections, so Skatteverket pages (Belopp och procent 2026, Traktamente och bilersättning, Kostförmån, Körjournal, Drivmedelsförmån, AG field codes, Resor i näringsverksamheten, Bilar och moms, Representation, Så fungerar rotavdraget, Rot och rut, Omvänd betalningsskyldighet) were read through a fetch tool that returns a model-written answer to a prompt. Quotes from those pages are therefore as the tool returned them. Where the tool paraphrased, the verdict says so.
- The prisbasbelopp for 2026 (59 200 kr) was confirmed on regeringen.se and on Skatteverket's page. The 2027 amount (59 600 kr) was confirmed by a search result pointing at regeringen.se's press release of September 2026; I did not open the SFS text.
- All arithmetic in the fixtures (mileage, recipe costing, variance, kostförmån, ROT, year-end proposals, thresholds) was recomputed and is correct.

Verdicts: CONFIRMED, WRONG (with the correct text and source) or UNVERIFIABLE (with why).

## WRONG findings (all sheets)

| # | Sheet | Rule | Finding |
|---|---|---|---|
| W1 | arbetsorder-och-ata | AO-R23 and the source table ("13 kap. 37 §") | **ML 13 kap. has no 37 §.** The chapter ends at 36 § (https://data.riksdagen.se/dokument/sfs-2023-200.text). The buyer side of reverse-charge construction services is not found in 13 kap. at all. 13 kap. 20-23 §§ is about cars and 24-25 §§ about representation. The reporting period for a buyer who is liable for the VAT follows 7 kap. 14 § p. 2 (and 7 kap. 24 § covers the supplier of construction services). I could not identify which provision the researcher meant; the reference must be removed or replaced after a human finds the intended rule. |
| W2 | korjournal | KJ-R13 (quote of SKV-KJ) | The sheet quotes "Båda villkoren ska vara uppfyllda". The Skatteverket körjournal page says "Båda förutsättningarna ska vara uppfyllda" (two reads of the page by the fetch tool agree that "villkoren" does not appear). The substance is unchanged, both conditions must hold. The Skatteverket sentence is "inte har använt företagets bil privat mer än 100 mil eller vid högst 10 tillfällen under ett år". The sheet's own prose ("not more than 100 mil or on more than 10 occasions") is easy to misread as an either-or; the fixtures F8b/F8c correctly treat both as required. Fix the quote and the prose before it is copied into code comments. |
| W3 | korjournal | Source table row ML: "13 kap. 20-23 §§, the 25 § on deferred reporting of uttag" | The deferral of output VAT for private use of a business car is **ML 7 kap. 25 §**, not a paragraph in 13 kap. 13 kap. 25 § is the representation exception (a different rule). Cite as "7 kap. 25 §". The rule itself (KJ-R18) is not affected. |

Not wrong but worth a correction before building (details in the tables): AO-R14 omits the exception in HFL 9 § p. 4 (hours are not required for the services in IL 67 kap. 13 § p. 12-13); ADR-075 B4 attributes the "within one month" to recital 59 while art. 12.3 itself says it.

---

## Sheet 1: korjournal.md (branch docs/adr-072-mileage-log)

| Rule | Verdict | Notes and source |
|---|---|---|
| KJ-R1 | CONFIRMED | IL 12 kap. 5 § första stycket: "Utgifter för tjänsteresor med egen bil ska dras av med 2 kronor och 50 öre för varje kilometer." Skatteverket: "Egen bil: 25 kronor per mil" (Belopp och procent 2026 and Traktamente och bilersättning). The claim that the 2027 note in rules.json is wrong is right: 12:5 has no end date in the consolidated text. |
| KJ-R2 | CONFIRMED | IL 12:5 andra stycket verbatim: 95 öre elbil, 1 krona och 20 öre annat drivmedel, only if the employee "betalar samtliga utgifter för drivmedel". Skatteverket: förmånsbil 12 kr/mil, helt el 9,50 kr/mil, laddhybrid the higher amount (12 kr/mil). |
| KJ-R3 | CONFIRMED | Skatteverket: "bara betala ut skattefri bilersättning enligt schablon om den anställda betalat allt drivmedel själv"; "Om din anställda bara delvis har betalat bilens drivmedel kan du inte betala ut skattefri bilersättning"; "du inte kan betala ut skattefri ersättning ... för hens faktiska kostnader för drivmedel". The final quote "eventuell ersättning är lön" was not returned by the fetch tool: UNVERIFIABLE for that fragment only. |
| KJ-R4 | CONFIRMED | IL 12:5 tredje stycket (Lag 2026:464) quoted exactly. Transitional rule: in force 1 July 2026, "tillämpas första gången på utgifter för drivmedel som är förenade med tjänsteresor efter ikraftträdandet" (quoted correctly). Skatteverket confirms the 1 July 2026 laddhybrid rule. The `>= 2026-07-01` choice is correctly flagged as a legal question (L4). |
| KJ-R5 | CONFIRMED | Skatteverket: "Om du betalar ut traktamente eller bilersättning som överstiger schablonbeloppen ska den överskjutande delen av ersättningen hanteras som lön." AG field 050: "Har du betalat ut högre bilersättning än schablonbeloppet ska du redovisa den överskjutande delen som lön." SFL 10 kap. 3 § andra stycket p. 9 and SAL 2 kap. 11 § p. 1 exist with the wording described (SAL 2:11 p. 1 refers to "10 kap. 3 § andra stycket 8 och 9"). The AG field 011 sentence is a paraphrase by the fetch tool (UNVERIFIABLE as a verbatim quote, substance consistent). |
| KJ-R6 | CONFIRMED | IL 11 kap. 1 § first sentence (quoted with ellipsis) and 12 kap. 26 § "resor mellan bostaden och arbetsplatsen (arbetsresor)" are correct. Skatteverket's drivmedelsförmån page lists "resor till och från arbetet" as private trips. |
| KJ-R7 | CONFIRMED | IL 12 kap. 8 § första och andra stycket quoted verbatim ("Tjänstestället är den plats där den skattskyldige utför huvuddelen av sitt arbete" ... "anses bostaden som tjänsteställe"). |
| KJ-R8 | CONFIRMED | Skatteverket: "Vid milersättning för egen bil ska antal körda kilometer framgå", and a körjournal from the employee as a source. SFL 39 kap. 3 §: "Den som är uppgiftsskyldig enligt 15-35 kap. ska i skälig omfattning genom räkenskaper, anteckningar eller annan lämplig dokumentation se till att det finns underlag för att fullgöra uppgiftsskyldigheten samt för kontroll av uppgiftsskyldigheten och beskattningen" (the sheet's ellipsis form is accurate). |
| KJ-R9 | CONFIRMED | Skatteverket körjournal page: odometer at start and end of year, the "bör" list, "behövs inte vid privata resor", electronic journal not "extrautrustning som höjer bilförmånsvärdet", must be completed with customers visited. The sheet's own statement that no law text makes a körjournal mandatory is consistent with the texts I read (no such provision in IL 61 or SFL 39). |
| KJ-R10 | CONFIRMED | AG field 050: "privat bil inklusive förmånsbil där den anställda själv betalar drivmedel". Skatteverket: up to the schablon amounts reported only by ticking 050 (and 051 for traktamente). |
| KJ-R11 | CONFIRMED | IL 61 kap. 10 § quoted verbatim, including "marknadsvärdet multiplicerat med 1,2" and the "gör sannolikt" reduction. Drivmedelsförmån page: "multiplicera marknadsvärdet med 1,2 för den mängd drivmedel som har använts vid privat körning", contributions "utan att multiplicera det med 1,2", market value "inklusive moms". |
| KJ-R12 | CONFIRMED | IL 61 kap. 9 §: "För den som kört minst 3 000 mil i tjänsten med förmånsbilen under ett kalenderår ska förmånsvärdet beräknas till 75 procent". |
| KJ-R13 | WRONG (minor, see W2) | IL 61 kap. 11 § andra stycket quoted verbatim ("Om den skattskyldige haft bilförmån bara i ringa omfattning ska förmånen värderas till noll."). The 100 mil / 10 occasions figures are Skatteverket guidance and are described as such. Quote wording must be "förutsättningarna", not "villkoren". |
| KJ-R14 | CONFIRMED (inference) | IL 61 kap. 5 § and 11 kap. 1 § exist as cited. The rule is an NONOX advice choice, no factual claim to check. |
| KJ-R15 | CONFIRMED | IL 16 kap. 27 § första stycket quoted verbatim, including "Detta gäller dock inte om bilen är en tillgång i näringsverksamheten". Skatteverket: "Om du använder din egen bil får du avdrag med 25 kronor per mil", förmånsbil 12 / 9,50, "Du bokför löpande dina utgifter ...", unbooked deduction "R16 eller R22" on the NE-bilaga (the page says both; the sheet's open question L8 is the right place to leave it). BAS 2026 has 2018 "Övriga egna insättningar" and 5800 "Resekostnader (gruppkonto)"; note that 5800 is a group account, the postable accounts are 5810-5890. |
| KJ-R16 | CONFIRMED | IL 16:27 andra stycket exists with the HB-partner rule (1,20 / 0,95 kr/km). |
| KJ-R17 | CONFIRMED | ML 13 kap. 23 § quoted verbatim ("får dra av ingående skatt som hänför sig till driftskostnader, om fordonet tillhör inventarierna i verksamheten eller har hyrts för användning i denna"). The sheet's honest statement that no provision gives input VAT on a mileage allowance is consistent with ML 13 kap. as read. |
| KJ-R18 | CONFIRMED (W3 for the source table) | ML 5 kap. 31 § exists (uttag av tjänster, personbil/motorcykel). ML 8 kap. 7 § refers to SAL 2 kap. 10 a and 10 b §§ for the base, as stated. Skatteverket Bilar och moms: "Du kan däremot få avdrag för moms på drivmedel och andra driftskostnader" and "För personbilar ska du redovisa utgående moms med 20 procent av bilförmånsvärdet". |
| KJ-R19 | CONFIRMED | The IMY "Använda platstjänster" table (PDF) contains all four quoted bullets verbatim, "Okej" and "Inte okej" sides correctly assigned. |
| KJ-R20 | CONFIRMED | BFL 7 kap. 2 § "fram till och med det sjunde året efter utgången av det kalenderår då räkenskapsåret avslutades"; BFL 1 kap. 2 § p. 9; 5 kap. 6-7 §§. |
| KJ-R21 | CONFIRMED | BFL 5 kap. 6 § ("För varje affärshändelse ska det finnas en verifikation") and 5 kap. 7 § (date compiled, date of event, what, amount, counterparty). |
| KJ-R22 | CONFIRMED (as a gap) | The sheet says BFL 7 kap. 6 § was not read and forbids telling users to discard paper. I did not check the content of 7:6 either, as the sheet makes no claim about it. |
| Fixtures F1-F16 | CONFIRMED | Recomputed: F1 43 125 öre, F2 51 750 / 8 625, F3 30 000, F4 23 750, F6 180 000, F7 310 000, F9 45 000 / 54 000, F10 12 000, F11 3 125 and 1 187,5 rounded to 1 188 (half-up), F12 9 600 + 2 400 = 12 000. All correct. F8a: 30 400 km is above 3 000 mil, F8b 95 mil on 9 occasions is within both limits. |

---

## Sheet 2: recept-och-svinn.md (branch docs/adr-073-recipes-and-waste)

| Rule | Verdict | Notes and source |
|---|---|---|
| Corrections block (K2 chapter 12, not 11; BAS 4010/4310 names) | CONFIRMED | K2 chapter 11 is "Finansiella anläggningstillgångar" (p. 220), chapter 12 "Varulager" (p. 232). BAS 2026: 4010 "Inköp av handelsvaror i Sverige", 4310 "Inköp av råvaror och material i Sverige". K2 12.10 does require wages and employer contributions in the cost of own-manufactured goods. |
| RS-R1 | CONFIRMED | K2 12.2 and the commentary sentence "Punkt 12.2 c innebär att råvaror, halvfabrikat och förnödenheter anses vara varulager" verbatim. The two chapter-4 quotes are present word for word. BAS 2026: 1410 Lager av råvaror, 4910 Förändring av lager av råvaror, 1460 Lager av handelsvaror, 4960 Förändring av lager av handelsvaror. |
| RS-R2 | CONFIRMED | ÅRL 4 kap. 9 § första stycket (the sheet inserts a comma after "14 e §" that is not in the law; harmless). K2 12.3, 12.4 andra stycket and the "kvittas" commentary verbatim. |
| RS-R3 | CONFIRMED | ÅRL 4:9 tredje stycket verbatim. K2 12.14 and 12.16 verbatim (12.16 is cut off by a page break in the PDF, the quoted part is right). Inkurans sentence present in the commentary. |
| RS-R4 | CONFIRMED | ÅRL 4:3 andra stycket and 4:11 verbatim. K2 12.7: "frakt, ... importavgifter, ... tull. Avdrag ska göras för varurabatter, bonus och andra liknande prisavdrag". |
| RS-R5 | CONFIRMED | K2 12.5 and its commentary, and the 12.18 commentary ("fysiskt ha avskilts från lagret med avsikt att slängas"; "anses det som om en inkuransbedömning gjorts"; "97-procentsregeln inte får användas för några varor i lagret") verbatim. IL 17 kap. 4 § verbatim. |
| RS-R6 | CONFIRMED | ÅRL 4 kap. 12 § verbatim; K2 12.6 plus/minus 20 % verbatim. |
| RS-R7 | CONFIRMED | IL 17 kap. 4 a § verbatim ("högst ett halvt prisbasbelopp", Lag 2024:1131). Half of 59 200 = 29 600 kr. The K1 file read is a change-marked draft ("BFNAR 2025:X", the old text "5 000 kr" struck for "ett halvt prisbasbelopp") in points 6.47 and 6.48, exactly as the sheet says; L3 is the right open question. |
| RS-R8 | CONFIRMED | K2 12.10, 12.12, 12.13 verbatim (12.10 b: "utgifter för lön till och arbetsgivaravgifter för anställda och utgifter för inhyrd personal"). ÅRL 4:3 tredje stycket permits a share of indirect costs, K2 12.12 forbids them, as the sheet implies. |
| RS-R9 | CONFIRMED | K2 12.18 "Kostnad för kasserade varor ska redovisas i den period varorna kasseras." Both chapter-4 quotes (Råvaror och förnödenheter: "t.ex. svinn och kassationer"; Handelsvaror: "Även inkurans och svinn redovisas i posten") present. BAS has no waste account in groups 40-49 (confirmed by reading groups 4900-4970). |
| RS-R10 | CONFIRMED | BFL 1 kap. 2 § p. 7, 8, 9 c; 5 kap. 6 §; 6 kap. 8 §; 7 kap. 2 § all verbatim. The "omvärlden" reading is the plain text; no authority applies it to waste (the sheet says this). |
| RS-R11 | CONFIRMED | ML 5:8, 5:9 (both stycken), 8:5 verbatim. The lagrådsremiss of 2 July 2026 contains the quoted Skatteverket sentence under 5.1.2 and "Lagändringarna föreslås träda i kraft den 1 januari 2028". It is a proposal; I did not check whether a proposition has followed since. |
| RS-R12 | CONFIRMED | IL 61:3 (both stycken) and 61:4 verbatim. Recomputed: 0,52 % of 59 200 = 307,84 rounded to 310; 40 % = 124; 20 % = 62. Skatteverket kostförmån: 310 / 124 / 62 and "Det saknar i princip betydelse om anställda får fri kost på den ordinarie arbetsplatsen"; AG field 012 for other taxable benefits (the page says "ruta 012", the sheet writes "box 012"; correct). The internal representation sentence was returned truncated ("under förutsättning att sammankomsten"): the substance is right. |
| RS-R13 | CONFIRMED | ML 5:28, 5:29, 8:6, 8:17 (the sheet's quote starts "... utgörs beskattningsunderlaget av marknadsvärdet", the law has "I de fall som avses i 2 och 11 §§" before it), 8:19. RÅ 2010 ref. 76 via lagen.nu: "beskattningsunderlaget ska enligt huvudregeln bestämmas till den ersättning som bolaget erhåller från de anställda"; the Court held the staff price market-based because it covered the company's costs. |
| RS-R14 | CONFIRMED | ML 9:5 (12 % restaurant, spirits, wine, strong beer excluded), 9:19 (6 % food, in force 1 April 2026, Lag 2026:118, header "Upphör att gälla U:2028-01-01"), 9:3 (12 % from 2028-01-01, Lag 2026:119) and the transitional provisions ("träder i kraft den 1 april 2026", "den 1 januari 2028") all verbatim. 6 % period is therefore 2026-04-01 to 2027-12-31. |
| RS-R15 | CONFIRMED | Skatteverket representation page: "på ett underlag på högst 300 kronor exklusive moms per person och tillfälle"; "Personalfest är en intern representation." Note that ML 13 kap. 25 § p. 3 words the cap as "den ingående skatten på 300 kronor per person och tillfälle", which Skatteverket reads as a base of 300 kr. |
| RS-R16 | CONFIRMED | BFL 1:2 p. 7 ("egna tillskott till och uttag ur verksamheten av pengar, varor eller annat"); ML 5:9 p. 1 and 8:5. BAS 2026 3401-3404 "Egna uttag momspliktiga 25/12/6 %, momsfria". |
| RS-R17 | CONFIRMED | KRL 2 §, 6 §, 9 § and BFL 5 kap. 6 § tredje stycket verbatim. The riksdagen text is "t.o.m. SFS 2011:796" as stated. |
| RS-R18 | CONFIRMED | Skatteverket: taxable meal benefits go in ruta 012. The GDPR statements are conclusions, not quotes. |
| RS-C1 to C8, F-RS-1 to F-RS-5 | CONFIRMED | Recomputed all fixtures with half-up rounding: 188 gross for garlic and 940 öre; 10 840 batch; 7 998; 7 280; 683; 1 084; batch 23 845; portion 2 385 (2 384,5 up); net price 16 875; contribution 14 490; margin 8 587 bp; food cost 1 413 bp; theoretical 18 395; unexplained 505 milli = 3 030 öre = 275 bp; waste 9 000 öre; staff meals 2 232 / 640 / 0 kr and 78 705 öre. All correct. |

---

## Sheet 3: arbetsorder-och-ata.md (branch docs/adr-074-work-orders-and-ata)

| Rule | Verdict | Notes and source |
|---|---|---|
| AO-R1 | CONFIRMED | KtjL 1 § (items 1-3, småhusentreprenader in 51-61 §§) and 1 a § ("en fysisk person som handlar huvudsakligen för ändamål som faller utanför näringsverksamhet") verbatim. |
| AO-R2 | CONFIRMED | KtjL 3 § verbatim. |
| AO-R3 | CONFIRMED | KtjL 8 § första stycket: "skall näringsidkaren underrätta konsumenten och begära dennes anvisningar." The law has no form requirement. HF17 guide: "Det är viktigt att ni skriver ned vad som ska gälla beträffande omfattning, tid och pris." |
| AO-R4 | CONFIRMED | KtjL 8 § andra och tredje stycket paraphrased correctly (obetydligt / lågt i förhållande till priset; särskilda skäl; cannot be postponed without danger of serious damage). |
| AO-R5 | CONFIRMED | KtjL 38 § p. 1 and 2 and the pointer to 36 § for the size. |
| AO-R6 | CONFIRMED | KtjL 36 § andra stycket verbatim ("inte överskridas med mer än 15 procent, såvida inte någon annan prisgräns har avtalats eller näringsidkaren har rätt till pristillägg enligt 38 §"). HF17 guide: "Ungefärlig prisuppgift får normalt överskridas med maximalt 15 procent och för mark- och rivningsarbeten gäller 25 procent." |
| AO-R7 | CONFIRMED | KtjL 6 § andra och tredje stycket (price may become "betydligt högre"; business must inform and ask, otherwise stop) and 7 §. |
| AO-R8 | CONFIRMED | KtjL 40 § and 41 § andra stycket. |
| AO-R9 | CONFIRMED | KtjL 41 § första stycket and 45 § (right to suspend if agreed advance payment is not made). HF17 guide section H: "Förskottsbetalning kräver särskild överenskommelse och kan föras in i en betalningsplan." |
| AO-R10 | CONFIRMED | DAL 1 kap. 2 § (definition of avtal utanför affärslokaler), 2 kap. 1 a § p. 2 (construction of a building) and andra stycket (under 400 kr), 2 kap. 5 § (document and copy/confirmation), 2 kap. 10 § (14 days), 11 § p. 13 and 14. All as described. |
| AO-R11 | CONFIRMED | KtjL 1 § andra stycket, 51-61 §§; 53 § is slutbesiktning. |
| AO-R12 | CONFIRMED | IL 67 kap. 19 §: 50 % for the work in 13 §, 30 % for other household work, "högst 75 000 kronor", ROT (13 a, 13 b) "högst 50 000 kronor"; Lag 2025:322 in force 1 January 2026, applies to work paid after 31 Dec 2025. HFL 7 §: payout at most the amount the buyer paid for 67:13 work (RUT) and "tre sjundedelar av det belopp som köparen har betalat" for other work (ROT), plus the same ceilings for the preliminary reduction. Skatteverket: ROT "30 procent", RUT "50 procent", "högst 75 000 kronor" combined. |
| AO-R13 | CONFIRMED | IL 67:18 andra stycket: "I underlaget räknas inte utgifter för material, utrustning och resor in." 11 a § (charged amount incl. VAT). Skatteverket: separate listing "eftersom rotavdraget endast gäller för arbetskostnaden". The ROT checklist (text-converted PDF): "Om ni inte tar betalt för resor till och från kund anser Skatteverket att dessa ingår i arbetskostnaden, och kan då betala ut ett lägre belopp än du ansökt om." |
| AO-R14 | CONFIRMED with caveat | HFL 9 § p. 3 and 4 (Lag 2020:1069): "vad hushållsarbetet avsett och antalet arbetade timmar". **Caveat:** p. 4 contains an exception "utom i fråga om sådant arbete som avses i 67 kap. 13 § 12 och 13" (laundry at a laundry plant and transport to reuse); the sheet states the rule without it. Not relevant for ROT. Skatteverket and the ROT checklist ("Notera antalet arbetstimmar även vid arbete mot fast pris") confirm the fixed-price case. |
| AO-R15 | CONFIRMED | Skatteverket lists personnummer, fastighetsbeteckning or association org. number and four-digit apartment number, kind of work and F-skatt statement. HFL 9 a § requires the property or apartment designation (and the association's org. number) in the claim. The sheet's remark that the RUT page uses "bör" could not be reproduced from the general "Rot och rut" page (no such wording there); UNVERIFIABLE for that sub-claim only. |
| AO-R16 | CONFIRMED | Skatteverket: "Dra av rotavdraget på varje enskild faktura om din kund vill betala i förskott, med a conto eller delbetala."; applications must arrive by 31 January the year after payment; if paid in advance before year-end the work must be finished by 31 January. HFL 8 §: "efter det att hushållsarbetet har utförts och betalats" and "senast den 31 januari året efter det beskattningsår då arbetet betalades". Note that the "work finished by 31 January" condition comes only from Skatteverket's page, not from HFL 8 (the sheet says so and flags L7). |
| AO-R17 | CONFIRMED | IL 67:13 a-13 c: 13 c p. 2 excludes work for which "försäkringsersättning lämnats". |
| AO-R18 | CONFIRMED | IL 67 kap. 15 a §: payment must be "förmedlats av en betaltjänstleverantör enligt lagen (2010:751)" with "avsändare, mottagare, belopp och tidpunkt". Skatteverket: cash, cheque and gift cards not accepted. |
| AO-R19 | CONFIRMED | IL 67 kap. 15 § andra stycket (a physical person doing the work may not be the buyer or a close relative); 16 § p. 1 (F-skatt "när avtalet om arbetet träffas eller när ersättningen betalas ut"). Skatteverket: "Rotavdrag gäller inte när du utför arbeten åt familj eller släkt." |
| AO-R20 | CONFIRMED | ML 16 kap. 13 § (buyer who "inte bara tillfälligt" provides such services, or resells to such a person; list of services including byggstädning and personal hire). Skatteverket: "Om det i en byggtjänst ingår material så räknas allt som en byggtjänst"; seller to private persons charges VAT; "fråga köparen". |
| AO-R21 | CONFIRMED | ML 17 kap. 24 § p. 4 (buyer's VAT number) and p. 14 ("uppgiften omvänd betalningsskyldighet"). |
| AO-R22 | CONFIRMED | ML 17 kap. 16 § verbatim; 7 kap. 8 § (taxable event is invoice issuance, otherwise 4 or 7 §); 17 kap. 11 § p. 3 (construction services to a private person). F6 deadline recomputed: March work, invoice by 31 May. |
| AO-R23 | WRONG (see W1) | ML 7 kap. 24 § is quoted accurately in substance (invoice period; a conto without invoice reported when received; at latest the period covering the second calendar month after performance) and 7 kap. 16 § is the cash-method exception (turnover up to 3 million kr). ML 17 kap. 14 § (invoice for advance/a conto payment) correct. **"Buyer side: ML 13 kap. 37 §" does not exist.** |
| AO-R24 | CONFIRMED | ML 17 kap. 24 § p. 8: date of the advance/a conto payment when it differs from the invoice date. |
| Section D (ÄTA content table) | CONFIRMED | References to BFL 5:7, KtjL 8/36/38 and IL 67:18 are correct. No statute prescribes the content, as the sheet states. |
| AO-R25 | CONFIRMED | BFL 1 kap. 2 § p. 9 b: "avtal och andra handlingar av särskild betydelse för att belysa verksamhetens ekonomiska förhållanden"; 5 kap. 7 § (upplysning om handlingar som legat till grund); 7 kap. 2 § seven years. |
| AO-R26 | CONFIRMED | SFL 39 kap. 11 a § (including the sentence on persons who only briefly load or unload). |
| AO-R27 | CONFIRMED | SFL 39 kap. 11 b § ("mer än fyra prisbasbelopp"; natural person not in business excepted), 11 c § (written agreement moves duties), 7 kap. 2 a § (registration before work starts). Four times 59 200 = 236 800 kr. The 2027 amount 59 600 kr is confirmed by the government press release of September 2026 (found by search; SFS text not opened). |
| AO-R28 | CONFIRMED | SFL 39 kap. 12 § andra stycket. |
| AO-R29 | UNVERIFIABLE | Statements about NONOX's own staff ledger and ADR-022 are internal; the Skatteverket XML schema v1.1 was not checked. |
| AO-R30 | CONFIRMED | K2 6.10 verbatim ("uteslutande eller så gott som uteslutande ..."), the kvadratmeter example in the 6.10 commentary, 6.13 and its commentary ("utförda arbeten som inte är fakturerade på balansdagen ska redovisas som intäkt"), 6.14 table (Upparbetad men ej fakturerad intäkt = asset; Förskott från kunder = liability). K3 23.11, 23.17 and "faktureringstidpunkten inte styr tidpunkten för intäktsredovisning" verbatim. BAS 2026 has 1620 "Upparbetad men ej fakturerad intäkt", 2420 "Förskott från kunder", 2450 "Fakturerad men ej upparbetad intäkt", 1470 "Pågående arbeten": L12 can be answered for these four account numbers. |
| AO-R31 | CONFIRMED | K2 6.12, 6.15 (same method for all; switching from main to alternative rule only with special reasons), 6.16-6.17, 6.19 (loss expensed), 6.22, 6.23 and its commentary (97 % via IL 17 kap. 27 §, "gäller inte för tjänsteuppdrag"), ÅRL 4 kap. 10 § as quoted in K2. IL 17:27 andra stycket verified: construction, civil and craft businesses may value ongoing work at not less than 97 % of cost. |
| AO-R32 | CONFIRMED | K2 6.12 commentary ("Dessa ska i normalfallet ändå räknas in i fastprisuppdraget, se punkt 6.8 tredje stycket"), 6.8 commentary including the electrician example (five flats by the hour plus 20 flats at fixed price are two jobs). K3 23.16 verbatim. |
| AO-R33 | CONFIRMED | K3 23.31 (BFNAR 2012:5) and IL 17 kap. 23 § / 27 §. |
| AO-R34 | UNVERIFIABLE | The sheet itself says K1 is not read. |
| AO-R35, AO-R36 | CONFIRMED (internal) | K2 6.14 and 6.21 placements verified. R36 is an NONOX design choice, open as L14. |
| AO-R37 | UNVERIFIABLE (partly) | The HF17 guide confirms the existence and use of Hantverkarformulär 17; the claim that Konsumentverket, Villaägarna and Byggföretagen publish it, and the scope of AB 04, ABT 06 and ABS 18, was not checked here (the sheet marks it as a search result). |
| AO-R38, AO-R39 | UNVERIFIABLE | Internal description of NONOX storage; no legal source claimed. |
| Fixtures F1-F10 | CONFIRMED | Recomputed: F1 ROT 15 000 kr on 50 000 kr, ÄTA 4 h x 600 = 2 400 ex VAT = 3 000 incl., ROT 900, customer pays 3 600, remaining room 34 100; F3 115 % of 40 000 = 46 000; F7a 7 800 + 2 000 = 9 800; F7b 75 % x 312 000 = 234 000 minus 200 000 = 34 000; F8 four prisbasbelopp = 236 800; F6 31 May. |

---

## Sheet 4: kundportal.md (branch docs/adr-075-customer-portal)

| Rule | Verdict | Notes and source |
|---|---|---|
| A1 | CONFIRMED | GDPR art. 6.1 b: "processing is necessary for the performance of a contract to which the data subject is party" (EUR-Lex text). |
| A2 | CONFIRMED | IMY "Avtal med den registrerade": "Det här gäller bara avtal som den registrerade har ingått eller planerar att ingå." and, for collecting more than the contract needs, "måste ni använda er av en annan rättslig grund". |
| A3 | UNVERIFIABLE | Application of 6.1 b to private persons and 6.1 f to contacts at legal persons is the architect's legal conclusion and is correctly flagged as L1. |
| A4 | CONFIRMED | GDPR art. 6.1 f: "processing is necessary for the purposes of the legitimate interests pursued by the controller or by a third party" (the "documented assessment" is the sheet's addition and is flagged as IMY page not read). |
| A5 | CONFIRMED | GDPR art. 21.1 "on grounds relating to his or her particular situation, at any time". |
| B1 | CONFIRMED | Art. 5.1 c "adequate, relevant and limited to what is necessary in relation to the purposes". |
| B2 | CONFIRMED | Art. 5.1 e "for no longer than is necessary for the purposes". The retention periods are the architect's proposals (L3). |
| B3 | UNVERIFIABLE | Art. 13 is not quoted; flagged L2 by the sheet. |
| B4 | CONFIRMED (attribution) | Art. 12.3 itself says "without undue delay and in any event within one month of receipt of the request". The sheet puts the one month in recital 59 only; it is in the article text too. |
| B5 | CONFIRMED | Art. 15.1 "the right to obtain from the controller confirmation as to whether or not personal data concerning him or her are being processed". |
| B6 | CONFIRMED | Art. 20.1 consent (6.1 a) or contract (6.1 b) basis and automated processing. |
| B7 | CONFIRMED | Art. 17.3 b "for compliance with a legal obligation which requires processing" and e "for the establishment, exercise or defence of legal claims". |
| B8 | CONFIRMED | Art. 25.2 "the amount of personal data collected, the extent of their processing, the period of their storage and their accessibility". |
| C1 | CONFIRMED | Art. 32.1 a-d: "the pseudonymisation and encryption of personal data", "a process for regularly testing, assessing and evaluating ...". |
| C2 | CONFIRMED | Art. 28.1 "sufficient guarantees". |
| C3 | CONFIRMED | Art. 33.1 "without undue delay and, where feasible, not later than 72 hours"; art. 34.1 communication to data subjects at high risk. |
| C4 | UNVERIFIABLE | Internal rule (ADR-046, ADR-075 §8). |
| D1, D2 | CONFIRMED (as gaps) | The sheet says PSD2 and lag (2010:751) were not read and builds nothing on them. I did not read them either. IL 67:15 a § (for ROT, on sheet 3) does cite lag (2010:751). |
| E1 | CONFIRMED | ML 17 kap. 30-31 §§ exist and concern keeping a copy of the invoice and securing authenticity, integrity and legibility; BFL 5 kap. 6 § exists. Whether the exact issued PDF must be shown is L6 and stays open. |
| E2-E5 | CONFIRMED (as gaps) | Honest "not read" items; no claim to check. |
| Statements taken from `docs/gdpr/lagringstider.md` and ADR-046 | UNVERIFIABLE | Internal documents, not checked against the law here. |

---

## Suggested follow-ups for the sheet authors

1. Sheet 3: delete or replace "ML 13 kap. 37 §" (W1).
2. Sheet 1: correct the SKV-KJ quote and tighten the "or/both" prose (W2); cite ML 7 kap. 25 § for the uttag deferral (W3).
3. Sheet 3: add the HFL 9 § p. 4 exception to AO-R14 (not needed for ROT, but the rule as written is broader than the law).
4. Sheet 4: cite art. 12.3 alone for the one-month limit.
5. Sheet 3 L12 can be partly closed: the four account numbers exist in BAS 2026 with the names the sheet needs.
6. Skatteverket quotes in all four sheets were read through a summarising fetch tool; a human spot check of the few verbatim quotes marked above as paraphrased is cheap.
