<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use InvalidArgumentException;

/**
 * The map from NONOX's K2 annual report to the Swedish XBRL taxonomy
 * concepts it is tagged with in the Inline XBRL file (ADR-058 §3). This
 * class is the only place concept names appear. The names are read from
 * the taxonomy package of taxonomier.se (2026-08-31, SHA-256 47e56bca…):
 * the presentation and calculation linkbases of K2 aktiebolag 2024-09-12
 * `risbs` (se-k2-ab-fbs, -fisbn, -fdr, -fcd, -fdisc) for the report and
 * of the fastställelseintyg 2020-12-01 (se-coa-fplc) for the certificate.
 * The taxonomy itself is not in this repository (its licence forbids
 * modifying it; ADR-058 §4): set BOLAGSVERKET_TAXONOMY_DIR to an unpacked
 * package to check every concept here against it.
 *
 * A null concept means the taxonomy has no concept that says what NONOX
 * prints on that line (the line is wider or narrower than every concept
 * the K2 presentation offers). The iXBRL export refuses a report that
 * prints such a line, naming it, rather than tagging it wrongly.
 *
 * TODO(legal-verify): whether a map of concept names is a derivative work
 * of the taxonomy that must carry XBRL International's copyright notice
 * and a link to the original taxonomy (https://www.taxonomier.se/copyright.html).
 */
final class K2Taxonomy
{
    /** The K2 aktiebolag taxonomy, full income statement and full balance sheet (`risbs`). */
    public const string VERSION = '2024-09-12';

    /**
     * TODO(provider-verify): which taxonomy version Bolagsverket accepts for
     * which fiscal year ("Gällande servicespecifikationer", not read).
     */
    public const string ENTRY_POINT = 'http://xbrl.taxonomier.se/se/fr/gaap/k2-all/ab/risbs/2024-09-12/se-k2-ab-risbs-2024-09-12.xsd';

    /** The fastställelseintyg for an aktiebolag without a group (coa `rplc`). */
    public const string CERTIFICATE_ENTRY_POINT = 'http://xbrl.taxonomier.se/se/fr/gaap/coa/rplc/2020-12-01/se-coa-rplc-2020-12-01.xsd';

    /**
     * TODO(provider-verify): the identifier scheme Bolagsverket's
     * tillämpningsanvisning prescribes for the organisation number.
     */
    public const string ENTITY_SCHEME = 'http://www.bolagsverket.se';

    /** Prefix => namespace of the schemas the concepts below live in. */
    public const array NAMESPACES = [
        'se-gen-base' => 'http://www.taxonomier.se/se/fr/gen-base/2021-10-31',
        'se-cd-base' => 'http://www.taxonomier.se/se/fr/cd-base/2021-10-31',
        'se-mem-base' => 'http://www.taxonomier.se/se/fr/mem-base/2021-10-31',
        'se-comp-base' => 'http://www.bolagsverket.se/se/fr/comp-base/2020-12-01',
    ];

    /** Prefix => the schema file inside an unpacked taxonomy package. */
    public const array SCHEMAS = [
        'se-gen-base' => 'xbrl.taxonomier.se/se/common/base/se-gen-base/2021-10-31/se-gen-base-2021-10-31.xsd',
        'se-cd-base' => 'xbrl.taxonomier.se/se/common/base/se-cd-base/2021-10-31/se-cd-base-2021-10-31.xsd',
        'se-mem-base' => 'xbrl.taxonomier.se/se/common/base/se-mem-base/2021-10-31/se-mem-base-2021-10-31.xsd',
        'se-comp-base' => 'xbrl.taxonomier.se/se/common/base/se-comp-base/2020-12-01/se-comp-base-2020-12-01.xsd',
    ];

