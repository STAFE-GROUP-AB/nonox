<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\PledgedAssetKind;
use App\Domain\Closing\DividendLimit;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Årsredovisning: the texts of the K2 annual report, a preview
 * built from the books, and the PDF.
 */
class AnnualReportPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    /** @var array<string, mixed> */
    public array $form = [];

    public int $version = 0;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            // The latest year that has ended; otherwise the current one.
            $this->year = (int) (FiscalYear::query()->whereDate('end_date', '<', now()->toDateString())->orderByDesc('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }

        $this->loadForm();
    }

    public function updatedYear(): void
    {
        $this->loadForm();
    }

    public function save(SaveAnnualReport $save): void
    {
        $save($this->user(), FiscalYear::query()->findOrFail($this->year), $this->form);
        $this->version++;
        $this->toast(__('Årsredovisningen är sparad.'));
    }

    public function render(): View
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $supported = AnnualReportDocument::supports($this->company());

        return view('livewire.accounting.annual-report', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'supported' => $supported,
            'freeEquity' => $supported ? DividendLimit::freeEquityOre($fiscalYear) : 0,
            'hasBankLoans' => $supported && self::hasBankLoans($fiscalYear),
            'pledgedKinds' => PledgedAssetKind::cases(),
        ]);
    }

    /**
     * Loans from credit institutions at the end of the year (BAS 2330–2359,
     * 2410–2419, 2480–2489), where a företagsinteckning is likely; the page
     * then reminds about the note on ställda säkerheter (#92).
     */
    private static function hasBankLoans(FiscalYear $fiscalYear): bool
    {
        $accounts = AnnualAccounts::for($fiscalYear);

        foreach ([[2330, 2359], [2410, 2419], [2480, 2489]] as [$from, $to]) {
            if ($accounts->closingOf($from, $to) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function loadForm(): void
    {
        $report = AnnualReport::query()->where('fiscal_year_id', $this->year)->first();
        // Jetstream keeps the company's owner on the company itself, other owners as members.
        $owners = collect([$this->company()->owner()->value('name')])
            ->merge($this->company()->users()->wherePivot('role', 'owner')->pluck('name'))
            ->filter()->unique()->map(fn (mixed $name): string => "{$name}, ".__('Styrelseledamot'))->implode("\n");

        $this->form = [
            'business_description' => (string) ($report->business_description ?? ''),
            'significant_events' => (string) ($report->significant_events ?? ''),
            'dividend' => $report === null || $report->dividend_ore->ore === 0 ? '' : $report->dividend_ore->format(withCurrency: false),
            'average_employees' => (string) ($report->average_employees ?? 0),
            'signing_place' => (string) ($report->signing_place ?? $this->company()->city ?? ''),
            'signing_date' => (string) ($report?->signing_date?->toDateString() ?? ''),
            'signatories' => $report === null ? $owners : collect($report->signatories)->map(fn (array $person): string => trim("{$person['name']}, {$person['role']}", ', '))->implode("\n"),
            'adoption_date' => (string) ($report?->adoption_date?->toDateString() ?? ''),
            'adoption_decision' => (string) ($report->adoption_decision ?? ''),
            'certifier_name' => (string) ($report->certifier_name ?? ''),
            'certifier_role' => (string) ($report->certifier_role ?? ''),
            'pledged' => collect(PledgedAssetKind::cases())->mapWithKeys(fn (PledgedAssetKind $kind): array => [
                $kind->value => isset($report->pledged_assets[$kind->value]) ? Money::ofOre((int) $report->pledged_assets[$kind->value])->format(withCurrency: false) : '',
            ])->all(),
            'pledged_other_text' => (string) ($report->pledged_other_text ?? ''),
            'contingent_liabilities' => $report?->contingent_liabilities_ore === null ? '' : Money::ofOre($report->contingent_liabilities_ore)->format(withCurrency: false),
            'contingent_liabilities_text' => (string) ($report->contingent_liabilities_text ?? ''),
            'group_commitments' => (string) ($report->group_commitments ?? ''),
            'events_after' => (string) ($report->events_after ?? ''),
            'events_effect_unknown' => (bool) ($report->events_effect_unknown ?? false),
        ];
        $this->resetErrorBag();
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
