<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Avtal (/kunder/avtal, ADR-046): the agreement register with filters in
 * the query string: running, ending or renewing within a number of days,
 * ended, and a search on title or customer. A fixed number of queries.
 */
class AgreementsPage extends Component
{
    use WithPagination;

    public const array WINDOWS = [30, 60, 90, 180, 365];

    #[Url(as: 'visa', except: 'lopande')]
    public string $show = 'lopande';

    #[Url(as: 'inom', except: 90)]
    public int $within = 90;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Account::class, $this->company()]), 403);
        $this->normalise();
    }

    public function updated(): void
    {
        $this->normalise();
        $this->resetPage();
    }

    public function render(): View
    {
        $today = CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
        $query = Agreement::query()->with(['account:id,name', 'owner:id,name']);

        match ($this->show) {
            'loper-ut' => $query->whereNotNull('end_date')
                ->where('end_date', '>=', $today->toDateString())
                ->where('end_date', '<=', $today->addDays($this->within)->toDateString()),
            'avslutade' => $query->whereNotNull('end_date')->where('end_date', '<', $today->toDateString())
                ->where(fn (Builder $q) => $q->where('auto_renew', false)->orWhereNotNull('terminated_on')->orWhereNull('renewal_months')),
            'alla' => $query,
            default => $query->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today->toDateString())
                ->orWhere(fn (Builder $r) => $r->where('auto_renew', true)->whereNull('terminated_on')->whereNotNull('renewal_months'))),
        };

        if (trim($this->search) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($this->search))).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereIn('account_id', Account::query()->whereRaw('LOWER(name) LIKE ?', [$term])->select('id')));
        }

        return view('crm::livewire.agreements', [
            'agreements' => $query->orderByRaw('end_date ASC NULLS LAST')->orderBy('id')->paginate(25, pageName: 'sida'),
            'today' => $today,
            'canManage' => $this->user()->can('manage', [Account::class, $this->company()]),
            'windows' => self::WINDOWS,
        ]);
    }

    private function normalise(): void
    {
        $this->show = in_array($this->show, ['lopande', 'loper-ut', 'avslutade', 'alla'], true) ? $this->show : 'lopande';
        $this->within = in_array($this->within, self::WINDOWS, true) ? $this->within : 90;
        $this->search = mb_substr($this->search, 0, 100);
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
