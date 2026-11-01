# Frågor till jurist

Senast uppdaterad 2026-11-01. Det här är alla öppna juridiska frågor om NONOX, samlade på ett ställe. Del A gäller dataskydd, AI, säkerhet och avtal och är numrerad i prioritetsordning. Del B samlar de frågor om bokföring, moms, skatt och lön som står som `TODO(legal-verify)` i koden och dokumentationen, med filreferenser.

Svaren förs in i respektive dokument eller ADR, och frågan stryks här. Öppna beslut följs också upp som ärenden på GitHub.

## Del A. Dataskydd, AI, säkerhet och avtal

### Incidenter och säkerhetskopior

**1. Äldre säkerhetskopior hos en molnlagringstjänst.**
*Bakgrund:* Före den nuvarande lösningen kan säkerhetskopior ha laddats upp till en molnlagringstjänst utanför tjänstens server. Det är inte utrett vilka kopior som finns kvar, om de innehåller kunduppgifter, eller om de omfattas av underbiträdesavtal.
*Fråga:* Ska förekomsten dokumenteras som en personuppgiftsincident i incidentloggen (artikel 33.5) även om ingen obehörig fått åtkomst? Ska den anmälas, och ska kunderna underrättas? Hur ska kopiorna raderas och raderingen dokumenteras?
*Varför det spelar roll:* Kopior på en plats som inte står på underbiträdeslistan strider mot det vi lovat kunderna (PUB-avtalet punkt 4–5) och mot lagringstiden på 7 dagar.

**2. Ansvarig för incidenter.**
*Bakgrund:* Incidentrutinen saknar namn och kontaktväg (`docs/gdpr/incidentrutin.md`). SECURITY.md anger ägarens adress för säkerhetsrapporter.
*Fråga:* Vem ska stå som incidentansvarig, och vilken kanal ska användas till kunderna inom PUB-avtalets 36 timmar?
*Varför:* Fristen på 72 timmar till IMY (artikel 33.1) förutsätter att någon äger frågan.

### Roller och underbiträden

**3. Rollkedjan för AI-leverantören när kundens egen nyckel används.**
*Bakgrund:* NONOX AI skickar underlag till OpenAI, Anthropic eller Google med kundens egen API-nyckel och under kundens eget avtal med leverantören. Stafe har inget avtal med AI-leverantören för det (ADR-018, PUB-avtalet punkt 6).
*Fråga:* Är AI-leverantören kundens eget biträde (artikel 28) och Stafe bara en teknisk förmedlare, eller är leverantören Stafes underbiträde? Räcker leverantörens standardvillkor (DPA, DPF eller standardavtalsklausuler) för överföringen till tredje land (artikel 44 och följande), och vem ska göra överföringsbedömningen?
*Varför:* Det avgör om AI-leverantörerna ska stå på underbiträdeslistan med 30 dagars förvarning, och vem som ansvarar om något går fel.

**4. Laravel Forge och övriga underbiträden.**
*Bakgrund:* Forge (USA) har teknisk åtkomst till servern. Underbiträdeslistan anger DPF som grund.
*Fråga:* Är Laravel Holdings certifierat under EU–US Data Privacy Framework, och finns ett personuppgiftsbiträdesavtal (DPA) med Forge, Vultr och Mailgun som uppfyller artikel 28.4?
*Varför:* Utan avtal och överföringsgrund är listan till kunderna fel (`docs/juridik/underbitraden.md`).

**5. Dataskyddsombud.**
*Bakgrund:* Som biträde behandlar tjänsten uppgifter om hälsa (sjukfrånvaro) åt många arbetsgivare. Bedömningen i dag är att ett ombud inte krävs ännu (`docs/gdpr/README.md`).
*Fråga:* Krävs ett dataskyddsombud enligt artikel 37.1 c, som också gäller biträden, och från vilken omfattning?
*Varför:* Att inte utse ett ombud när det krävs är en egen överträdelse.

### Autentisering och åtkomst

