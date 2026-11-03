<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
            'account_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::enum(PersonRole::class)],
        ], [
            'legal_basis.required' => __('Ange den rättsliga grunden för att spara personen.'),
            'consent_at.required_if' => __('Ange datumet då personen samtyckte.'),
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

        if (($attributes['legal_basis'] ?? null) !== LegalBasis::Consent->value) {
            $attributes['consent_at'] = null;
        }

        return DB::transaction(fn (): Person => CurrentCompany::run($company, function () use ($user, $company, $attributes, $person, $accountId, $role): Person {
            if ($person === null) {
                $person = Person::create([...$attributes, 'last_activity_at' => now()]);

                Audit::record('crm.person_created', $person, ['legal_basis' => $person->legal_basis->value], $company, $user);

                if ($accountId !== null) {
                    ($this->link)($user, $company, Account::query()->findOrFail((int) $accountId), $person, PersonRole::tryFrom((string) $role) ?? PersonRole::Other);
                }

                return $person;
            }

            $before = $person->only(array_keys($attributes));
            $person->fill($attributes)->forceFill(['last_activity_at' => now()])->save();

            // Names and contact details stay out of the append-only log: only which fields changed.
            Audit::record('crm.person_updated', $person, ['fields' => array_keys(Audit::changes($before, $person->only(array_keys($attributes))))], $company, $user);

            return $person;
        }));
    }
}
