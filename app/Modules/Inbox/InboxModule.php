<?php

declare(strict_types=1);

namespace App\Modules\Inbox;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Livewire\InboxSettingsForm;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * NONOX Inbox (ADR-023): an e-mail address per company that receives
 * daily reports, invoices and receipts, keeps the original and turns it
 * into a proposal for a person to approve.
 */
final class InboxModule implements Module
{
    public const string KEY = 'inbox';

    public const array PERMISSIONS = ['inbox:view', 'inbox:manage', 'inbox:settings'];

    public const array DEFAULTS = ['ai_interpretation' => true, 'daily_summary' => false, 'notify_on_action' => false, 'imap_enabled' => false, 'imap_encryption' => 'ssl', 'imap_port' => 993, 'imap_folder' => 'INBOX'];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Inkorg');
    }

    public function description(): string
    {
        return __('En egen e-postadress dit kassasystem, leverantörer och personal skickar dagsrapporter, fakturor och kvitton. Originalet sparas som underlag och blir ett förslag som du godkänner.');
    }

    public function icon(): string
    {
        return 'inbox';
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
            'bookkeeper' => ['inbox:view', 'inbox:manage'],
            'auditor' => ['inbox:view'],
        ];
    }

    public function archivePermissions(): array
    {
        return [];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function navigation(): array
    {
        $company = CurrentCompany::id();
        $waiting = $company === null ? 0 : InboxMessage::query()->whereIn('status', [MessageStatus::Quarantine->value, MessageStatus::NeedsReview->value, MessageStatus::DraftReady->value])->count();

        return [
            ['label' => $waiting > 0 ? __('Inkorg (:count)', ['count' => $waiting]) : __('Inkorg'), 'route' => 'inbox.index', 'permission' => 'inbox:view', 'active' => 'inbox.*'],
        ];
    }

    public function settingsComponent(): string
    {
        return InboxSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $open = CurrentCompany::run($company, fn (): int => InboxMessage::query()->whereIn('status', [MessageStatus::Quarantine->value, MessageStatus::NeedsReview->value, MessageStatus::DraftReady->value])->count());
        $warnings = [__('Adressen slutar ta emot e-post. Det som redan har kommit in finns kvar, och allt som har bokförts finns kvar som underlag.')];

        if ($open > 0) {
            $warnings[] = __(':count underlag i inkorgen är inte hanterade. De ligger kvar tills modulen slås på igen.', ['count' => $open]);
        }

        return new ModuleCheck(warnings: $warnings);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, self::DEFAULTS);
        InboxAddress::mailbox($company);
    }

    public function onDisable(Company $company, User $by): void {}
}
