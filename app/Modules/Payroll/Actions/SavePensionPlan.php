<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates or changes a pension plan. The templates only fill in common rates;
 * the rates that apply are the company's own, from its collective agreement or
 * policy (PAYROLL_MODULE.md B.8).
 */
class SavePensionPlan
{
    /** Template => [rate below the threshold, rate above], in basis points. */
    public const array TEMPLATES = [
        'ITP1_LIKE' => [450, 3000],
        'SAF_LO_LIKE' => [450, 3000],
        'CUSTOM' => [0, 0],
    ];

    /**
     * @param  array<string, mixed>  $input  name, template, below_percent, above_percent
     */
    public function __invoke(User $user, Company $company, array $input, ?PensionPlan $plan = null): PensionPlan
    {
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'template' => ['required', Rule::in(array_keys(self::TEMPLATES))],
            'below_percent' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'above_percent' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
        ], ['below_percent.regex' => __('Skriv procenten som ett tal, till exempel 4,5.'), 'above_percent.regex' => __('Skriv procenten som ett tal, till exempel 30.')],
            ['name' => __('namn'), 'below_percent' => __('procent under taket'), 'above_percent' => __('procent över taket')])->validate();

        $bp = fn (string $percent): int => (int) round((float) str_replace(',', '.', $percent) * 100);

        return CurrentCompany::run($company, function () use ($user, $company, $validated, $plan, $bp): PensionPlan {
            $plan ??= new PensionPlan;
            $plan->fill([
                'name' => $validated['name'],
                'template' => $validated['template'],
                'rules' => ['below_bp' => $bp((string) $validated['below_percent']), 'above_bp' => $bp((string) $validated['above_percent'])],
            ])->save();
            Audit::record('payroll.pension_plan_saved', $plan, ['name' => $plan->name, 'rules' => $plan->rules], $company, $user);

            return $plan;
        });
    }
}
