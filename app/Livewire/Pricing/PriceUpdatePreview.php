<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\ApplyPriceUpdate;
use App\Actions\Pricing\RevertPriceUpdate;
use App\Domain\Pricing\PriceListExport;
use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The preview and the record of one mass price update (ADR-082 §8): old and
 * new price per line with the change, flags and the reason a price cannot be
 * changed; lines can be left out before the update is applied. Afterwards the
 * same page shows what was written and can revert it until its date has
 * passed. A fixed number of queries whatever the number of lines: one page of
 * lines with its articles, lists and units, and the totals in one query.
 */
class PriceUpdatePreview extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Locked]
    public int $runId;

    /** alla, andras, fel or oforandrade. */
    #[Url(as: 'visa', except: 'alla')]
    public string $show = 'alla';

    /** @var list<int> lines the user leaves out of the update */
    public array $excluded = [];

    public function mount(int $runId): void
    {
        $this->runId = $runId;

        abort_unless($this->user()->can('view', $this->run()), 403);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function toggle(int $lineId): void
    {
        $this->excluded = in_array($lineId, $this->excluded, true)
            ? array_values(array_diff($this->excluded, [$lineId]))
            : [...$this->excluded, $lineId];
    }

    public function apply(ApplyPriceUpdate $apply): void
    {
        try {
            $run = $apply($this->user(), $this->company(), $this->run(), $this->excluded);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->excluded = [];
        $counts = $run->counts ?? [];
        $this->toast($run->status === PriceUpdateStatus::Scheduled
            ? __(':applied priser är ändrade. :waiting grundpriser ändras den :date.', ['applied' => $counts['applied'] ?? 0, 'waiting' => $counts['scheduled'] ?? 0, 'date' => $run->lines()->where('status', PriceUpdateLineStatus::Ready->value)->min('valid_from')])
            : __(':applied priser är ändrade.', ['applied' => $counts['applied'] ?? 0]));

        if (($counts['skipped'] ?? 0) > 0) {
            $this->toast(__(':skipped priser hade ändrats sedan förhandsgranskningen och hoppades över.', ['skipped' => $counts['skipped']]), 'warning');
        }
    }

    public function revert(RevertPriceUpdate $revert): void
    {
        try {
            $run = $revert($this->user(), $this->company(), $this->run());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $kept = $run->counts['kept'] ?? 0;
        $this->toast($kept > 0
            ? __('Uppdateringen är återställd. :kept priser hade ändrats av någon annan efteråt och lämnades som de var.', ['kept' => $kept])
            : __('Uppdateringen är återställd. De gamla priserna gäller igen och ändringen finns kvar i historiken.'));
    }

    public function downloadDiff(): StreamedResponse
    {
        $run = $this->run();
        $lines = $run->lines()->with(['article:id,number', 'priceList:id,code', 'unit:id,code'])->orderBy('position')->orderBy('id')->get();
        $csv = PriceListExport::diffCsv($run, $lines);

        Audit::record('price_update.exported', $run, ['lines' => $lines->count()], $this->company(), $this->user());

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, "prisuppdatering-{$run->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        $run = $this->run();

        $lines = PriceUpdateRunLine::query()
            ->where('price_update_run_id', $run->id)
            ->with(['article:id,number,name', 'priceList:id,code,currency', 'unit:id,code'])
            ->when($this->show === 'andras', fn ($query) => $query->whereIn('status', [PriceUpdateLineStatus::Ready->value, PriceUpdateLineStatus::Applied->value]))
            ->when($this->show === 'fel', fn ($query) => $query->whereIn('status', [PriceUpdateLineStatus::Refused->value, PriceUpdateLineStatus::Skipped->value]))
            ->when($this->show === 'oforandrade', fn ($query) => $query->whereIn('status', [PriceUpdateLineStatus::Unchanged->value, PriceUpdateLineStatus::Excluded->value]))
            ->orderBy('position')
            ->orderBy('id')
            ->paginate(50);

        $totals = PriceUpdateRunLine::query()
            ->where('price_update_run_id', $run->id)
            ->toBase()
            ->selectRaw("count(*) filter (where status in ('READY', 'APPLIED')) as changing")
            ->selectRaw("count(*) filter (where status in ('REFUSED', 'SKIPPED')) as refused")
            ->selectRaw("count(*) filter (where status in ('UNCHANGED', 'EXCLUDED')) as unchanged")
            ->selectRaw("avg((new_minor - old_minor) * 10000.0 / old_minor) filter (where status in ('READY', 'APPLIED') and old_minor > 0 and new_minor is not null) as average_bp")
            ->selectRaw("count(*) filter (where flags is not null and status in ('READY', 'APPLIED')) as flagged")
            ->first();

        return view('livewire.pricing.price-update-preview', [
            'run' => $run,
            'lines' => $lines,
            'totals' => $totals,
            'canManage' => $this->user()->can('update', $run),
            'excludedCount' => count($this->excluded),
        ]);
    }

    private function run(): PriceUpdateRun
    {
        return PriceUpdateRun::query()->with('creator:id,name')->findOrFail($this->runId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
