<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Facades\Artisan;

/**
 * NONOX Avtal at the restaurant: in late October the company becomes a
 * member of Visita and is bound by Gröna Riks (Visita – HRF) from
 * November, and every employment gets its lönegrupp, branschvana and how
 * OB and overtime are paid. Binding the agreement adds its OB and overtime
 * pay codes.
 *
 * Only in this demo database, as the product owner decided for the payroll
 * rules, the agreement version is marked as checked by "Demodata – inte
 * kontrollerad": nobody has compared it with the sources. November's run
 * prices OB and overtime with it; the runs before November are not bound.
 */
final class AgreementsExtras extends DemoExtra
{
    public const string AGREEMENT = 'visita-hrf-grona-riks';

    /** Lönegrupp 1, kvalificerat yrkesarbete; everyone else is in group 2. */
    private const array GROUP_ONE = ['Köksmästare', 'Hovmästare'];

    public function industries(): array
    {
        return ['bokforing-for-restauranger'];
    }

    public function schedule(DemoContext $context): void
    {
        $context->on(CarbonImmutable::parse('2026-10-26'), 5, fn () => $this->bind($context));
    }

    private function bind(DemoContext $context): void
    {
        app(ModuleManager::class)->enable(AgreementsModule::KEY, $context->company, $context->user);

        // Demo database only (product owner's decision, as for the payroll rules): the version is marked as
        // checked by the demo itself, through the installation's verify command, not by any person.
        Artisan::call('agreements:verify', ['key' => self::AGREEMENT, 'valid_from' => '2025-04-01', '--variant' => 'MEMBER', '--by' => PayrollExtras::VERIFIED_BY]);

        $setup = app(AgreementSetup::class);
        $binding = $setup->saveBinding($context->user, $context->company, [
            'agreement_key' => self::AGREEMENT,
            'variant' => 'MEMBER',
            'bound_from' => '2026-11-01',
            'bound_to' => '',
        ]);

        $ended = Employee::query()->where('status', 'TERMINATED')->pluck('id')->all();
        $employments = Employment::query()->whereNull('end_date')->whereNotIn('employee_id', $ended)->orderBy('employee_id')->get();

        foreach ($employments as $employment) {
            $setup->saveEmployment($context->user, $context->company, $employment, [
                'collective_agreement_id' => $binding->id,
                'agreement_group' => in_array($employment->position_title, self::GROUP_ONE, true) ? '1' : '2',
                'industry_experience_from' => $employment->start_date->subYears(PayrollExtras::pick("experience-{$employment->id}", 0, 6))->toDateString(),
                'overtime_compensation' => $employment->salary_form === 'HOURLY' ? 'PAY' : 'COMP_TIME',
                'ob_compensation' => 'PAY',
            ]);
        }
    }
}
