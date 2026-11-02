<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Support\Hq\HqAccess;
use Closure;
use Illuminate\Support\Str;

/**
 * The one navigation tree of the app: the sections in the sidebar and, for
 * the section that is open, its pages. Every place that renders navigation
 * reads it from here, with the permission and module checks in one place.
 *
 * @phpstan-type ChildLink array{label: string, route: string, active?: list<string>, group?: string}
 */
final class Navigation
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleManager $modules,
    ) {}

    /** @return list<NavSection> */
    public function for(User $user, ?Company $company, ?string $currentRoute): array
    {
        $sections = $this->sections($user, $company);
        $open = $this->openSection($sections, $currentRoute);
        $result = [];

        foreach ($sections as $index => $section) {
            $children = $index === $open && $company !== null ? $this->links(($section['children'])($user, $company), $currentRoute) : [];
            $result[] = new NavSection($section['label'], $section['route'], $section['patterns'], $index === $open, $children);
        }

        $hq = $this->hq($user, $currentRoute);

        if ($hq !== null) {
            $result[] = $hq;
        }

        return $result;
    }

    /**
     * HQ (ADR-038): the instance's own area, for the HQ admins only and
     * whatever company they have selected, or none. It belongs to no
     * company, so it is not one of the company sections above.
     */
    private function hq(User $user, ?string $currentRoute): ?NavSection
    {
        if (! HqAccess::allows($user)) {
            return null;
        }

        $patterns = ['hq.*'];
        $active = $currentRoute !== null && Str::is($patterns, $currentRoute);
        $children = $active ? $this->links([
            ['label' => __('Kö'), 'route' => 'hq.waitlist'],
            ['label' => __('Kunder'), 'route' => 'hq.customers'],
        ], $currentRoute) : [];

        return new NavSection(__('HQ'), 'hq.waitlist', $patterns, $active, $children);
    }

    /**
     * Every page in the tree with the section it belongs to, for the
     * spotlight's "Gå till" (ADR-026). Unlike the sidebar, this resolves the
     * pages of every section.
     *
     * @return list<array{label: string, route: string, section: string, group: ?string}>
     */
    public function pages(User $user, Company $company): array
    {
        $pages = [];
        $label = fn (string $label): string => trim((string) preg_replace('/\s*\(\d+\)$/', '', $label));

        foreach ($this->sections($user, $company) as $section) {
            foreach (($section['children'])($user, $company) as $child) {
                $pages[$child['route']] ??= ['label' => $label($child['label']), 'route' => $child['route'], 'section' => $label($section['label']), 'group' => $child['group'] ?? null];
            }

            // A section whose start page is also one of its pages is found under the page's own name.
            $pages[$section['route']] ??= ['label' => $label($section['label']), 'route' => $section['route'], 'section' => $label($section['label']), 'group' => null];
        }

        return array_values($pages);
    }

    /** @return list<array{label: string, route: string, patterns: list<string>, children: Closure(User, Company): list<ChildLink>}> */
    private function sections(User $user, ?Company $company): array
    {
        $can = fn (string $permission): bool => $company !== null && $user->hasTeamPermission($company, $permission);

        $sections = [
            [
                'label' => __('Dashboard'),
                'route' => 'dashboard',
                'patterns' => ['dashboard'],
                'children' => fn (User $user, Company $company): array => [],
            ],
            [
                'label' => __('Försäljning'),
                'route' => 'invoicing.invoices.index',
                'patterns' => ['invoicing.invoices.*', 'invoicing.quotes*', 'invoicing.orders*', 'invoicing.incomingPayments*', 'invoicing.agreements*'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Offerter'), 'route' => 'invoicing.quotes'],
                    ['label' => __('Order'), 'route' => 'invoicing.orders'],
                    ['label' => __('Fakturor'), 'route' => 'invoicing.invoices.index'],
                    ['label' => __('Avtal'), 'route' => 'invoicing.agreements'],
                    ['label' => __('Inbetalningar'), 'route' => 'invoicing.incomingPayments'],
                ],
            ],
            [
                'label' => __('Inköp'),
                'route' => 'payables.invoices.index',
                'patterns' => ['payables.invoices.*', 'payables.batches.*'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Leverantörsfakturor'), 'route' => 'payables.invoices.index'],
                    ['label' => __('Betalfiler'), 'route' => 'payables.batches.index'],
                ],
            ],
            [
                'label' => __('Register'),
                'route' => 'invoicing.customers',
                'patterns' => ['invoicing.customers*', 'invoicing.articles*', 'payables.suppliers*'],
                'children' => fn (User $user, Company $company): array => [
                    ['label' => __('Kunder'), 'route' => 'invoicing.customers'],
                    ['label' => __('Artiklar'), 'route' => 'invoicing.articles'],
                    ['label' => __('Leverantörer'), 'route' => 'payables.suppliers'],
                ],
            ],
            [
                'label' => __('Bokföring'),
                'route' => 'accounting.vouchers.index',
                'patterns' => ['accounting.*', 'vat.*'],
                'children' => fn (User $user, Company $company): array => $this->bookkeeping($can),
            ],
        ];

        // Tasks (ADR-028): the company's, the user's own in every company, and recurring ones.
        $sections[] = [
            'label' => __('Tasks'),
            'route' => 'tasks.index',
            'patterns' => ['tasks.*'],
            'children' => fn (User $user, Company $company): array => [
                ['label' => __('Alla uppgifter'), 'route' => 'tasks.index', 'active' => ['tasks.index', 'tasks.show']],
                ...($can('tasks:manage') ? [['label' => __('Återkommande'), 'route' => 'tasks.recurring']] : []),
            ],
        ];

        foreach ($this->moduleItems($user, $company) as $item) {
            $sections[] = $item;
        }

        if ($user->companies()->count() > 1) {
            $sections[] = [
                'label' => __('Mina företag'),
                'route' => 'companies.overview',
                'patterns' => ['companies.overview'],
                'children' => fn (User $user, Company $company): array => [],
            ];
        }

        return $sections;
    }

    /**
     * @param  Closure(string): bool  $can
     * @return list<ChildLink>
     */
    private function bookkeeping(Closure $can): array
    {
        $ongoing = __('Löpande');
        $reports = __('Rapporter och moms');
        $closing = __('Bokslut');
        $settings = __('Inställningar');

        $links = [
            ['label' => __('Verifikationer'), 'route' => 'accounting.vouchers.index', 'group' => $ongoing],
            $can('vouchers:post') ? ['label' => __('Bankavstämning'), 'route' => 'accounting.bankReconciliation', 'group' => $ongoing] : null,
            $can('fiscal-years:manage') ? ['label' => __('Periodiseringar'), 'route' => 'accounting.accruals', 'group' => $ongoing] : null,
            $can('fiscal-years:manage') ? ['label' => __('Anläggningar'), 'route' => 'accounting.fixedAssets', 'group' => $ongoing] : null,
            $can('import:sie') ? ['label' => __('Läs in SIE-fil'), 'route' => 'accounting.sieImports.index', 'group' => $ongoing] : null,
            $can('reports:view') ? ['label' => __('Rapporter'), 'route' => 'accounting.reports', 'group' => $reports] : null,
            $can('reports:view') ? ['label' => __('Reskontra'), 'route' => 'accounting.ledgers', 'group' => $reports] : null,
            $can('vat:prepare') ? ['label' => __('Moms'), 'route' => 'vat.index', 'active' => ['vat.index', 'vat.file'], 'group' => $reports] : null,
            $can('vat:prepare') ? ['label' => __('EU-rapporter'), 'route' => 'vat.euReports', 'group' => $reports] : null,
            ['label' => __('Räkenskapsår'), 'route' => 'accounting.fiscalYears', 'group' => $closing],
            $can('fiscal-years:manage') ? ['label' => __('Bokslut'), 'route' => 'accounting.closing', 'group' => $closing] : null,
            $can('fiscal-years:manage') ? ['label' => __('Årsredovisning'), 'route' => 'accounting.annualReport', 'group' => $closing] : null,
            $can('fiscal-years:manage') ? ['label' => __('Inkomstdeklaration'), 'route' => 'accounting.incomeTax', 'group' => $closing] : null,
            ['label' => __('Kontoplan'), 'route' => 'accounting.accounts', 'group' => $settings],
            ['label' => __('Verifikationsserier'), 'route' => 'accounting.voucherSeries', 'group' => $settings],
            ['label' => __('Kostnadsställen'), 'route' => 'accounting.costCenters', 'group' => $settings],
            ['label' => __('Projekt'), 'route' => 'accounting.projects', 'group' => $settings],
        ];

        return array_values(array_filter($links));
    }

    /**
     * Navigation of the modules that are on for the company, filtered by
     * permission (PAYROLL_MODULE.md A.5). A module that is off may still
     * show its archive (PAYROLL_MODULE.md A.6).
     *
     * @return list<array{label: string, route: string, patterns: list<string>, children: Closure(User, Company): list<ChildLink>}>
     */
    private function moduleItems(User $user, ?Company $company): array
    {
        if ($company === null) {
            return [];
        }

        $enabled = $this->modules->enabledModules($company);
        $items = [];

        foreach ($this->registry->all() as $module) {
            $moduleItems = in_array($module, $enabled, true) ? $module->navigation() : $module->archiveNavigation($company);

            foreach ($moduleItems as $item) {
                if (! $user->hasTeamPermission($company, $item['permission'])) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'patterns' => (array) $item['active'],
                    'children' => $item['children'] ?? fn (User $user, Company $company): array => [],
                ];
            }
        }

        return $items;
    }

    /**
     * The section the user is in. When two sections claim the route, the one
     * whose own page it is wins (Min incheckning inside Personalliggare).
     *
     * @param  list<array{label: string, route: string, patterns: list<string>, children: Closure(User, Company): list<ChildLink>}>  $sections
     */
    private function openSection(array $sections, ?string $currentRoute): ?int
    {
        if ($currentRoute === null) {
            return null;
        }

        $matching = [];

        foreach ($sections as $index => $section) {
            if ($section['route'] === $currentRoute) {
                return $index;
            }

            if (Str::is($section['patterns'], $currentRoute)) {
                $matching[] = $index;
            }
        }

        return $matching[0] ?? null;
    }

    /**
     * @param  list<ChildLink>  $children
     * @return list<NavLink>
     */
    private function links(array $children, ?string $currentRoute): array
    {
        $links = [];
        $anyActive = false;

        foreach ($children as $child) {
            $patterns = $child['active'] ?? $this->patternsFor($child['route']);
            $active = ! $anyActive && $currentRoute !== null && Str::is($patterns, $currentRoute);
            $anyActive = $anyActive || $active;

            $links[] = new NavLink($child['label'], $child['route'], $patterns, $active, $child['group'] ?? null);
        }

        return $links;
    }

    /**
     * A page's own route and the routes under it: `vouchers.index` covers
     * `vouchers.*`, `quotes` covers `quotes` and `quotes.*`.
     *
     * @return list<string>
     */
    private function patternsFor(string $route): array
    {
        if (str_ends_with($route, '.index')) {
            return [Str::beforeLast($route, '.index').'.*'];
        }

        return [$route, "{$route}.*"];
    }
}
