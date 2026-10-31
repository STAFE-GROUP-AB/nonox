<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Models\Company;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;

/**
 * The inbox module's settings for a company, with defaults.
 */
final class InboxSettings
{
    /**
     * @return array{ai_interpretation: bool, daily_summary: bool, notify_on_action: bool, imap_enabled: bool}
     */
    public static function for(Company $company): array
    {
        $settings = app(ModuleManager::class)->settings(InboxModule::KEY, $company);

        return [
            'ai_interpretation' => (bool) ($settings['ai_interpretation'] ?? true),
            'daily_summary' => (bool) ($settings['daily_summary'] ?? false),
            'notify_on_action' => (bool) ($settings['notify_on_action'] ?? false),
            'imap_enabled' => (bool) ($settings['imap_enabled'] ?? false),
        ];
    }
}
