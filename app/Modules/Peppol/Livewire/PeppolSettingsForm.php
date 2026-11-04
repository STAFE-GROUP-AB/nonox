<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\SavePeppolSettings;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Peppol e-faktura's settings under Moduler: the company's Peppol ID,
 * receiving on or off, and the registration as a receiver.
 */
class PeppolSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'peppol.settings-form';

    #[Locked]
    public int $companyId;

    public string $identifier = '';

    public bool $receive = false;

    public bool $consent = false;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'settings:manage'), 403);

        $this->companyId = $company->id;
        $participant = CurrentCompany::run($company, fn (): ?PeppolParticipant => PeppolParticipant::query()->first());
        $this->identifier = $participant->identifier ?? (PeppolIdentifier::usesPersonalIdentityNumber($company) ? '' : (string) PeppolIdentifier::forOrganisationNumber($company->org_number)?->toString());
        $this->receive = (bool) $participant?->receive_enabled;
    }

    public function save(SavePeppolSettings $save): void
    {
        $save($this->user(), $this->company(), $this->identifier, $this->receive);

        $this->toast(__('Inställningarna för Peppol är sparade.'));
    }

    public function register(SavePeppolSettings $save): void
    {
        $participant = $save($this->user(), $this->company(), $this->identifier, $this->receive, register: true, consent: $this->consent);
        $this->consent = false;

        $this->toast($participant->registration_status === RegistrationStatus::Registered
            ? __('Företaget är registrerat som mottagare i Peppol.')
            : __('Registreringen är begärd. Den blir klar när Peppol-operatören har kontrollerat företaget.'));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('peppol::settings-form', [
            'participant' => CurrentCompany::run($company, fn (): ?PeppolParticipant => PeppolParticipant::query()->first()),
            'inboxOn' => app(ModuleManager::class)->isEnabled(InboxModule::KEY, $company),
        ]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
