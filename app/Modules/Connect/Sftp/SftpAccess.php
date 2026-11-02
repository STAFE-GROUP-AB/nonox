<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\ModuleManager;

/**
 * Whether an SFTP account may be used right now (ADR-032, "SFTP"). Asked
 * at every login, every upload notice and again before an uploaded file is
 * read, so turning something off takes effect at once. Every "no" has a
 * short reason for the log; nothing here is shown to the SFTP client.
 */
final class SftpAccess
{
    public const string NOT_SET_UP = 'not_set_up';

    public const string DISABLED = 'disabled';

    public const string ARCHIVED = 'company_archived';

    public const string MODULE_OFF = 'module_off';

    public const string RESPONSIBLE_LACKS_RIGHTS = 'responsible_lacks_rights';

    public const string IP_NOT_ALLOWED = 'ip_not_allowed';

    /** Null when allowed, otherwise the reason. Give no IP to skip the address check (a queued job has none). */
    public static function refusal(ConnectSftpAccount $account, ?Company $company, ?string $ip = null, bool $checkIp = true): ?string
    {
        if (! SftpConfig::available()) {
            return self::NOT_SET_UP;
        }

        if (! $company instanceof Company || ! $account->is_enabled) {
            return self::DISABLED;
        }

        if ($company->isArchived()) {
            return self::ARCHIVED;
        }

        if (! app(ModuleManager::class)->isEnabled(ConnectModule::KEY, $company)) {
            return self::MODULE_OFF;
        }

        if (! self::responsibleMayReceive($account->responsible, $company)) {
            return self::RESPONSIBLE_LACKS_RIGHTS;
        }

        if ($checkIp && ! ConnectSettings::ipAllowed($ip, $account->allowed_cidrs)) {
            return self::IP_NOT_ALLOWED;
        }

        return null;
    }

    /**
     * The responsible person's own rights, now: a member with connect:manage
     * and import:sie, evaluated without any API credential of the current
     * request.
     */
    public static function responsibleMayReceive(?User $user, Company $company): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return (bool) ConnectAccess::without(fn (): bool => $user->belongsToTeam($company)
            && $user->hasTeamPermission($company, 'connect:manage')
            && $user->hasTeamPermission($company, 'import:sie'));
    }
}