    /**
     * Income statement lines (K2Schedule::INCOME and its subtotals).
     *
     * TODO(legal-verify): the taxonomy's financial items are "exklusive
     * nedskrivningar" and puts write-downs of financial assets on their own
     * concept, while K2Schedule keeps BAS 8000–8399 whole per kind; tax
     * (8900–8989) is tagged as tax on the year's result although BAS 8980
     * Övriga skatter has its own concept (OvrigaSkatter).
     */
    public const array INCOME = [
        'net_sales' => 'se-gen-base:Nettoomsattning',
        'stock_change' => 'se-gen-base:ForandringLagerProdukterIArbeteFardigaVarorPagaendeArbetenAnnansRakning',
        'capitalised_work' => 'se-gen-base:AktiveratArbeteEgenRakning',
        'other_operating_income' => 'se-gen-base:OvrigaRorelseintakter',
        'raw_materials' => 'se-gen-base:RavarorFornodenheterKostnader',
        'goods_for_resale' => 'se-gen-base:HandelsvarorKostnader',
        'other_external' => 'se-gen-base:OvrigaExternaKostnader',
        'personnel' => 'se-gen-base:Personalkostnader',
        'depreciation' => 'se-gen-base:AvskrivningarNedskrivningarMateriellaImmateriellaAnlaggningstillgangar',
        'other_operating_expenses' => 'se-gen-base:OvrigaRorelsekostnader',
        'operating_result' => 'se-gen-base:Rorelseresultat',
        'group_shares' => 'se-gen-base:ResultatAndelarKoncernforetag',
        'associated_shares' => 'se-gen-base:ResultatAndelarIntresseforetagGemensamtStyrda',
        'other_financial_assets' => 'se-gen-base:ResultatOvrigaFinansiellaAnlaggningstillgangar',
        'interest_income' => 'se-gen-base:OvrigaRanteintakterLiknandeResultatposter',
        'interest_expense' => 'se-gen-base:RantekostnaderLiknandeResultatposter',
        'after_financial' => 'se-gen-base:ResultatEfterFinansiellaPoster',
        'appropriations' => 'se-gen-base:Bokslutsdispositioner',
        'before_tax' => 'se-gen-base:ResultatForeSkatt',
        'tax' => 'se-gen-base:SkattAretsResultat',
        'year_result' => 'se-gen-base:AretsResultat',
    ];

    /**
     * Subtotals of the taxonomy's calculation linkbase that the report does
     * not print, given as hidden facts so the printed lines add up to the
     * printed totals: subtotal => [line key => weight].
     */
    public const array INCOME_HIDDEN_SUBTOTALS = [
        'se-gen-base:RorelseintakterLagerforandringarMm' => ['net_sales' => 1, 'stock_change' => 1, 'capitalised_work' => 1, 'other_operating_income' => 1],
        'se-gen-base:Rorelsekostnader' => ['raw_materials' => 1, 'goods_for_resale' => 1, 'other_external' => 1, 'personnel' => 1, 'depreciation' => 1, 'other_operating_expenses' => 1],
        'se-gen-base:FinansiellaPoster' => ['group_shares' => 1, 'associated_shares' => 1, 'other_financial_assets' => 1, 'interest_income' => 1, 'interest_expense' => -1],
    ];

