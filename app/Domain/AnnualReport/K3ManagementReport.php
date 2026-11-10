<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The förvaltningsberättelse of a K3 annual report for a mindre aktiebolag
 * (ADR-059, A.2.7; rule sheet S1-R8.4; package S1-P9), as sections of text
 * and data. The K3 document (S1-P10) prints them in this order, before the
 * income statement (K3 3.10); the equity statement of ÅRL 6 kap. 2 § andra
 * stycket is K3EquityStatement's (S1-P7) and is placed by S1-P10.
 *
 * - ÅRL 6 kap. 1 §: a fair review, with significant events; "Andra stycket
 *   3-5, tredje stycket och fjärde stycket gäller inte mindre företag", so
 *   none of those parts is a section here (LARGER_COMPANY_ONLY).
 * - K3 3.7 (quoted in S1-R8.4): the nature and focus of the business,
 *   important changes, important external factors, special circumstances,
 *   "e) ägare som har mer än tio procent av antalet andelar eller rösterna i
 *   företaget" and "g) väsentliga förändringar i ägarstrukturen". (f, the
 *   komplementär of a kommanditbolag, never applies to an aktiebolag.)
 * - K3 3.7 comment: the multi-year overview shows the development over a
 *   longer time; NONOX keeps its four-year table and states the framework
 *   of each year, because a first K3 year does not restate the K2 years.
 * - K3 3.14 and ÅRL 6 kap. 2 §: the proposed disposition of the free equity.
 *
 * Enligt AI-utredning 2026-11-09, juristgranskning återstår.
 * TODO(legal-verify): the letters a–d of K3 3.7 were taken from ADR-059 §1.4,
 * not re-read; the sentence on the frameworks of the multi-year overview is
 * NONOX's own (K3 35.3A only covers a restated overview).
 */
final class K3ManagementReport
{
    /**
     * Parts of ÅRL 6 kap. 1 § that apply to större företag only (S1-R8.4:
     * "Andra stycket 3-5, tredje stycket och fjärde stycket gäller inte mindre
     * företag"). The keys name what NONOX understands each part to cover.
     * TODO(legal-verify): the labels per point were not re-read against the
     * SFS text (riksdagen.se, SFS 2026:780) for this package.
     */
    public const array LARGER_COMPANY_ONLY = [
        'non_financial_kpis', // 6 kap. 1 § andra stycket 3
        'research_and_development', // 6 kap. 1 § andra stycket 4 (also K3 3.9)
        'branches_abroad', // 6 kap. 1 § andra stycket 5
        'environment_and_staff', // 6 kap. 1 § tredje stycket
        'sustainability_report', // 6 kap. 1 § fjärde stycket
    ];

    /** @var list<array{key: string, heading: string, rule: string, paragraphs: list<string>, data: array<string, mixed>}> */
    private array $sections = [];

    /** @var list<array{key: string, message: string}> */
    private array $questions = [];

