<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;

/**
 * Who may do what with which retention class (ADR-081 gap 9, owner decision
 * 21). Every class needs its documents:* permission; a personnel document
 * additionally needs payroll:view-sensitive. The people who hold that
 * permission are payroll administrators, whose module role cannot carry a
 * core permission, so for personnel documents the matching payroll
 * permission stands in for the documents one: payroll:view for reading,
 * employees:manage for uploading and editing, payroll:manage for legal hold.
 * payroll:view-sensitive is required either way, except for the owner while
 * the payroll module is off (ownerFallback), who would otherwise have no
 * way to answer a request about them.
 */
final class DocumentAccess
{
    /** @var array<string, string> documents permission => the payroll permission that stands in for it on personnel documents */
    public const array PAYROLL_EQUIVALENT = [
        'documents:view' => 'payroll:view',
        'documents:manage' => 'employees:manage',
        'documents:hold' => 'payroll:manage',
    ];

    public static function allows(User $user, Company $company, string $permission, RetentionClass $class): bool
    {
        if ($class !== RetentionClass::Personnel) {
            return $user->hasTeamPermission($company, $permission);
        }

        // With payroll off nobody holds payroll:view-sensitive: the owner keeps a way to see, export and delete them.
        if (self::ownerFallback($user, $company)) {
            return true;
        }

        if (! $user->hasTeamPermission($company, 'payroll:view-sensitive')) {
            return false;
        }

        return $user->hasTeamPermission($company, $permission)
            || $user->hasTeamPermission($company, self::PAYROLL_EQUIVALENT[$permission] ?? $permission);
    }

    /**
     * The classes the user may use the permission on. The owner's fallback
     * counts for reading only: it lets the owner see, export and delete
     * personnel documents, not file new ones.
     *
     * @return list<RetentionClass>
     */
    public static function classes(User $user, Company $company, string $permission): array
    {
        $general = $user->hasTeamPermission($company, $permission);
        $personnel = $permission === 'documents:view' || ! self::ownerFallback($user, $company)
            ? self::allows($user, $company, $permission, RetentionClass::Personnel)
            : $user->hasTeamPermission($company, 'payroll:view-sensitive');

        return array_values(array_filter(
            RetentionClass::cases(),
            fn (RetentionClass $class): bool => $class === RetentionClass::Personnel ? $personnel : $general,
        ));
    }

    /**
     * The owner's path to personnel documents while the payroll module is
     * off (GDPR requests must still be answered). Every opening is audited
     * as such (DocumentController).
     */
    public static function ownerFallback(User $user, Company $company): bool
    {
        return $user->ownsTeam($company) && ! app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company);
    }

    /** Whether the user may use the permission on at least one class. */
    public static function allowsAny(User $user, Company $company, string $permission): bool
    {
        return self::classes($user, $company, $permission) !== [];
    }
}
