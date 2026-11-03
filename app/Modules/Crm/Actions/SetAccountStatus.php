<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Sets an account's status by hand (ADR-043). Lost needs a reason. The
 * status is kept until the next ledger event: an invoice issued after it
 * lets the daily rules decide again.
 */
final class SetAccountStatus
{
    public function __invoke(User $user, Company $company, Account $account, string $status, ?string $reason = null): Account
    {
        Gate::forUser($user)->authorize('update', $account);

        $validated = Validator::make(['status' => $status, 'reason' => $reason], [
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'reason' => ['nullable', 'required_if:status,'.AccountStatus::Lost->value, 'string', 'max:500'],
        ], [
            'reason.required_if' => __('Skriv varför kunden är förlorad, så går det att följa upp senare.'),
        ])->validate();

        $new = AccountStatus::from((string) $validated['status']);
        $reason = $new === AccountStatus::Lost ? trim((string) $validated['reason']) : null;

        return DB::transaction(fn (): Account => CurrentCompany::run($company, function () use ($user, $company, $account, $new, $reason): Account {
            $before = $account->status;

            $account->forceFill([
                'status' => $new,
                'status_set_manually_at' => now(),
                'lost_reason' => $reason,
                'last_activity_at' => now(),
            ])->save();

            Audit::record('crm.status_changed', $account, ['before' => $before->value, 'after' => $new->value, 'by' => 'hand', 'reason' => $reason], $company, $user);

            return $account;
        }));
    }
}
