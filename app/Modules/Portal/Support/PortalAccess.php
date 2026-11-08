<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Modules\ModuleManager;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\PortalModule;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Collection;

/**
 * Who may be in which portal (ADR-075 §8): an ACTIVE contact whose customer
 * is active (not anonymised, processing not restricted), whose company is
 * not archived and has the module on. Checked at sign-in and on every
 * request; anything else ends the session and answers as for an unknown
 * portal.
 */
final class PortalAccess
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Every portal the identity may enter, with company, customer and settings loaded.
     *
     * @return Collection<int, PortalContact>
     */
    public function contactsFor(PortalIdentity $identity): Collection
    {
        // The chooser lists one person's portals in every company: read across companies by the identity's own id only.
        return CompanyScope::bypass(fn (): Collection => PortalContact::query()
            ->with(['customer', 'company'])
            ->where('portal_identity_id', $identity->id)
            ->where('status', ContactStatus::Active->value)
            ->orderBy('company_id')->orderBy('id')
            ->get()
            ->filter(fn (PortalContact $contact): bool => $this->usable($contact))
            ->values());
    }

    /** The contact may use the portal now. */
    public function usable(PortalContact $contact): bool
    {
        $company = $contact->relationLoaded('company') ? $contact->company : Company::query()->find($contact->company_id);
        $customer = $contact->relationLoaded('customer') ? $contact->customer : CompanyScope::bypass(fn (): ?Customer => Customer::query()->find($contact->customer_id));

        return $contact->status === ContactStatus::Active
            && $company instanceof Company && ! $company->isArchived()
            && $customer instanceof Customer && $customer->is_active && $customer->processing_restricted_at === null
            && $this->modules->isEnabled(PortalModule::KEY, $company);
    }

    /** Whether a code may be sent to the address: it has at least one portal to enter. */
    public function hasAccess(string $email): bool
    {
        $identity = PortalIdentity::query()->where('email', PortalIdentity::normalise($email))->first();

        return $identity instanceof PortalIdentity && $this->contactsFor($identity)->isNotEmpty();
    }

    /** The company behind a portal key, or null. */
    public function settingsFor(string $key): ?PortalSettings
    {
        if (preg_match('/^'.PortalSettings::KEY_PATTERN.'$/', $key) !== 1) {
            return null;
        }

        // The key in the address is the only thing known before the context is set: looked up across companies by the key alone.
        return CompanyScope::bypass(fn (): ?PortalSettings => PortalSettings::query()->where('portal_key', $key)->first());
    }
}
