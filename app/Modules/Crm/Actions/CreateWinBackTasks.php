<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\WinBackBlock;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Vinn tillbaka" (ADR-043 stage 2): on a lost deal's or a lost account's
 * win-back date, a task for its owner to get in touch again, due at nine
 * that morning. Each date gives one task: win_back_task_at marks it, under
 * a row lock, so running twice in a day (or two servers at once) makes no
 * second one. The owner is the deal's, else the account's, else the
 * company's owner, and only someone still in the company. Returns the
 * number of tasks made.
 *
 * No task (and the date is cleared) for someone who must not be contacted
 * (WinBackBlock), or when the account has won a deal since the loss. The
 * task does not touch last_activity_at: a reminder is not contact, and must
 * not keep a contact from being pruned.
 */
final class CreateWinBackTasks
{
    public function __invoke(Company $company, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
        $members = CompanyMembers::ids($company);
        $made = 0;

        CurrentCompany::run($company, function () use ($company, $today, $members, &$made): void {
            $deals = Deal::query()
                ->where('status', DealStatus::Lost->value)
                ->whereNotNull('win_back_at')
                ->whereNull('win_back_task_at')
                ->where('win_back_at', '<=', $today->toDateString())
                ->pluck('id');

            foreach ($deals as $id) {
                $made += DB::transaction(function () use ($company, $id, $today, $members): int {
                    $deal = Deal::query()->whereKey($id)->whereNull('win_back_task_at')->where('status', DealStatus::Lost->value)->lockForUpdate()->first();

                    if (! $deal instanceof Deal) {
                        return 0;
                    }

                    $skip = $this->skip($deal, $deal->account_id, $deal->lost_at);

                    if ($skip !== null) {
                        $deal->forceFill(['win_back_at' => null])->save();
                        Audit::record('crm.win_back_skipped', $deal, ['deal_id' => $deal->id, 'account_id' => $deal->account_id, 'reason' => $skip], $company);

                        return 0;
                    }

                    $account = Account::query()->find($deal->account_id);
                    $activity = $this->task($company, $deal->account_id, $deal->id, $this->owner([$deal->owner_id, $account?->owner_id, $company->user_id], $members), $today, __('Vinn tillbaka: :title', ['title' => $deal->title]));
                    $deal->forceFill(['win_back_task_at' => now()])->save();
                    Audit::record('crm.win_back_task', $activity, ['deal_id' => $deal->id, 'account_id' => $deal->account_id], $company);

                    return 1;
                });
            }

            $accounts = Account::query()
                ->where('status', AccountStatus::Lost->value)
                ->whereNotNull('win_back_at')
                ->whereNull('win_back_task_at')
                ->where('win_back_at', '<=', $today->toDateString())
                ->pluck('id');

            foreach ($accounts as $id) {
                $made += DB::transaction(function () use ($company, $id, $today, $members): int {
                    $account = Account::query()->whereKey($id)->whereNull('win_back_task_at')->where('status', AccountStatus::Lost->value)->lockForUpdate()->first();

                    if (! $account instanceof Account) {
                        return 0;
                    }

                    $skip = $this->skip($account, $account->id, $account->status_set_manually_at);

                    if ($skip !== null) {
                        $account->forceFill(['win_back_at' => null])->save();
                        Audit::record('crm.win_back_skipped', $account, ['account_id' => $account->id, 'reason' => $skip], $company);

                        return 0;
                    }

                    $activity = $this->task($company, $account->id, null, $this->owner([$account->owner_id, $company->user_id], $members), $today, __('Vinn tillbaka kunden'));
                    $account->forceFill(['win_back_task_at' => now()])->save();
                    Audit::record('crm.win_back_task', $activity, ['account_id' => $account->id], $company);

                    return 1;
                });
            }
        });

        return $made;
    }

    /**
     * Why no task is made, or null: someone who must not be contacted
     * (WinBackBlock), or the account has won a deal since it was lost.
     */
    private function skip(Deal|Account $record, int $accountId, ?CarbonImmutable $lostAt): ?string
    {
        $block = WinBackBlock::for($record);

        if ($block !== null) {
            return $block->value;
        }

        $wonSince = Deal::query()
            ->where('account_id', $accountId)
            ->where('status', DealStatus::Won->value)
            ->when($lostAt !== null, fn ($query) => $query->where('won_at', '>', $lostAt))
            ->exists();

        return $wonSince ? 'WON_SINCE' : null;
    }

    /**
     * The first of $candidates still in the company.
     *
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

    private function task(Company $company, int $accountId, ?int $dealId, ?int $owner, CarbonImmutable $today, string $subject): Activity
    {
        $activity = Activity::create([
            'account_id' => $accountId,
            'deal_id' => $dealId,
            'type' => ActivityType::Task,
            'subject' => $subject,
            'body' => __('Påminnelse om att höra av sig igen efter en förlorad affär. Vad har ändrats sedan dess?'),
            'assigned_to' => $owner,
            'due_at' => CarbonImmutable::parse($today->toDateString().' 09:00', LogActivity::ZONE),
            'created_by' => null,
        ]);

        // A machine reminder is not contact: the pruning clock (last_activity_at) is not reset here.

        return $activity;
    }
}
