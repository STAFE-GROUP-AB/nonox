<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3Schedule;
use App\Domain\Company\OrganisationNumber;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
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
 * they are natural persons (Lead decision after the S1-P9 review): an owner
 * is a name and, for a legal person, optionally a Swedish organisation number
 * or a foreign registration number with its country. A natural person is a
 * name only: no birth year is kept and a personal identity number is
 * refused (GDPR, data minimisation; register of processing, docs/gdpr). The
 * yes/no answers behind the notes (ANSWERS) are stored as given. The amount
 * due after five years may not exceed the long-term debt in the books. The
 * audit log gets the names of the fields that changed, never their content.
 */
class SaveK3ReportInputs
{
    /** Free texts, in the order they are audited. */
    public const array TEXTS = [
        'k3_business_changes', 'k3_external_factors', 'k3_special_circumstances', 'k3_ownership_changes',
        'k3_off_balance_arrangements', 'k3_loans_to_executives', 'k3_parent_company',
        'k3_related_party_transactions', 'k3_departures', 'k3_goodwill_note',
    ];

    /** Every field, in the order they are audited. */
    public const array FIELDS = [
        'k3_answers', 'k3_business_changes', 'k3_external_factors', 'k3_special_circumstances', 'k3_ownership_changes', 'k3_major_owners',
        'k3_long_term_liabilities_ore', 'k3_off_balance_arrangements', 'k3_loans_to_executives',
        'k3_exceptional_items', 'k3_parent_company', 'k3_related_party_transactions', 'k3_departures', 'k3_goodwill_note',
    ];

    /**
     * The yes/no questions behind the notes (K3Notes): whether rows 11, 12, 13
     * and 16 apply, and whether a material error was corrected retroactively
     * (row 21).
     */
    public const array ANSWERS = ['group_commitments', 'off_balance_arrangements', 'loans_to_executives', 'parent_company', 'material_error_corrected'];

    /** K3 3.7 e: "mer än tio procent", in basis points. */
    public const int MAJOR_OWNER_MORE_THAN_BP = 1000;

