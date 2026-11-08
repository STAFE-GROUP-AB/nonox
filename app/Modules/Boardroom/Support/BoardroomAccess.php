<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Models\ClientProfile;
use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Models\Register;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may do what in the share register and the boardroom. The board keeps
 * the register, or someone it has authorised (ABL 5 kap. 9 §). An accounting
 * firm's consultant (Redovisningskonsult) has share-register:manage (owner
 * decision 2) but records entries only once the company has recorded the
 * board's authorisation. TODO(legal-verify): L9 – the form the authorisation
 * must take.
 */
final class BoardroomAccess
{
    public static function can(User $user, Company $company, string $permission): bool
    {
        return $user->hasTeamPermission($company, $permission);
    }

    /** @throws AuthorizationException */
    public static function authorize(User $user, Company $company, string $permission): void
    {
        if (! self::can($user, $company, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Whether the user may record an entry in the register now.
     *
     * @throws AuthorizationException
     */
    public static function authorizeEntry(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(BoardroomModule::KEY, $company);
        self::authorize($user, $company, 'share-register:manage');

        if (self::isFirmUser($user, $company) && ! self::firmAuthorised($company)) {
            throw new AuthorizationException(__('Styrelsen har inte bemyndigat byrån att föra aktieboken. Ägaren eller en administratör registrerar bemyndigandet under Aktieägare.'));
        }
    }

    /** A consultant from an accounting firm: the Redovisningskonsult role, not the owner. */
    public static function isFirmUser(User $user, Company $company): bool
    {
        return ! $user->ownsTeam($company) && $user->teamRole($company)?->key === ClientProfile::FIRM_ROLE;
    }

    public static function firmAuthorised(Company $company): bool
    {
        return CurrentCompany::run($company, fn (): bool => Register::query()->whereNotNull('firm_authorised_on')->exists());
    }

    /** Owner and admin record the board's authorisation; a firm user cannot authorise itself. */
    public static function mayRecordAuthorisation(User $user, Company $company): bool
    {
        return $user->ownsTeam($company) || $user->teamRole($company)?->key === 'admin';
    }
}
