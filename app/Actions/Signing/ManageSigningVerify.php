<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The company's switch for the public Verify page (ADR-046 amendment, gap 6,
 * S12): whether recipients may check files on /kontrollera. Needs
 * signing:settings. The audit entry holds only the new value.
 */
final class ManageSigningVerify
{
    public function __invoke(User $user, Company $company, bool $enabled): void
    {
        if (! $user->hasTeamPermission($company, 'signing:settings')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($user, $company, $enabled): void {
            $before = (bool) DB::table('teams')->where('id', $company->id)->lockForUpdate()->value('signing_verify_enabled');

            if ($before === $enabled) {
                return;
            }

            DB::table('teams')->where('id', $company->id)->update(['signing_verify_enabled' => $enabled]);
            Audit::record('signing.verify_page_changed', $company, ['enabled' => $enabled], $company, $user);
        });
    }
}
