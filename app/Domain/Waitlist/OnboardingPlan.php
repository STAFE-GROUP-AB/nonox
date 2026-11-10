<?php

declare(strict_types=1);

namespace App\Domain\Waitlist;

use App\Domain\Company\Industry;
use App\Models\Company;
use App\Models\WaitlistEntry;
use App\Modules\Contracts\OfferedTo;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;

/**
 * What a person who was let in from the waiting list should do first,
 * worked out from what they answered there (ADR-038 addendum): where they
 * come from, whether they have employees and what the company does. The
 * welcome mail, the checklist on the overview and the help mail all read
 * the plan, so they cannot disagree.
 */
final readonly class OnboardingPlan
{
    public const string FORTNOX = 'fortnox';

    public const string OTHER_PROGRAM = 'other';

    public const string NO_PROGRAM = 'none';

    /**
     * The modules worth switching on for an industry, in the order they
     * make sense. A module's dependencies come before it. Every key is
     * checked against the registry when the plan is used.
     *
     * @var array<string, list<string>>
     */
    private const array MODULES = [
        'cafe' => ['inventory', 'pos', 'kitchen'],
        'restaurant' => ['inventory', 'pos', 'kitchen'],
        'crafts' => ['work_orders', 'portal', 'inbox'],
        'consultant' => ['crm', 'inbox'],
        'retail' => ['inventory', 'pos', 'inbox'],
        'hair_beauty' => ['inventory', 'pos'],
        'accounting_firm' => ['inbox', 'crm'],
        'finance_consultant' => ['inbox', 'crm'],
        'other' => ['inbox'],
    ];

    /**
     * Where each module is explained in the manual: a section or an article
     * under /manual.
     *
     * @var array<string, string>
     */
    public const array MANUAL = [
        'inventory' => 'lager',
        'pos' => 'lager',
        'kitchen' => 'kok',
        'work_orders' => 'fakturering/arbetsorder',
        'portal' => 'fakturering/kundportal',
        'crm' => 'crm',
        'inbox' => 'leverantorer/inkorgen',
        'payroll' => 'lon',
    ];

    public function __construct(
        public string $system,
        public bool $hasEmployees,
        public ?Industry $industry,
    ) {}

    public static function forEntry(WaitlistEntry $entry): self
    {
        return new self(
            self::systemFor($entry->current_system),
            $entry->employees !== 'inga',
            self::industryFor($entry->industry),
        );
    }

    /** "Inget" and "Kalkylblad eller papper" are no program; "fortnox" has its own move; anything else is another program. */
    public static function systemFor(string $currentSystem): string
    {
        return match ($currentSystem) {
            self::FORTNOX => self::FORTNOX,
            WaitlistOptions::NONE, WaitlistOptions::SPREADSHEET => self::NO_PROGRAM,
            default => self::OTHER_PROGRAM,
        };
    }

    /** The industry of an industry page's slug on the waiting list form, or null for "Annat" and anything unknown. */
    public static function industryFor(string $slug): ?Industry
    {
        foreach (Industry::cases() as $industry) {
            if ($industry->landingSlug() === $slug) {
                return $industry;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function moduleKeysFor(?Industry $industry): array
    {
        return self::MODULES[($industry ?? Industry::Other)->value];
    }

    /**
     * The suggested modules that exist and are offered. A module that is
     * only for some companies is left out when there is no company to ask
     * about; one that is already on is left out when a company is given and
     * `$includeEnabled` is false.
     *
     * @return list<array{key: string, name: string, manual: string, enabled: bool}>
     */
    public function modules(?Company $company = null, bool $includeEnabled = true): array
    {
        $registry = app(ModuleRegistry::class);
        $modules = [];

        foreach (self::moduleKeysFor($this->industry) as $key) {
            if (! $registry->has($key)) {
                continue;
            }

            $module = $registry->get($key);

            if ($module instanceof OfferedTo && ($company === null || ! $module->isOfferedTo($company))) {
                continue;
            }

            $enabled = $company !== null && app(ModuleManager::class)->isEnabled($key, $company);

            if ($enabled && ! $includeEnabled) {
                continue;
            }

            $modules[] = ['key' => $key, 'name' => $module->name(), 'manual' => self::manualUrl($key), 'enabled' => $enabled];
        }

        return $modules;
    }

    public static function manualUrl(string $key): string
    {
        return url('/manual/'.(self::MANUAL[$key] ?? ''));
    }
}
