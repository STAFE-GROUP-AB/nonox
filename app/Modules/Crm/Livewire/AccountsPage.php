<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Domain\AccountFigures;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kunder while CRM is on (ADR-043): every account, prospects and customers,
 * with search, filters for status, owner, city and tag and a sort order,
 * all kept in the query string. Each row shows the next step and two key
 * figures from the ledger. A constant number of queries per page: the
 * accounts with their next step as a subquery, the owners, and the sales.
 *
 * The register's old links keep working: ?ny=1 opens the form for a new
 * prospect and ?redigera=<customer id> opens that customer's card.
 */
class AccountsPage extends Component
{
    use WithPagination;

    public const array SORTS = ['namn', 'status', 'senast', 'ort'];

    public const array FORM_FIELDS = ['name', 'org_number', 'kind', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'website', 'source', 'tags', 'owner_id'];

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** A user id, 'mina' for the signed-in user's, or '' for everyone's. */
    #[Url(as: 'ansvarig', except: '')]
    public string $owner = '';

    #[Url(as: 'ort', except: '')]
    public string $city = '';

    #[Url(as: 'tagg', except: '')]
    public string $tag = '';

    #[Url(as: 'sortera', except: 'namn')]
    public string $sort = 'namn';

    public bool $showForm = false;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Account::class, $this->company()]), 403);

        $customerId = request()->integer('redigera');

        if ($customerId > 0) {
            $account = Account::query()->where('customer_id', $customerId)->first();

            if ($account !== null) {
                $this->redirectRoute('crm.accounts.show', ['account' => $account->id]);

                return;
            }
        }

        if (request()->boolean('ny') && $this->canManage()) {
            $this->create();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'owner', 'city', 'tag', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'owner', 'city', 'tag');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->form = array_fill_keys(self::FORM_FIELDS, '');
        $this->form['kind'] = AccountKind::Company->value;
        $this->form['owner_id'] = (string) $this->user()->id;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'form');
    }

    public function save(SaveAccount $save): void
    {
        $account = $save($this->user(), $this->company(), [...$this->form, 'owner_id' => $this->form['owner_id'] === '' ? null : (int) $this->form['owner_id']]);

        Toast::flash(__(':name är tillagt som prospekt.', ['name' => $account->name]));
        $this->redirectRoute('crm.accounts.show', ['account' => $account->id]);
    }

    public function render(AccountFigures $figures): View
    {
        $accounts = $this->query()->paginate(25);
        $customerIds = array_values(array_filter($accounts->getCollection()->pluck('customer_id')->all(), fn (mixed $id): bool => $id !== null));
        $today = CarbonImmutable::today();

        return view('crm::livewire.accounts', [
            'accounts' => $accounts,
            'sales' => $figures->sales(array_map('intval', $customerIds), $today->subYearNoOverflow()->addDay()->toDateString(), $today->toDateString()),
            'statuses' => AccountStatus::cases(),
            'kinds' => AccountKind::cases(),
            'members' => CompanyMembers::all($this->company()),
            'cities' => Account::query()->whereNotNull('city')->distinct()->orderBy('city')->limit(200)->pluck('city'),
            'tags' => Account::query()->toBase()->selectRaw('DISTINCT jsonb_array_elements_text(tags) AS tag')->orderBy('tag')->limit(200)->pluck('tag'),
            'canManage' => $this->canManage(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->owner !== '' || $this->city !== '' || $this->tag !== '',
            'now' => CarbonImmutable::now(),
        ]);
    }

    /** @return Builder<Account> */
    private function query(): Builder
    {
        $search = trim($this->search);
        $owner = $this->owner === 'mina' ? $this->user()->id : (int) $this->owner;
        $next = Activity::query()
            ->whereColumn('crm_activities.account_id', 'crm_accounts.id')
            ->whereNull('done_at')
            ->orderByRaw('due_at ASC NULLS LAST')
            ->orderBy('id')
            ->limit(1);

        return Account::query()
            ->with('owner:id,name')
            ->select('crm_accounts.*')
            ->selectSub((clone $next)->select('subject'), 'next_subject')
            ->selectSub((clone $next)->select('due_at'), 'next_due_at')
            ->selectSub((clone $next)->select('type'), 'next_type')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('org_number', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('city', 'ilike', "%{$search}%")
                ->orWhereExists(fn ($people) => $people->select(DB::raw(1))
                    ->from('crm_account_person')
                    ->join('crm_people', 'crm_people.id', '=', 'crm_account_person.person_id')
                    ->whereColumn('crm_account_person.account_id', 'crm_accounts.id')
                    ->where('crm_people.name', 'ilike', "%{$search}%"))))
            ->when(AccountStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($owner > 0, fn (Builder $query) => $query->where('owner_id', $owner))
            ->when($this->city !== '', fn (Builder $query) => $query->where('city', $this->city))
            ->when($this->tag !== '', fn (Builder $query) => $query->whereJsonContains('tags', mb_strtolower($this->tag)))
            ->tap(fn (Builder $query) => match ($this->sort) {
                'status' => $query->orderByRaw("CASE status WHEN 'ACTIVE' THEN 1 WHEN 'PROSPECT' THEN 2 WHEN 'INACTIVE' THEN 3 ELSE 4 END")->orderBy('name'),
                'senast' => $query->orderByRaw('last_activity_at DESC NULLS LAST')->orderBy('name'),
                'ort' => $query->orderByRaw('city ASC NULLS LAST')->orderBy('name'),
                default => $query->orderBy('name'),
            })
            ->orderBy('id');
    }

    private function canManage(): bool
    {
        return $this->user()->can('manage', [Account::class, $this->company()]);
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
