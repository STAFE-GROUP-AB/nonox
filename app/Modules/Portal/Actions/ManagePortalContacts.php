<?php

declare(strict_types=1);

namespace App\Modules\Portal\Actions;

use App\Domain\Signing\SigningToken;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Models\Person;
use App\Modules\ModuleManager;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\PortalModule;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Who has access to a customer's portal (ADR-075 §3), with
 * customer-portal:manage:
 *
 * - invite: a name and an e-mail, or a CRM person (never one marked
 *   "Kontakta inte"); a customer who is a private person can only have
 *   their own address, as one contact. The link carries a 256-bit token,
 *   only its SHA-256 is stored, valid 14 days and once; inviting again
 *   revokes the earlier link. Whether the contact may accept quotes is
 *   chosen at invitation: by default yes for a private customer (it is
 *   their own contract), no for a contact at a legal person. The mail goes
 *   in the company's brand within
 *   the invitations quota, never for a test company.
 * - disable: the contact's sessions end on their next request and open
 *   links are revoked.
 * - remove: the contact is deleted; its messages keep their text with the
 *   author shown as "Borttagen kontakt". The identity goes when it has no
 *   contacts left.
 *
 * The audit log gets ids only.
 */
final class ManagePortalContacts
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  array<string, mixed>  $input  name, email, crm_person_id (optional), can_accept_quotes (optional; see above)
     * @return array{contact: PortalContact, url: string}
     */
    public function invite(User $by, Company $company, Customer $customer, array $input): array
    {
        $this->authorize($by, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'email' => ['required', 'email', 'max:255'],
            'crm_person_id' => ['nullable', 'integer'],
            'can_accept_quotes' => ['nullable', 'boolean'],
        ], [], ['name' => __('namn'), 'email' => __('e-post')])->validate();

        $email = PortalIdentity::normalise((string) $validated['email']);

        return CurrentCompany::run($company, function () use ($by, $company, $customer, $validated, $email): array {
            $customer = Customer::query()->findOrFail($customer->id);

            if (! $customer->is_active || $customer->processing_restricted_at !== null) {
                throw ValidationException::withMessages(['email' => __('Kunden är inaktiv eller har begränsad behandling och kan inte bjudas in.')]);
            }

            // TODO(legal-verify): L1 — a private person's invoices can carry a personal number (ROT/RUT): only their own address, as one contact.
            if ($customer->personal_number !== null) {
                $own = $customer->email === null ? null : PortalIdentity::normalise($customer->email);

                if ($own !== $email) {
                    throw ValidationException::withMessages(['email' => __('Kunden är en privatperson. Bara kundens egen e-postadress kan bjudas in.')]);
                }

                if (PortalContact::query()->where('customer_id', $customer->id)->whereHas('identity', fn ($query) => $query->where('email', '<>', $email))->exists()) {
                    throw ValidationException::withMessages(['email' => __('En privatperson kan ha en enda inloggning till kundportalen.')]);
                }
            }

            $personId = null;

            if (($validated['crm_person_id'] ?? null) !== null) {
                $person = Person::query()->findOrFail((int) $validated['crm_person_id']);

                if ($person->do_not_contact_at !== null) {
                    throw ValidationException::withMessages(['email' => __(':name är markerad ”Kontakta inte” och kan inte bjudas in.', ['name' => $person->name])]);
                }

                $personId = $person->id;
            }

            MailQuota::take($company, 'invitations');
            $token = SigningToken::generate();

            $canAccept = array_key_exists('can_accept_quotes', $validated) && $validated['can_accept_quotes'] !== null
                ? (bool) $validated['can_accept_quotes']
                : self::acceptsQuotesByDefault($customer);

            $contact = DB::transaction(function () use ($by, $customer, $validated, $email, $personId, $token, $canAccept): PortalContact {
                // Identities are global, like users: found by the address alone.
                $identity = CompanyScope::bypass(fn (): PortalIdentity => PortalIdentity::query()->firstOrCreate(['email' => $email], ['name' => trim((string) $validated['name'])]));

                $contact = PortalContact::query()->where('customer_id', $customer->id)->where('portal_identity_id', $identity->id)->lockForUpdate()->first();

                if ($contact instanceof PortalContact && $contact->status === ContactStatus::Active) {
                    throw ValidationException::withMessages(['email' => __(':email har redan tillgång till kundportalen för den här kunden.', ['email' => $email])]);
                }

                $contact ??= new PortalContact(['customer_id' => $customer->id, 'portal_identity_id' => $identity->id]);
                $contact->forceFill([
                    'crm_person_id' => $personId ?? $contact->crm_person_id,
                    'status' => ContactStatus::Invited,
                    'can_accept_quotes' => $canAccept,
                    'invited_by' => $by->id,
                    'invited_at' => now(),
                    'disabled_at' => null,
                    'disabled_by' => null,
                ])->save();

                $this->revokeLinks($contact);

                PortalInvitation::query()->create([
                    'contact_id' => $contact->id,
                    'token_sha256' => $token['hash'],
                    'expires_at' => now()->addDays(PortalInvitation::VALID_DAYS),
                ]);

                Audit::record('customer_portal.contact_invited', $contact, ['contact_id' => $contact->id, 'customer_id' => $customer->id, 'crm_person_id' => $personId, 'can_accept_quotes' => $canAccept], null, $by);

                return $contact;
            });

            $url = route('portal.invitation', ['token' => $token['token']]);
            $settings = PortalSettings::current();

            // A test company's mail never leaves NONOX (ADR-032).
            if (! $company->isTestCompany()) {
                Mail::to($email)->queue((new PortalMail(PortalMail::INVITATION, $company->id, $url, extra: [
                    'name' => trim((string) $validated['name']),
                    'inviter' => $by->name,
                    'customer' => $customer->name,
                    'privacy' => route('portal.privacy', ['portalKey' => $settings->portal_key]),
                ]))->afterCommit());
            }

            return ['contact' => $contact, 'url' => $url];
        });
    }

    /** By default a private customer accepts their own quotes; a contact at a legal person does not until the company says so. */
    public static function acceptsQuotesByDefault(Customer $customer): bool
    {
        return $customer->personal_number !== null;
    }

    /** "Får godkänna offerter" on a contact. */
    public function setCanAcceptQuotes(User $by, Company $company, PortalContact $contact, bool $allowed): void
    {
        $this->authorize($by, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($by, $contact, $allowed): void {
            $locked = PortalContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['can_accept_quotes' => $allowed])->save();

            Audit::record('customer_portal.contact_quote_right_changed', $locked, ['contact_id' => $locked->id, 'customer_id' => $locked->customer_id, 'can_accept_quotes' => $allowed], null, $by);
        }));
    }

    /**
     * The company changes a contact's e-mail address, which is the sign-in
     * (ADR-075 §12, rectification). The contact moves to the identity of the
     * new address (made if there is none); the old identity's sessions end,
     * and it is deleted when it has no contacts left. Identities are shared
     * between companies, so one company never edits another's sign-in.
     */
    public function changeEmail(User $by, Company $company, PortalContact $contact, string $email): PortalContact
    {
        $this->authorize($by, $company);
        $validated = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();
        $email = PortalIdentity::normalise((string) $validated['email']);

        return CurrentCompany::run($company, fn (): PortalContact => DB::transaction(function () use ($by, $contact, $email): PortalContact {
            $locked = PortalContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $old = PortalIdentity::query()->findOrFail($locked->portal_identity_id);

            if ($old->email === $email) {
                return $locked;
            }

            // Identities are global, like users: found by the address alone.
            $new = CompanyScope::bypass(fn (): PortalIdentity => PortalIdentity::query()->firstOrCreate(['email' => $email], ['name' => $old->name]));

            if (PortalContact::query()->where('customer_id', $locked->customer_id)->where('portal_identity_id', $new->id)->exists()) {
                throw ValidationException::withMessages(['email' => __(':email har redan tillgång till kundportalen för den här kunden.', ['email' => $email])]);
            }

            $locked->forceFill(['portal_identity_id' => $new->id])->save();
            $this->revokeLinks($locked);
            $old->forceFill(['sessions_valid_from' => now()])->save();

            $left = CompanyScope::bypass(fn (): bool => PortalContact::query()->where('portal_identity_id', $old->id)->exists());

            if (! $left) {
                $old->delete();
            }

            Audit::record('customer_portal.contact_email_changed', $locked, ['contact_id' => $locked->id, 'customer_id' => $locked->customer_id], null, $by);

            return $locked;
        }));
    }

    public function disable(User $by, Company $company, PortalContact $contact): void
    {
        $this->authorize($by, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($by, $contact): void {
            $locked = PortalContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => ContactStatus::Disabled, 'disabled_at' => now(), 'disabled_by' => $by->id])->save();
            $this->revokeLinks($locked);

            Audit::record('customer_portal.contact_disabled', $locked, ['contact_id' => $locked->id, 'customer_id' => $locked->customer_id], null, $by);
        }));
    }

    public function remove(User $by, Company $company, PortalContact $contact): void
    {
        $this->authorize($by, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($by, $contact): void {
            $locked = PortalContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            self::delete($locked);

            Audit::record('customer_portal.contact_removed', null, ['contact_id' => $locked->id, 'customer_id' => $locked->customer_id], null, $by);
        }));
    }

    /**
     * Deletes a contact (inside its company); the identity goes when it has
     * no contacts left in any company.
     */
    public static function delete(PortalContact $contact): void
    {
        $identityId = $contact->portal_identity_id;
        $contact->delete();

        // Whether the person has a portal anywhere else: counted across companies by the identity's own id.
        $left = CompanyScope::bypass(fn (): bool => PortalContact::query()->where('portal_identity_id', $identityId)->exists());

        if (! $left) {
            PortalIdentity::query()->whereKey($identityId)->delete();
        }
    }

    private function revokeLinks(PortalContact $contact): void
    {
        PortalInvitation::query()->where('contact_id', $contact->id)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @throws AuthorizationException */
    private function authorize(User $by, Company $company): void
    {
        abort_unless($this->modules->isEnabled(PortalModule::KEY, $company), 404);

        if (! $by->hasTeamPermission($company, 'customer-portal:manage')) {
            throw new AuthorizationException;
        }
    }
}
