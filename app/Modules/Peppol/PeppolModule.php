<?php

declare(strict_types=1);

namespace App\Modules\Peppol;

use App\Models\Company;
use App\Models\User;
use App\Modules\Contracts\Module;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleCheck;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Livewire\PeppolSettingsForm;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Tenancy\CurrentCompany;

/**
 * Peppol e-invoicing (ADR-050): customer invoices and credit invoices go
 * out as Peppol BIS Billing 3.0 through the installation's access-point
 * provider, and invoices from suppliers on Peppol come in to the inbox.
 *
 * No permissions of its own: sending takes invoices:send, receiving lands
 * in the inbox (inbox:*), and the company's Peppol settings take
 * settings:manage. It can only be turned on when the installation has an
 * access point (an agreement with a provider and its keys).
 */
final class PeppolModule implements Module
{
    public const string KEY = 'peppol';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('Peppol e-faktura');
    }

    public function description(): string
    {
        return __('Skicka kundfakturor som e-faktura via Peppol, till exempel till kommuner, regioner och myndigheter, och ta emot leverantörsfakturor från Peppol i inkorgen.');
    }

    public function icon(): string
    {
        return 'send';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function roles(): array
    {
        return [];
    }

    public function rolePermissions(): array
    {
        return [];
    }

    public function archivePermissions(): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [];
    }

    public function archiveNavigation(Company $company): array
    {
        return [];
    }

    public function settingsComponent(): string
    {
        return PeppolSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        if (AccessPoints::isPaused()) {
            return new ModuleCheck(blockers: [AccessPoints::pausedMessage()]);
        }

        return AccessPoints::isConfigured()
            ? ModuleCheck::allow()
            : new ModuleCheck(blockers: [__('Peppol är inte inställt på den här installationen. Den som driftar NONOX behöver ett avtal med en Peppol-operatör (accesspunkt).')]);
    }

    public function canDisable(Company $company): ModuleCheck
    {
        $waiting = CurrentCompany::run($company, fn (): int => PeppolTransmission::query()->whereIn('status', [TransmissionStatus::Queued->value, TransmissionStatus::Sent->value])->count());
        $participant = CurrentCompany::run($company, fn (): ?PeppolParticipant => PeppolParticipant::query()->first());
        $warnings = [__('Inga fler fakturor skickas eller tas emot via Peppol. Det som redan har skickats och tagits emot finns kvar.')];

        // Turning the module off must not leave the company in the Peppol Directory: receiving is switched off (and deregistered) first.
        if ($participant instanceof PeppolParticipant && ($participant->receive_enabled || $participant->registration_status !== RegistrationStatus::NotRegistered)) {
            return new ModuleCheck(blockers: [__('Stäng först av mottagningen så att företaget tas bort ur Peppols adressbok. Annars kan leverantörer fortsätta skicka e-fakturor hit.')], warnings: $warnings);
        }

        if ($waiting > 0) {
            $warnings[] = __(':count fakturor är på väg via Peppol och väntar på leveransbesked.', ['count' => $waiting]);
        }

        return new ModuleCheck(warnings: $warnings);
    }

    public function onEnable(Company $company, User $by): void {}

    public function onDisable(Company $company, User $by): void {}

    /** Receiving needs the inbox: that is where the supplier invoice is approved. */
    public static function inboxKey(): string
    {
        return InboxModule::KEY;
    }
}
