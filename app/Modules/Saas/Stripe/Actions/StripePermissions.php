<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;
use App\Support\Connect\ConnectAddons;
use App\Support\Instance\InstanceSettings;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may do what with Stripe (ADR-080 §1): connecting, rotating the key,
 * the webhook secret and disconnecting need settings:manage and saas:manage;
 * linking Stripe customers needs saas:manage. All of it only while NONOX SaaS
 * is on and Stripe is switched on in NONOX Connect.
 */
final class StripePermissions
{
    /**
     * Stripe may be used on this installation at all (ADR-080 step 2, review A):
     * an instance setting in /hq, off by default, so the hosted service ships it
     * dark until its DPA names NONOX SaaS and Stripe.
     */
    public static function available(): bool
    {
        return InstanceSettings::current()->stripeEnabled();
    }

    public static function authorizeAvailable(): void
    {
        if (! self::available()) {
            throw new AuthorizationException(__('Kopplingen till Stripe finns inte än i den här installationen.'));
        }
    }

    public static function authorizeConnect(User $user, Company $company): void
    {
        self::authorizeManage($user, $company);

        if (! $user->hasTeamPermission($company, 'settings:manage')) {
            throw new AuthorizationException(__('Du behöver behörighet att ändra inställningar och att hantera återkommande intäkter för att koppla Stripe.'));
        }
    }

    public static function authorizeManage(User $user, Company $company): void
    {
        self::authorizeAvailable();

        if (! app(ModuleManager::class)->isEnabled(SaasModule::KEY, $company) || ! ConnectAddons::isOn($company, ConnectAddons::STRIPE)) {
            throw new AuthorizationException(__('Stripe är inte påslaget i NONOX Connect för företaget.'));
        }

        if (! $user->hasTeamPermission($company, SaasModule::MANAGE)) {
            throw new AuthorizationException(__('Du har inte behörighet att hantera återkommande intäkter.'));
        }
    }

    /** Turning Stripe on or off in NONOX Connect: settings:manage (ADR-064 §3). */
    public static function authorizeAddon(User $user, Company $company): void
    {
        self::authorizeAvailable();

        if (! app(ModuleManager::class)->isEnabled(SaasModule::KEY, $company)) {
            throw new AuthorizationException(__('NONOX SaaS är inte påslaget för företaget.'));
        }

        if (! $user->hasTeamPermission($company, 'settings:manage')) {
            throw new AuthorizationException(__('Du behöver behörighet att ändra inställningar för att slå på NONOX Connect.'));
        }
    }
}
