<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Models\Company;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Models\BankConnection;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;

/**
 * Marks the company's connections whose consent has passed its end
 * (valid_until) as EXPIRED and forgets their session ids, without asking
 * the provider: the bank no longer accepts the consent anyway, and nothing
 * is fetched with it again (ADR-034, addendum 2026-11-06). Översikt then
 * shows "Koppla … igen". Runs before every scheduled fetch and with the
 * daily reminders, also while the provider is paused (it is local).
 */
class ExpireBankConsents
{
    /** @return int how many connections were marked */
    public function __invoke(Company $company): int
    {
        return CurrentCompany::run($company, function () use ($company): int {
            $passed = BankConnection::query()
                ->where('status', ConnectionStatus::Active->value)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', now())
                ->get();

            foreach ($passed as $connection) {
                $connection->forceFill(['status' => ConnectionStatus::Expired, 'session_id' => null, 'consecutive_failures' => 0, 'next_attempt_at' => null])->save();
                Audit::record('banking.consent_ended', $connection, ['bank' => $connection->institution_name, 'reason' => 'valid_until', 'valid_until' => $connection->valid_until?->toIso8601String()], $company);
            }

            return $passed->count();
        });
    }
}
