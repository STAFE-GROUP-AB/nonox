<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Support\Audit\Audit;
use App\Support\Privacy\DataSubjectExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /aterkommande-intakter/stripe/registerutdrag/{link}: the register
 * extract (GDPR art. 15 and 20) for a Stripe customer that is not linked to a
 * customer in the customer register (ADR-080 step 2, review C1); a linked one
 * is in the customer's own extract. Needs saas:manage, works also while
 * Stripe is switched off. Another company's Stripe customer answers 404.
 */
class StripeCustomerExportController
{
    public function __invoke(Request $request, int $link): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();
        abort_unless($user->hasTeamPermission($company, SaasModule::MANAGE), 403);

        $row = StripeCustomerLink::query()->findOrFail($link);
        Audit::record('privacy.exported', $row, ['subject' => 'stripe_customer'], $company, $user);

        return response()->json(DataSubjectExport::unlinkedStripeCustomer($row), 200, [
            'Content-Disposition' => 'attachment; filename="registerutdrag-stripe-kund-'.$row->id.'.json"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
