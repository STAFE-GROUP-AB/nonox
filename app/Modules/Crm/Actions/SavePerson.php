<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes a contact person (ADR-043). The legal basis is required
 * (GDPR art. 6.1), and consent needs the date it was given. A new person can
 * be placed at an account at once, with a role.
 */
final class SavePerson
{
    public function __construct(private readonly LinkPerson $link) {}

    /**
     * @param  array<string, mixed>  $input  account_id and role place a new person at an account
     */
    public function __invoke(User $user, Company $company, array $input, ?Person $person = null): Person
    {
        $person === null
            ? Gate::forUser($user)->authorize('manage', [Person::class, $company])
            : Gate::forUser($user)->authorize('update', $person);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'legal_basis' => ['required', Rule::enum(LegalBasis::class)],
            'legal_basis_note' => ['nullable', 'string', 'max:1000'],
            'consent_at' => ['nullable', 'required_if:legal_basis,'.LegalBasis::Consent->value, 'date', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
            'informed_at' => ['nullable', 'date', 'before_or_equal:today'],
            // ADR-048: a dated consent to e-mail marketing, and the customer exception (marknadsföringslagen 19 § second paragraph).
            'marketing_consent_at' => ['nullable', 'date', 'before_or_equal:today'],
            'customer_exception_at' => ['nullable', 'date', 'before_or_equal:today'],
            'do_not_contact' => ['sometimes', 'boolean'],
            'lift_restriction' => ['sometimes', 'boolean'],
            'do_not_contact_reason' => ['nullable', 'required_if_accepted:do_not_contact', Rule::enum(DoNotContact::class)],
            'account_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::enum(PersonRole::class)],
        ], [
            'legal_basis.required' => __('Ange den rättsliga grunden för att spara personen.'),
            'consent_at.required_if' => __('Ange datumet då personen samtyckte.'),
            'do_not_contact_reason.required_if_accepted' => __('Ange om personen har invänt eller begärt begränsad behandling.'),
        ], [
            'name' => __('namn'),
            'email' => __('e-post'),
            'legal_basis' => __('rättslig grund'),
            'consent_at' => __('datum för samtycke'),
        ])->validate();

        $attributes = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $validated);
        $accountId = $attributes['account_id'] ?? null;
        $role = $attributes['role'] ?? null;
        unset($attributes['account_id'], $attributes['role']);

        $lift = (bool) ($attributes['lift_restriction'] ?? false);
        unset($attributes['lift_restriction']);
        $wasRestricted = $person?->do_not_contact_reason === DoNotContact::Restriction;

        if (array_key_exists('do_not_contact', $attributes)) {
            $flag = (bool) $attributes['do_not_contact'];
            $attributes['do_not_contact_reason'] = $flag ? $attributes['do_not_contact_reason'] : null;

            // Lifting a restriction is its own deliberate step: the person must be told first (art. 18(3)).
            if ($person?->do_not_contact_reason === DoNotContact::Restriction && $attributes['do_not_contact_reason'] !== DoNotContact::Restriction->value && ! $lift) {
                throw ValidationException::withMessages(['do_not_contact' => __('En begränsning hävs bara med Häv begränsningen på personens sida, och först när personen har informerats (GDPR artikel 18.3).')]);
            }

            // A new reason starts its own clock: the one note allowed under a restriction counts from the restriction.
            $sameReason = $person?->do_not_contact_reason?->value === $attributes['do_not_contact_reason'];
            $attributes['do_not_contact_at'] = $flag ? ($sameReason ? ($person->do_not_contact_at ?? now()) : now()) : null;
        } else {
            unset($attributes['do_not_contact_reason']);
        }

        unset($attributes['do_not_contact']);

        if (($attributes['legal_basis'] ?? null) !== LegalBasis::Consent->value) {
            $attributes['consent_at'] = null;
        }

        return DB::transaction(fn (): Person => CurrentCompany::run($company, function () use ($user, $company, $attributes, $person, $accountId, $role, $wasRestricted): Person {
            if ($person === null) {
                // Someone who objected and was erased is flagged again when added back (art. 21(3)).
                if (($attributes['do_not_contact_at'] ?? null) === null
                    && SuppressionList::match($attributes['email'] ?? null, $attributes['phone'] ?? null, $attributes['mobile'] ?? null)) {
                    $attributes['do_not_contact_at'] = now();
                    $attributes['do_not_contact_reason'] = DoNotContact::Objection->value;
                }

                $person = Person::create([...$attributes, 'last_activity_at' => now()]);
                SuppressionList::remember($person);

                Audit::record('crm.person_created', $person, ['legal_basis' => $person->legal_basis->value], $company, $user);

                if ($accountId !== null) {
                    ($this->link)($user, $company, Account::query()->findOrFail((int) $accountId), $person, PersonRole::tryFrom((string) $role) ?? PersonRole::Other);
                }

                return $person;
            }

            $before = $person->only(array_keys($attributes));
            $objected = $person->do_not_contact_reason === DoNotContact::Objection;
            $restricted = $person->do_not_contact_reason === DoNotContact::Restriction;
            $old = $person->only(['email', 'phone', 'mobile']);
            $person->fill($attributes)->forceFill(['last_activity_at' => now()]);

            // A new address has not bounced (ADR-048).
            if ($person->isDirty('email')) {
                $person->forceFill(['email_bounced_at' => null]);
            }

            $person->save();

            if ($objected && $person->do_not_contact_reason !== DoNotContact::Objection) {
                // The objection is withdrawn: the old and new details leave the list, unless another objector uses them.
                SuppressionList::forget($person, [$old['email'], $person->email], [$old['phone'], $old['mobile'], $person->phone, $person->mobile]);
            }

            SuppressionList::remember($person);

            if (! $restricted && $person->do_not_contact_reason === DoNotContact::Restriction) {
                // Art. 18: the data may only be stored. Open tasks and planned activities about the person are closed, so they leave Mina aktiviteter.
                $closed = Activity::query()->where('person_id', $person->id)->whereNull('done_at')->update(['done_at' => now(), 'updated_at' => now()]);
                Audit::record('crm.activities_closed_for_restriction', $person, ['person_id' => $person->id, 'activities' => $closed], $company, $user);
            }

            // Names and contact details stay out of the append-only log: only which fields changed.
            Audit::record('crm.person_updated', $person, ['fields' => array_keys(Audit::changes($before, $person->only(array_keys($attributes))))], $company, $user);

            if ($wasRestricted && $person->do_not_contact_reason !== DoNotContact::Restriction) {
                Audit::record('crm.restriction_lifted', $person, ['person_id' => $person->id], $company, $user);
            }

            return $person;
        }));
    }
}
