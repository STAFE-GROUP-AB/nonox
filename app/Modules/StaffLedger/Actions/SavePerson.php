<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\StaffLedger\Domain\Countries;
use App\Modules\StaffLedger\Domain\Identity;
use App\Modules\StaffLedger\Domain\QrToken;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Audit\Audit;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a person in the staff ledger register. The person is
 * identified by a Swedish personal or coordination number (validated with
 * the Luhn digit) or, for someone without one, a foreign identity number
 * and the country that issued it (never Luhn-checked). Either is kept
 * encrypted. Hired staff also get their employer's name and Swedish
 * organisation number, or a foreign employer number and its country. A
 * person may be linked to a payroll employee (payroll on) and to a login of
 * the company (for mobile check-in). Neither the number nor its country
 * reaches the audit log.
 *
 * Foreign numbers follow 9 kap. 5 § skatteförfarandeförordningen (2011:1261):
 * "personnummer, samordningsnummer eller motsvarande utländska nummer";
 * enligt AI-utredning 2026-11-02, juristgranskning återstår.
 *
 * TODO(legal-verify): the employer data from 2027 (prop. 2025/26:282,
 * 39 kap. 11 a § andra stycket SFL; Riksdag decision planned 2026-11-11,
 * applied from 2027-07-01; the förordning is not issued). Not implemented:
 * an employer is still asked for hired staff only.
 */
final class SavePerson
{
    public const array LANGUAGES = ['sv', 'en', 'ar', 'fa', 'so', 'es', 'th', 'ti'];

    /** identity_type: a Swedish personal or coordination number (the default). */
    public const string SWEDISH = 'SE';