    private ?K3Policies $policies = null;

    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly AnnualReport $report,
        public readonly K3SizeClass $size,
        private readonly AnnualAccounts $accounts,
    ) {}

    public static function for(Company $company, FiscalYear $fiscalYear, AnnualReport $report, AnnualAccounts $accounts, K3SizeClass $size): self
    {
        if ($accounts->schedule !== K3Schedule::class) {
            throw new LogicException('The K3 management report needs the K3 schedule.');
        }

        return CurrentCompany::run($company, function () use ($company, $fiscalYear, $report, $accounts, $size): self {
            $management = new self($company, $fiscalYear, $report, $size, $accounts);
            // The annual report is a Swedish document: built in Swedish, the caller's locale restored.
            $locale = App::getLocale();
            App::setLocale('sv');

            try {
                $management->build();
            } finally {
                App::setLocale($locale);
            }

            return $management;
        });
    }

    /** @return list<array{key: string, heading: string, rule: string, paragraphs: list<string>, data: array<string, mixed>}> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** @return list<array{key: string, message: string}> */
    public function questions(): array
    {
        return $this->questions;
    }

    /** Why NONOX does not make this management report, in Swedish, or null (human decision S1-H1). */
    public function refusal(): ?string
    {
        $refusal = match (true) {
            $this->company->legal_form !== LegalForm::Ab => __('NONOX tar fram förvaltningsberättelsen enligt K3 för aktiebolag.'),
            $this->size === K3SizeClass::Storre => __('NONOX tar ännu inte fram förvaltningsberättelsen för ett större företag (årsredovisningslagen 6 kap. 1 § andra stycket 3–5, tredje och fjärde stycket). Den delen kommer i ett senare steg (beslut S1-H1).'),
            $this->size === K3SizeClass::Undetermined => __('Det går inte att avgöra om företaget är ett mindre eller ett större företag. Besvara frågorna om företagets storlek under Bokslut → Regelverk först.'),
            default => null,
        };

        return is_string($refusal) ? $refusal : null;
    }

    private function build(): void
    {
        $business = $this->report->business_description;

        if (blank($business)) {
            $this->questions[] = ['key' => 'business', 'message' => __('Beskriv verksamhetens art och inriktning (K3 punkt 3.7).')];
        }

        $this->text('business', __('Allmänt om verksamheten'), 'ÅRL 6 kap. 1 §, K3 3.7', $business);
        $this->text('significant_events', __('Väsentliga händelser under räkenskapsåret'), 'ÅRL 6 kap. 1 §, K3 3.7', $this->report->significant_events);
        // TODO(legal-verify): "viktiga förändringar i verksamheten" as a part of K3 3.7 is taken from ADR-059 §1.4, not re-read.
        $this->text('business_changes', __('Viktiga förändringar i verksamheten'), 'K3 3.7', $this->report->k3_business_changes);
        $this->text('external_factors', __('Viktiga externa faktorer'), 'K3 3.7', $this->report->k3_external_factors);
        $this->text('special_circumstances', __('Speciella omständigheter'), 'K3 3.7', $this->report->k3_special_circumstances);
        $this->majorOwners();
        $this->text('ownership_changes', __('Väsentliga förändringar i ägarstrukturen'), 'K3 3.7 g', $this->report->k3_ownership_changes);
        $this->multiYear();
        $this->disposition();
    }

    private function text(string $key, string $heading, string $rule, ?string $text): void
    {
        if (blank($text)) {
            return;
        }

        $paragraphs = preg_split('/\R\s*\R/u', trim((string) $text)) ?: [];
        $this->sections[] = ['key' => $key, 'heading' => $heading, 'rule' => $rule, 'paragraphs' => array_values(array_filter(array_map('trim', $paragraphs), fn (string $p): bool => $p !== '')), 'data' => []];
    }

    /** K3 3.7 e: null until a person answers; [] is the answer "no owner above ten per cent". */
    private function majorOwners(): void
    {
        $stored = $this->report->k3_major_owners;

        if ($stored === null) {
            $this->questions[] = ['key' => 'major_owners', 'message' => __('Ange ägare som har mer än tio procent av andelarna eller rösterna (K3 punkt 3.7 e), eller att det inte finns någon.')];

            return;
        }

        $owners = array_map(fn (array $owner): array => [
            'name' => (string) $owner['name'],
            'owner_type' => isset($owner['owner_type']) ? (string) $owner['owner_type'] : null,
            'org_number' => isset($owner['org_number']) ? (string) $owner['org_number'] : null,
            'foreign_registration' => isset($owner['foreign_registration']) ? (string) $owner['foreign_registration'] : null,
            'country' => isset($owner['country']) ? (string) $owner['country'] : null,
            'votes_bp' => (int) $owner['votes_bp'],
            'capital_bp' => (int) $owner['capital_bp'],
        ], $stored);

        // The document prints the name and the share only (Lead decision after the S1-P9 review).
        $paragraphs = $owners === []
            ? [__('Ingen ägare har mer än tio procent av andelarna eller rösterna.')]
            : array_map(fn (array $owner): string => __(':owner: :votes av rösterna och :capital av kapitalet.', [
                'owner' => $owner['name'],
                'votes' => self::percent($owner['votes_bp']),
                'capital' => self::percent($owner['capital_bp']),
            ]), $owners);

        $this->sections[] = ['key' => 'major_owners', 'heading' => __('Ägare med mer än tio procent'), 'rule' => 'K3 3.7 e', 'paragraphs' => $paragraphs, 'data' => ['owners' => $owners]];
    }

    /**
     * The four-year overview with the framework of each year (K3 3.7
     * comment). The frameworks are read in one query.
     */
    private function multiYear(): void
    {
        $rows = $this->accounts->multiYear();
        $frameworks = FiscalYear::query()->whereIn('start_date', array_column($rows, 'start'))->get(['start_date', 'reporting_framework'])
            ->mapWithKeys(fn (FiscalYear $year): array => [$year->start_date->toDateString() => $year->reporting_framework->value]);

        $rows = array_map(fn (array $row): array => [...$row, 'framework' => $frameworks[$row['start']] ?? null], $rows);
        $earlier = array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $row['framework'] === 'K3' ? null : $row['framework'], $rows))));
        $paragraphs = $earlier === []
            ? []
            : [__('Beloppen för räkenskapsår som upprättades enligt :frameworks har inte räknats om enligt K3.', ['frameworks' => implode(__(' och '), $earlier)])];

        // NONOX does not restate comparatives at the transition yet (S1-P12 refuses it), so it never prints a K2 figure as a restated one (K3 35.3A).
        if ($this->policies()->value(K3PolicyKey::ComparativesRestatedAtTransition) === K3YesNo::Yes) {
            $this->questions[] = ['key' => 'multi_year', 'message' => __('Jämförelsetalen ska räknas om enligt K3 vid övergången, men NONOX räknar inte om dem ännu. Flerårsöversikten kan därför inte visa omräknade belopp för föregående år (K3 punkt 35.3A).')];
        }

        $this->sections[] = ['key' => 'multi_year', 'heading' => __('Flerårsöversikt'), 'rule' => 'ÅRL 6 kap. 1 §, K3 3.7', 'paragraphs' => $paragraphs, 'data' => ['rows' => $rows]];
    }

    /** K3 3.14: the proposed disposition of the free equity. */
    private function disposition(): void
    {
        $free = $this->accounts->freeEquity();
        $dividend = $this->report->dividend_ore->ore;
        $available = max($free, 0);

        // ABL 17 kap. 3 §: no more than the free equity may be distributed.
        // TODO(legal-verify): the prudence rule of ABL 17 kap. 3 § andra stycket is a judgement NONOX does not make.
        if ($dividend > $available) {
            $this->questions[] = ['key' => 'disposition', 'message' => __('Den föreslagna utdelningen, :dividend, är större än det fria egna kapitalet, :free. Ett aktiebolag får inte dela ut mer än så (aktiebolagslagen 17 kap. 3 §). Ändra förslaget.', ['dividend' => Money::ofOre($dividend)->format(), 'free' => Money::ofOre($available)->format()])];
        }

        $this->sections[] = ['key' => 'disposition', 'heading' => __('Resultatdisposition'), 'rule' => 'ÅRL 6 kap. 2 §, K3 3.14', 'paragraphs' => [], 'data' => [
            'free_ore' => $free,
            'dividend_ore' => $dividend,
            // A dividend never makes the amount carried forward negative; a loss carried forward stays as it is.
            'carried_ore' => $free - min($dividend, $available),
        ]];
    }

    private function policies(): K3Policies
    {
        return $this->policies ??= K3Policies::forYear($this->fiscalYear);
    }

    private static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',').' %';
    }
}
