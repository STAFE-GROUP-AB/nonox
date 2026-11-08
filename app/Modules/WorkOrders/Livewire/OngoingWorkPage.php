<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Models\FiscalYear;
use App\Modules\WorkOrders\Actions\ProposeOngoingWorkVouchers;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Support\Input;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokslut → Pågående arbeten (ADR-074 §7): the open work orders on the
 * balance sheet date with the figures and a proposed adjustment. "Skapa
 * verifikationsutkast" makes two drafts, the adjustment and its reversal;
 * nothing is posted here.
 */
class OngoingWorkPage extends Component
{
    use WorkOrdersPage;

    #[Url(as: 'datum')]
    public string $date = '';

    /** @var array<int|string, string> work order id => estimated total cost as typed */
    public array $estimated = [];

    public function mount(): void
    {
        abort_unless($this->can('manage'), 403);

        if ($this->date === '') {
            $year = FiscalYear::query()->whereDate('end_date', '<', CarbonImmutable::today()->addMonths(6)->toDateString())->orderByDesc('end_date')->first();
            $this->date = ($year->end_date ?? CarbonImmutable::today()->endOfYear())->toDateString();
        }
    }

    public function propose(ProposeOngoingWorkVouchers $propose): void
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->date) ?: CarbonImmutable::today();
        $vouchers = null;
        $ok = $this->attempt(function () use ($propose, $date, &$vouchers): void {
            $vouchers = $propose($this->user(), $this->company(), $date, $this->estimatedOre());
        }, '', '');

        if ($ok && is_array($vouchers)) {
            Toast::flash(__('Två verifikationsutkast är skapade: justeringen och återföringen. Granska och bokför dem, eller ta bort dem.'));
            $this->redirectRoute('accounting.vouchers.edit', $vouchers[0]->id);
        }
    }

    public function render(): View
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->date) ?: CarbonImmutable::today();
        $lines = [];
        $error = null;

        try {
            $lines = OngoingWork::at($this->company(), $date, $this->estimatedOre());
        } catch (ValidationException $exception) {
            $error = $this->firstError($exception);
        }

        return view('work-orders::livewire.ongoing', [
            'lines' => $lines,
            'error' => $error,
            'method' => WorkOrdersSettings::fixedPriceMethod($this->company()),
            'accounts' => [OngoingWork::UNBILLED, OngoingWork::ADVANCES, OngoingWork::BILLED_AHEAD, OngoingWork::WORK_IN_PROGRESS],
        ]);
    }

    /** @return array<int, int> */
    private function estimatedOre(): array
    {
        $ore = [];

        foreach ($this->estimated as $id => $typed) {
            try {
                $value = Input::money($typed, "estimated.{$id}");
            } catch (ValidationException) {
                $value = null;
            }

            if ($value !== null && $value > 0) {
                $ore[(int) $id] = $value;
            }
        }

        return $ore;
    }
}