**6. Engångskod via e-post, och passkey för känsliga uppgifter.**
*Bakgrund:* Alla loggar in med en engångskod till e-post, utan lösenord (ADR-000). Sedan 2026-11-01 bekräftar den som kan se sjukfrånvaro, lönebesked eller fullständiga personnummer dessutom inloggningen med en passkey, och ägaren kan kräva passkey för alla i företaget (ADR-029). Övriga roller, till exempel bokförare och attestanter, loggar fortfarande in med bara koden. En helt ny användare som måste ha passkey lägger till den första med bara e-postkoden. API-nycklar kräver ingen passkey.
*Fråga:* Räcker detta som lämplig säkerhet enligt artikel 32 för åtkomst till uppgifter om hälsa? Vad säger IMY:s praxis om stark autentisering för känsliga uppgifter? Räcker det att den första passkeyn läggs till efter enbart e-postkoden?
*Varför:* För roller utan passkey ger ett kapat e-postkonto full åtkomst till det rollen får se. Det är inte utrett om IMY i tillsynsbeslut har krävt stark autentisering för hälsouppgifter utanför vården, och i så fall vad det betyder här.

**7. Revisorns läsning av lönebesked.**
*Bakgrund:* Revisorn kan läsa lönebesked, som kan avslöja sjukfrånvaro, och varje läsning loggas. ADR-021 godkändes av projektägaren 2026-11-01; den juridiska frågan står kvar.
*Fråga:* Krävs en egen rättslig grund utöver revisionsplikten i aktiebolagslagen 9 kap.?

### Personalliggare

**8. Krävs en konsekvensbedömning för personalliggaren och inkorgen?**
*Bakgrund:* Mallar finns (`docs/gdpr/konsekvensbedomning-personalliggare.md`, `konsekvensbedomning-inkorg.md`). Personalliggaren innebär systematisk registrering av anställda, men den är föreskriven i lag.
*Fråga:* Kräver artikel 35.1 och 35.3 och IMY:s förteckning enligt artikel 35.4 en bedömning hos varje kund? Hur långt räcker biträdets skyldighet att bistå (artikel 28.3 f)?

**9. Foto på kiosken: samtycke eller berättigat intresse?**
*Bakgrund:* Ett foto sparas bara om rutan för samtycke är ikryssad. Det kan tas bort när som helst.
*Fråga:* Kan ett samtycke vara frivilligt i en anställning (artikel 6.1 a, 7.4 och skäl 43), eller bör grunden vara berättigat intresse (artikel 6.1 f)? Blir fotot en biometrisk uppgift (artikel 4.14) när det bara visas på kiosken och inte jämförs maskinellt?

**10. Personalliggarens bevarandetid och innehåll.**
*Bakgrund:* Posterna gallras efter två år från utgången av det kalenderår då liggaren fördes. Det är ett antagande (`app/Modules/StaffLedger/Actions/PruneStaffLedger.php`, `docs/gdpr/lagringstider.md`).
*Fråga:* Vilken bevarandetid gäller enligt 39 kap. skatteförfarandelagen efter ändringarna 2027 (prop. 2025/26:282)? Exakt vilka uppgifter ska registreras per person (`SavePerson.php`), och hur avgränsas branscherna (`Domain/BusinessType.php`)? Vilka paragrafer ska citeras?
*Varför:* En för kort tid bryter mot skatteförfarandelagen. En för lång tid bryter mot artikel 5.1 e. Gallringen kan inte ångras.

### Inkorgen

**11. Information till avsändare och tredje man (artikel 14).**
*Bakgrund:* Inkorgen tar emot mejl med personuppgifter om avsändaren och om andra, utan att de informeras.
*Fråga:* Räcker undantagen i artikel 14.5 b eller 14.5 c, eller vad ska kunden skriva i sin integritetspolicy?

**12. Gallring av avvisade meddelanden.**
*Bakgrund:* Avvisade meddelanden raderas efter 7 dagar, enligt projektägarens beslut 2026-11-01 (`PruneInboxCommand.php`).
*Fråga:* Kan ett avvisat underlag ändå vara räkenskapsinformation (bokföringslagen 5 kap. 7 §), till exempel en dubblett av ett bokfört underlag? Är 7 dagar rätt? Uppfyller ett underlag som sparas som `.eml` kraven i bokföringslagen 7 kap. på hur räkenskapsinformation ska bevaras?

