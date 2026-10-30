<?php

declare(strict_types=1);

namespace App\Modules\Ai;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\Livewire\AiSettingsForm;
use App\Modules\Contracts\Module;
use App\Modules\ModuleCheck;
use App\Modules\ModuleManager;

/**
 * NONOX AI: reads supplier invoices, receipts and other documents and fills
 * in a proposal for a person to check. It never saves or posts anything.
 */
class AiModule implements Module
{
    public const string KEY = 'ai';

    public const array PERMISSIONS = ['ai:use', 'ai:settings'];

    /** CHEAPEST: the provider's cheapest suitable model; SMARTEST: its best; CUSTOM: the model named in "model". */
    public const array MODEL_CHOICES = ['CHEAPEST', 'SMARTEST', 'CUSTOM'];

    public const array DEFAULTS = ['provider' => 'OPENAI', 'model_choice' => 'CHEAPEST', 'model' => null, 'api_keys' => []];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('NONOX AI');
    }

    public function description(): string
    {
        return __('Läser leverantörsfakturor, kvitton och andra underlag och fyller i ett förslag med konton och belopp. Du kontrollerar och sparar; AI:n sparar aldrig något själv.');
    }

    public function icon(): string
    {
        return 'sparkles';
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
            'accountant' => ['ai:use'],
            'bookkeeper' => ['ai:use'],
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
        return [];
    }

    public function settingsComponent(): string
    {
        return AiSettingsForm::NAME;
    }

    public function canEnable(Company $company): ModuleCheck
    {
        return ModuleCheck::allow();
    }

    public function canDisable(Company $company): ModuleCheck
    {
        return new ModuleCheck(warnings: [__('API-nycklarna finns kvar krypterade om modulen slås på igen.')]);
    }

    public function onEnable(Company $company, User $by): void
    {
        app(ModuleManager::class)->seedSettings(self::KEY, $company, self::DEFAULTS);
    }

    public function onDisable(Company $company, User $by): void {}
}
