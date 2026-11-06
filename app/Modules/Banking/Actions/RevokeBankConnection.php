<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\BankingGate;
use App\Modules\Banking\Support\ProviderFailed;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;

/**
 * Ends a bank connection: the provider is asked to close the consent (and
 * the bank's, where the bank allows it), the session id is forgotten, and
 * nothing more is fetched. What was fetched stays, as underlag for the
 * reconciliations made with it. Works while the module is off too, so a
 * company can always stop the bank from sharing.
 */
class RevokeBankConnection
{
    public function __invoke(User $user, Company $company, BankConnection $connection): void
    {
        BankingGate::manage($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $connection): void {
            $closedAtProvider = true;

            // While the operator has paused the provider nothing is sent to it; the bank's consent then runs out by itself.
            if ($connection->session_id !== null && BankDataProviders::isPaused($connection->provider)) {
                $closedAtProvider = false;
            } elseif ($connection->session_id !== null) {
                try {
                    BankDataProviders::make($connection->provider)->revoke($connection->session_id);
                } catch (ProviderFailed $exception) {
                    // NONOX stops using the consent anyway; the bank's own consent then runs out by itself.
                    $closedAtProvider = false;
                    report($exception);
                }
            }

            $connection->forceFill([
                'status' => ConnectionStatus::Revoked,
                'session_id' => null,
                'revoked_by' => $user->id,
                'revoked_at' => now(),
            ])->save();

            Audit::record('banking.revoked', $connection, ['bank' => $connection->institution_name, 'closed_at_provider' => $closedAtProvider], $company, $user);
        });
    }
}
