<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Support\Navigation\Navigation;
use App\Support\Search\BaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use App\Support\Search\TextScore;

/**
 * "Gå till": every page of the navigation tree the user may open, with the
 * permission and module checks of App\Support\Navigation, plus the pages
 * outside the sidebar (profile, company settings, reports by name).
 */
final class PagesProvider extends BaseSearchProvider
{
    public const string KEY = 'pages';

    /** Other words a page answers to, by route name. */
    private const array KEYWORDS = [
        'dashboard' => ['översikt', 'start', 'hem'],
        'vat.index' => ['momsdeklaration', 'momsrapport', 'skatteverket', 'vat'],
        'vat.euReports' => ['periodisk sammanställning', 'oss'],
        'accounting.reports' => ['resultatrapport', 'balansrapport', 'huvudbok', 'saldobalans', 'verifikationslista'],
        'accounting.accounts' => ['konton', 'bas', 'kontoplanen'],
        'accounting.vouchers.index' => ['verifikat', 'bokföring', 'grundbok'],
        'accounting.bankReconciliation' => ['avstämning', 'bank'],
        'accounting.closing' => ['årsbokslut', 'bokslutsverifikationer'],
        'accounting.annualReport' => ['k2', 'bolagsverket'],
        'accounting.incomeTax' => ['ink2', 'sru', 'deklaration'],
        'accounting.sieImports.index' => ['sie', 'import', 'flytta'],
        'invoicing.incomingPayments' => ['betalningar', 'bankfil', 'camt', 'bgmax'],
        'invoicing.agreements' => ['avtalsfakturering', 'återkommande', 'abonnemang'],
        'payables.batches.index' => ['betalningar', 'pain', 'bankgirot'],
        'invoicing.customers' => ['kundregister'],
        'payables.suppliers' => ['leverantörsregister'],
        'companies.overview' => ['byt företag', 'klienter'],
        'payroll.agi' => ['agi', 'arbetsgivardeklaration'],
        'payroll.runs.index' => ['lön', 'löner'],
    ];

    public function __construct(private readonly Navigation $navigation) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Gå till');
    }

    public function order(): int
    {
        return 1;
    }

    public function authorize(SearchContext $context): bool
    {
        return true;
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        $text = $query->navigationText();

        if ($text === '') {
            return null;
        }

        $results = [];

        foreach ($this->pages($context) as $page) {
            $score = TextScore::score($page['label'], $text, [...self::KEYWORDS[$page['route']] ?? [], $page['section']]);

            if ($score === null) {
                continue;
            }

            $results[] = new SearchResult(
                type: self::KEY,
                id: $page['route'],
                title: $page['label'],
                url: $page['url'],
                score: $score,
                subtitle: $page['subtitle'],
                icon: 'arrow',
                remember: false,
            );
        }

        return $this->best($results, $limit);
    }

    /** @return list<array{label: string, route: string, url: string, section: string, subtitle: ?string}> */
    private function pages(SearchContext $context): array
    {
        $pages = [];

        foreach ($this->navigation->pages($context->user, $context->company) as $page) {
            $subtitle = $page['label'] === $page['section'] ? null : self::join($page['section'], $page['group']);
            $pages[] = $this->page($page['label'], $page['route'], route($page['route']), $page['section'], $subtitle);
        }

        $pages[] = $this->page(__('Inställningar för företaget'), 'teams.show', route('teams.show', $context->company->id), __('Företag'), $context->company->name);
        $pages[] = $this->page(__('Min profil'), 'profile.show', route('profile.show'), __('Profil'), null);

        if ($context->can('reports:view')) {
            foreach (['resultat' => __('Resultatrapport'), 'balans' => __('Balansrapport'), 'huvudbok' => __('Huvudbok'), 'saldobalans' => __('Saldobalans')] as $report => $label) {
                $pages[] = $this->page($label, "accounting.reports.{$report}", route('accounting.reports', ['rapport' => $report]), __('Rapporter'), __('Bokföring · Rapporter'));
            }
        }

        return $pages;
    }

    /** @return array{label: string, route: string, url: string, section: string, subtitle: ?string} */
    private function page(string $label, string $route, string $url, string $section, ?string $subtitle): array
    {
        return ['label' => $label, 'route' => $route, 'url' => $url, 'section' => $section, 'subtitle' => $subtitle];
    }
}
