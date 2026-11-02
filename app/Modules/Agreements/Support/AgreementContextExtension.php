<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\AgreementsSettings;
use App\Modules\Agreements\Domain\AgreementContext;
use App\Modules\Agreements\Domain\AgreementPayTerms;
use App\Modules\Agreements\Domain\AgreementPricer;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\Run\ContextExtension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Brings the collective agreement into a payroll run while NONOX Avtal is
 * on (ADR-033 §1–2): for each employment bound to an agreement, the
 * verified versions that cover the payment date and the period's first and
 * last day. Without one the employment is blocked on the run's checklist;
 * there is no fallback to an older version or generic rules (B.6). Then
 * the agreement prices the lines it is asked to (AgreementPricer) and the
 * agreement's checks run as a step (AgreementChecksStep).
 *
 * Loaded once per run in three queries (bindings, versions, the company's
 * own checks), so a run's queries do not grow with its employments.
 */
final class AgreementContextExtension implements ContextExtension
{
    private ?int $preparedRun = null;

    private bool $enabled = false;

    private bool $minimumWageWarnings = true;

    /** @var Collection<int, CollectiveAgreement> */
    private Collection $bindings;

    /** @var Collection<int, AgreementRuleVersion> */
    private Collection $versions;

    /** @var list<int> */
    private array $companyChecked = [];

    public function __construct(private readonly ModuleManager $modules, private readonly AgreementPricer $pricer, private readonly AgreementPayTerms $terms = new AgreementPayTerms)
    {
        $this->bindings = collect();
        $this->versions = collect();
    }

    public function prepare(PayrollRun $run, Collection $employments): void
    {
        $this->preparedRun = $run->id;
        $this->enabled = $this->modules->isEnabled(AgreementsModule::KEY, $run->company_id);
        $this->bindings = collect();
        $this->versions = collect();
        $this->companyChecked = [];

        if (! $this->enabled) {
            return;
        }

        $company = $run->company;
        $this->minimumWageWarnings = $company === null || AgreementsSettings::bool($this->modules->settings(AgreementsModule::KEY, $company), 'minimum_wage_warnings');
        $ids = $employments->pluck('collective_agreement_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->bindings = CollectiveAgreement::query()->whereKey($ids)->get()->keyBy('id');
        $keys = $this->bindings->pluck('agreement_key')->filter()->unique()->values();

        if ($keys->isEmpty()) {
            return;
        }

        $this->versions = AgreementRuleVersion::query()->whereIn('agreement_key', $keys)->orderBy('valid_from')->get();
        $this->companyChecked = AgreementVersions::companyChecked();
    }

    public function extend(PayrollContext $context, PayrollRun $run, Employment $employment): PayrollContext
    {
        if ($this->preparedRun !== $run->id) {
            $this->prepare($run, collect([$employment]));
        }

        if (! $this->enabled || $employment->collective_agreement_id === null) {
            return $context;
        }

        $binding = $this->bindings->get($employment->collective_agreement_id);

        if (! $binding instanceof CollectiveAgreement || $binding->agreement_key === null || $binding->variant === null) {
            return $context;
        }

        $key = $binding->agreement_key;
        $variant = $binding->variant;

        $name = $this->versions->first(fn (AgreementRuleVersion $version): bool => $version->agreement_key === $binding->agreement_key && $version->variant === $binding->variant)?->agreementRules()->name() ?? $binding->name;
        $this->ensureBound($binding, $name, $context);

        $usable = $this->versions
            ->filter(fn (AgreementRuleVersion $version): bool => $version->agreement_key === $binding->agreement_key && $version->variant === $binding->variant && AgreementVersions::isUsable($version, $this->companyChecked))
            ->values();

        foreach (array_unique([$context->periodStart->toDateString(), $context->periodEnd->toDateString(), $context->paymentDate->toDateString()]) as $day) {
            if (! $usable->contains(fn (AgreementRuleVersion $version): bool => $version->covers(CarbonImmutable::parse($day)))) {
                throw new PayrollBlockedException(__('Det finns ingen kontrollerad version av :agreement (:variant) för :date. Öppna Lön → Avtal → Versioner, jämför versionen med källan och markera den som kontrollerad.', [
                    'agreement' => $name,
                    'variant' => mb_strtolower(AgreementRuleVersion::variantLabel($variant)),
                    'date' => $day,
                ]));
            }
        }

        $agreement = new AgreementContext(
            agreementKey: $key,
            variant: $variant,
            versions: array_values($usable->map(fn (AgreementRuleVersion $version): array => [
                'id' => $version->id,
                'valid_from' => $version->valid_from,
                'valid_to' => $version->valid_to,
                'rules' => self::withLocalOverrides($version->agreementRules(), $binding),
            ])->all()),
            group: $employment->agreement_group,
            experienceFrom: $employment->industry_experience_from,
            employmentStart: $employment->start_date,
            overtimeCompensation: $employment->overtime_compensation,
            obCompensation: $employment->ob_compensation,
            minimumWageWarnings: $this->minimumWageWarnings,
            sickPayDirectTransfer: $employment->sick_pay_direct_transfer,
            fixedSupplementsOre: $employment->fixed_supplements_ore?->ore,
        );

        return $this->applyAgreement($context, $agreement);
    }

    /**
     * Adds the agreement to the context and applies it before the
     * calculation: prices the agreement's lines (A3), then vacation,
     * pension, insurances, commission and meals (A9–A11). Pure; the golden
     * payslips call it directly.
     *
     * @throws PayrollBlockedException
     */
    public function applyAgreement(PayrollContext $context, AgreementContext $agreement): PayrollContext
    {
        $context = $context->withExtension(AgreementContext::KEY, $agreement);
        $context = $context->withInputs($this->pricer->price($context, $agreement));

        return $this->terms->apply($context, $agreement);
    }

    /**
     * The version's rules with the company's local overrides that a person
     * with agreements:verify has verified (ADR-033 §3, LocalOverrides).
     */
    public static function withLocalOverrides(AgreementRules $rules, CollectiveAgreement $binding): AgreementRules
    {
        $verified = array_filter((array) $binding->overrides, fn (mixed $override): bool => is_array($override) && filled($override['verified_at'] ?? null));

        return $verified === [] ? $rules : $rules->withOverrides($verified);
    }

    private function ensureBound(CollectiveAgreement $binding, string $name, PayrollContext $context): void
    {
        if (($binding->bound_from !== null && $binding->bound_from->greaterThan($context->periodEnd))
            || ($binding->bound_to !== null && $binding->bound_to->lessThan($context->periodStart))) {
            throw new PayrollBlockedException(__('Anställningen är knuten till :agreement, men företaget är inte bundet av avtalet under perioden enligt Lön → Avtal.', ['agreement' => $name]));
        }
    }
}
