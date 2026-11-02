<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Notifications\PayrollYearNotice;
use App\Modules\Payroll\PayrollModule;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;

/**
 * Tells everyone responsible for payroll (payroll:settings: owner,
 * administrator, payroll administrator) in every active company with Lön
 * that shared payroll data has changed, so each company checks it for its
 * own staff. Four queries whatever the number of companies.
 */
final class NotifyPayrollResponsibles
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  Closure(Company): PayrollYearNotice  $notice
     * @return int the number of people told
     */
    public function __invoke(Closure $notice): int
    {
        $ids = $this->modules->companiesWith(PayrollModule::KEY);

        if ($ids === []) {
            return 0;
        }

        $companies = Company::query()->whereIn('id', $ids)->whereNull('archived_at')->get(['id', 'name', 'user_id']);
        $roles = array_values(array_filter(array_keys(Jetstream::$roles), fn (string $role): bool => in_array('payroll:settings', Jetstream::findRole($role)->permissions ?? [], true)));
        $members = DB::table('team_user')->whereIn('team_id', $companies->modelKeys())->whereIn('role', $roles)->get(['team_id', 'user_id'])->groupBy('team_id');
        $userIds = $companies->pluck('user_id')->merge($members->flatten(1)->pluck('user_id'))->unique()->all();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        $told = 0;

        foreach ($companies as $company) {
            $ids = collect($members->get($company->id, []))->pluck('user_id')->push($company->user_id)->unique();

            foreach ($ids as $id) {
                $user = $users->get($id);

                if ($user instanceof User) {
                    $user->notify($notice($company));
                    $told++;
                }
            }
        }

        return $told;
    }
}