**13. Den gemensamma adressen.**
*Bakgrund:* När ett mejl matchar flera företag visas avsändare, ämne och datum för alla möjliga företag innan någon hämtar mejlet (`docs/gdpr/registerforteckning.md`).
*Fråga:* Är det förenligt med uppgiftsminimering (artikel 5.1 c)?

### Personnummer och lagringstider

**14. ROT- och RUT-köparens personnummer.**
*Fråga:* Hur länge ska köparens personnummer sparas efter att Skatteverket betalat, och ska kundregistret självt sparas under lagringstiden (`app/Actions/Privacy/EraseRegisterEntry.php`)? Vilket lagrum ska citeras för att behandlingen är "klart motiverad" (dataskyddslagen 3 kap. 10 §)?

**15. Övriga lagringstider.**
*Fråga:* Är perioderna i `docs/gdpr/lagringstider.md` och `app/Console/Commands/PrunePersonalDataCommand.php` rätt? Det gäller särskilt avslagna ansökningar om frånvaro och avslagna utlägg (ett år) och anställdas uppgifter efter avslutad anställning. Är frånvaro bakom betald sjuklön räkenskapsinformation (`app/Modules/Payroll/Actions/EraseEmployee.php`)? Ska en leverantörsfaktura som kommer under en begränsning (artikel 18) ändå bokföras (`app/Actions/Privacy/RestrictProcessing.php`)?

**16. Fackföreningsavgift.**
*Bakgrund:* Det finns ingen löneart för fackavgift. Ett nettolöneavdrag eller en fritext om facket avslöjar fackligt medlemskap (artikel 9.1).
*Fråga:* Vilket undantag i artikel 9.2 gäller (b, med kollektivavtal)? Bör tjänsten varna eller ha en särskild skyddad löneart?

### AI-förordningen

**17. Stafes roll enligt AI-förordningen.**
*Fråga:* Är Stafe leverantör (artikel 3.3) av NONOX AI, för den driftade tjänsten och för kopior som andra kör själva? Gäller undantaget för fri och öppen källkod (artikel 2.12) när samma företag tar betalt för den driftade tjänsten? Blir kunden leverantör enligt artikel 25 om den väljer en egen modell?

**18. Datum efter förordning (EU) 2026/1744, och kvittoläsaren.**
*Bakgrund:* Enligt sekundära källor flyttar ändringsförordningen tillämpningen för fristående högrisksystem enligt bilaga III till den 2 december 2027, medan artikel 50 gäller från den 2 augusti 2026. Kvittoläsaren för anställdas utlägg (`ExpenseReceiptAgent`) bad tidigare modellen anteckna kvitton som ser privata ut, alkohol och representation. Sedan 2026-11-01 är den bedömningen borttagen: modellen läser bara det kvittot visar, antecknar bara det den inte kunde läsa och får aldrig bedöma inköpet eller personen (ADR-018).
*Fråga:* Stämmer datumen med texten i EUR-Lex? Räcker ändringen för att kvittoläsaren ska falla utanför bilaga III punkt 4 b (bedömning av beteende i ett anställningsförhållande), eller behövs något mer (artikel 6.3)?

**19. AI-kunnighet efter ändringen.**
*Fråga:* Gäller artikel 4 om AI-kunnighet fortfarande för leverantörer och tillhandahållare efter förordning (EU) 2026/1744, eller har skyldigheten flyttats till kommissionen och medlemsstaterna? Vad behöver Stafe och kunderna göra?

**20. Artikel 50.2 och strukturerad utläsning.**
*Fråga:* Gäller märkningskravet för syntetiskt innehåll när AI:n bara läser ut och klassar uppgifter ur ett underlag?

### Cybersäkerhet och tillgänglighet

