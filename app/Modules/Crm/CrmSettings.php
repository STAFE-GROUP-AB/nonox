<?php

declare(strict_types=1);

namespace App\Modules\Crm;

use App\Models\Company;
use App\Modules\ModuleManager;

/**
 * CRM's settings per company (ADR-043), kept in company_modules.settings.
 *
 * - months_to_inactive: an active customer without an invoice for this many
 *   months becomes inactive. The company's own rule.
 * - prune_after_months: contact people and prospects without any activity
 *   for this many months are erased by privacy:prune, unless they are linked
 *   to the ledger. TODO(legal-verify): the default retention for prospects
 *   and their contact people (docs/gdpr/lagringstider.md).
 * - suppression_review_years: an entry on the list of people who objected
 *   (SuppressionList) is listed in "Att göra" for review when it has not
 *   been reviewed for this many years. TODO(legal-verify): the default.
 * - win_back_months: a lost deal or a lost account gets a "Vinn tillbaka"
 *   date this many months after the loss (ADR-043 stage 2); the date can be
 *   changed or cleared per deal and account.
 * - registry_monitoring: 1 turns changes in the company register for the
 *   company's customers into "Ändring hos kunden" tasks (ADR-047), 0 not.
 * - registry_monitoring_prospects: 1 watches the prospects as well; off by
 *   default, since a prospect is not yet someone the company deals with.
 * - calendar_details (1/0, ADR-049): users may put the details (subject,
 *   place, note, people) in their calendar link and connected calendar.
 *   Off: every calendar gets "Möte: <företag>" and a link only.
 * - calendar_sync (1/0, ADR-049): users may connect their own Google or
 *   Microsoft calendar. Off by default: the company, as controller, decides
 *   that CRM data may be copied to those services.
 * - calendar_private_accounts (1/0, ADR-049): private Google or Microsoft
 *   accounts may be connected too. Off: only organisation accounts
 *   (Google Workspace, Microsoft Entra work or school accounts).
 * - agreement_reminder_days: an agreement gets a CRM task this many days
 *   before its notice deadline (or its end without a notice period), once
 *   per period (ADR-046).
 */
final class CrmSettings
{
    /** @var array<string, int> */
    public const array DEFAULTS = [
        'months_to_inactive' => 12,
        'prune_after_months' => 24,
        'suppression_review_years' => 3,
        'win_back_months' => 6,
        'registry_monitoring' => 1,
        'registry_monitoring_prospects' => 0,
        'calendar_details' => 1,
        'calendar_sync' => 0,
        'calendar_private_accounts' => 0,
        'agreement_reminder_days' => 60,
    ];

    /** @var array<string, array{int, int}> key => [min, max] */
    public const array LIMITS = [
        'months_to_inactive' => [1, 120],
        'prune_after_months' => [6, 120],
        'suppression_review_years' => [1, 10],
        'win_back_months' => [1, 36],
        'registry_monitoring' => [0, 1],
        'registry_monitoring_prospects' => [0, 1],
        'calendar_details' => [0, 1],
        'calendar_sync' => [0, 1],
        'calendar_private_accounts' => [0, 1],
        'agreement_reminder_days' => [7, 365],
    ];

    /** The settings that are a yes or a no. */
    public const array SWITCHES = ['registry_monitoring', 'registry_monitoring_prospects', 'calendar_details', 'calendar_sync', 'calendar_private_accounts'];

    /** Days ahead "Att göra" warns before people and prospects are erased. */
    public const int PRUNE_NOTICE_DAYS = 30;

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, int>
     */
    public static function resolve(array $stored): array
    {
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            [$min, $max] = self::LIMITS[$key];
            $settings[$key] = is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
        }

        return $settings;
    }

    /**
     * The company's own privacy policy (ADR-048): linked from every web form
     * and every mailing, and required before either can be published or
     * sent. Null when not set.
     */
    public static function privacyUrl(Company $company): ?string
    {
        $url = app(ModuleManager::class)->settings(CrmModule::KEY, $company)['privacy_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /** @return array<string, int> */
    public static function for(Company $company): array
    {
        return self::resolve(app(ModuleManager::class)->settings(CrmModule::KEY, $company));
    }
}
