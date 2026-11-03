<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Domain\AccountFigures;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Domain\StepPath;
use App\Modules\Crm\Domain\Timeline;
use App\Modules\Crm\Livewire\Concerns\EditsPeople;
use App\Modules\Crm\Livewire\Concerns\WorksActivities;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * An account's card (ADR-043): status and owner, key figures from the
 * ledger, the people and their roles, the next step, quick buttons for a
 * call, a meeting, a note and a task, and the timeline of activities and
 * ledger events. Completing an activity asks for the next step; skipping it
 * is one tap. Built for the phone first.
 *
 * Stage 2: the account's deals, "Ny affär", and a lost account's win-back
 * date. The last open step of an open deal is completed only together with
 * the next one; no skip (DealNextStep).
 */
class AccountCard extends Component
{
    use EditsPeople;
    use ShowsToasts;
    use WithPagination;
    use WorksActivities;

    /** The tabs: the timeline, one per activity type, deals, people and the ledger documents. */
    public const array TABS = ['tidslinje' => null, 'anteckningar' => 'NOTE', 'samtal' => 'CALL', 'moten' => 'MEETING', 'uppgifter' => 'TASK', 'affarer' => 'deals', 'personer' => 'people', 'dokument' => 'ledger'];

    #[Locked]
    public int $accountId;

    #[Url(as: 'flik', except: 'tidslinje')]
    public string $tab = 'tidslinje';

    /** The stepper: '' for the status path, or an open deal's id for its pipeline. */
    #[Url(as: 'steg', except: '')]
    public string $path = '';

    /** The open panel: activity, complete, next, person, link, edit or status; '' for none. */
    public string $panel = '';

    /** @var array<string, mixed> */
    public array $edit = [];

    public string $newStatus = '';

    public string $lostReason = '';

    public string $linkPersonId = '';

    public string $linkRole = 'OTHER';

    public string $winBackAt = '';

    public function mount(int $accountId): void
    {
        $account = Account::query()->findOrFail($accountId);
        abort_unless($this->user()->can('view', $account), 403);

        $this->accountId = $account->id;
    }

    /** A lost account's win-back date: changed or cleared. */
    public function startWinBack(): void
    {
        $this->authorizeManage();

        $this->winBackAt = (string) $this->account()->win_back_at?->toDateString();
        $this->panel = 'winback';
        $this->resetErrorBag();
    }

    public function saveWinBack(SetWinBack $set): void
    {
        $date = $this->winBackAt;
        $set($this->user(), $this->company(), $this->account(), $date === '' ? null : $date);

        $this->closePanel();
        $this->toast($date === '' ? __('Ingen påminnelse om att vinna tillbaka kunden.') : __('Påminnelsen är flyttad till :date.', ['date' => CarbonImmutable::parse($date)->translatedFormat('j F Y')]));
    }

