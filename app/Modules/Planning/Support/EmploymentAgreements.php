<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The collective agreement versions behind a set of employments, loaded in
 * three queries while NONOX Avtal is on, the way the payroll run finds them
 * (AgreementContextExtension): a version counts only when it is checked.
 */
final class EmploymentAgreements
{
    /** @var Collection<int, CollectiveAgreement> */
    private Collection $bindings;

    /** @var Collection<int, AgreementRuleVersion> */
    private Collection $versions;

    /** @var list<int> */
    private array $checked = [];

    /** @var array<int, AgreementRules> */
    private array $rules = [];

    /** @param  Collection<int, Employment>  $employments */
    public function __construct(ModuleManager $modules, Company $company, Collection $employments)
    {
        $this->bindings = collect();
        $this->versions = collect();
        $ids = $employments->pluck('collective_agreement_id')->filter()->unique()->values();

        if ($ids->isEmpty() || ! $modules->isEnabled(AgreementsModule::KEY, $company)) {
            return;
        }

        $this->bindings = CollectiveAgreement::query()->whereKey($ids)->get()->keyBy('id');
        $keys = $this->bindings->pluck('agreement_key')->filter()->unique()->values();
        $this->versions = $keys->isEmpty() ? collect() : AgreementRuleVersion::query()->whereIn('agreement_key', $keys)->orderBy('valid_from')->get();
        $this->checked = AgreementVersions::companyChecked();
    }

    /**
     * The checked agreement version for the employment on the day, or that
     * the employment is bound to an agreement without one.
     *
     * @return array{?AgreementRules, bool}
     */
    public function on(?Employment $employment, CarbonImmutable $day): array
    {
        $binding = $employment?->collective_agreement_id === null ? null : $this->bindings->get($employment->collective_agreement_id);

        if (! $binding instanceof CollectiveAgreement || $binding->agreement_key === null || $binding->variant === null) {
            return [null, false];
        }

        $version = $this->versions->last(fn (AgreementRuleVersion $version): bool => $version->agreement_key === $binding->agreement_key
            && $version->variant === $binding->variant
            && $version->covers($day)
            && AgreementVersions::isUsable($version, $this->checked));

        if ($version === null) {
            return [null, true];
        }

        return [$this->rules[$version->id] ??= $version->agreementRules(), false];
    }
}
