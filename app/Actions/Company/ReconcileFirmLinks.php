<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\AccountingFirms;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * After a membership changes (someone leaves or is removed, or a role
 * changes), a link between a client and its firm that no consultant connects
 * any more is removed and recorded in both audit logs (ADR-030). The client's
 * people then see the company's own brand again.
 */
class ReconcileFirmLinks
{
    public function __construct(private readonly AccountingFirmLink $links) {}

    public function __invoke(Company $company, ?User $user = null): void
    {
        $stale = AccountingFirms::linksWithoutConsultant($company);

        if ($stale->isEmpty()) {
            return;
        }

        DB::transaction(fn () => $stale->each(fn (Company $client) => $this->links->detach($client, AccountingFirmLink::NO_CONSULTANT, $user, $company)));
    }
}