    public function startEdit(): void
    {
        $this->authorizeManage();
        $account = $this->account();

        $this->edit = [
            ...array_map(fn (mixed $value): string => (string) $value, $account->only(['name', 'org_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'sni_code', 'website', 'source', 'owner_id', 'parent_id'])),
            'kind' => $account->kind->value,
            'tags' => implode(', ', $account->tags),
        ];
        $this->panel = 'edit';
        $this->resetErrorBag();
    }

    public function saveEdit(SaveAccount $save): void
    {
        $account = $this->account();
        $input = [...$this->edit, 'owner_id' => ($this->edit['owner_id'] ?? '') === '' ? null : (int) $this->edit['owner_id'], 'parent_id' => ($this->edit['parent_id'] ?? '') === '' ? null : (int) $this->edit['parent_id']];

        if (! $this->canEditShared($account)) {
            $input = array_diff_key($input, array_flip(array_diff(Account::CUSTOMER_FIELDS, ['name'])));
            $input['name'] = $account->name;
        }

        $save($this->user(), $this->company(), $input, $account);

        $this->closePanel();
        $this->toast(__('Uppgifterna är sparade.'));
    }

    public function startStatus(): void
    {
        $this->authorizeManage();
        $account = $this->account();

        $this->newStatus = $account->status->value;
        $this->lostReason = (string) $account->lost_reason;
        $this->panel = 'status';
        $this->resetErrorBag();
    }

    public function saveStatus(SetAccountStatus $set): void
    {
        $account = $set($this->user(), $this->company(), $this->account(), $this->newStatus, $this->lostReason);

        $this->closePanel();
        $this->toast(__('Statusen är nu :status.', ['status' => mb_strtolower($account->status->label())]));
    }

    public function startLink(): void
    {
        $this->authorizeManage();
        $this->linkPersonId = '';
        $this->linkRole = PersonRole::Other->value;
        $this->panel = 'link';
        $this->resetErrorBag();
    }

    public function link(LinkPerson $link): void
    {
        $this->validate(['linkPersonId' => ['required', 'integer'], 'linkRole' => ['required', 'in:'.implode(',', array_column(PersonRole::cases(), 'value'))]], [], ['linkPersonId' => __('person')]);
        $person = Person::query()->findOrFail((int) $this->linkPersonId);

        $link($this->user(), $this->company(), $this->account(), $person, PersonRole::from($this->linkRole));

        $this->closePanel();
        $this->toast(__(':name är kopplad till kunden.', ['name' => $person->name]));
    }

    public function changeRole(int $personId, string $role, LinkPerson $link): void
    {
        $person = $this->account()->people()->findOrFail($personId);

        $link($this->user(), $this->company(), $this->account(), $person, PersonRole::tryFrom($role) ?? PersonRole::Other);

        $this->toast(__('Rollen är ändrad.'));
    }

    public function unlink(int $personId, LinkPerson $link): void
    {
        $person = $this->account()->people()->findOrFail($personId);

        $link->unlink($this->user(), $this->company(), $this->account(), $person);

        $this->toast(__(':name är inte längre kopplad till kunden. Personen finns kvar under Personer.', ['name' => $person->name]));
    }

    public function convert(ConvertToCustomer $convert): void
    {
        try {
            $customer = $convert($this->user(), $this->company(), $this->account());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':name är nu kund nummer :number.', ['name' => $customer->name, 'number' => $customer->number]));
    }

    /** GDPR erasure of a prospect, on request; a customer is erased in the customer register. */
    public function eraseProspect(EraseCrmData $erase): void
    {
        try {
            $erase->prospect($this->user(), $this->company(), $this->account());
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        Toast::flash(__('Prospektet och dess aktiviteter är raderade.'));
        $this->redirectRoute('invoicing.customers');
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'activity', 'editingActivityId', 'completion', 'edit', 'newStatus', 'lostReason', 'linkPersonId', 'linkRole', 'personForm', 'editingPersonId', 'mentionCount', 'nextDealId', 'dealStepRequired', 'winBackAt');
        $this->resetErrorBag();
    }

    protected function personSaved(Person $person, bool $created): void
    {
        $this->closePanel();
        $this->toast($created ? __(':name är tillagd.', ['name' => $person->name]) : __(':name är sparad.', ['name' => $person->name]));
    }

    protected function personAccountId(): ?int
    {
        return $this->accountId;
    }

    public function render(AccountFigures $figures, Timeline $timeline): View
    {
        $account = $this->account()->load(['owner:id,name', 'parent:id,name', 'customer', 'people' => fn ($query) => $query->orderBy('name')]);
        $company = $this->company();
        $user = $this->user();
        $now = CarbonImmutable::now();
        $canSeeInvoices = $user->hasTeamPermission($company, 'invoices:view');
        // What a private person has bought and owes is for those who may see the invoices, not for a seller: the card shows only the CRM.
        $limitedLedger = ! $canSeeInvoices && $account->kind === AccountKind::Person;

        $deals = Deal::query()
            ->where('account_id', $account->id)
            ->with(['stage:id,name', 'person:id,name'])
            ->withMin(['activities as next_due_at' => fn (Builder $query) => $query->whereNull('done_at')], 'due_at')
            ->withCount(['activities as open_steps' => fn (Builder $query) => $query->whereNull('done_at')])
            ->orderByRaw("CASE status WHEN 'OPEN' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
        $openDeals = $deals->filter(fn (Deal $deal): bool => $deal->isOpen())->values();
        $stepperDeal = $openDeals->first(fn (Deal $deal): bool => (string) $deal->id === $this->path);
        $only = self::TABS[$this->tab] ?? null;
        $counts = $timeline->counts($account, ! $limitedLedger);
        // Who first got an issued invoice, for "Kund sedan" and the status path; one query.
        $firstInvoice = $account->customer_id === null ? null : Invoice::query()->where('customer_id', $account->customer_id)->whereNotNull('issued_at')->where('kind', Invoice::INVOICE)->min('invoice_date');

        return view('crm::livewire.account-card', [
            'account' => $account,
            'figures' => $figures->forCustomer($account->customer_id),
            'timeline' => in_array($only, ['deals', 'people'], true) || ($only === 'ledger' && ! $canSeeInvoices) ? null : $timeline->for($account, 10, 'tidslinje', ! $limitedLedger, $only),
            'tabs' => $this->tabs($counts, $deals->count(), $account->people->count(), $canSeeInvoices && ! $limitedLedger && $account->customer_id !== null),
            'steps' => $stepperDeal instanceof Deal ? StepPath::forDeal($stepperDeal) : StepPath::forAccount($account, $firstInvoice),
            'stepperDeal' => $stepperDeal,
            'openDeals' => $openDeals,
            'firstInvoice' => $firstInvoice,
            'lastContact' => $this->activities()->whereNotNull('done_at')->max('done_at'),
            'canSeeInvoices' => $canSeeInvoices,
            'limitedLedger' => $limitedLedger,
            'next' => $this->activities()->whereNull('done_at')->with(['person:id,name', 'assignee:id,name', 'deal:id,title'])->orderByRaw('due_at ASC NULLS LAST')->orderBy('id')->limit(5)->get(),
            'deals' => $deals,
            'canDeal' => $user->can('manage', [Deal::class, $company]),
            'canManage' => $user->can('update', $account),
            'canEditCustomer' => $account->customer instanceof Customer && $user->can('update', $account->customer),
            'canConvert' => $account->customer_id === null && $user->can('update', $account) && $user->can('create', [Customer::class, $company]),
            // The editors offer only active customers whose processing is not restricted; an anonymised customer is inactive.
            'canSell' => $account->customer instanceof Customer && $account->customer->is_active && ! $account->customer->isRestricted() && $user->hasTeamPermission($company, 'invoices:create'),
            'canExport' => $account->customer instanceof Customer && $user->can('update', $account->customer),
            'members' => CompanyMembers::all($company),
            'types' => ActivityType::cases(),
            'outcomes' => CallOutcome::cases(),
            'roles' => PersonRole::cases(),
            'statuses' => AccountStatus::cases(),
            'kinds' => AccountKind::cases(),
            'otherPeople' => $this->panel === 'link' ? Person::query()->whereNotIn('id', $account->people->pluck('id'))->orderBy('name')->limit(500)->get(['id', 'name', 'title']) : collect(),
            'parents' => $this->panel === 'edit' ? Account::query()->whereKeyNot($account->id)->orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
            'sharedLocked' => ! $this->canEditShared($account),
            'now' => $now,
        ]);
    }

    public function updatedTab(): void
    {
        $this->resetPage('tidslinje');
    }

    /**
     * @param  array{all: int, ledger: int, types: array<string, int>}  $counts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function tabs(array $counts, int $deals, int $people, bool $documents): array
    {
        $labels = ['tidslinje' => __('Tidslinje'), 'anteckningar' => __('Anteckningar'), 'samtal' => __('Samtal'), 'moten' => __('Möten'), 'uppgifter' => __('Uppgifter'), 'affarer' => __('Affärer'), 'personer' => __('Personer'), 'dokument' => __('Dokument')];
        $tabs = [];

        foreach (self::TABS as $key => $only) {
            if ($key === 'dokument' && ! $documents) {
                continue;
            }

            $tabs[] = ['key' => $key, 'label' => $labels[$key], 'count' => match ($only) {
                null => $counts['all'],
                'deals' => $deals,
                'people' => $people,
                'ledger' => $counts['ledger'],
                default => $counts['types'][$only] ?? 0,
            }];
        }

        return $tabs;
    }

    private function canEditShared(Account $account): bool
    {
        if ($account->customer_id === null) {
            return true;
        }

        $customer = Customer::query()->find($account->customer_id);

        return $customer instanceof Customer && $this->user()->can('update', $customer);
    }

    /** @return Builder<Activity> */
    protected function activities(): Builder
    {
        return Activity::query()->where('account_id', $this->accountId);
    }

    protected function activityAccountId(): int
    {
        return $this->accountId;
    }

    private function account(): Account
    {
        return Account::query()->findOrFail($this->accountId);
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->user()->can('update', $this->account()), 403);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
