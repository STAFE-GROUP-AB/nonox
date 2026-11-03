<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\StatusRules;
use App\Modules\Crm\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies the status rules (StatusRules) to every account of a company, in
 * one query for the accounts and their customers' latest invoices, and one
 * update per account whose status changes. Each change is audited.
 */
final class RefreshStatuses
{
    /** @return int the number of accounts whose status changed */
    public function __invoke(Company $company, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $months = CrmSettings::for($company)['months_to_inactive'];

        return CurrentCompany::run($company, function () use ($company, $today, $months): int {
            $latest = Invoice::query()
                ->toBase()
                ->where('kind', Invoice::INVOICE)
                ->where('status', '<>', InvoiceStatus::Draft->value)
                ->groupBy('customer_id')
                ->select('customer_id')
                ->selectRaw('MAX(invoice_date) AS last_invoice_date, MAX(issued_at) AS last_issued_at');

            $accounts = Account::query()
                ->leftJoinSub($latest, 'latest', 'latest.customer_id', '=', 'crm_accounts.customer_id')
                ->get(['crm_accounts.*', 'latest.last_invoice_date', 'latest.last_issued_at']);

            $changed = 0;

            foreach ($accounts as $account) {
                $lastDate = $account->getAttribute('last_invoice_date');
                $lastIssued = $account->getAttribute('last_issued_at');
                $status = StatusRules::statusFor(
                    $account->status,
                    $account->status_set_manually_at,
                    $lastDate === null ? null : CarbonImmutable::parse((string) $lastDate),
                    $lastIssued === null ? null : CarbonImmutable::parse((string) $lastIssued),
                    $today,
                    $months,
                );
                $keepsManual = StatusRules::keepsManualStatus($account->status_set_manually_at, $lastIssued === null ? null : CarbonImmutable::parse((string) $lastIssued));

                if ($status === $account->status && ($keepsManual || $account->status_set_manually_at === null)) {
                    continue;
                }

                $before = $account->status;

                DB::transaction(function () use ($account, $status, $keepsManual, $before, $company): void {
                    // Only the columns of the account itself: the joined aggregates are not columns.
                    Account::query()->whereKey($account->id)->update([
                        'status' => $status->value,
                        'status_set_manually_at' => $keepsManual ? $account->status_set_manually_at : null,
                        'lost_reason' => $status === AccountStatus::Lost ? $account->lost_reason : null,
                        'updated_at' => now(),
                    ]);

                    if ($status !== $before) {
                        Audit::record('crm.status_changed', $account, ['before' => $before->value, 'after' => $status->value, 'by' => 'rules'], $company);
                    }
                });

                if ($status !== $before) {
                    $changed++;
                }
            }

            return $changed;
        });
    }
}
