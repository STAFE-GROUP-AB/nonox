<?php

declare(strict_types=1);

namespace App\Modules\Portal\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalSettings;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /kunder/portal (ADR-075 §10): every contact with access to the company's
 * portal, the latest portal events, and the settings: the footer line
 * "Kundportalen drivs med NONOX" (on by default, decision O8), how many
 * months of invoices the portal shows, and the company's own addition to
 * the privacy notice. A fixed number of queries whatever the number of
 * contacts.
 */
class PortalAdmin extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const string NAME = 'portal.admin';

    public bool $showPoweredBy = true;

    public string $historyMonths = '24';

    public string $privacyNotice = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'customer-portal:view'), 403);
        $settings = PortalSettings::current();
        $this->showPoweredBy = $settings->show_powered_by;
        $this->historyMonths = (string) $settings->history_months;
        $this->privacyNotice = (string) $settings->privacy_notice;
    }

    public function saveSettings(): void
    {
        $this->authorizeManage();
        $validated = Validator::make(
            ['history_months' => $this->historyMonths, 'privacy_notice' => $this->privacyNotice],
            ['history_months' => ['required', 'integer', 'min:3', 'max:120'], 'privacy_notice' => ['nullable', 'string', 'max:4000']],
            [],
            ['history_months' => __('historik'), 'privacy_notice' => __('text om personuppgifter')],
        )->validate();

        $settings = PortalSettings::current();
        $settings->forceFill([
            'show_powered_by' => $this->showPoweredBy,
            'history_months' => (int) $validated['history_months'],
            'privacy_notice' => blank($validated['privacy_notice']) ? null : trim((string) $validated['privacy_notice']),
        ])->save();
        Audit::record('customer_portal.settings_saved', $settings, ['show_powered_by' => $this->showPoweredBy, 'history_months' => (int) $validated['history_months']]);

        $this->toast(__('Inställningarna för kundportalen är sparade.'));
    }

    public function disable(int $contactId, ManagePortalContacts $manage): void
    {
        $manage->disable($this->user(), $this->company(), PortalContact::query()->findOrFail($contactId));
        $this->toast(__('Kontakten är avstängd och kan inte längre logga in.'));
    }

    public function remove(int $contactId, ManagePortalContacts $manage): void
    {
        $manage->remove($this->user(), $this->company(), PortalContact::query()->findOrFail($contactId));
        $this->toast(__('Kontakten är borttagen från kundportalen.'));
    }

    public function render(): View
    {
        return view('portal::livewire.admin', [
            'settings' => PortalSettings::current(),
            'contacts' => PortalContact::query()->with(['identity', 'customer:id,name,number'])->orderBy('customer_id')->orderBy('id')->paginate(25),
            'events' => PortalEvent::query()->with(['contact.identity'])->orderByDesc('id')->limit(30)->get(),
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'customer-portal:manage'),
        ]);
    }

    private function authorizeManage(): void
    {
        if (! $this->user()->hasTeamPermission($this->company(), 'customer-portal:manage')) {
            throw new AuthorizationException;
        }
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