    /**
     * Balance sheet lines (K2Schedule::BALANCE and the year's result).
     *
     * - machinery: NONOX prints Maskiner och inventarier (BAS 1200–1279,
     *   1290–1299) as one line; the K2 presentation has only the separate
     *   MaskinerAndraTekniskaAnlaggningar and InventarierVerktygInstallationer.
     *   The combined MaskinerInventarier exists in se-gen-base but no K2
     *   linkbase uses it (only INK3), so it is not used (ADR-058 addendum E.2).
     * - reserve: Reservfond och annat bundet eget kapital (2085–2089) spans
     *   Uppskrivningsfond, Reservfond and Bunden överkursfond.
     * - long_group / group_liabilities: one line for koncern-, intresse- and
     *   gemensamt styrda företag; the taxonomy has a concept for each.
     */
    public const array BALANCE = [
        'intangible' => 'se-gen-base:ImmateriellaAnlaggningstillgangar',
        'buildings' => 'se-gen-base:ByggnaderMark',
        'machinery' => null,
        'improvements' => 'se-gen-base:ForbattringsutgifterAnnansFastighet',
        'construction_in_progress' => 'se-gen-base:PagaendeNyanlaggningarForskottMateriellaAnlaggningstillgangar',
        'financial_fixed' => 'se-gen-base:FinansiellaAnlaggningstillgangar',
        'inventory' => 'se-gen-base:VarulagerMm',
        'trade_receivables' => 'se-gen-base:Kundfordringar',
        'other_receivables' => 'se-gen-base:OvrigaFordringarKortfristiga',
        'prepaid' => 'se-gen-base:ForutbetaldaKostnaderUpplupnaIntakter',
        'short_investments' => 'se-gen-base:KortfristigaPlaceringar',
        'cash' => 'se-gen-base:KassaBank',
        'share_capital' => 'se-gen-base:Aktiekapital',
        'reserve' => null,
        'retained' => 'se-gen-base:BalanseratResultat',
        'year_result' => 'se-gen-base:AretsResultatEgetKapital',
        'untaxed_reserves' => 'se-gen-base:ObeskattadeReserver',
        'provisions' => 'se-gen-base:Avsattningar',
        'bonds' => 'se-gen-base:Obligationslan',
        'long_overdraft' => 'se-gen-base:CheckrakningskreditLangfristig',
        'long_credit_institutions' => 'se-gen-base:OvrigaLangfristigaSkulderKreditinstitut',
        'long_group' => null,
        'long_other' => 'se-gen-base:OvrigaLangfristigaSkulder',
        'overdraft' => 'se-gen-base:CheckrakningskreditKortfristig',
        'credit_institutions' => 'se-gen-base:OvrigaKortfristigaSkulderKreditinstitut',
        'customer_advances' => 'se-gen-base:ForskottFranKunder',
        'work_in_progress' => 'se-gen-base:PagaendeArbetenAnnansRakningKortfristigaSkulder',
        'invoiced_not_earned' => 'se-gen-base:FaktureradEjUpparbetadIntakt',
        'trade_payables' => 'se-gen-base:Leverantorsskulder',
        'group_liabilities' => null,
        'tax_liabilities' => 'se-gen-base:Skatteskulder',
        'other_liabilities' => 'se-gen-base:OvrigaKortfristigaSkulder',
        'accrued' => 'se-gen-base:UpplupnaKostnaderForutbetaldaIntakter',
    ];

    /** The sum of each balance sheet section (K2Schedule::SECTIONS). */
    public const array SECTIONS = [
        'fixed' => 'se-gen-base:Anlaggningstillgangar',
        'current' => 'se-gen-base:Omsattningstillgangar',
        'restricted' => 'se-gen-base:BundetEgetKapital',
        'free' => 'se-gen-base:FrittEgetKapital',
        'untaxed' => 'se-gen-base:ObeskattadeReserver',
        'provisions' => 'se-gen-base:Avsattningar',
        'long_term' => 'se-gen-base:LangfristigaSkulder',
        'short_term' => 'se-gen-base:KortfristigaSkulder',
    ];

    public const array TOTALS = [
        'assets' => 'se-gen-base:Tillgangar',
        'equity_liabilities' => 'se-gen-base:EgetKapitalSkulder',
    ];

    /**
     * Balance sheet subtotals that the report does not print, as hidden
     * facts: subtotal => line keys. (Eget kapital is printed, in the
     * specification of changes in equity.)
     */
    public const array BALANCE_HIDDEN_SUBTOTALS = [
        'se-gen-base:MateriellaAnlaggningstillgangar' => ['buildings', 'machinery', 'improvements', 'construction_in_progress'],
        'se-gen-base:KortfristigaFordringar' => ['trade_receivables', 'other_receivables', 'prepaid'],
    ];

    /** The multi-year overview (flerårsöversikt). */
    public const array MULTI_YEAR = [
        'net_sales' => 'se-gen-base:Nettoomsattning',
        'after_financial' => 'se-gen-base:ResultatEfterFinansiellaPoster',
        'solidity' => 'se-gen-base:Soliditet',
    ];

