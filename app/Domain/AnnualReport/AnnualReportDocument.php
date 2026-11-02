<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Assets\AssetCategory;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A K2 annual report put together from the books and the texts a person
 * wrote: förvaltningsberättelse with the multi-year overview, changes in
 * equity and the proposed disposition, the income statement, the balance
 * sheet, notes and signatures. Rendered as HTML and as PDF (ADR-002).
 *
 * TODO(legal-verify): the report follows ÅRL and BFNAR 2016:10 as far as
 * the books allow; the note set (accounting principles, employees, fixed
 * assets, and the entered notes on ställda säkerheter, eventualförpliktelser,
 * åtaganden for group companies and events after the year) and the standard
 * texts must be checked before filing.
 */
final class AnnualReportDocument
{
    /** Legal forms whose annual report this document is made for. */
    public static function supports(Company $company): bool
    {
        return in_array($company->legal_form, [LegalForm::Ab, LegalForm::EkonomiskForening], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(Company $company, FiscalYear $fiscalYear, AnnualReport $report): array
    {
        return CurrentCompany::run($company, function () use ($company, $fiscalYear, $report): array {
            $accounts = AnnualAccounts::for($fiscalYear);
            $free = $accounts->freeEquity();
            $dividend = $report->dividend_ore->ore;

            return [
                'company' => $company,
                'fiscalYear' => $fiscalYear,
                'report' => $report,
                'previousLabel' => $accounts->previousYear?->label() ?? $fiscalYear->start_date->subDay()->toDateString(),
                'income' => $accounts->incomeStatement(),
                'hasPreviousIncome' => $accounts->previousYear !== null,
                'balance' => $accounts->balanceSheet(),
                'multiYear' => $accounts->multiYear(),
                'equityColumns' => $accounts->equityColumns(),
                'equityChanges' => $accounts->equityChanges(),
                'disposition' => ['free' => $free, 'dividend' => $dividend, 'carried' => $free - $dividend],
                'fixedAssets' => $accounts->fixedAssetNotes(),
                'certificate' => self::certificate($company, $fiscalYear, $report, $free),
                'pledged' => self::pledged($report),
                'depreciation' => self::depreciationPrinciples(),
            ];
        });
    }

    /**
     * The fastställelseintyg on the copy filed with Bolagsverket (ÅRL 8 kap.
     * 3 §): a board member or the managing director certifies that the income
     * statement and balance sheet have been adopted, on which day, and the
     * meeting's decision on the profit or loss. The decision is the text the
     * person wrote, else the booked disposition (#91).
     *
     * TODO(legal-verify): Bolagsverket's form requirements for the
     * certificate and the certified copy (bestyrkande), which could not be
     * read on 2026-11-02.
     *
     * @return array{meeting: string, date: ?string, decision: ?string, name: ?string, role: ?string}
     */
    public static function certificate(Company $company, FiscalYear $fiscalYear, AnnualReport $report, int $free): array
    {
        return [
            'meeting' => self::meeting($company),
            'date' => $report->adoption_date?->toDateString(),
            'decision' => filled($report->adoption_decision) ? $report->adoption_decision : self::bookedDecision($company, $fiscalYear, $free),
            'name' => $report->certifier_name,
            'role' => $report->certifier_role,
        ];
    }

    /** The meeting that adopts the accounts: årsstämman (AB) or föreningsstämman (ekonomisk förening). */
    public static function meeting(Company $company): string
    {
        return $company->legal_form === LegalForm::EkonomiskForening ? __('Föreningsstämman') : __('Årsstämman');
    }

    /**
     * The meeting's decision written out: from the booked disposition when
     * there is one, else from the board's proposal in the report.
     */
    public static function decisionText(Company $company, int $free, int $dividend): string
    {
        $owners = $company->legal_form === LegalForm::EkonomiskForening ? __('medlemmarna') : __('aktieägarna');
        $amount = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);

        return $dividend > 0
            ? __(':meeting beslutade att av de till förfogande stående medlen, :free kr, dela ut :dividend kr till :owners och balansera :carried kr i ny räkning.', ['meeting' => self::meeting($company), 'free' => $amount($free), 'dividend' => $amount($dividend), 'owners' => $owners, 'carried' => $amount($free - $dividend)])
            : __(':meeting beslutade att de till förfogande stående medlen, :free kr, balanseras i ny räkning.', ['meeting' => self::meeting($company), 'free' => $amount($free)]);
    }

    private static function bookedDecision(Company $company, FiscalYear $fiscalYear, int $free): ?string
    {
        $entry = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::DISPOSITION)->first();

        return $entry === null ? null : self::decisionText($company, $free, $entry->dividend_ore->ore);
    }

    /**
     * The ställda säkerheter entered, per kind in the order of BFNAR
     * 2016:10 p. 18.13 (#92).
     *
     * @return list<array{label: string, amount: int}>
     */
    public static function pledged(AnnualReport $report): array
    {
        $rows = [];

        foreach (PledgedAssetKind::cases() as $kind) {
            $amount = (int) ($report->pledged_assets[$kind->value] ?? 0);

            if ($amount !== 0) {
                $label = $kind === PledgedAssetKind::Other && filled($report->pledged_other_text) ? (string) $report->pledged_other_text : $kind->label();
                $rows[] = ['label' => $label, 'amount' => $amount];
            }
        }

        return $rows;
    }

    public static function html(Company $company, FiscalYear $fiscalYear, AnnualReport $report): string
    {
        return view('annual-report.document', self::data($company, $fiscalYear, $report))->render();
    }

    public static function pdf(Company $company, FiscalYear $fiscalYear, AnnualReport $report): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($company, $fiscalYear, $report));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(Company $company, FiscalYear $fiscalYear): string
    {
        return 'Arsredovisning-'.preg_replace('/\D/', '', (string) $company->org_number).'-'.$fiscalYear->end_date->format('Y').'.pdf';
    }

    /**
     * The useful lives in the fixed asset register, per kind of asset.
     *
     * @return list<string>
     */
    private static function depreciationPrinciples(): array
    {
        $lives = [];

        foreach (FixedAsset::query()->get(['category', 'useful_life_months']) as $asset) {
            $lives[$asset->category->value][] = $asset->useful_life_months / 12;
        }

        $principles = [];

        foreach ($lives as $category => $years) {
            $shortest = min($years);
            $longest = max($years);
            $span = $shortest === $longest ? self::years($shortest) : self::years($shortest).'–'.self::years($longest);
            $principles[] = __(':kind: :years år', ['kind' => AssetCategory::from($category)->label(), 'years' => $span]);
        }

        return $principles;
    }

    private static function years(int|float $years): string
    {
        return rtrim(rtrim(number_format($years, 1, ',', ''), '0'), ',');
    }
}
