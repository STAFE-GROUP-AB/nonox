<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Records that a named user in the company has checked a rule version
 * against its source (PAYROLL_MODULE.md B.6). From then on the company's
 * payroll runs may use it.
 */
class VerifyPayrollRule
{
    public function __invoke(User $user, Company $company, PayrollRuleVersion $rule): PayrollRuleVerification
    {
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:settings')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $rule): PayrollRuleVerification {
            $existing = PayrollRuleVerification::query()->where('payroll_rule_version_id', $rule->id)->first();

            if ($existing instanceof PayrollRuleVerification) {
                return $existing;
            }

            $verification = PayrollRuleVerification::create([
                'payroll_rule_version_id' => $rule->id,
                'verified_by' => $user->id,
                'verifier_name' => $user->name,
                'verified_at' => now(),
            ]);

            Audit::record('payroll.rule_verified', $verification, [
                'key' => $rule->key,
                'valid_from' => $rule->valid_from->toDateString(),
                'value' => $rule->value,
                'source_url' => $rule->source_url,
            ], $company, $user);

            return $verification;
        });
    }
}
