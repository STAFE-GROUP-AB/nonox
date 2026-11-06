<?php

declare(strict_types=1);

namespace App\Modules\Connect;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Livewire\ConnectSettingsForm;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\OAuth\GrantTokens;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * NONOX API (ADR-032, ADR-064; key `connect`): the public API, OAuth 2.0 for apps and API keys
 * for scripts. Off by default; while it is off every API call for the
 * company answers 404.
 */
final class ConnectModule implements Module
{
    public const string KEY = 'connect';

    public const array PERMISSIONS = ['connect:view', 'connect:manage'];

    /**
     * app_policy: "all" lets every app registered on this installation ask
     * for access, "listed" only those in allowed_clients. ip_allowlist limits
     * every API call for the company to these addresses or CIDR ranges
     * (empty: no limit).
     */
    public const array DEFAULTS = ['app_policy' => 'all', 'allowed_clients' => [], 'ip_allowlist' => []];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('NONOX API');
    }

    public function description(): string
    {
        return __('Ett öppet API för andra program: appar som du godkänner med passkey, API-nycklar för egna skript, webhooks som berättar när något händer och SFTP för SIE-filer. Varje anslutning gäller ett företag och bara det du har gett den rätt till.');
    }

    public function icon(): string
    {
        return 'link';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public function roles(): array
    {
        return [];
    }

    public function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'admin' => self::PERMISSIONS,
        ];
    }

    public function archivePermissions(): array
    {
        return ['connect:view'];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [
            ['label' => __('Anslutningar'), 'route' => 'connect.index', 'permission' => 'connect:view', 'active' => 'connect.*'],
        ];
    }

    public function settingsComponent(): string
    {
        return ConnectSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        $check = ModuleCheck::allow();

        if (! self::hasSigningKeys()) {
            $check = $check->withBlocker(__('Servern saknar nycklar för OAuth. Den som driftar NONOX kör php artisan passport:keys först.'));
        }

        return $check;
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [
            __('Alla anslutna appar kopplas bort, alla API-nycklar och SFTP-nycklar återkallas och alla webhooks och SFTP stängs av. Slår du på modulen igen måste apparna godkännas på nytt och nya nycklar skapas.'),
        ]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, self::DEFAULTS);
    }

    /** Ends every grant and key and turns every webhook off: a module that is off must not leave live credentials or outgoing data behind. */
    public function onDisable(Company $company, User $by): void
    {
        CurrentCompany::run($company, function () use ($by): void {
            foreach (ConnectGrant::query()->active()->pluck('id') as $grantId) {
                GrantTokens::revoke((string) $grantId, GrantTokens::MODULE_DISABLED, $by);
            }

            DB::table('connect_api_keys')
                ->where('company_id', CurrentCompany::id())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'updated_at' => now()]);

            DB::table('connect_webhooks')
                ->where('company_id', CurrentCompany::id())
                ->whereNull('disabled_at')
                ->update(['disabled_at' => now(), 'disabled_reason' => ConnectWebhook::DISABLED_MODULE, 'updated_at' => now()]);

            DB::table('connect_webhook_deliveries')
                ->where('company_id', CurrentCompany::id())
                ->where('status', ConnectWebhookDelivery::PENDING)
                ->update(['status' => ConnectWebhookDelivery::CANCELLED, 'next_attempt_at' => null, 'updated_at' => now()]);

            // SFTP (ADR-032): the drop is turned off and its keys revoked; the upload log is kept.
            DB::table('connect_sftp_accounts')
                ->where('company_id', CurrentCompany::id())
                ->where('is_enabled', true)
                ->update(['is_enabled' => false, 'disabled_at' => now(), 'updated_at' => now()]);

            DB::table('connect_sftp_keys')
                ->where('company_id', CurrentCompany::id())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'updated_at' => now()]);
        });
    }

    public static function hasSigningKeys(): bool
    {
        if (filled(config('passport.private_key')) && filled(config('passport.public_key'))) {
            return true;
        }

        return is_file(Passport::keyPath('oauth-private.key')) && is_file(Passport::keyPath('oauth-public.key'));
    }
}
