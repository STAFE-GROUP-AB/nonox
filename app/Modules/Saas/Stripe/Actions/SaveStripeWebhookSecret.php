<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The signing secret (whsec_…) of the event destination the company adds in
 * Stripe (ADR-080 §7.4), stored encrypted like the key and never shown
 * again. An empty value removes it, and the webhook answers 404 again.
 */
class SaveStripeWebhookSecret
{
    public function __invoke(User $user, Company $company, StripeConnection $connection, #[SensitiveParameter] string $secret): void
    {
        StripePermissions::authorizeConnect($user, $company);
        $secret = trim($secret);

        if ($secret !== '' && preg_match('/^whsec_[A-Za-z0-9+\/=]{16,255}$/', $secret) !== 1) {
            throw ValidationException::withMessages(['webhookSecret' => __('Signeringshemligheten börjar med whsec_ och finns under händelsemottagaren i Stripe.')]);
        }

        if (! $connection->isActive()) {
            throw ValidationException::withMessages(['webhookSecret' => __('Stripe är inte kopplat.')]);
        }

        $connection->forceFill(['webhook_secret' => $secret === '' ? null : $secret])->save();
        Audit::record($secret === '' ? 'stripe.webhook_removed' : 'stripe.webhook_configured', $connection, ['connection' => $connection->id], $company, $user);
    }
}
