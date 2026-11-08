<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Actions\Signing\PruneSigningLinks;
use App\Models\Company;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The agreement register's daily run (ADR-046), per company:
 *
 * 1. Renewal: a renewing agreement (auto-renewal, a term, not terminated)
 *    whose period has ended gets its end date moved forward by the term,
 *    as many times as needed, and may be reminded again.
 * 2. Reminder: when the notice deadline (the end date less the notice
 *    period; the end date itself without one) is at most
 *    agreement_reminder_days ahead (setting, default 60), a CRM task for the
 *    agreement's owner (else the account's, else the company owner; members
 *    only), due at nine on the deadline or today if it has passed.
 *    reminded_for_end_date, set under a row lock, makes it one task per
 *    period, however often this runs.
 * 3. Pruning: a link that was never signed (declined, revoked or expired)
 *    is deleted with its PDF a year after it closed; signed ones are kept
 *    (ADR-046, docs/gdpr/lagringstider.md).
 *
 * Returns [renewed, reminded, pruned].
 */
final class RunAgreementReminders
{
    public const int PRUNE_UNSIGNED_AFTER_MONTHS = PruneSigningLinks::AFTER_MONTHS;

    /** @return array{int, int, int} */
    public function __invoke(Company $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
        $days = CrmSettings::for($company)['agreement_reminder_days'];
        $members = CompanyMembers::ids($company);

        return CurrentCompany::run($company, fn (): array => [
            $this->renew($company, $today),
            $this->remind($company, $today, $days, $members),
            app(PruneSigningLinks::class)($today),
        ]);
    }

    private function renew(Company $company, CarbonImmutable $today): int
    {
        $ids = Agreement::query()
            ->where('auto_renew', true)
            ->whereNull('terminated_on')
            ->whereNotNull('renewal_months')
            ->whereNotNull('end_date')
            ->where('end_date', '<', $today->toDateString())
            ->pluck('id');

        $renewed = 0;

        foreach ($ids as $id) {
            $renewed += DB::transaction(function () use ($company, $id, $today): int {
                $agreement = Agreement::query()->whereKey($id)->lockForUpdate()->first();

                if (! $agreement instanceof Agreement || ! $agreement->renews() || $agreement->end_date === null || ! $agreement->end_date->lessThan($today)) {
                    return 0;
                }

                $from = $agreement->end_date;
                $end = $from;

                for ($i = 0; $i < 600 && $end->lessThan($today); $i++) {
                    $end = $end->addMonthsNoOverflow((int) $agreement->renewal_months);
                }

                $agreement->forceFill(['end_date' => $end->toDateString(), 'reminded_for_end_date' => null])->save();
                Audit::record('crm.agreement_renewed', $agreement, ['agreement_id' => $agreement->id, 'from' => $from->toDateString(), 'to' => $end->toDateString()], $company);

                return 1;
            });
        }

        return $renewed;
    }

    /** @param  list<int>  $members */
    private function remind(Company $company, CarbonImmutable $today, int $days, array $members): int
    {
        $candidates = Agreement::query()
            ->whereNotNull('end_date')
            ->whereNull('terminated_on')
            ->where('end_date', '>=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('reminded_for_end_date')->orWhereColumn('reminded_for_end_date', '!=', 'end_date'))
            ->where('start_date', '<=', $today->addDays($days)->toDateString())
            ->pluck('id');

        $made = 0;

        foreach ($candidates as $id) {
            $made += DB::transaction(function () use ($company, $id, $today, $days, $members): int {
                $agreement = Agreement::query()->whereKey($id)->lockForUpdate()->first();

                if (! $agreement instanceof Agreement || $agreement->end_date === null || $agreement->reminded_for_end_date?->equalTo($agreement->end_date)) {
                    return 0;
                }

                $deadline = $agreement->noticeDeadline() ?? $agreement->end_date;

                if ($deadline->greaterThan($today->addDays($days))) {
                    return 0;
                }

                $account = Account::query()->find($agreement->account_id);
                $owner = $this->owner([$agreement->owner_id, $account?->owner_id, $company->user_id], $members);
                $due = ($deadline->lessThan($today) ? $today : $deadline)->setTimezone(LogActivity::ZONE);

                $subject = $agreement->renews()
                    ? __('Avtalet ”:title” förlängs :date om det inte sägs upp', ['title' => $agreement->title, 'date' => $agreement->end_date->toDateString()])
                    : __('Avtalet ”:title” löper ut :date', ['title' => $agreement->title, 'date' => $agreement->end_date->toDateString()]);

                $activity = Activity::create([
                    'account_id' => $agreement->account_id,
                    'type' => ActivityType::Task,
                    'subject' => mb_substr($subject, 0, 255),
                    'body' => $agreement->notice_months > 0
                        ? __('Sista dag att säga upp eller omförhandla är :date. Bestäm om avtalet ska fortsätta, och kontakta kunden i god tid.', ['date' => $deadline->toDateString()])
                        : __('Bestäm om avtalet ska fortsätta, och kontakta kunden i god tid.'),
                    'assigned_to' => $owner,
                    'due_at' => CarbonImmutable::parse($due->toDateString().' 09:00', LogActivity::ZONE),
                    'created_by' => null,
                ]);

                // A reminder is not contact: the pruning clock (last_activity_at) is not reset (as for win-back).
                $agreement->forceFill(['reminded_for_end_date' => $agreement->end_date->toDateString()])->save();
                Audit::record('crm.agreement_reminder', $activity, ['agreement_id' => $agreement->id, 'account_id' => $agreement->account_id], $company);

                return 1;
            });
        }

        return $made;
    }

    /**
     * @param  list<?int>  $candidates
     * @param  list<int>  $members
     */
    private function owner(array $candidates, array $members): ?int
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && in_array($candidate, $members, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
