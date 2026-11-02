<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The addresses the SFTP drop may be used from (ADR-032, "SFTP"): one
 * address or CIDR range per line, at most 20; an empty list allows every
 * address. A range of everything (/0) is refused, since it says the same
 * as an empty list but looks like a limit. Changing it can widen access,
 * so it needs a fresh passkey confirmation like adding a key.
 */
final class SaveSftpAllowlist
{
    public const int MAX_ENTRIES = 20;

    /**
     * @return list<string>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, string $text): array
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        $entries = mb_strlen($text) > 2000 ? null : ConnectSettings::parseAllowlist($text);

        if ($entries === null) {
            throw ValidationException::withMessages(['allowlist' => __('Skriv en IP-adress eller ett CIDR-intervall per rad, till exempel 192.0.2.10 eller 192.0.2.0/24.')]);
        }

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/0')) {
                throw ValidationException::withMessages(['allowlist' => __(':entry släpper in alla adresser. Lämna listan tom om alla ska få ansluta.', ['entry' => $entry])]);
            }
        }

        if (count($entries) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['allowlist' => __('Högst :max adresser eller intervall.', ['max' => self::MAX_ENTRIES])]);
        }

        $account = CurrentCompany::run($company, fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->first());

        if (! $account instanceof ConnectSftpAccount) {
            throw ValidationException::withMessages(['allowlist' => __('Slå på SFTP först.')]);
        }

        PasskeyStepUp::consume($user);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $account, $entries): array {
            $before = $account->allowed_cidrs;
            $account->forceFill(['allowed_cidrs' => $entries])->save();

            Audit::record('connect.sftp.allowlist_changed', $account, ['before' => $before, 'after' => $entries], $company, $user);

            return $entries;
        }));
    }
}
