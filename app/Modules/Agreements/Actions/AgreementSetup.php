<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Actions;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\AgreementPricer;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The company's agreement setup (ADR-033 §3): which agreements it is bound
 * by (member or hängavtal, from and to), and each employment's agreement
 * facts (agreement, lönegrupp, branschvana from, how overtime and OB are
 * compensated). Binding an agreement adds its pay codes (OB and overtime
 * per kind), which the agreement prices in the run. Every change is
 * audited; needs agreements:manage.
 */
class AgreementSetup
{
    public const array OVERTIME_COMPENSATION = ['PAY', 'COMP_TIME', AgreementPricer::INCLUDED_IN_SALARY];

    public const array OB_COMPENSATION = ['PAY', AgreementPricer::INCLUDED];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Adds or changes a binding.
     *
     * @param  array<string, mixed>  $input  agreement_key, variant, bound_from, bound_to
     */
    public function saveBinding(User $user, Company $company, array $input, ?CollectiveAgreement $binding = null): CollectiveAgreement
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $input, $binding): CollectiveAgreement {
            $catalog = AgreementVersions::catalog();
            $validated = Validator::make(array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $input), [
                'agreement_key' => ['required', 'string', Rule::in($catalog->pluck('agreement_key')->unique()->values()->all())],
                'variant' => ['required', Rule::in(AgreementRuleVersion::VARIANTS)],
                'bound_from' => ['required', 'date_format:Y-m-d'],
                'bound_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bound_from'],
            ], [], [
                'agreement_key' => __('avtal'),
                'variant' => __('bundet som'),
                'bound_from' => __('bundet från'),
                'bound_to' => __('bundet till'),
            ])->validate();

            $version = $catalog->get("{$validated['agreement_key']}|{$validated['variant']}");

            if (! $version instanceof AgreementRuleVersion) {
                throw ValidationException::withMessages(['variant' => [__('Det finns ingen data för avtalet som :variant ännu.', ['variant' => mb_strtolower(AgreementRuleVersion::variantLabel($validated['variant']))])]]);
            }

            $duplicate = CollectiveAgreement::query()
                ->where('agreement_key', $validated['agreement_key'])
                ->when($binding instanceof CollectiveAgreement, fn ($query) => $query->whereKeyNot((int) $binding?->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['agreement_key' => [__('Företaget är redan bundet av det avtalet. Ändra den befintliga bindningen.')]]);
            }

            return DB::transaction(function () use ($user, $company, $validated, $binding, $version): CollectiveAgreement {
                $before = $binding?->only(['agreement_key', 'variant', 'bound_from', 'bound_to']);
                $binding ??= new CollectiveAgreement(['rules' => []]);
                $binding->fill([
                    'name' => $version->agreementRules()->name(),
                    'agreement_key' => $validated['agreement_key'],
                    'variant' => $validated['variant'],
                    'bound_from' => $validated['bound_from'],
                    'bound_to' => $validated['bound_to'] ?? null,
                ])->save();

                $created = $this->seedPayCodes($version->agreementRules());

                Audit::record('agreements.binding_saved', $binding, [
                    'before' => $before,
                    'after' => $binding->only(['agreement_key', 'variant', 'bound_from', 'bound_to']),
                    'pay_codes_added' => $created,
                ], $company, $user);

                return $binding;
            });
        });
    }

    public function removeBinding(User $user, Company $company, CollectiveAgreement $binding): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $binding): void {
            $binding = CollectiveAgreement::query()->findOrFail($binding->id);

            if (Employment::query()->where('collective_agreement_id', $binding->id)->exists()) {
                throw ValidationException::withMessages(['binding' => [__('Anställningar är knutna till avtalet. Ange ett slutdatum för bindningen i stället, eller flytta anställningarna först.')]]);
            }

            Audit::record('agreements.binding_removed', $binding, ['agreement_key' => $binding->agreement_key, 'variant' => $binding->variant], $company, $user);
            $binding->delete();
        });
    }

    /**
     * Saves an employment's agreement facts.
     *
     * @param  array<string, mixed>  $input  collective_agreement_id, agreement_group, industry_experience_from, overtime_compensation, ob_compensation, sick_pay_direct_transfer, fixed_supplements (kronor as typed)
     */
    public function saveEmployment(User $user, Company $company, Employment $employment, array $input): Employment
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $employment, $input): Employment {
            $employment = Employment::query()->findOrFail($employment->id);
            $validated = Validator::make(array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $input), [
                'collective_agreement_id' => ['nullable', 'integer', Rule::exists('collective_agreements', 'id')->where('company_id', $company->id)],
                'agreement_group' => ['nullable', 'string', 'max:20'],
                'industry_experience_from' => ['nullable', 'date_format:Y-m-d'],
                'overtime_compensation' => ['nullable', Rule::in(self::OVERTIME_COMPENSATION)],
                'ob_compensation' => ['nullable', Rule::in(self::OB_COMPENSATION)],
                'sick_pay_direct_transfer' => ['nullable', 'boolean'],
                'fixed_supplements' => ['nullable', 'string', 'max:20'],
            ], [], [
                'collective_agreement_id' => __('avtal'),
                'agreement_group' => __('lönegrupp'),
                'industry_experience_from' => __('branschvana från'),
                'fixed_supplements' => __('fasta tillägg per månad'),
            ])->validate();

            $validated['collective_agreement_id'] = isset($validated['collective_agreement_id']) ? (int) $validated['collective_agreement_id'] : null;

            if (array_key_exists('sick_pay_direct_transfer', $validated)) {
                $validated['sick_pay_direct_transfer'] = (bool) $validated['sick_pay_direct_transfer'];
            }

            if (array_key_exists('fixed_supplements', $validated)) {
                $validated['fixed_supplements_ore'] = $this->fixedSupplements($validated['fixed_supplements']);
                unset($validated['fixed_supplements']);
            }

            if ($validated['collective_agreement_id'] === null) {
                $validated = [...$validated, 'agreement_group' => null, 'industry_experience_from' => null, 'overtime_compensation' => null, 'ob_compensation' => null];
            } else {
                $this->ensureGroup($validated['collective_agreement_id'], $validated['agreement_group'] ?? null);
            }

            $before = $employment->only(array_keys($validated));
            $employment->fill($validated)->save();

            Audit::record('agreements.employment_terms_saved', $employment, [
                'employee_id' => $employment->employee_id,
                'before' => array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value instanceof Money ? $value->ore : $value), $before),
                'after' => array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value instanceof Money ? $value->ore : $value), $employment->only(array_keys($validated))),
            ], $company, $user);

            return $employment;
        });
    }

    /**
     * The fixed monthly supplements typed in kronor, in öre; null when empty.
     *
     * @throws ValidationException when the amount cannot be read or is negative
     */
    private function fixedSupplements(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['fixed_supplements' => [__('Beloppet går inte att läsa. Skriv till exempel 1 500 eller 1 500,50.')]]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages(['fixed_supplements' => [__('Fasta tillägg kan inte vara negativa.')]]);
        }

        return $ore;
    }

    /**
     * Adds the agreement's pay codes the company does not have, treated for
     * tax, contributions and vacation like the engine's pay code they follow
     * (OB-tillägg or Övertid) and booked on the same accounts. Never changes
     * an existing pay code.
     */
    public function seedPayCodes(AgreementRules $rules): int
    {
        $existing = PayCode::query()->pluck('code')->all();
        $accounts = Account::query()->whereIn('number', array_keys(PayCodeCatalog::ACCOUNTS))->pluck('id', 'number');
        $created = 0;

        foreach ($rules->codes() as $code => $definition) {
            $kind = (string) ($definition['kind'] ?? '');
            // A commission is entered as the turnover and a meal deduction as days: both are paid as
            // lines of their own (AgreementPayTerms), so the input's pay code follows an addition and is
            // never paid as it is.
            $base = PayCodeCatalog::CODES[match ($kind) {
                'MEAL' => 'AVTALSTILLAGG',
                default => (string) ($definition['pay_code'] ?? ''),
            }] ?? null;

            if ($base === null || in_array($code, $existing, true)) {
                continue;
            }

            if (isset($definition['vacation_basis']) && is_bool($definition['vacation_basis'])) {
                $base['vacation'] = $definition['vacation_basis'];
            }

            if ($kind === 'MEAL') {
                $base = [...$base, 'vacation' => false, 'pension' => false, 'debit' => null];
            }

            PayCode::create([
                'code' => $code,
                'name_sv' => mb_substr("{$definition['name']} ({$rules->shortName()})", 0, 255),
                'name_en' => mb_substr("{$base['name_en']} ({$rules->shortName()})", 0, 255),
                'category' => $base['category'],
                'unit' => in_array($kind, ['COMMISSION', 'MEAL'], true) || ($definition['unit'] ?? 'HOUR') === 'OCCASION' ? 'QUANTITY' : 'HOURS',
                'formula_ref' => mb_substr(PayCode::AGREEMENT_PREFIX.$code, 0, 50),
                'is_taxable' => $base['taxable'],
                'tax_method' => $base['tax_method'],
                'is_contribution_basis' => $base['contribution'],
                'is_vacation_basis' => $base['vacation'],
                'is_pension_basis' => $base['pension'],
                'debit_account_id' => $base['debit'] === null ? null : ($accounts[$base['debit']] ?? null),
                'credit_account_id' => $base['credit'] === null ? null : ($accounts[$base['credit']] ?? null),
                'is_system' => true,
            ]);
            $created++;
        }

        return $created;
    }

    private function ensureGroup(int $bindingId, ?string $group): void
    {
        if ($group === null) {
            return;
        }

        $binding = CollectiveAgreement::query()->findOrFail($bindingId);
        $version = $binding->agreement_key === null ? null : AgreementRuleVersion::query()
            ->where('agreement_key', $binding->agreement_key)
            ->where('variant', $binding->variant)
            ->orderByDesc('valid_from')
            ->first();

        if ($version !== null && $version->agreementRules()->group($group) === null) {
            throw ValidationException::withMessages(['agreement_group' => [__('Lönegruppen finns inte i avtalet.')]]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(AgreementsModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'agreements:manage')) {
            throw new AuthorizationException;
        }
    }
}