**21. NIS2: koncernens storlek.**
*Fråga:* Är Stafe Group, räknat med partnerföretag och anknutna företag enligt kommissionens rekommendation 2003/361/EG, ett medelstort eller större företag? Är NONOX en molntjänst i cybersäkerhetslagens (2025:1506) mening?
*Varför:* Om både storlek och sektor stämmer gäller registrering, riskhantering och incidentrapportering.

**22. Tillsynsmyndighet enligt cybersäkerhetsförordningen (2025:1507).**
*Fråga:* Om lagen gäller, vilken svensk tillsynsmyndighet ansvarar för digitala tjänster av det här slaget?

**23. Tillgänglighetslagen och konsumenter.**
*Fråga:* Riktar sig någon del av NONOX till konsumenter i den mening som lagen (2023:254) avser, till exempel Min sida för anställda eller webbplatsen? Gäller undantaget för mikroföretag?

**24. Cyberresiliensförordningen: tillverkare eller förvaltare.**
*Bakgrund:* NONOX ges ut som öppen källkod under AGPL och säljs som driftad tjänst av samma företag. Enligt sekundära källor gäller rapporteringsskyldigheten (artikel 14) från den 11 september 2026 och övriga skyldigheter från den 11 december 2027.
*Fråga:* Släpper Stafe ut en produkt med digitala element på marknaden i förordningens (EU) 2024/2847 mening? Är Stafe i så fall tillverkare eller förvaltare av programvara med öppen källkod (artikel 24)? Stämmer datumen?

### E-faktura

**25. ViDA-datum.**
*Fråga:* När gäller de olika delarna av direktiv (EU) 2025/516: e-faktura och digital rapportering vid handel inom EU, och eventuella inhemska krav i Sverige? Vad betyder det för när NONOX måste kunna skicka och ta emot via Peppol?

### Texterna

**26. Granskning av alla texter i `docs/juridik`.**
*Fråga:* Granska integritetspolicyn, villkoren, personuppgiftsbiträdesavtalet, underbiträdeslistan, AI-sidan, regelverksregistret, konsekvensbedömningarna och SECURITY.md (särskilt löftet om att inte vidta rättsliga åtgärder mot säkerhetsforskare). Alla är utkast och ska inte användas mot betalande kunder innan de har granskats.

**27. Jämförelser med andra program på de publika sidorna.**
*Fråga:* Är jämförelserna och användningen av andra programs namn förenliga med marknadsföringslagen och varumärkesrätten (`docs/adr/ADR-020-naming-other-programs-on-public-pages.md`)?

**28. Fortnox villkor för flytt via API.**
*Fråga:* Vad säger Fortnox villkor för utvecklare och integrationer om att läsa ett annat företags uppgifter på dess uppdrag (`app/Migration/Fortnox/FortnoxOAuth.php`)? Finns ett exportformat för lön, till exempel PAXml (`docs/migration/leaving-fortnox.md`, `app/Modules/Payroll/Actions/MovePayrollData.php`)?

**29. BAS-licensen.**
*Fråga:* Får hela BAS-kontoplanen distribueras i ett repository med öppen källkod? Kontonumren och namnen i `database/data/bas/` är skrivna utifrån allmän kunskap om BAS och har inte kontrollerats mot den officiella BAS 2026 (`database/data/bas/README.md`).

### Bank (öppen bankdata)

**30. Registrering hos Finansinspektionen för kontoinformation.**
*Bakgrund:* Modulen Bank ([ADR-034](../adr/ADR-034-nonox-banking.md)) läser företagets bankkonton (saldon och transaktioner) genom Enable Banking, ett betalningsinstitut med tillstånd för kontoinformationstjänster (PSD2, lag (2010:751) om betaltjänster). Företaget godkänner medgivandet i sin bank med BankID; Stafe har avtalet med Enable Banking, driver tjänsten och tar emot och lagrar uppgifterna. Inga betalningar initieras; betalfiler signeras fortfarande i banken.
*Fråga:* Måste Stafe registreras hos Finansinspektionen som ombud (agent) för Enable Banking, eller själv registreras som kontoinformationstjänst, eller räcker det att Enable Banking är tjänsteleverantören och Stafe en teknisk mottagare på företagets uppdrag? Ändras svaret om den som kör NONOX själv har eget avtal med Enable Banking?
*Varför:* Utan svar erbjuds modulen inte i den driftade tjänsten (ingen nyckel är inställd).

