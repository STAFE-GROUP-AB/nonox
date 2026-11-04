<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\UnsubscribePerson;
use App\Modules\Crm\Domain\AccountFigures;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Domain\StepPath;
use App\Modules\Crm\Domain\Timeline;
use App\Modules\Crm\Livewire\Concerns\EditsPeople;
use App\Modules\Crm\Livewire\Concerns\SendsTemplateEmails;
use App\Modules\Crm\Livewire\Concerns\WorksActivities;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A contact person's page (ADR-043): who they are and where they work, the
 * facts and the legal basis, the path of their most relevant open deal
 * (or their company's status), the activities about them in tabs, their
 * deals, what is planned next and their company's key figures from the
 * ledger. Logging, planning and completing go through the same Actions as
 * the account card (WorksActivities); the deal's next-step rule applies.
 * A person marked Kontakta inte gets no calls, mail or meetings from here;
 * under a restriction the page is read-only, as in stage 1. Phone first,
 * with a fixed number of queries whatever the history.
 */
class PersonCard extends Component
{
    use EditsPeople;
    use SendsTemplateEmails;
    use ShowsToasts;
    use WithPagination;
    use WorksActivities {
        startActivity as protected openActivityForm;
    }

    /** The tabs: the timeline, one per activity type, and the deals. */
    public const array TABS = ['tidslinje' => null, 'anteckningar' => 'NOTE', 'samtal' => 'CALL', 'moten' => 'MEETING', 'uppgifter' => 'TASK', 'e-post' => 'EMAIL', 'affarer' => 'deals'];

    #[Locked]
    public int $personId;

    /** The open panel: activity, next, complete, person or kontakta; '' for none. */
    public string $panel = '';

    #[Url(as: 'flik', except: 'tidslinje')]
    public string $tab = 'tidslinje';

    /** The open deal shown in the stepper, when the person is on several. */
    #[Url(as: 'affar', except: '')]
    public string $dealId = '';

    public string $doNotContactReason = 'OBJECTION';

    public function mount(int $personId): void
    {
        $person = Person::query()->findOrFail($personId);
        abort_unless($this->user()->can('view', $person), 403);

        $this->personId = $person->id;
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'tidslinje';
    }

    public function updatedTab(): void
    {
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'tidslinje';
        $this->resetPage('tidslinje');
    }

    /** Logga samtal, Anteckning, Planera möte, Uppgift: the form, at the person's (first) company. */
    public function startActivity(string $type, ?int $personId = null): void
    {
        $this->openActivityForm($type, $this->personId);
        $this->activity['account_id'] = (string) ($this->accounts()->first()->id ?? '');
    }

    /** Kontakta inte: set (objection or restriction) or lifted, through SavePerson as everywhere else. */
    public function startDoNotContact(): void
    {
        $this->authorizeManage();
        $this->doNotContactReason = DoNotContact::Objection->value;
        $this->panel = 'kontakta';
        $this->resetErrorBag();
    }

    public function saveDoNotContact(SavePerson $save, bool $flag = true, bool $liftRestriction = false): void
    {
        $person = $this->person();

        $save($this->user(), $this->company(), [
            ...$person->only(['name', 'title', 'email', 'phone', 'mobile', 'legal_basis_note', 'is_active']),
            'legal_basis' => $person->legal_basis->value,
            'consent_at' => $person->consent_at?->toDateString(),
            'informed_at' => $person->informed_at?->toDateString(),
            'do_not_contact' => $flag,
            'do_not_contact_reason' => $flag ? $this->doNotContactReason : null,
            'lift_restriction' => $liftRestriction,
        ], $person);

        $this->closePanel();
        $this->toast($flag ? __(':name är märkt Kontakta inte.', ['name' => $person->name]) : __(':name kan kontaktas igen.', ['name' => $person->name]));
    }

    /** Avregistrera från utskick: no more mailings, other contact still allowed (ADR-048). */
    public function unsubscribeFromMailings(UnsubscribePerson $unsubscribe): void
    {
        $unsubscribe($this->user(), $this->company(), $this->person());
        $this->toast(__('Personen får inga fler utskick.'));
    }

    /** Mejla med mall: always to this person, logged at their (first) company. */
    public function startPersonMail(): void
    {
        $this->startTemplateMail($this->personId);
    }

    protected function mailAccountId(): ?int
    {
        return $this->accounts()->first()?->id;
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'activity', 'editingActivityId', 'completion', 'personForm', 'editingPersonId', 'mentionCount', 'nextDealId', 'dealStepRequired', 'lockedAccountId');
        $this->resetErrorBag();
    }

    protected function personSaved(Person $person, bool $created): void
    {
        $this->closePanel();
        $this->toast(__(':name är sparad.', ['name' => $person->name]));
    }

    protected function personAccountId(): ?int
    {
        return null;
    }

    /** Erased from their own page: the page is gone, so back to Personer with the message. */
    protected function personErased(): void
    {
        Toast::flash(__('Personen är raderad. Aktiviteterna om personen finns kvar utan namn och text.'));
        $this->redirectRoute('crm.people');
    }

    public function render(AccountFigures $figures, Timeline $timeline): View
    {
        $person = $this->person();
        $company = $this->company();
        $user = $this->user();
        $now = CarbonImmutable::now();
        $accounts = $this->accounts();
        $primary = $accounts->first();
        $canSeeInvoices = $user->hasTeamPermission($company, 'invoices:view');
        $limitedLedger = ! $canSeeInvoices && $primary?->kind === AccountKind::Person;
        $canUpdate = $user->can('update', $person);
        $restricted = $person->do_not_contact_reason === DoNotContact::Restriction;
        $objected = $person->do_not_contact_reason === DoNotContact::Objection;

        $deals = Deal::query()
            ->where('person_id', $person->id)
            ->with(['stage:id,name', 'pipeline:id,name'])
            ->withMin(['activities as next_due_at' => fn (Builder $query) => $query->whereNull('done_at')], 'due_at')
            ->withCount(['activities as open_steps' => fn (Builder $query) => $query->whereNull('done_at')])
            ->orderByRaw("CASE status WHEN 'OPEN' THEN 0 ELSE 1 END")
            ->orderByRaw('expected_close_date ASC NULLS LAST')
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();
        $openDeals = $deals->filter(fn (Deal $deal): bool => $deal->isOpen())->values();
        $current = $openDeals->first(fn (Deal $deal): bool => (string) $deal->id === $this->dealId) ?? $openDeals->first();
        $type = self::TABS[$this->tab] ?? null;
        $counts = $timeline->counts($person);
        // As on the account card: the first invoice dates the status path, unless the seller may not see a private person's ledger.
        $firstInvoice = $primary?->customer_id === null || $limitedLedger ? null : Invoice::query()->where('customer_id', $primary->customer_id)->whereNotNull('issued_at')->where('kind', Invoice::INVOICE)->min('invoice_date');

        return view('crm::livewire.person-card', [
            // Mejla med mall (ADR-048): only while its panel is open; the person is the recipient.
            'templates' => $this->panel === 'mall' ? EmailTemplate::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'mailRecipients' => [(string) $person->id => "{$person->name} ({$person->email})"],
            'person' => $person,
            'accounts' => $accounts,
            // The edited activity's own account belongs in the form's choice, also when the person has left it.
            'activityAccounts' => $this->lockedAccountId === null ? $accounts : Account::query()->whereKey($this->lockedAccountId)->get(['id', 'name']),
            'primary' => $primary,
            'deals' => $deals,
            'openDeals' => $openDeals,
            'currentDeal' => $current,
            'steps' => $current instanceof Deal ? StepPath::forDeal($current) : StepPath::forAccount($primary, $firstInvoice === null ? null : (string) $firstInvoice),
            'tabs' => $this->tabs($counts, $deals->count()),
            'entries' => $type === 'deals' ? null : $timeline->forPerson($person, 10, 'tidslinje', $type),
            'upcoming' => Activity::query()->where('person_id', $person->id)->whereNull('done_at')->where('type', '<>', ActivityType::Note->value)->with(['account:id,name', 'deal:id,title'])->orderByRaw('due_at ASC NULLS LAST')->orderBy('id')->limit(4)->get(),
            'lastContact' => Activity::query()->where('person_id', $person->id)->whereNotNull('done_at')->max('done_at'),
            'figures' => $primary?->customer_id !== null && ! $limitedLedger ? $figures->forCustomer($primary->customer_id) : null,
            'limitedLedger' => $limitedLedger,
            'canSeeInvoices' => $canSeeInvoices,
            'canUpdate' => $canUpdate,
            // Under a restriction the data may only be stored (stage 1): no new contact, no edits; an open step can still be completed.
            'canManage' => $canUpdate && ! $restricted,
            'restricted' => $restricted,
            'objected' => $objected,
            'contactBlock' => match (true) {
                $restricted => __('Behandlingen av personens uppgifter är begränsad.'),
                $objected => __('Personen har bett att inte bli kontaktad.'),
                default => null,
            },
            'noAccount' => $primary === null ? __('Koppla personen till ett företag först.') : null,
            'members' => in_array($this->panel, ['activity', 'next', 'complete'], true) ? CompanyMembers::all($company) : collect(),
            'types' => ActivityType::cases(),
            'outcomes' => CallOutcome::cases(),
            'roles' => PersonRole::cases(),
            'reasons' => DoNotContact::cases(),
            'now' => $now,
        ]);
    }

    /**
     * @param  array{all: int, ledger: int, types: array<string, int>}  $counts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function tabs(array $counts, int $deals): array
    {
        $labels = ['tidslinje' => __('Tidslinje'), 'anteckningar' => __('Anteckningar'), 'samtal' => __('Samtal'), 'moten' => __('Möten'), 'uppgifter' => __('Uppgifter'), 'e-post' => __('E-post'), 'affarer' => __('Affärer')];
        $tabs = [];

        foreach (self::TABS as $key => $type) {
            $tabs[] = ['key' => $key, 'label' => $labels[$key], 'count' => match ($type) {
                null => $counts['all'],
                'deals' => $deals,
                default => $counts['types'][$type] ?? 0,
            }];
        }

        return $tabs;
    }

    /**
     * The person's companies, the decision maker's place first.
     *
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()
            ->whereHas('people', fn (Builder $query) => $query->whereKey($this->personId))
            ->with(['owner:id,name', 'people' => fn ($query) => $query->whereKey($this->personId)])
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Account $account): int => $account->people->first()?->link->role === PersonRole::DecisionMaker ? 0 : 1)
            ->values();
    }

    private function person(): Person
    {
        return Person::query()->findOrFail($this->personId);
    }

    /** @return Builder<Activity> */
    protected function activities(): Builder
    {
        return Activity::query()->where('person_id', $this->personId);
    }

    /**
     * An edited activity, and the next step after one, stay at their own
     * account (even one the person has since left); a new one is at the
     * company chosen in the form, if the person is there, else their first.
     */
    protected function activityAccountId(): int
    {
        if ($this->lockedAccountId !== null) {
            return $this->lockedAccountId;
        }

        $chosen = (int) ($this->activity['account_id'] ?? 0);
        $ids = $this->accounts()->pluck('id')->all();

        return in_array($chosen, $ids, true) ? $chosen : (int) ($ids[0] ?? 0);
    }

    protected function authorizeManage(): void
    {
        $person = $this->person();
        abort_unless($this->user()->can('update', $person), 403);
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
