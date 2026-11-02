<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosStockPush;
use App\Modules\Pos\Support\PosDrivers;
use App\Modules\Pos\Support\PosGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;

/**
 * Disconnects a till (ADR-042): the till is asked to forget the app and the
 * webhook where its API allows it, then the credentials are erased here.
 * Sales, links and the log stay: credentials are not data.
 */
class DisconnectPos
{
    public function __invoke(User $user, Company $company, PosConnection $connection): void
    {
        PosGate::manage($user, $company);

        if ($connection->status === ConnectionStatus::Disconnected) {
            return;
        }

        if ($connection->credentials !== null) {
            PosDrivers::for($connection->provider)->revoke($connection);
        }

        CurrentCompany::run($company, function () use ($user, $company, $connection): void {
            $connection->forceFill([
                'status' => ConnectionStatus::Disconnected,
                'credentials' => null,
                'disconnected_by' => $user->id,
                'disconnected_at' => now(),
            ])->save();

            PosStockPush::query()->where('pos_connection_id', $connection->id)->where('status', PosStockPush::PENDING)
                ->update(['status' => PosStockPush::SKIPPED, 'last_error' => 'disconnected']);

            Audit::record('pos.disconnected', $connection, ['provider' => $connection->provider->value], $company, $user);
            $connection->log('info', __(':till kopplades bort av :name.', ['till' => $connection->provider->label(), 'name' => $user->name]));
        });
    }
}