**31. Enable Banking som underbiträde och bankdata som räkenskapsinformation.**
*Fråga:* Är Enable Banking Stafes underbiträde (artikel 28) eller företagets egen leverantör? Finns DPA och sker någon överföring utanför EU/EES? Är transaktioner som hämtats från banken räkenskapsinformation i sig (BFL 5 kap. 7 §, 7 kap.), och ska de därmed sparas i sju år och tas med i arkivpaketet? Gäller något särskilt för en enskild firmas privatkonto, där transaktionerna är personuppgifter om ägaren och motparterna?

## Del B. Bokföring, moms, skatt och lön (TODO(legal-verify) i koden)

Dessa frågor kräver i första hand en redovisningskonsult eller skattejurist. Varje rad pekar på var frågan står.

### Bokföring och bokslut

| Fråga | Var |
|---|---|
| Får underlag läggas till på en redan bokförd verifikation, så länge verifikationen inte ändras? | `app/Actions/Accounting/AttachToVoucher.php`, ADR-007 |
| Räcker en post i behandlingshistoriken per SIE-import i stället för en per verifikation? | `app/Actions/Accounting/ImportSie.php` |
| Är SIE:s typkoder "I" och "S" rätt för ideella föreningar och stiftelser? | `app/Actions/Accounting/ExportSie.php` |
| Vilket resultatkonto gäller per företagsform vid årsskiftet, särskilt för HB och KB? Är 2512 och 2898 rätt konton för beräknad skatt och utdelning? | `app/Actions/Accounting/CarryForwardBalances.php`, `app/Domain/Closing/ClosingAccounts.php` |
| Finns en minsta längd på ett räkenskapsår, eller bara den längsta på 18 månader? | `app/Domain/Accounting/FiscalYearRules.php` |
| Vilka företagsformer och storlekar får använda K1, K2 och K3? | `app/Actions/Company/CreateCompany.php` |
| Skatt vid bokslut beräknas av användaren. Utdelningen kontrolleras inte mot fritt eget kapital (ABL 17 kap.). | `app/Actions/Closing/YearEndClosing.php` |
| Hur ska obetalda kund- och leverantörsfakturor bokas vid bokslut när kontantmetoden används? | `app/Domain/Invoicing/InvoiceBooking.php`, `app/Domain/Payables/SupplierInvoiceBooking.php`, ADR-011, ADR-013 |
| Moms vid försäljning av inventarier, avskrivning i avyttringsmånaden, och skattemässig avskrivning (30- och 20-regeln, överavskrivning) | `app/Actions/Assets/FixedAssets.php`, `app/Domain/Assets/DepreciationPlan.php` |
| Årsredovisning K2: radställningen mot ÅRL bilaga 2 och 3 och BFNAR 2016:10, noterna, antal jämförelseår och skattesatsen 20,6 % på obeskattade reserver | `app/Domain/AnnualReport/*` |
| INK2: kopplingen mellan BAS och koder, de justeringar som inte stöds, och sekelprefixet 16 för juridiska personer | `app/Domain/IncomeTax/Ink2.php`, `Ink2Codes.php` |
| Vilken valutakurs gäller för leverantörsfakturor i utländsk valuta, och hur bokas kursdifferenser? | `app/Support/Currency/RiksbankRates.php` |
| Hur bokas dricks som betalats med kort, presentkort och öresavrundning från en dagsrapport, och vilka momssatser gäller på rapportens datum? | `app/Modules/Inbox/Support/DailyReportVoucher.php` |

### Moms och fakturering

