<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Hq\UpdateInstanceSettings;
use App\Jobs\SyncToOperator;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\Company;
use App\Models\OperatorLink;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Inställningar in /hq (ADR-044): the operator company and where the
 * customers and queue applicants go, the queue and self-registration
 * switches, and how the sync is doing. Saving and retrying are decisions:
 * a fresh passkey confirmation, like the queue's.
 */
class Settings extends Component implements SpansCompanies
{
    use DecidesWithPasskey;
    use ShowsToasts;

    public string $operatorCompanyId = '';

    public string $customersCompanyId = '';

    public string $waitlistCompanyId = '';

    public bool $waitlistOpen = true;

    public bool $registrationOpen = false;

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
            'customers_company_id' => $this->customersCompanyId === '' ? null : $this->customersCompanyId,
            'waitlist_company_id' => $this->waitlistCompanyId === '' ? null : $this->waitlistCompanyId,
            'waitlist_open' => $this->waitlistOpen,
            'registration_open' => $this->registrationOpen,
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

        $failed = OperatorLink::query()->whereNotNull('failed_at')->orderBy('id')->limit(500)->get();

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

    public function render(): View
    {
        $admin = $this->admin();
        $settings = InstanceSettings::current();
        $waitlistTarget = $settings->waitlistCompanyId();
        $modules = app(ModuleManager::class);

        return view('livewire.hq.settings', [
            'companies' => Company::query()->whereNull('archived_at')->where('is_test_company', false)->orderBy('name')->get(['id', 'name']),
            'customersTarget' => $settings->customersCompanyId(),
            'waitlistTarget' => $waitlistTarget,
            'waitlistCrmOff' => $waitlistTarget !== null && ! $modules->isEnabled(CrmModule::KEY, $waitlistTarget),
            'customerLinks' => OperatorLink::query()->whereNotNull('source_company_id')->whereNotNull('customer_id')->count(),
            'prospectLinks' => OperatorLink::query()->whereNull('source_company_id')->whereNotNull('crm_account_id')->count(),
            'suppressed' => OperatorLink::query()->where('status', OperatorLink::SUPPRESSED)->count(),
            'failures' => OperatorLink::query()->whereNotNull('failed_at')->latest('failed_at')->limit(50)->get(['id', 'source_company_id', 'waitlist_entry_id', 'error', 'failed_at']),
            'failureCount' => OperatorLink::query()->whereNotNull('failed_at')->count(),
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
        ]);
    }

    /** @return array{operatorCompanyId: string, customersCompanyId: string, waitlistCompanyId: string, waitlistOpen: bool, registrationOpen: bool} */
    private static function stored(): array
    {
        $settings = InstanceSettings::current();
        $settings->forget();
        $id = fn (mixed $value): string => is_int($value) ? (string) $value : '';

        return [
            'operatorCompanyId' => $id($settings->get(InstanceSettings::OPERATOR)),
            'customersCompanyId' => $id($settings->get(InstanceSettings::CUSTOMERS_TARGET)),
            'waitlistCompanyId' => $id($settings->get(InstanceSettings::WAITLIST_TARGET)),
            'waitlistOpen' => $settings->waitlistOpen(),
            'registrationOpen' => $settings->selfRegistrationOpen(),
        ];
    }
}
