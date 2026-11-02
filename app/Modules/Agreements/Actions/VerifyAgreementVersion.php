<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementRuleVerification;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Records that a named user of the company has compared an agreement
 * version with its sources (docs/avtal/README.md §5). From then on the
 * company's payroll runs may use it. A version with a value marked
 * unverified (only a secondary source) can never be verified.
 */
class VerifyAgreementVersion
{
    public function __invoke(User $user, Company $company, AgreementRuleVersion $version): AgreementRuleVerification
    {
        app(ModuleManager::class)->ensureEnabled(AgreementsModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'agreements:verify')) {
            throw new AuthorizationException;
        }

        if ($version->agreementRules()->hasUnverifiedValues()) {
            throw ValidationException::withMessages(['version' => [__('Versionen innehåller värden som bara finns i en sekundär källa och kan därför inte kontrolleras. Ange lönen på raderna själv tills en version med källa finns.')]]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $version): AgreementRuleVerification {
            $existing = AgreementRuleVerification::query()->where('agreement_rule_version_id', $version->id)->first();

            if ($existing instanceof AgreementRuleVerification) {
                return $existing;
            }

            // A newer version can bring codes the company's binding did not have (supplements, commission, meals).
            if (CollectiveAgreement::query()->where('agreement_key', $version->agreement_key)->where('variant', $version->variant)->exists()) {
                app(AgreementSetup::class)->seedPayCodes($version->agreementRules());
            }

            $verification = AgreementRuleVerification::create([
                'agreement_rule_version_id' => $version->id,
                'verified_by' => $user->id,
                'verifier_name' => $user->name,
                'verified_at' => now(),
            ]);

            Audit::record('agreements.version_verified', $verification, [
                'agreement_key' => $version->agreement_key,
                'variant' => $version->variant,
                'valid_from' => $version->valid_from->toDateString(),
                'checksum' => $version->checksum,
            ], $company, $user);

            return $verification;
        });
    }
}
