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
 * Creates or updates a person in the staff ledger register. The personal or
 * coordination number is required, validated with the Luhn digit and kept
 * encrypted; hired staff also get their employer's name and organisation
 * number. A person may be linked to a payroll employee (payroll on) and to a
 * login of the company (for mobile check-in). The personal number never
 * reaches the audit log.
 *
 * TODO(legal-verify): the exact data to register per person under 39 kap.
 * skatteförfarandelagen and Skatteverket's regulations from 2027.
 */
final class SavePerson
{
    public const array LANGUAGES = ['sv', 'en', 'ar', 'fa', 'so', 'es', 'th', 'ti'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Person $person = null): Person
    {
        app(ModuleManager::class)->ensureEnabled(StaffLedgerModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Person::class, $company]);

        // The form never receives the stored number; empty when editing means "keep it".
        if ($person !== null && blank($input['personal_number'] ?? null)) {
            $input['personal_number'] = $person->personal_number;
        }

        $hired = (bool) ($input['hired'] ?? false);

        $validated = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'personal_number' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (PersonalNumber::tryFrom((string) $value) === null) {
                    $fail(__('Skriv person- eller samordningsnumret med tolv siffror, ÅÅÅÅMMDD-NNNN, och kontrollera siffrorna.'));
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'language' => ['required', Rule::in(self::LANGUAGES)],
            'employer_name' => [$hired ? 'required' : 'nullable', 'string', 'max:255'],
            'employer_org_number' => [$hired ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (OrganisationNumber::tryFrom((string) $value) === null) {
                    $fail(__('Kontrollera arbetsgivarens organisationsnummer.'));
                }
            }],
            'employee_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'is_manager' => ['boolean'],
        ])->validate();

        /** @var PersonalNumber $number */
        $number = PersonalNumber::tryFrom((string) $validated['personal_number']);
        $index = Person::indexFor($number->digits);

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
            'personal_number' => $number->digits,
            'personal_number_index' => $index,
            'email' => $validated['email'] ?? null,
            'language' => $validated['language'],
            'employer_name' => $hired ? $validated['employer_name'] : null,
            'employer_org_number' => $hired ? OrganisationNumber::tryFrom((string) $validated['employer_org_number'])?->formatted() : null,
            'employee_id' => $employeeId,
            'user_id' => $userId,
            'is_manager' => (bool) ($validated['is_manager'] ?? false),
        ];

        return DB::transaction(function () use ($user, $company, $person, $attributes): Person {
            $logged = array_diff_key($attributes, array_flip(['personal_number', 'personal_number_index']));
            $before = $person?->only(array_keys($logged)) ?? [];
            $numberChanged = $person !== null && $person->personal_number !== $attributes['personal_number'];
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
