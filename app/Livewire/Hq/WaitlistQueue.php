<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Actions\Waitlist\ExportWaitlist;
use App\Domain\Waitlist\WaitlistOptions;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\WaitlistEntry;
use App\Support\Hq\HqAccess;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The waiting list in /hq (ADR-038): key figures, filters in the URL, the
 * entries newest first, bulk approval and a CSV export. Decisions on single
 * entries are made on the entry's own page.
 */
class WaitlistQueue extends Component implements SpansCompanies
{
    use DecidesWithPasskey;
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'status', except: 'PENDING')]
    public string $status = 'PENDING';

    #[Url(as: 'bransch', except: '')]
    public string $industry = '';

    #[Url(as: 'anstallda', except: '')]
    public string $employees = '';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'ordning', except: 'nyast')]
    public string $sort = 'nyast';

    /** @var list<int> */
    public array $selected = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'industry', 'employees', 'search', 'sort'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function approveSelected(?array $credential, ApproveWaitlistEntry $approve): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));

        if ($ids === []) {
            throw ValidationException::withMessages(['selected' => __('Markera minst en anmälan först.')]);
        }

        $this->confirmDecision($credential);

        $admin = $this->admin();
        $approved = 0;

        foreach (WaitlistEntry::query()->whereIn('id', $ids)->orderBy('id')->get() as $entry) {
            if (! $entry->status->isOpen()) {
                continue;
            }

            $approve($admin, $entry);
            $approved++;
        }

        $this->selected = [];
        $this->toast(trans_choice('{0} Inga av de markerade kunde godkännas.|{1} :count anmälan är godkänd. Ett mejl om inloggningen är på väg.|[2,*] :count anmälningar är godkända. Mejl om inloggningen är på väg.', $approved, ['count' => $approved]), $approved === 0 ? 'error' : 'success');
    }

    public function export(ExportWaitlist $export): StreamedResponse
    {
        $csv = $export($this->admin(), $this->query(), $this->filters());

        $this->toast(__('Exporten är klar. Filen innehåller personuppgifter, så spara den säkert och radera den när du är klar.'));

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'nonox-ko-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        $counts = WaitlistEntry::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $admin = $this->admin();

        return view('livewire.hq.waitlist-queue', [
            'entries' => $this->sorted($this->query())->paginate(25),
            'counts' => $counts,
            'approvedThisWeek' => WaitlistEntry::query()->where('status', WaitlistStatus::Approved->value)->where('decided_at', '>=', now()->startOfWeek())->count(),
            'total' => array_sum($counts),
            'statuses' => WaitlistStatus::cases(),
            'industries' => WaitlistOptions::industries(),
            'employeeRanges' => WaitlistOptions::employees(),
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
        ]);
    }

    /** @return Builder<WaitlistEntry> */
    private function query(): Builder
    {
        $query = WaitlistEntry::query();

        if (WaitlistStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }

        if ($this->industry !== '') {
            $query->where('industry', $this->industry);
        }

        if ($this->employees !== '') {
            $query->where('employees', $this->employees);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(email) like ?', [$like])
                ->orWhereRaw('lower(company_name) like ?', [$like])
                ->orWhere('org_number', 'like', $like));
        }

        return $query;
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    private function sorted(Builder $query): Builder
    {
        return $this->sort === 'aldst'
            ? $query->orderBy('created_at')->orderBy('id')
            : $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return array_filter([
            'status' => $this->status,
            'industry' => $this->industry,
            'employees' => $this->employees,
            'search' => trim($this->search) === '' ? '' : 'yes',
        ], fn (string $value): bool => $value !== '');
    }
}
