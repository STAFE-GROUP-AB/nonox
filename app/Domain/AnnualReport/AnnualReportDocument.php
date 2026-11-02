<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Assets\AssetCategory;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
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
 * the books allow; the note set (only accounting principles, employees and
 * fixed assets) and the standard texts must be checked before filing.
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
                'depreciation' => self::depreciationPrinciples(),
            ];
        });
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