    /**
     * The specification of changes in equity: per row (AnnualAccounts::
     * equityChanges) and column, the concept. Opening and closing are the
     * balance sheet concepts at the previous and this year's balance date.
     * The 'other' row (Övriga förändringar, a residual) has no concept: a
     * new issue, a shareholder contribution and a bonus issue each have
     * their own, and NONOX cannot tell which it was.
     */
    public const array EQUITY = [
        'opening' => ['share_capital' => 'se-gen-base:Aktiekapital', 'reserve' => null, 'retained' => 'se-gen-base:BalanseratResultat', 'year_result' => 'se-gen-base:AretsResultatEgetKapital', 'total' => 'se-gen-base:EgetKapital'],
        'dividend' => ['retained' => 'se-gen-base:ForandringEgetKapitalBalanseratResultatUtdelning', 'year_result' => 'se-gen-base:ForandringEgetKapitalAretsResultatUtdelning', 'total' => 'se-gen-base:ForandringEgetKapitalTotaltUtdelning'],
        'carried_forward' => ['retained' => 'se-gen-base:ForandringEgetKapitalBalanseratResultatBalanserasNyRakning', 'year_result' => 'se-gen-base:ForandringEgetKapitalAretsResultatBalanserasNyRakning', 'total' => 'se-gen-base:ForandringEgetKapitalTotaltBalanserasNyRakning'],
        'year_result' => ['year_result' => 'se-gen-base:ForandringEgetKapitalAretsResultatAretsResultat', 'total' => 'se-gen-base:ForandringEgetKapitalTotaltAretsResultat'],
        'other' => [],
        'closing' => ['share_capital' => 'se-gen-base:Aktiekapital', 'reserve' => null, 'retained' => 'se-gen-base:BalanseratResultat', 'year_result' => 'se-gen-base:AretsResultatEgetKapital', 'total' => 'se-gen-base:EgetKapital'],
    ];

    /** The proposed disposition (resultatdisposition). */
    public const array DISPOSITION = [
        'free' => 'se-gen-base:FrittEgetKapital',
        'dividend' => 'se-gen-base:ForslagDispositionUtdelning',
        'carried' => 'se-gen-base:ForslagDispositionBalanserasINyRakning',
        'total' => 'se-gen-base:ForslagDisposition',
    ];

    /**
     * The note per fixed asset line (AnnualAccounts::fixedAssetNotes). A
     * line without an entry has no note concepts that match the rows NONOX
     * prints: intangible assets are one line in NONOX and five notes in the
     * taxonomy, machinery is split (see BALANCE), and the note on
     * construction in progress has no depreciation rows.
     */
    public const array FIXED_ASSET_NOTES = [
        'buildings' => [
            'acquisition' => 'se-gen-base:ByggnaderMarkAnskaffningsvarden',
            'additions' => 'se-gen-base:ByggnaderMarkForandringAnskaffningsvardenInkop',
            'acquisition_disposals' => 'se-gen-base:ByggnaderMarkForandringAnskaffningsvardenForsaljningarUtrangeringar',
            'depreciation' => 'se-gen-base:ByggnaderMarkAvskrivningar',
            'depreciation_disposals' => 'se-gen-base:ByggnaderMarkForandringAvskrivningarAterfordaAvskrivningarForsaljningarRespUtrangeringar',
            'charge' => 'se-gen-base:ByggnaderMarkForandringAvskrivningarAretsAvskrivningar',
            'book_value' => 'se-gen-base:ByggnaderMark',
        ],
        'improvements' => [
            'acquisition' => 'se-gen-base:ForbattringsutgifterAnnansFastighetAnskaffningsvarden',
            'additions' => 'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAnskaffningsvardenInkop',
            'acquisition_disposals' => 'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAnskaffningsvardenForsaljningarUtrangeringar',
            'depreciation' => 'se-gen-base:ForbattringsutgifterAnnansFastighetAvskrivningar',
            'depreciation_disposals' => 'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAvskrivningarAterfordaAvskrivningarForsaljningarRespUtrangeringar',
            'charge' => 'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAvskrivningarAretsAvskrivningar',
            'book_value' => 'se-gen-base:ForbattringsutgifterAnnansFastighet',
        ],
    ];

