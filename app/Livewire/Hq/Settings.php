<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Hq\ResolveOperatorReview;
use App\Actions\Hq\UpdateInstanceSettings;
use App\Jobs\SyncToOperator;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OperatorLink;
use App\Models\WaitlistEntry;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\MailingPause;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Inställningar in /hq (ADR-044): the operator company, the queue and
 * self-registration switches, how the sync is doing, and the links waiting
 * for a decision ("Koppla till befintlig kund?"). Saving, retrying and
 * deciding are decisions: a fresh passkey confirmation, like the queue's.
 */
class Settings extends Component implements SpansCompanies
{
    use DecidesWithPasskey;
    use ShowsToasts;

    public string $operatorCompanyId = '';

    public bool $waitlistOpen = true;

    public bool $registrationOpen = false;

    public bool $demoEnabled = false;

    public function mount(): void
    {
        $this->fill(self::stored());
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function save(?array $credential, UpdateInstanceSettings $update): void
    {
        $this->confirmDecision($credential);

        $changed = $update($this->admin(), [
            'operator_company_id' => $this->operatorCompanyId === '' ? null : $this->operatorCompanyId,
            'waitlist_open' => $this->waitlistOpen,
            'registration_open' => $this->registrationOpen,
            'demo_enabled' => $this->demoEnabled,
        ]);

        $this->fill(self::stored());
        $this->toast($changed === [] ? __('Inget ändrades.') : __('Inställningarna är sparade.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function retryFailed(?array $credential): void
    {
        $this->confirmDecision($credential);

        $failed = $this->links()->whereNotNull('failed_at')->orderBy('id')->limit(500)->get();

        foreach ($failed as $link) {
            if ($link->source_company_id !== null) {
                SyncToOperator::dispatch(SyncToOperator::COMPANY, $link->source_company_id);
            } elseif ($link->waitlist_entry_id !== null) {
                SyncToOperator::dispatch(SyncToOperator::WAITLIST, $link->waitlist_entry_id);
            }
        }

        Audit::instance('hq.operator_sync_retried', null, ['count' => $failed->count()], $this->admin());

        $this->toast(trans_choice('{0} Inget att försöka igen.|{1} En synk körs igen.|[2,*] :count synkar körs igen.', $failed->count(), ['count' => $failed->count()]));
    }

    /**
     * Koppla (link to the operator's existing record) or Skapa ny.
     *
     * @param  ?array<array-key, mixed>  $credential
     */
    public function resolve(?array $credential, int $linkId, string $decision, ResolveOperatorReview $resolve): void
    {
        $this->confirmDecision($credential);

        $resolve($this->admin(), $this->links()->findOrFail($linkId), $decision);

        $this->toast($decision === OperatorLink::DECISION_LINK ? __('Kopplad till den befintliga posten.') : __('En ny post skapas.'));
    }

    /**
     * Lifts a company's mailing pause that only HQ may lift (ADR-048: a
     * second complaint pause within 30 days).
     *
     * @param  ?array<array-key, mixed>  $credential
     */
    public function liftMailingPause(?array $credential, int $companyId, MailingPause $pause): void
    {
        $this->confirmDecision($credential);

        abort_unless(in_array($companyId, MailingPause::hqOnlyCompanyIds(), true), 404);
        $pause->liftByHq($this->admin(), Company::query()->findOrFail($companyId));

        $this->toast(__('Utskicken är återupptagna för företaget.'));
    }

    public function render(): View
    {
        $admin = $this->admin();
        $operatorId = InstanceSettings::current()->operatorCompanyId();
        $operator = $operatorId === null ? null : Company::query()->find($operatorId);

        return view('livewire.hq.settings', [
            'companies' => Company::query()->whereNull('archived_at')->where('is_test_company', false)->orderBy('name')->get(['id', 'name']),
            'operator' => $operator,
            'crmOff' => $operator !== null && ! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $operator),
            'customerLinks' => $this->links()->whereNotNull('source_company_id')->whereNotNull('customer_id')->count(),
            'prospectLinks' => $this->links()->whereNull('source_company_id')->whereNotNull('crm_account_id')->count(),
            'suppressed' => $this->links()->where('status', OperatorLink::SUPPRESSED)->count(),
            'failures' => $this->links()->whereNotNull('failed_at')->latest('failed_at')->limit(50)->get(['id', 'source_company_id', 'waitlist_entry_id', 'error', 'failed_at']),
            'failureCount' => $this->links()->whereNotNull('failed_at')->count(),
            'reviews' => $operator === null ? collect() : $this->reviews($operator),
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
            'pausedMailings' => Company::query()->whereIn('id', MailingPause::hqOnlyCompanyIds())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return Builder<OperatorLink> the current operator company's links only */
    private function links(): Builder
    {
        return OperatorLink::query()->where('target_company_id', InstanceSettings::current()->operatorCompanyId() ?? 0);
    }

    /**
     * The links waiting for a decision, with the two records side by side.
     * A fixed number of queries, whatever the number of links.
     *
     * @return Collection<int, array{id: int, source: array{label: string, name: string, org_number: ?string, detail: ?string}, candidate: array{label: string, name: string, org_number: ?string, detail: ?string}}>
     */
    private function reviews(Company $operator): Collection
    {
        $links = $this->links()->where('status', OperatorLink::NEEDS_REVIEW)->whereNull('review_decision')->orderBy('id')->limit(50)->get();

        if ($links->isEmpty()) {
            return collect();
        }

        $companies = Company::query()->whereIn('id', $links->pluck('source_company_id')->filter())->get(['id', 'name', 'legal_name', 'org_number'])->keyBy('id');
        $entries = WaitlistEntry::query()->whereIn('id', $links->pluck('waitlist_entry_id')->filter())->get(['id', 'name', 'email', 'company_name', 'org_number'])->keyBy('id');

        [$customers, $accounts, $people] = CurrentCompany::run($operator, fn (): array => [
            Customer::query()->whereIn('id', $links->pluck('review_customer_id')->filter())->get(['id', 'number', 'name', 'org_number'])->keyBy('id'),
            Account::query()->whereIn('id', $links->pluck('review_account_id')->filter())->get(['id', 'name', 'org_number'])->keyBy('id'),
            Person::query()->whereIn('id', $links->pluck('review_person_id')->filter())->get(['id', 'name', 'email'])->keyBy('id'),
        ]);

        // A translation key with replacements is always a string here; __() is typed wider.
        $t = fn (string $key, array $replace = []): string => (string) __($key, $replace);

        return $links->map(function (OperatorLink $link) use ($companies, $entries, $customers, $accounts, $people, $t): array {
            $company = $link->source_company_id === null ? null : $companies->get($link->source_company_id);
            $entry = $link->waitlist_entry_id === null ? null : $entries->get($link->waitlist_entry_id);
            $customer = $link->review_customer_id === null ? null : $customers->get($link->review_customer_id);
            $account = $link->review_account_id === null ? null : $accounts->get($link->review_account_id);
            $person = $link->review_person_id === null ? null : $people->get($link->review_person_id);

            $source = $company !== null
                ? ['label' => $t('Företag :id i NONOX', ['id' => $company->id]), 'name' => (string) ($company->legal_name ?: $company->name), 'org_number' => $company->org_number, 'detail' => null]
                : ['label' => $t('Anmälan :id i kön', ['id' => $link->waitlist_entry_id]), 'name' => (string) $entry?->company_name, 'org_number' => $entry?->org_number, 'detail' => $entry === null ? null : "{$entry->name}, {$entry->email}"];

            $candidate = match (true) {
                $customer !== null => ['label' => $t('Kund :number (id :id)', ['number' => $customer->number, 'id' => $customer->id]), 'name' => $customer->name, 'org_number' => $customer->org_number, 'detail' => null],
                $account !== null => ['label' => $t('CRM-konto :id', ['id' => $account->id]), 'name' => $account->name, 'org_number' => $account->org_number, 'detail' => $person === null ? null : $t('Person :id: :name, :email', ['id' => $person->id, 'name' => $person->name, 'email' => (string) $person->email])],
                default => ['label' => $t('CRM-person :id', ['id' => (int) $person?->id]), 'name' => (string) $person?->name, 'org_number' => null, 'detail' => $person?->email],
            };

            return ['id' => $link->id, 'source' => $source, 'candidate' => $candidate];
        });
    }

    /** @return array{operatorCompanyId: string, waitlistOpen: bool, registrationOpen: bool} */
    private static function stored(): array
    {
        $settings = InstanceSettings::current();
        $settings->forget();
        $operator = $settings->operatorCompanyId();

        return [
            'operatorCompanyId' => $operator === null ? '' : (string) $operator,
            'waitlistOpen' => $settings->waitlistOpen(),
            'registrationOpen' => $settings->selfRegistrationOpen(),
            'demoEnabled' => $settings->demoEnabled(),
        ];
    }
}
