<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\IcalFeed;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\PublishedSchedule;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * The calendar feed behind an employee's secret link: no login, the token
 * is the key. A revoked or unknown token, a company with Planering or Min
 * sida off, or an employee who has left all answer 404. The published
 * shifts from 30 days back to 180 days ahead.
 */
final class IcalFeedController
{
    public const int DAYS_BACK = 30;

    public const int DAYS_AHEAD = 180;

    public function __invoke(string $token, ModuleManager $modules): Response
    {
        // The token decides the company, so it is looked up before any company is known.
        $row = CompanyScope::bypass(fn (): ?IcalToken => IcalToken::query()->where('token_hash', IcalToken::hash($token))->whereNull('revoked_at')->first());
        $company = $row === null ? null : Company::query()->find($row->company_id);

        abort_unless($row instanceof IcalToken && $company instanceof Company && ! $company->isArchived(), 404);
        abort_unless($modules->isEnabled(PlanningModule::KEY, $company) && (bool) ($modules->settings(PayrollModule::KEY, $company)['employee_self_service'] ?? true), 404);

        return CurrentCompany::run($company, function () use ($row, $company): Response {
            $employee = Employee::query()->find($row->employee_id);
            abort_unless($employee instanceof Employee && $employee->status !== 'TERMINATED', 404);

            $today = CarbonImmutable::today(LocalTimestamp::ZONE);
            $shifts = PublishedSchedule::forEmployee($employee->id, $today->subDays(self::DAYS_BACK), $today->addDays(self::DAYS_AHEAD), withCancelled: false);
            $row->forceFill(['last_used_at' => CarbonImmutable::now()])->saveQuietly();

            $body = IcalFeed::render((string) $company->name, $shifts, Area::query()->pluck('name', 'id'), (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'nonox'));

            return response($body, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="schema.ics"',
                'Cache-Control' => 'private, max-age=900',
            ]);
        });
    }
}
