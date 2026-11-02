<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Sftp\SftpAccess;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns the company's SFTP drop on or off (ADR-032, "SFTP"). The first time
 * it is turned on the account gets a generated user name. Whoever turns it
 * on becomes responsible: files that arrive are checked in their name, so
 * they must hold import:sie as well as connect:manage. Turning it on grants
 * no access by itself; that needs a key, which needs a passkey.
 */
final class SetSftpEnabled
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, bool $enabled): ConnectSftpAccount
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        app(ModuleManager::class)->ensureEnabled(ConnectModule::KEY, $company);

        if ($enabled && ! SftpConfig::available()) {
            throw ValidationException::withMessages(['sftp' => __('SFTP är inte uppsatt på den här servern. Den som driftar NONOX behöver installera SFTP-servern först.')]);
        }

        if ($enabled && ! SftpAccess::responsibleMayReceive($user, $company)) {
            throw ValidationException::withMessages(['sftp' => __('Den som slår på SFTP blir ansvarig för filerna som kommer in och behöver själv få läsa in SIE-filer.')]);
        }

        return CurrentCompany::run($company, fn (): ConnectSftpAccount => DB::transaction(function () use ($user, $company, $enabled): ConnectSftpAccount {
            $account = ConnectSftpAccount::query()->lockForUpdate()->first();

            if (! $account instanceof ConnectSftpAccount) {
                if (! $enabled) {
                    throw ValidationException::withMessages(['sftp' => __('SFTP är inte påslaget.')]);
                }

                $account = new ConnectSftpAccount(['username' => self::uniqueUsername(), 'responsible_user_id' => $user->id]);
            }

            $account->fill($enabled
                ? ['is_enabled' => true, 'responsible_user_id' => $user->id, 'enabled_at' => now(), 'disabled_at' => null]
                : ['is_enabled' => false, 'disabled_at' => now()]);
            $account->save();

            Audit::record($enabled ? 'connect.sftp.enabled' : 'connect.sftp.disabled', $account, [
                'username' => $account->username,
                'responsible_user_id' => $account->responsible_user_id,
            ], $company, $user);

            return $account;
        }));
    }

    private static function uniqueUsername(): string
    {
        do {
            $username = SftpConfig::newUsername();
        } while (DB::table('connect_sftp_accounts')->where('username', $username)->exists());

        return $username;
    }
}