    /** identity_type: a number issued in another country, with identity_country. */
    public const string FOREIGN = 'FOREIGN';

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Person $person = null): Person
    {
        app(ModuleManager::class)->ensureEnabled(StaffLedgerModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Person::class, $company]);

        $foreign = ($input['identity_type'] ?? self::SWEDISH) === self::FOREIGN;
        $country = $foreign ? strtoupper(trim((string) ($input['identity_country'] ?? ''))) : null;
        $input['identity_country'] = $country;

        // The form never receives the stored number; empty when editing means "keep it", unless the kind of number changes.
        if ($person !== null && blank($input['personal_number'] ?? null)) {
            if ($person->identity_country !== $country) {
                throw ValidationException::withMessages(['personal_number' => __('Skriv numret igen när du byter till ett annat slags nummer eller land.')]);
            }

            $input['personal_number'] = $person->personal_number;
        }

        if ($foreign) {
            $input['personal_number'] = Identity::cleanForeign((string) ($input['personal_number'] ?? ''));
        }

        $hired = (bool) ($input['hired'] ?? false);
        $foreignEmployer = $hired && (bool) ($input['employer_foreign'] ?? false);
        $input['employer_country'] = $foreignEmployer ? strtoupper(trim((string) ($input['employer_country'] ?? ''))) : null;

        if ($foreignEmployer) {
            $input['employer_org_number'] = Identity::cleanForeign((string) ($input['employer_org_number'] ?? ''));
        }

        $foreignNumber = ['required', 'string', 'max:'.Identity::FOREIGN_MAX_LENGTH, 'regex:/[\p{L}\p{N}]/u'];

        $validated = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'personal_number' => $foreign ? $foreignNumber : ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (PersonalNumber::tryFrom((string) $value) === null) {
                    $fail(__('Skriv person- eller samordningsnumret med tolv siffror, ÅÅÅÅMMDD-NNNN, och kontrollera siffrorna.'));
                }
            }],
            'identity_country' => $foreign ? ['required', Rule::in(Countries::foreign())] : ['nullable'],
            'email' => ['nullable', 'email', 'max:255'],
            'language' => ['required', Rule::in(self::LANGUAGES)],
            'employer_name' => [$hired ? 'required' : 'nullable', 'string', 'max:255'],
            'employer_org_number' => $foreignEmployer ? $foreignNumber : [$hired ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (OrganisationNumber::tryFrom((string) $value) === null) {
                    $fail(__('Kontrollera arbetsgivarens organisationsnummer.'));
                }
            }],
            'employer_country' => $foreignEmployer ? ['required', Rule::in(Countries::foreign())] : ['nullable'],
            'employee_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'is_manager' => ['boolean'],
        ], [
            'personal_number.max' => __('Det utländska numret får vara högst :max tecken.'),
            'personal_number.regex' => __('Skriv det utländska identitetsnumret.'),
            'identity_country.required' => __('Välj landet som har utfärdat numret.'),
            'identity_country.in' => __('Välj landet som har utfärdat numret.'),
            'employer_org_number.max' => __('Arbetsgivarens utländska nummer får vara högst :max tecken.'),
            'employer_org_number.regex' => __('Skriv arbetsgivarens utländska nummer.'),
            'employer_country.required' => __('Välj landet där arbetsgivaren är registrerad.'),
            'employer_country.in' => __('Välj landet där arbetsgivaren är registrerad.'),
        ])->validate();

        $identity = $foreign
            ? new Identity((string) $validated['personal_number'], $country)
            : new Identity((string) PersonalNumber::tryFrom((string) $validated['personal_number'])?->digits);
        $index = Person::indexFor($identity->indexKey());

        if (Person::query()->where('personal_number_index', $index)->when($person, fn ($query) => $query->whereKeyNot($person?->id))->exists()) {
            throw ValidationException::withMessages(['personal_number' => __('Det finns redan en person med det numret i personalliggaren.')]);
        }

        $employeeId = $validated['employee_id'] ?? null;

        if ($employeeId !== null && (! app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company) || ! Employee::query()->whereKey($employeeId)->exists())) {
            throw ValidationException::withMessages(['employee_id' => __('Den anställda finns inte i lönen.')]);
        }

        $userId = $validated['user_id'] ?? null;

        if ($userId !== null && ! $company->users()->whereKey($userId)->exists() && $company->user_id !== $userId) {
            throw ValidationException::withMessages(['user_id' => __('Inloggningen hör inte till företaget.')]);
        }

        $attributes = [
            'first_name' => trim((string) $validated['first_name']),
            'last_name' => trim((string) $validated['last_name']),
            'personal_number' => $identity->number,
            'personal_number_index' => $index,
            'identity_country' => $identity->country,
            'email' => $validated['email'] ?? null,
            'language' => $validated['language'],
            'employer_name' => $hired ? $validated['employer_name'] : null,
            'employer_org_number' => match (true) {
                $foreignEmployer => (string) $validated['employer_org_number'],
                $hired => OrganisationNumber::tryFrom((string) $validated['employer_org_number'])?->formatted(),
                default => null,
            },
            'employer_country' => $foreignEmployer ? (string) $validated['employer_country'] : null,
            'employee_id' => $employeeId,
            'user_id' => $userId,
            'is_manager' => (bool) ($validated['is_manager'] ?? false),
        ];

        return DB::transaction(function () use ($user, $company, $person, $attributes): Person {
            $logged = array_diff_key($attributes, array_flip(['personal_number', 'personal_number_index', 'identity_country']));
            $before = $person?->only(array_keys($logged)) ?? [];
            $numberChanged = $person !== null
                && ($person->personal_number !== $attributes['personal_number'] || $person->identity_country !== $attributes['identity_country']);
            $person ??= new Person;
            $person->fill($attributes)->save();

            if ($person->qr_nonce === null) {
                QrToken::issue($person);
            }

            $changes = Audit::changes($before, $person->only(array_keys($logged)));

            if ($numberChanged) {
                $changes['after']['personal_number'] = '[ändrat]';
            }

            Audit::record($before === [] ? 'staff_ledger.person_created' : 'staff_ledger.person_updated', $person, $changes, $company, $user);

            return $person;
        });
    }
}
