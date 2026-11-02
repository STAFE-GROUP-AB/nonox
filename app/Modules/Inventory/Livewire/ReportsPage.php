<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Domain\Accounting\LedgerBalances;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\YearEndEntry;
use App\Modules\Inventory\Actions\BookStockValuation;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Rapporter: the stock value on any day (lagervärde), every
 * movement in a period (rörelser), and the year-end basis (bokslut): the
 * valuation on the last day of a fiscal year against the stock account,
 * with the button that books the change.
 */
class ReportsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'rapport')]
    public string $report = 'lagervarde';

    #[Url(as: 'datum')]
    public string $date = '';

    #[Url(as: 'metod')]
    public string $method = '';

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'typ')]
    public string $type = '';

    #[Url(as: 'ar')]
    public ?int $year = null;

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);

        $today = CarbonImmutable::today();
        $this->date = $this->validDate($this->date) ?? $today->toDateString();
        $this->from = $this->validDate($this->from) ?? $today->startOfMonth()->toDateString();
        $this->to = $this->validDate($this->to) ?? $today->toDateString();
        $this->method = (ValuationMethod::tryFrom($this->method) ?? InventorySettings::method($this->company()))->value;

        if (! $this->seesPrices() && $this->report !== 'rorelser') {
            $this->report = 'rorelser';
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['from', 'to', 'type', 'report'], true)) {
            $this->resetPage();
        }
    }

    public function book(BookStockValuation $book): void
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $valuation = null;

        $ok = $this->attempt(function () use ($book, $fiscalYear, &$valuation): void {
            $valuation = $book($this->user(), $this->company(), $fiscalYear);
        }, '', '');

        if ($ok && $valuation instanceof StockValuation) {
            $this->toast($valuation->booked_change_ore === 0
                ? __('Lagervärdet är sparat. Det stämmer redan med bokföringen, så ingen verifikation behövdes.')
                : __('Lagerförändringen är bokförd och lagervärdet är sparat som underlag.'));
        }
    }

    public function render(): View
    {
        $seesPrices = $this->seesPrices();
        $data = ['seesPrices' => $seesPrices, 'methods' => ValuationMethod::cases(), 'types' => MovementType::cases()];

        if ($this->report === 'lagervarde' && $seesPrices) {
            $data['valuation'] = StockValuationReport::at($this->company(), CarbonImmutable::parse($this->validDate($this->date) ?? 'today'), ValuationMethod::from($this->method));
        }

        if ($this->report === 'rorelser') {
            $data['movements'] = StockMovement::query()
                ->with(['article:id,number,name,unit', 'location:id,full_code', 'creator:id,name'])
                ->whereDate('occurred_on', '>=', $this->validDate($this->from) ?? '1900-01-01')
                ->whereDate('occurred_on', '<=', $this->validDate($this->to) ?? '9999-12-31')
                ->when(MovementType::tryFrom($this->type) !== null, fn ($query) => $query->where('type', $this->type))
                ->orderByDesc('occurred_on')->orderByDesc('id')
                ->paginate(50);
        }

        if ($this->report === 'bokslut' && $seesPrices) {
            $years = FiscalYear::query()->orderByDesc('start_date')->get();
            $fiscalYear = $years->firstWhere('id', $this->year) ?? $years->first(fn (FiscalYear $year): bool => $year->end_date->lessThanOrEqualTo(CarbonImmutable::today())) ?? $years->first();
            $this->year = $fiscalYear?->id;
            $data['years'] = $years;
            $data['fiscalYear'] = $fiscalYear;

            if ($fiscalYear instanceof FiscalYear) {
                $settings = InventorySettings::for($this->company());
                $account = Account::query()->where('number', (int) $settings['stock_account'])->first();
                $data['closing'] = StockValuationReport::at($this->company(), $fiscalYear->end_date);
                $data['bookValue'] = $account === null ? 0 : (int) (LedgerBalances::opening($fiscalYear)[$account->id] ?? 0) + (int) (LedgerBalances::movements($fiscalYear)[$account->id] ?? 0);
                $data['settings'] = $settings;
                $data['saved'] = StockValuation::query()->where('fiscal_year_id', $fiscalYear->id)->with('voucher.series')->latest('id')->get();
                $data['resultBooked'] = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::RESULT)->exists();
                $data['canBook'] = $this->can('manage') && $this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage') && $fiscalYear->isOpen();
            }
        }

        return view('inventory::livewire.reports', $data);
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
