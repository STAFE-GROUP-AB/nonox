<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Models\BankConnection;
use Illuminate\Support\Facades\Gate;

/** The bank feed's checks, through BankConnectionPolicy. */
final class BankingGate
{
    public static function view(User $user, Company $company): void
    {
        Gate::forUser($user)->authorize('viewAny', [BankConnection::class, $company]);
    }

    public static function manage(User $user, Company $company): void
    {
        Gate::forUser($user)->authorize('manage', [BankConnection::class, $company]);
    }

    public static function match(User $user, Company $company): void
    {
        Gate::forUser($user)->authorize('match', [BankConnection::class, $company]);
    }
}
