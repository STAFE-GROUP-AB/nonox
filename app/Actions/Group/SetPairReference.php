<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Models\Company;
use App\Models\IntercompanyPair;
use App\Models\User;
use App\Policies\IntercompanyDeliveryPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enters the counterpart's voucher reference (series and number, for
 * example "A12") on the company's own pair row (ADR-081 E27), once the
 * counterpart has posted its draft. The company's own row only: NONOX never
 * reads the other company's voucher to fill it in. The audit entry holds the
 * pair's id, not the reference.
 */
class SetPairReference
{
    public function __invoke(User $user, Company $company, int $pairId, string $reference): IntercompanyPair
    {
        if (! IntercompanyDeliveryPolicy::mayPair($user, $company)) {
            throw new AuthorizationException;
        }

        $reference = trim(preg_replace('/\s+/', ' ', $reference) ?? '');

        if (mb_strlen($reference) > 40) {
            throw ValidationException::withMessages(['reference' => __('Skriv serie och nummer, till exempel A12, med högst 40 tecken.')]);
        }

        return CurrentCompany::run($company, fn (): IntercompanyPair => DB::transaction(function () use ($user, $company, $pairId, $reference): IntercompanyPair {
            $pair = IntercompanyPair::query()->whereKey($pairId)->lockForUpdate()->firstOrFail();
            $pair->fill(['counterparty_voucher_ref' => $reference === '' ? null : $reference])->save();

            Audit::record('intercompany.pair_reference_set', $pair, ['pair_id' => $pair->id, 'set' => $reference !== ''], $company, $user);

            return $pair;
        }));
    }
}
