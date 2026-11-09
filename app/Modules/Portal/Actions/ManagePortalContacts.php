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
use App\Modules\Portal\Models\PortalEvent;
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
     * (ADR-075 §12, rectification) [Lead]. The address belongs to a person,
     * so the change makes a NEW contact for the identity of the new address
     * (the same customer, status and quote right) and removes the old one
     * the way "Ta bort min inloggning" does: the IP address and browser on
     * its sign-in events are cleared and its messages show as "Borttagen
     * kontakt". Nothing of the previous person's follows the new one. Only
     * the old contact's portal closes for the previous person (its
     * sessions answer 404 there); their portals elsewhere stay open. An
     * old contact still INVITED gets its invitation sent to the new address.
     */
    public function changeEmail(User $by, Company $company, PortalContact $contact, string $email): PortalContact
    {
        $this->authorize($by, $company);
        $validated = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();
        $email = PortalIdentity::normalise((string) $validated['email']);

        $result = CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($by, $contact, $email): array {
            $old = PortalContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $oldIdentity = PortalIdentity::query()->findOrFail($old->portal_identity_id);

            if ($oldIdentity->email === $email) {
                return ['contact' => $old, 'invite' => null];
            }

            // Identities are global, like users: found by the address alone.
            $identity = CompanyScope::bypass(fn (): PortalIdentity => PortalIdentity::query()->firstOrCreate(['email' => $email], ['name' => $oldIdentity->name]));

            if (PortalContact::query()->where('customer_id', $old->customer_id)->where('portal_identity_id', $identity->id)->exists()) {
                throw ValidationException::withMessages(['email' => __(':email har redan tillgång till kundportalen för den här kunden.', ['email' => $email])]);
            }

            $invited = $old->status === ContactStatus::Invited;
            $customerId = $old->customer_id;
            $canAccept = $old->can_accept_quotes;
            $name = $identity->name;

            self::removeContact($old);
            Audit::record('customer_portal.contact_email_changed', null, ['contact_id' => $old->id, 'customer_id' => $customerId], null, $by);

            if ($invited) {
                // Invited again below, through the normal invitation, to the new address.
                return ['contact' => null, 'invite' => ['customer_id' => $customerId, 'name' => $name, 'can_accept_quotes' => $canAccept]];
            }

            $successor = new PortalContact(['customer_id' => $customerId, 'portal_identity_id' => $identity->id]);
            $successor->forceFill([
                'status' => $old->status,
                'can_accept_quotes' => $canAccept,
                'invited_by' => $by->id,
                'invited_at' => now(),
                'accepted_at' => $old->status === ContactStatus::Active ? now() : null,
                'disabled_by' => $old->status === ContactStatus::Disabled ? $by->id : null,
                'disabled_at' => $old->status === ContactStatus::Disabled ? now() : null,
            ])->save();

            Audit::record('customer_portal.contact_invited', $successor, ['contact_id' => $successor->id, 'customer_id' => $customerId, 'replaces_contact_id' => $old->id], null, $by);

            return ['contact' => $successor, 'invite' => null];
        }));

        if ($result['invite'] !== null) {
            $customer = CurrentCompany::run($company, fn (): Customer => Customer::query()->findOrFail($result['invite']['customer_id']));

            return $this->invite($by, $company, $customer, ['name' => $result['invite']['name'], 'email' => $email, 'can_accept_quotes' => $result['invite']['can_accept_quotes']])['contact'];
        }

        /** @var PortalContact */
        return $result['contact'];
    }

    /**
     * Removes a contact as "Ta bort min inloggning" does (ADR-075 §12): the
     * IP address and browser on its events are cleared (decision [Lead]; the
     * events trigger allows only that), its messages keep their text with
     * the author shown as "Borttagen kontakt", and the identity goes when it
     * has no contacts left. Inside the contact's company and a transaction.
     */
    public static function removeContact(PortalContact $contact): void
    {
        // A query, not the model: the events are append-only.
        PortalEvent::query()->where('contact_id', $contact->id)->update(['ip' => null, 'user_agent' => null]);
        self::delete($contact);
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
