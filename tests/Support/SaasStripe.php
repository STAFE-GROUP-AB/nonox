<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\Stripe\Actions\ConnectStripe;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Support\Connect\ConnectAddons;
use App\Support\Tenancy\CurrentCompany;

/**
 * Set-up for the Stripe tests (ADR-080 step 2): a company with NONOX SaaS on
 * and Stripe switched on in NONOX Connect, connected to a fake Stripe
 * account. Everything synthetic.
 */
final class SaasStripe
{
    /** @return array{0: User, 1: Company} */
    public static function company(bool $addon = true): array
    {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        test()->actingAs($owner);
        Saas::enable($company, $owner);

        if ($addon) {
            ConnectAddons::set($company, $owner, ConnectAddons::STRIPE, true);
        }

        return [$owner, $company];
    }

    public static function connect(User $owner, Company $company, StripeFake $fake): StripeConnection
    {
        return app(ConnectStripe::class)($owner, $company, $fake->key);
    }
}
