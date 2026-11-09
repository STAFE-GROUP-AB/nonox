<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\Company\OrganisationNumber;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves what a person enters for a K3 annual report and the books cannot
 * tell (ADR-059, A.2.7; S1-P9): the K3 3.7 parts of the management report and
 * the facts behind the conditional notes of S1-R8.1. Only the fields in the
 * input change. Needs fiscal-years:manage; refused for a year that is not K3
 * and once the report is adopted in a closed year (K3ReportWrite).
 *
 * The owners with more than ten per cent (K3 3.7 e) are personal data when
 * they are natural persons: each owner is a name and either an organisation
 * number or a birth year, never a personal identity number (GDPR, data
 * minimisation; register of processing, docs/gdpr). The audit log gets the
 * names of the fields that changed, never their content.
 */
class SaveK3ReportInputs
{
    /** Free texts, in the order they are audited. */
    public const array TEXTS = [
        'k3_external_factors', 'k3_special_circumstances', 'k3_ownership_changes',
        'k3_off_balance_arrangements', 'k3_loans_to_executives', 'k3_parent_company',
        'k3_related_party_transactions', 'k3_departures', 'k3_goodwill_note',
    ];

    /** Every field, in the order they are audited. */
    public const array FIELDS = [
        'k3_external_factors', 'k3_special_circumstances', 'k3_ownership_changes', 'k3_major_owners',
        'k3_long_term_liabilities_ore', 'k3_off_balance_arrangements', 'k3_loans_to_executives',
        'k3_exceptional_items', 'k3_parent_company', 'k3_related_party_transactions', 'k3_departures', 'k3_goodwill_note',
    ];

    /** K3 3.7 e: "mer än tio procent", in basis points. */
    public const int MAJOR_OWNER_MORE_THAN_BP = 1000;

    /**
     * @param  array<string, mixed>  $input  any of TEXTS; k3_major_owners (null, or a list of {name, org_number?, birth_year?, votes_bp, capital_bp}); k3_long_term_liabilities (kronor); k3_exceptional_items (a list of {description, amount} in kronor)
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, array $input): AnnualReport
    {
        $company = K3ReportWrite::company($user, $fiscalYear);
        $values = self::values($input);

        return CurrentCompany::run($company, fn (): AnnualReport => DB::transaction(function () use ($user, $company, $fiscalYear, $values): AnnualReport {
            $report = K3ReportWrite::lockedReport($user, $company, $fiscalYear);
            $changed = [];

            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $values) && $values[$field] != $report->getAttribute($field)) {
                    $changed[] = $field;
                }
            }

            $report->forceFill([...$values, 'updated_by' => $user->id])->save();

            Audit::record('annual_report.k3_inputs_saved', $report, [
                'fiscal_year_id' => $fiscalYear->id,
                'fields' => $changed,
            ], $company, $user);

            return $report;
        }));
    }

    /**
     * The validated values of the fields in the input, ready for the model.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function values(array $input): array
    {
        $rules = [
            'k3_major_owners' => ['nullable', 'array', 'max:50'],
            'k3_major_owners.*.name' => ['required', 'string', 'max:200'],
            'k3_major_owners.*.org_number' => ['nullable', 'string', 'max:20'],
            'k3_major_owners.*.birth_year' => ['nullable', 'integer', 'min:1900', 'max:'.CarbonImmutable::today('Europe/Stockholm')->year],
            'k3_major_owners.*.votes_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'k3_major_owners.*.capital_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'k3_long_term_liabilities' => ['nullable', 'string', 'max:30'],
            'k3_exceptional_items' => ['nullable', 'array', 'max:50'],
            'k3_exceptional_items.*.description' => ['required', 'string', 'max:500'],
            'k3_exceptional_items.*.amount' => ['required', 'string', 'max:30'],
        ];

        foreach (self::TEXTS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:5000'];
        }

        $validated = Validator::make($input, $rules, [], [
            'k3_major_owners.*.name' => __('ägarens namn'),
            'k3_exceptional_items.*.description' => __('beskrivning'),
            'k3_exceptional_items.*.amount' => __('belopp'),
        ])->validate();
        $values = [];

        foreach (self::TEXTS as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = blank($validated[$field] ?? null) ? null : trim((string) $validated[$field]);
            }
        }

        if (array_key_exists('k3_major_owners', $input)) {
            $values['k3_major_owners'] = $input['k3_major_owners'] === null ? null : self::owners($validated['k3_major_owners'] ?? []);
        }

        if (array_key_exists('k3_long_term_liabilities', $input)) {
            $amount = self::amount($validated['k3_long_term_liabilities'] ?? null, 'k3_long_term_liabilities');

            if ($amount !== null && $amount < 0) {
                throw ValidationException::withMessages(['k3_long_term_liabilities' => __('Beloppet kan inte vara negativt.')]);
            }

            $values['k3_long_term_liabilities_ore'] = $amount;
        }

        if (array_key_exists('k3_exceptional_items', $input)) {
            $items = [];

            foreach ($validated['k3_exceptional_items'] ?? [] as $index => $item) {
                $items[] = ['description' => trim((string) $item['description']), 'amount_ore' => (int) self::amount($item['amount'], "k3_exceptional_items.{$index}.amount")];
            }

            $values['k3_exceptional_items'] = $items === [] ? null : $items;
        }

        return $values;
    }

    /**
     * K3 3.7 e: the owners with more than ten per cent of the shares or the
     * votes. A legal person by its organisation number, a natural person by
     * birth year only: a personal identity number is refused.
     *
     * @param  array<int, array<string, mixed>>  $owners
     * @return list<array{name: string, org_number: ?string, birth_year: ?int, votes_bp: int, capital_bp: int}>
     */
    private static function owners(array $owners): array
    {
        $result = [];

        foreach (array_values($owners) as $index => $owner) {
            $orgInput = trim((string) ($owner['org_number'] ?? ''));
            $birthYear = blank($owner['birth_year'] ?? null) ? null : (int) $owner['birth_year'];
            $orgNumber = null;

            if ($orgInput !== '') {
                $number = OrganisationNumber::tryFrom($orgInput);

                if ($number === null || $number->isPersonalIdentityNumber()) {
                    throw ValidationException::withMessages(["k3_major_owners.{$index}.org_number" => __('Ange ett organisationsnummer för en juridisk person. För en fysisk person anges bara födelseåret, aldrig personnumret.')]);
                }

                $orgNumber = $number->formatted();
            }

            if (($orgNumber === null) === ($birthYear === null)) {
                throw ValidationException::withMessages(["k3_major_owners.{$index}" => __('Ange organisationsnummer för en juridisk person eller födelseår för en fysisk person, inte båda.')]);
            }

            $votes = (int) $owner['votes_bp'];
            $capital = (int) $owner['capital_bp'];

            if ($votes <= self::MAJOR_OWNER_MORE_THAN_BP && $capital <= self::MAJOR_OWNER_MORE_THAN_BP) {
                throw ValidationException::withMessages(["k3_major_owners.{$index}" => __('Förvaltningsberättelsen tar upp ägare som har mer än tio procent av andelarna eller rösterna (K3 punkt 3.7). :name har inte det.', ['name' => trim((string) $owner['name'])])]);
            }

            $result[] = ['name' => trim((string) $owner['name']), 'org_number' => $orgNumber, 'birth_year' => $birthYear, 'votes_bp' => $votes, 'capital_bp' => $capital];
        }

        return $result;
    }

    private static function amount(mixed $value, string $field): ?int
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }
    }
}