    /** Ställda säkerheter per PledgedAssetKind value, and their sum. */
    public const array PLEDGED = [
        'company_mortgage' => 'se-gen-base:StalldaSakerheterForetagsinteckningar',
        'property_mortgage' => 'se-gen-base:StalldaSakerheterFastighetsinteckningar',
        'retention_of_title' => 'se-gen-base:StalldaSakerheterTillgangarAganderattsforbehall',
        'pledged_receivables' => 'se-gen-base:StalldaSakerheterBelanadeFordringar',
        'other' => 'se-gen-base:StalldaSakerheterAndraStalldaSakerheter',
        'total' => 'se-gen-base:StalldaSakerheter',
    ];

    /**
     * Texts and other single facts. group_commitments (ÅRL 5 kap. 16 §) has
     * no concept of its own in the K2 notes.
     */
    public const array FACTS = [
        'company_name' => 'se-cd-base:ForetagetsNamn',
        'org_number' => 'se-cd-base:Organisationsnummer',
        'first_day' => 'se-cd-base:RakenskapsarForstaDag',
        'last_day' => 'se-cd-base:RakenskapsarSistaDag',
        'business_description' => 'se-gen-base:AllmantVerksamheten',
        'significant_events' => 'se-gen-base:VasentligaHandelserRakenskapsaret',
        'net_sales_comment' => 'se-gen-base:KommentarFlerarsoversikt',
        'principles' => 'se-gen-base:RedovisningsVarderingsprinciper',
        'average_employees' => 'se-gen-base:MedelantaletAnstallda',
        'contingent' => 'se-gen-base:EventualForpliktelser',
        'contingent_text' => 'se-gen-base:NotEventualForpliktelserKommentar',
        'group_commitments' => null,
        'events_after' => 'se-gen-base:NotVasentligaHandelserRakenskapsaretsSlut',
        'signing_date' => 'se-gen-base:UndertecknandeArsredovisningDatum',
    ];

    /**
     * Hidden facts with a member of a value list (extensible enumerations):
     * Swedish, seat in Sweden, Swedish kronor, amounts in whole kronor, and
     * "Styrelsen avger följande årsredovisning" as the PDF says.
     *
     * TODO(provider-verify): which of these the tillämpningsanvisning
     * requires, and whether Företagets säte (not kept in NONOX) is required.
     */
    public const array ENUMERATIONS = [
        'se-cd-base:SprakHandlingUpprattadList' => 'se-mem-base:SprakSvenskaMember',
        'se-cd-base:LandForetagetsSateList' => 'se-mem-base:LandSverigeMember',
        'se-cd-base:RedovisningsvalutaHandlingList' => 'se-mem-base:ValutaSvenskaKronorMember',
        'se-cd-base:BeloppsformatList' => 'se-mem-base:BeloppsformatNormalformMember',
        'se-gen-base:FinansiellRapportList' => 'se-mem-base:FinansiellRapportStyrelsenAvgerArsredovisningMember',
    ];

    /**
     * The fastställelseintyg (se-comp-base 2020-12-01). Two of its facts
     * take one of a few fixed texts from the schema; the document prints
     * exactly those. The signer's name, role, date and the electronic
     * signature are not tagged: the certificate is signed in Bolagsverket's
     * service (ADR-058 §5).
     */
    public const array CERTIFICATE = [
        'adoption' => 'se-comp-base:FaststallelseResultatBalansrakning',
        'meeting_date' => 'se-comp-base:Arsstamma',
        'original' => 'se-comp-base:IntygandeOriginalInnehall',
    ];

    /** The fixed texts of the schema's enumerations, as the facts must carry them. */
    public const string ADOPTION_TEXT = 'Jag intygar att resultaträkningen och balansräkningen har fastställts på årsstämma';