| Fråga | Var |
|---|---|
| Kopplingen mellan konton och momsrutor, rutorna 06, 07, 08, 37 och 38, och om ören stryks per ruta | `app/Domain/Vat/VatBoxes.php`, `VatReturn.php`, ADR-010 |
| eSKDUpload-filens format | `app/Domain/Vat/EskdFile.php` |
| Kvartal som går över ett brutet räkenskapsår, och datum för årsmoms | `app/Domain/Vat/VatPeriods.php`, `VatDeadlines.php` |
| Periodisk sammanställning: period, avrundning och teckenkodning | `app/Domain/Vat/EuSalesList.php` |
| OSS: momssatser, eurokurs, period och tröskeln 10 000 euro | `app/Domain/Vat/OssRates.php`, `OssReport.php` |
| Konton, rutor och fakturatexter vid försäljning till EU och export, och VIES-kontroll | `app/Domain/Invoicing/ForeignSale.php`, `app/Actions/Invoicing/IssueInvoice.php`, `CheckVatNumber.php`, ADR-016 |
| Omvänd betalningsskyldighet inom bygg: fakturakrav, konto 3231 och ruta 41 | `app/Actions/Invoicing/IssueInvoice.php`, `app/Domain/Invoicing/InvoiceBooking.php` |
| Obligatoriskt fakturainnehåll | ADR-011 |
| ROT och RUT: andelar, tak, avrundning, arbetskostnad, kontantmetoden och nekade belopp | `app/Domain/Invoicing/TaxReduction.php`, `app/Actions/Invoicing/IssueInvoice.php`, `RegisterTaxReductionPayment.php` |
| Påminnelseavgift och dröjsmålsränta, som i dag inte tas ut | `app/Actions/Invoicing/SendReminder.php`, ADR-011 |
| Bankgiro och plusgiro i Peppol-fakturor enligt SFTI | `app/Support/Peppol/UblInvoiceParser.php` |

### Lön

Den samlade listan för lönen finns i [ADR-012](../adr/ADR-012-payroll.md) (frågorna 1–14) och i systemdokumentationens avsnitt 8. Frågor som står i koden:

| Fråga | Var |
|---|---|
| Skattetabellens intervall vid inkomst med ören, och obligatoriskt skatteavdrag när skatteuppgift saknas | `app/Modules/Payroll/Domain/Tax/WithholdingTax.php`, `Engine/Steps/WithholdingTaxStep.php` |
| Avrundning av arbetsgivaravgifter, avgifter för tidigare månader och regionalt stöd | `Engine/Steps/EmployerContributions.php` |
| AGI: avrundning, deklarationsdag för företag med omsättning över 40 miljoner kronor, direkt inlämning via API, och enskild firma | `Support/Agi/AgiDeclaration.php`, `AgiDeadlines.php`, `Actions/AgiSubmissions.php` |
| Sjuklön: timvärde, karensavdrag större än sjuklönen, och antal karensavdrag på tolv månader | `Engine/HourlyValue.php`, `Engine/Steps/SickPay.php`, `Support/Run/ContextBuilder.php` |
| Semester: värde per dag, förskottssemester, sparade dagar, 26 a §, procentregeln, semesterersättning och skuldvärdering | `Support/Vacation/VacationLedger.php`, `Actions/CloseVacationYear.php`, `Engine/Steps/Vacation.php`, `Support/Calendar/VacationDays.php`, `SwedishHolidays.php` |
| Retroaktiv lön: frånvaro och semester värderade på den gamla lönen | `Support/Run/ContextBuilder.php` |
| Traktamente, pension och särskild löneskatt | `Engine/Steps/Allowances.php`, `Engine/Steps/Pension.php`, `Actions/PayrollRuns.php` |
| Utmätning stöds inte | `Engine/Steps/NetItems.php` |
| Arbetsgivarintygets fält | `Http/EmployerCertificateController.php` |
| Bankernas krav på lönefiler, och extra kontroll vid byte av lönekonto i enmansföretag | `Actions/SalaryPaymentFile.php`, `Actions/ConfirmBankChange.php` |

Sökvägarna i lönetabellen utgår från `app/Modules/Payroll/`.
