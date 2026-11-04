<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\RegistryChange;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\RegistryChangeText;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\RegistryNotice;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring (bevakning, ADR-047): the changes in the company register for
 * the company's accounts that it has not been told about become one task
 * "Ändring hos kunden" per account, for the account's owner (else the
 * company's owner), due at nine, listing what changed. A notice per change
 * makes it idempotent. Only changes detected after the account was created
 * count. Like the win-back reminder, the task does not reset the pruning
 * clock: a register change is not contact. Returns the number of tasks.
 */
final class CreateRegistryNotices
{
    public function __invoke(Company $company, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
        $members = CompanyMembers::ids($company);

        return CurrentCompany::run($company, function () use ($company, $today, $members): int {
            // Customers only, unless the company also watches its prospects (CrmSettings).
            $watched = fn () => Account::query()
                ->whereNotNull('org_number')
                ->when(CrmSettings::for($company)['registry_monitoring_prospects'] !== 1, fn ($query) => $query->whereNotNull('customer_id'));
            $accounts = $watched()
                ->get(['id', 'org_number', 'owner_id', 'created_at'])
                ->keyBy(fn (Account $account): string => (string) preg_replace('/\D/', '', (string) $account->org_number));

            if ($accounts->isEmpty()) {
                return 0;
            }

            // Changes for the company's organisation numbers (a subquery, however many accounts), not yet noticed here.
            $changes = RegistryChange::query()
                ->whereIn('org_number', $watched()->toBase()->selectRaw("regexp_replace(org_number, '\\D', '', 'g')"))
                ->where('detected_at', '>=', $accounts->min('created_at'))
                ->whereNotIn('id', RegistryNotice::query()->toBase()->select('registry_change_id'))
                ->orderBy('id')
                ->get()
                ->filter(function (RegistryChange $change) use ($accounts): bool {
                    $account = $accounts->get($change->org_number);

                    return ! $account instanceof Account || $account->created_at === null || $change->detected_at->greaterThanOrEqualTo($account->created_at);
                })
                ->groupBy('org_number');

            $made = 0;

            foreach ($changes as $org => $group) {
                $account = $accounts->get((string) $org);

                if (! $account instanceof Account) {
                    continue;
                }

                $made += DB::transaction(function () use ($company, $account, $group, $today, $members): int {
                    $owner = in_array($account->owner_id, $members, true) ? $account->owner_id : (in_array($company->user_id, $members, true) ? $company->user_id : null);
                    $lines = $group->map(fn (RegistryChange $change): string => RegistryChangeText::line($change))->unique()->values();

                    $activity = Activity::create([
                        'account_id' => $account->id,
                        'type' => ActivityType::Task,
                        'subject' => __('Ändring hos kunden: :what', ['what' => RegistryChangeText::summary(array_values($group->map(fn (RegistryChange $change): string => $change->field)->unique()->all()))]),
                        'body' => $lines->implode("\n")."\n\n".__('Källa: Bolagsverket och SCB, värdefulla datamängder, bearbetat av NONOX.'),
                        'assigned_to' => $owner,
                        'due_at' => CarbonImmutable::parse($today->toDateString().' 09:00', LogActivity::ZONE),
                        'created_by' => null,
                    ]);

                    foreach ($group as $change) {
                        RegistryNotice::create(['account_id' => $account->id, 'registry_change_id' => $change->id, 'activity_id' => $activity->id]);
                    }

                    // Field names only: never names or addresses in the append-only log.
                    Audit::record('crm.registry_notice', $activity, ['account_id' => $account->id, 'fields' => $group->map(fn (RegistryChange $change): string => $change->field)->unique()->values()->all()], $company);

                    return 1;
                });
            }

            return $made;
        });
    }
}