    public const string ORIGINAL_TEXT = 'Jag intygar att innehållet i dessa elektroniska handlingar överensstämmer med originalen och att originalen undertecknats av samtliga personer som enligt lag ska underteckna dessa.';

    /**
     * Period type, balance and kind of every concept used above, as the
     * schemas define them. A monetary concept's balance decides the sign of
     * its fact: the value is positive in the direction of the balance.
     *
     * @var array<string, array{0: 'instant'|'duration', 1: 'debit'|'credit'|null, 2: 'monetary'|'pure'|'decimal'|'string'|'date'|'enum'}>
     */
    public const array CONCEPTS = [
        'se-gen-base:Nettoomsattning' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ForandringLagerProdukterIArbeteFardigaVarorPagaendeArbetenAnnansRakning' => ['duration', 'credit', 'monetary'],
        'se-gen-base:AktiveratArbeteEgenRakning' => ['duration', 'credit', 'monetary'],
        'se-gen-base:OvrigaRorelseintakter' => ['duration', 'credit', 'monetary'],
        'se-gen-base:RorelseintakterLagerforandringarMm' => ['duration', 'credit', 'monetary'],
        'se-gen-base:RavarorFornodenheterKostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:HandelsvarorKostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:OvrigaExternaKostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:Personalkostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:AvskrivningarNedskrivningarMateriellaImmateriellaAnlaggningstillgangar' => ['duration', 'debit', 'monetary'],
        'se-gen-base:OvrigaRorelsekostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:Rorelsekostnader' => ['duration', 'debit', 'monetary'],
        'se-gen-base:Rorelseresultat' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ResultatAndelarKoncernforetag' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ResultatAndelarIntresseforetagGemensamtStyrda' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ResultatOvrigaFinansiellaAnlaggningstillgangar' => ['duration', 'credit', 'monetary'],
        'se-gen-base:OvrigaRanteintakterLiknandeResultatposter' => ['duration', 'credit', 'monetary'],
        'se-gen-base:RantekostnaderLiknandeResultatposter' => ['duration', 'debit', 'monetary'],
        'se-gen-base:FinansiellaPoster' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ResultatEfterFinansiellaPoster' => ['duration', 'credit', 'monetary'],
        'se-gen-base:Bokslutsdispositioner' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ResultatForeSkatt' => ['duration', 'credit', 'monetary'],
        'se-gen-base:SkattAretsResultat' => ['duration', 'debit', 'monetary'],
        'se-gen-base:AretsResultat' => ['duration', 'credit', 'monetary'],
        'se-gen-base:ImmateriellaAnlaggningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:ByggnaderMark' => ['instant', 'debit', 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighet' => ['instant', 'debit', 'monetary'],
        'se-gen-base:PagaendeNyanlaggningarForskottMateriellaAnlaggningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:MateriellaAnlaggningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:FinansiellaAnlaggningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:Anlaggningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:VarulagerMm' => ['instant', 'debit', 'monetary'],
        'se-gen-base:Kundfordringar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:OvrigaFordringarKortfristiga' => ['instant', 'debit', 'monetary'],
        'se-gen-base:ForutbetaldaKostnaderUpplupnaIntakter' => ['instant', 'debit', 'monetary'],
        'se-gen-base:KortfristigaFordringar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:KortfristigaPlaceringar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:KassaBank' => ['instant', 'debit', 'monetary'],
        'se-gen-base:Omsattningstillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:Tillgangar' => ['instant', 'debit', 'monetary'],
        'se-gen-base:Aktiekapital' => ['instant', 'credit', 'monetary'],
        'se-gen-base:BundetEgetKapital' => ['instant', 'credit', 'monetary'],
        'se-gen-base:BalanseratResultat' => ['instant', 'credit', 'monetary'],
        'se-gen-base:AretsResultatEgetKapital' => ['instant', 'credit', 'monetary'],
        'se-gen-base:FrittEgetKapital' => ['instant', 'credit', 'monetary'],
        'se-gen-base:EgetKapital' => ['instant', 'credit', 'monetary'],
        'se-gen-base:ObeskattadeReserver' => ['instant', 'credit', 'monetary'],
        'se-gen-base:Avsattningar' => ['instant', 'credit', 'monetary'],
        'se-gen-base:Obligationslan' => ['instant', 'credit', 'monetary'],
        'se-gen-base:CheckrakningskreditLangfristig' => ['instant', 'credit', 'monetary'],
        'se-gen-base:OvrigaLangfristigaSkulderKreditinstitut' => ['instant', 'credit', 'monetary'],
        'se-gen-base:OvrigaLangfristigaSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:LangfristigaSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:CheckrakningskreditKortfristig' => ['instant', 'credit', 'monetary'],
        'se-gen-base:OvrigaKortfristigaSkulderKreditinstitut' => ['instant', 'credit', 'monetary'],
        'se-gen-base:ForskottFranKunder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:PagaendeArbetenAnnansRakningKortfristigaSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:FaktureradEjUpparbetadIntakt' => ['instant', 'credit', 'monetary'],
        'se-gen-base:Leverantorsskulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:Skatteskulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:OvrigaKortfristigaSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:UpplupnaKostnaderForutbetaldaIntakter' => ['instant', 'credit', 'monetary'],
        'se-gen-base:KortfristigaSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:EgetKapitalSkulder' => ['instant', 'credit', 'monetary'],
        'se-gen-base:Soliditet' => ['instant', null, 'pure'],
        'se-gen-base:ForandringEgetKapitalBalanseratResultatUtdelning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalAretsResultatUtdelning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalTotaltUtdelning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalBalanseratResultatBalanserasNyRakning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalAretsResultatBalanserasNyRakning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalTotaltBalanserasNyRakning' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalAretsResultatAretsResultat' => ['duration', null, 'monetary'],
        'se-gen-base:ForandringEgetKapitalTotaltAretsResultat' => ['duration', null, 'monetary'],
        'se-gen-base:ForslagDispositionUtdelning' => ['instant', null, 'monetary'],
        'se-gen-base:ForslagDispositionBalanserasINyRakning' => ['instant', null, 'monetary'],
        'se-gen-base:ForslagDisposition' => ['instant', 'credit', 'monetary'],
        'se-gen-base:ByggnaderMarkAnskaffningsvarden' => ['instant', 'debit', 'monetary'],
        'se-gen-base:ByggnaderMarkForandringAnskaffningsvardenInkop' => ['duration', null, 'monetary'],
        'se-gen-base:ByggnaderMarkForandringAnskaffningsvardenForsaljningarUtrangeringar' => ['duration', null, 'monetary'],
        'se-gen-base:ByggnaderMarkAvskrivningar' => ['instant', 'credit', 'monetary'],
        'se-gen-base:ByggnaderMarkForandringAvskrivningarAterfordaAvskrivningarForsaljningarRespUtrangeringar' => ['duration', null, 'monetary'],
        'se-gen-base:ByggnaderMarkForandringAvskrivningarAretsAvskrivningar' => ['duration', null, 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetAnskaffningsvarden' => ['instant', 'debit', 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAnskaffningsvardenInkop' => ['duration', null, 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAnskaffningsvardenForsaljningarUtrangeringar' => ['duration', null, 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetAvskrivningar' => ['instant', 'credit', 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAvskrivningarAterfordaAvskrivningarForsaljningarRespUtrangeringar' => ['duration', null, 'monetary'],
        'se-gen-base:ForbattringsutgifterAnnansFastighetForandringAvskrivningarAretsAvskrivningar' => ['duration', null, 'monetary'],
        'se-gen-base:StalldaSakerheterForetagsinteckningar' => ['instant', null, 'monetary'],
        'se-gen-base:StalldaSakerheterFastighetsinteckningar' => ['instant', null, 'monetary'],
        'se-gen-base:StalldaSakerheterTillgangarAganderattsforbehall' => ['instant', null, 'monetary'],
        'se-gen-base:StalldaSakerheterBelanadeFordringar' => ['instant', null, 'monetary'],
        'se-gen-base:StalldaSakerheterAndraStalldaSakerheter' => ['instant', null, 'monetary'],
        'se-gen-base:StalldaSakerheter' => ['instant', null, 'monetary'],
        'se-gen-base:EventualForpliktelser' => ['instant', null, 'monetary'],
        'se-gen-base:NotEventualForpliktelserKommentar' => ['instant', null, 'string'],
        'se-gen-base:MedelantaletAnstallda' => ['duration', null, 'decimal'],
        'se-gen-base:AllmantVerksamheten' => ['duration', null, 'string'],
        'se-gen-base:VasentligaHandelserRakenskapsaret' => ['duration', null, 'string'],
        'se-gen-base:KommentarFlerarsoversikt' => ['duration', null, 'string'],
        'se-gen-base:RedovisningsVarderingsprinciper' => ['duration', null, 'string'],
        'se-gen-base:NotVasentligaHandelserRakenskapsaretsSlut' => ['duration', null, 'string'],
        'se-gen-base:UndertecknandeArsredovisningDatum' => ['duration', null, 'date'],
        'se-gen-base:FinansiellRapportList' => ['duration', null, 'enum'],
        'se-cd-base:ForetagetsNamn' => ['duration', null, 'string'],
        'se-cd-base:Organisationsnummer' => ['duration', null, 'string'],
        'se-cd-base:RakenskapsarForstaDag' => ['duration', null, 'date'],
        'se-cd-base:RakenskapsarSistaDag' => ['duration', null, 'date'],
        'se-cd-base:SprakHandlingUpprattadList' => ['duration', null, 'enum'],
        'se-cd-base:LandForetagetsSateList' => ['duration', null, 'enum'],
        'se-cd-base:RedovisningsvalutaHandlingList' => ['duration', null, 'enum'],
        'se-cd-base:BeloppsformatList' => ['duration', null, 'enum'],
        'se-comp-base:FaststallelseResultatBalansrakning' => ['instant', null, 'string'],
        'se-comp-base:Arsstamma' => ['instant', null, 'date'],
        'se-comp-base:IntygandeOriginalInnehall' => ['instant', null, 'string'],
    ];

    /**
     * The domain members used as enumeration values; they must exist in
     * se-mem-base but are not facts.
     *
     * @var list<string>
     */
    public const array MEMBERS = [
        'se-mem-base:SprakSvenskaMember',
        'se-mem-base:LandSverigeMember',
        'se-mem-base:ValutaSvenskaKronorMember',
        'se-mem-base:BeloppsformatNormalformMember',
        'se-mem-base:FinansiellRapportStyrelsenAvgerArsredovisningMember',
    ];

    /**
     * @return array{0: 'instant'|'duration', 1: 'debit'|'credit'|null, 2: 'monetary'|'pure'|'decimal'|'string'|'date'|'enum'}
     */
    public static function concept(string $qname): array
    {
        return self::CONCEPTS[$qname] ?? throw new InvalidArgumentException("Unknown concept '{$qname}'.");
    }

    /**
     * Every concept named in the maps above.
     *
     * @return list<string>
     */
    public static function mapped(): array
    {
        $names = [];

        foreach ([self::INCOME, self::BALANCE, self::SECTIONS, self::TOTALS, self::MULTI_YEAR, self::DISPOSITION, self::PLEDGED, self::FACTS, self::CERTIFICATE] as $map) {
            array_push($names, ...array_values(array_filter($map, 'is_string')));
        }

        foreach ([self::EQUITY, self::FIXED_ASSET_NOTES] as $nested) {
            foreach ($nested as $map) {
                array_push($names, ...array_values(array_filter($map, 'is_string')));
            }
        }

        array_push($names, ...array_keys(self::INCOME_HIDDEN_SUBTOTALS), ...array_keys(self::BALANCE_HIDDEN_SUBTOTALS), ...array_keys(self::ENUMERATIONS));

        return array_values(array_unique($names));
    }
}
