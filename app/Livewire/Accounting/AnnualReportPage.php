<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\DividendLimit;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
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

    /** @var array<string, string> */
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
        ]);
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
