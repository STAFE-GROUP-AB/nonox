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
    ];

    /** @var array<string, array{int, int}> key => [min, max] */
    public const array LIMITS = [
        'months_to_inactive' => [1, 120],
        'prune_after_months' => [6, 120],
        'suppression_review_years' => [1, 10],
        'win_back_months' => [1, 36],
        'registry_monitoring' => [0, 1],
        'registry_monitoring_prospects' => [0, 1],
    ];

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

    /** @return array<string, int> */
    public static function for(Company $company): array
    {
        return self::resolve(app(ModuleManager::class)->settings(CrmModule::KEY, $company));
    }
}
