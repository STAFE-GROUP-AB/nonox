<?php

declare(strict_types=1);

namespace App\Modules\Portal;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\IsNew;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Kundportal (ADR-075): the company's customers sign in with an e-mail
 * code and see their invoices, credit notes, reminders and statement, the
 * payment details, their quotes (and accept them), and ask the company
 * questions. Portal contacts are never NONOX users: they have their own
 * identities, guard and session cookie.
 *
 * Permissions: customer-portal:view sees who has access, the questions and
 * the portal events; customer-portal:manage invites, disables and removes
 * contacts and changes the settings; customer-portal:reply answers
 * questions. Switching the module off closes every portal page of the
 * company at once and keeps the data.
 */
final class PortalModule implements IsNew, Module
{
    public const string KEY = 'portal';

    public const array PERMISSIONS = ['customer-portal:view', 'customer-portal:manage', 'customer-portal:reply'];

    public function newUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-05-01');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Kundportal');
    }

    public function description(): string
    {
        return __('Dina kunder loggar in med en kod i mejlet och ser sina fakturor, kreditfakturor, påminnelser och kontoutdrag, betalningsuppgifter med OCR-nummer, och sina offerter, som de kan godkänna. De kan ställa frågor och bestrida en faktura, och du ser när en faktura har öppnats. Kundportalen bär företagets egen logotyp och färg.');
    }

    public function icon(): string
    {
        return 'users';
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
            'accountant' => self::PERMISSIONS,
            'bookkeeper' => self::PERMISSIONS,
            'auditor' => ['customer-portal:view'],
        ];
    }

    public function archivePermissions(): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [
            [
                'label' => __('Kundportal'),
                'route' => 'portal.admin',
                'permission' => 'customer-portal:view',
                'active' => ['portal.admin', 'portal.admin.*'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Kontakter och inställningar'), 'route' => 'portal.admin', 'active' => ['portal.admin']],
                    ['label' => __('Frågor från kunder'), 'route' => 'portal.admin.questions', 'active' => ['portal.admin.questions']],
                ],
            ],
        ];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function settingsComponent(): ?string
    {
        return null;
    }

    /**
     * TODO(legal-verify): L1, L2, L4, L5 must be answered by a lawyer before the
     * portal is on for any company (ADR-075, build step 1). Until then it can
     * only be turned on where the installation has opened it
     * (NONOX_CUSTOMER_PORTAL=true), such as a test installation.
     */
    public function canEnable(Company $company): ModuleCheck
    {
        return config('nonox.customer_portal')
            ? ModuleCheck::allow()
            : new ModuleCheck(blockers: [__('Kundportalen är byggd och väntar på en juristgranskning av hur kundernas kontaktpersoners uppgifter får behandlas. Den kan slås på när granskningen är klar.')]);
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $active = CurrentCompany::run($company, fn (): int => PortalContact::query()->where('status', ContactStatus::Active->value)->count());

        $warnings = [(string) __('Kundportalen stängs direkt för alla kunder. Kontakter, frågor och händelser finns kvar och syns igen om du slår på modulen.')];

        if ($active > 0) {
            $warnings[] = (string) __(':count kontakter kan inte längre logga in.', ['count' => $active]);
        }

        return new ModuleCheck(warnings: $warnings);
    }

    /** The company's random portal key, made once. */
    public function onEnable(Company $company, User $by): void
    {
        CurrentCompany::run($company, fn (): PortalSettings => PortalSettings::current());
    }

    public function onDisable(Company $company, User $by): void {}
}