    /**
     * @param  array<string, mixed>  $input  any of TEXTS; k3_answers (ANSWERS => bool|null); k3_major_owners (null, or a list of {name, org_number?, foreign_registration?, country?, votes_bp, capital_bp}); k3_long_term_liabilities (kronor); k3_exceptional_items (a list of {description, amount} in kronor)
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, array $input): AnnualReport
    {
        $company = K3ReportWrite::company($user, $fiscalYear);
        $values = self::values($input);

        return CurrentCompany::run($company, fn (): AnnualReport => DB::transaction(function () use ($user, $company, $fiscalYear, $values): AnnualReport {
            $report = K3ReportWrite::lockedReport($user, $company, $fiscalYear);
            $changed = [];

            if (array_key_exists('k3_answers', $values)) {
                $values['k3_answers'] = array_filter([...($report->k3_answers ?? []), ...$values['k3_answers']], fn (?bool $answer): bool => $answer !== null);
                $values['k3_answers'] = $values['k3_answers'] === [] ? null : $values['k3_answers'];
            }

            $dueLater = $values['k3_long_term_liabilities_ore'] ?? null;

            if ($dueLater !== null && $dueLater > 0) {
                $longTerm = K3Notes::longTermDebt(AnnualAccounts::for($fiscalYear, K3Schedule::class));

                if ($dueLater > $longTerm) {
                    throw ValidationException::withMessages(['k3_long_term_liabilities' => __('Beloppet kan inte vara större än de långfristiga skulderna i bokföringen vid balansdagen (konto 2300–2399), :books.', ['books' => Money::ofOre(max($longTerm, 0))->format()])]);
                }
            }

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
            'k3_major_owners.*.foreign_registration' => ['nullable', 'string', 'max:40'],
            'k3_major_owners.*.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'k3_answers' => ['nullable', 'array:'.implode(',', self::ANSWERS)],
            'k3_answers.*' => ['nullable', 'boolean'],
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

        if (array_key_exists('k3_answers', $input)) {
            $values['k3_answers'] = array_map(fn (mixed $answer): ?bool => $answer === null ? null : (bool) $answer, $validated['k3_answers'] ?? []);
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
     * votes. A legal person by name and, optionally, its Swedish
     * organisation number or a foreign registration number with the country;
     * a natural person by name only. Any other identifier is dropped, and a
     * personal identity number is refused wherever it is given.
     *
     * @param  array<int, array<string, mixed>>  $owners
     * @return list<array{name: string, org_number: ?string, foreign_registration: ?string, country: ?string, votes_bp: int, capital_bp: int}>
     */
    private static function owners(array $owners): array
    {
        $result = [];

        foreach (array_values($owners) as $index => $owner) {
            $orgInput = trim((string) ($owner['org_number'] ?? ''));
            $foreign = trim((string) ($owner['foreign_registration'] ?? ''));
            $country = strtoupper(trim((string) ($owner['country'] ?? '')));
            $orgNumber = null;

            if ($orgInput !== '' && $foreign !== '') {
                throw ValidationException::withMessages(["k3_major_owners.{$index}" => __('Ange antingen ett svenskt organisationsnummer eller ett utländskt registreringsnummer, inte båda.')]);
            }

            foreach ([$orgInput, $foreign] as $identifier) {
                $number = OrganisationNumber::tryFrom($identifier);

                if (($number !== null && $number->isPersonalIdentityNumber()) || self::looksLikePersonalNumber($identifier)) {
                    throw ValidationException::withMessages(["k3_major_owners.{$index}" => __('Ett personnummer sparas aldrig. För en fysisk person anges bara namnet.')]);
                }
            }

            if ($orgInput !== '') {
                $number = OrganisationNumber::tryFrom($orgInput);

                if ($number === null) {
                    throw ValidationException::withMessages(["k3_major_owners.{$index}.org_number" => __('Ange ett giltigt svenskt organisationsnummer, eller lämna fältet tomt.')]);
                }

                $orgNumber = $number->formatted();
            }

            if ($foreign !== '' && ($country === '' || $country === 'SE')) {
                throw ValidationException::withMessages(["k3_major_owners.{$index}.country" => __('Ange landet för ett utländskt registreringsnummer, med landskod (till exempel DE). Ett svenskt företag anges med organisationsnummer.')]);
            }

            $votes = (int) $owner['votes_bp'];
            $capital = (int) $owner['capital_bp'];

            if ($votes <= self::MAJOR_OWNER_MORE_THAN_BP && $capital <= self::MAJOR_OWNER_MORE_THAN_BP) {
                throw ValidationException::withMessages(["k3_major_owners.{$index}" => __('Förvaltningsberättelsen tar upp ägare som har mer än tio procent av andelarna eller rösterna (K3 punkt 3.7). :name har inte det.', ['name' => trim((string) $owner['name'])])]);
            }

            $result[] = [
                'name' => trim((string) $owner['name']),
                'org_number' => $orgNumber,
                'foreign_registration' => $foreign === '' ? null : $foreign,
                'country' => $foreign === '' ? null : $country,
                'votes_bp' => $votes,
                'capital_bp' => $capital,
            ];
        }

        return $result;
    }

    /**
     * Whether a text has the shape of a Swedish personal identity or
     * coordination number, YYMMDD-NNNN with or without the century, whatever
     * its check digit: refused rather than stored by mistake.
     */
    private static function looksLikePersonalNumber(string $identifier): bool
    {
        if (! preg_match('/^(?:\d{2})?(\d{2})(\d{2})(\d{2})[-+ ]?\d{4}$/', trim($identifier), $parts)) {
            return false;
        }

        $month = (int) $parts[2];
        $day = (int) $parts[3];

        return $month >= 1 && $month <= 12 && (($day >= 1 && $day <= 31) || ($day >= 61 && $day <= 91));
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
