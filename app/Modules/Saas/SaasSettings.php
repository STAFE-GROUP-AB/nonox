<?php

declare(strict_types=1);

namespace App\Modules\Saas;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Saas\Domain\Months;

/**
 * NONOX SaaS's settings per company (ADR-080 §3.5), in company_modules.settings.
 *
 * - history_from: the month the module was first switched on (Y-m-01). The
 *   ledger's months start here; earlier months are only estimated from issued
 *   recurring invoices and labelled "uppskattad" (O9). Set once, never moved.
 * - close_reminder_day: the day of the following month on which Mina datum
 *   reminds that a month can be closed (O6: a person closes; default the 10th).
 *
 * Gross margin, CAC accounts, periodisation and Stripe settings come with
 * later build steps (O7, step 4, step 2).
 */
final class SaasSettings
{
    public const int CLOSE_REMINDER_DAY = 10;

    public static function historyFrom(Company $company): ?string
    {
        $value = app(ModuleManager::class)->settings(SaasModule::KEY, $company)['history_from'] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}-01$/', $value) === 1 ? Months::of($value) : null;
    }

    public static function closeReminderDay(Company $company): int
    {
        $day = (int) (app(ModuleManager::class)->settings(SaasModule::KEY, $company)['close_reminder_day'] ?? self::CLOSE_REMINDER_DAY);

        return max(1, min(28, $day));
    }
}
