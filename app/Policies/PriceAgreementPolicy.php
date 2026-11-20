<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceAgreement;
use App\Models\User;

/**
 * Customer price agreements, "Prisavtal" (ADR-082 §4): seen with
 * prices:view, made, sent and terminated with price-agreements:manage, which
 * the CRM seller also has while CRM is on.
 *
 * Freezing (P5): only a draft's terms may change (changeTerms) and only a
 * draft may be deleted (delete). Sending, activating, terminating and making
 * a new version are life-cycle steps under update.
 */
class PriceAgreementPolicy extends PricingPolicy
{
    protected function managePermission(): string
    {
        return 'price-agreements:manage';
    }

    public function changeTerms(User $user, PriceAgreement $agreement): bool
    {
        return $agreement->status->isDraft() && $this->update($user, $agreement);
    }

    public function delete(User $user, PriceAgreement $agreement): bool
    {
        return $agreement->status->isDraft() && $this->update($user, $agreement);
    }
}
