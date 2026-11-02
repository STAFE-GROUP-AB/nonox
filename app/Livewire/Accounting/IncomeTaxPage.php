<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\IncomeTax\Ink2;
use App\Domain\IncomeTax\Ink2Codes;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Inkomstdeklaration: INK2 from the books, the adjustments a
 * person adds, and the SRU files for Skatteverket.
 */
class IncomeTaxPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    /** @var array<int, string> */
    public array $adjustments = [];

    public string $consultant = '';

    public string $audited = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            $this->year = (int) (FiscalYear::query()->whereDate('end_date', '<', now()->toDateString())->orderByDesc('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }

        $this->loadForm();
    }

    public function updatedYear(): void
    {
        $this->loadForm();
    }

    public function save(IncomeTaxReturns $returns): void
    {
        $returns->save(
            $this->user(),
            FiscalYear::query()->findOrFail($this->year),
            $this->adjustments,
            $this->consultant === '' ? null : $this->consultant === '1',
            $this->audited === '' ? null : $this->audited === '1',
        );
        $this->toast(__('Deklarationsuppgifterna är sparade.'));
    }

    public function render(): View
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $supported = AnnualReportDocument::supports($this->company());
        $ink2 = $supported ? Ink2::for($this->company(), $fiscalYear, IncomeTaxReturn::query()->where('fiscal_year_id', $fiscalYear->id)->first()) : null;

        return view('livewire.accounting.income-tax', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'ink2' => $ink2,
            'forms' => $ink2 === null ? [] : ['INK2' => $ink2->ink2(), 'INK2R' => $ink2->ink2r(), 'INK2S' => $ink2->ink2s()],
            'fieldLabels' => Ink2Codes::enteredLabels(),
            'proposals' => $ink2?->proposals() ?? [],
            'labels' => Ink2Codes::LABELS,
        ]);
    }

    private function loadForm(): void
    {
        $return = IncomeTaxReturn::query()->where('fiscal_year_id', $this->year)->first();
        $this->adjustments = [];

        foreach (array_keys(Ink2Codes::enteredLabels()) as $code) {
            $amount = $return->adjustments[$code] ?? null;
            $this->adjustments[$code] = $amount === null ? '' : (string) $amount;
        }

        $this->consultant = $return?->consultant === null ? '' : ($return->consultant ? '1' : '0');
        $this->audited = $return?->audited === null ? '' : ($return->audited ? '1' : '0');
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
