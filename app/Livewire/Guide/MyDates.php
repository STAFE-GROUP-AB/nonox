<?php

declare(strict_types=1);

namespace App\Livewire\Guide;

use App\Actions\Guide\IssueDeadlineFeed;
use App\Actions\Guide\RevokeDeadlineFeed;
use App\Domain\Deadlines\AgiDates;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineCategory;
use App\Domain\Deadlines\DeadlineCompletion;
use App\Domain\Deadlines\DeadlineFilter;
use App\Domain\Deadlines\YearAtAGlance;
use App\Domain\Guide\MoneyNotYours;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Guide\Guide;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Mina datum (/foretagsguiden/mina-datum, ADR-051 stage 2): the company's
 * deadlines for the coming six months (all 15 with ?alla=1) by month, why
 * each applies, how many days are left, whether a VAT or AGI period is
 * already reported, and the user's calendar link. Computed from the company's
 * settings and fiscal years on every view; nothing is stored but the link.
 *
 * The page also teaches (ADR-051 addendum): "Ditt år i korthet" says what
 * kinds of dates the company has and which setting decides them, every row
 * carries a chip for its kind and whether it is a payment or a declaration,
 * the first row of each kind explains it, a flag that repeats in a series is
 * shown once, and ?visa=… filters by kind (a GET link, no JavaScript).
 */
class MyDates extends Component
{
    use ShowsToasts;

    /** The new link, shown once right after it is made; never stored in clear. */
    public ?string $newLink = null;

    /** All 15 months (?alla=1) instead of the next six. */
    #[Locked]
    public bool $showAll = false;

    /** The kind of dates shown (?visa=moms …), or '' for all of them. */
    #[Locked]
    public string $filter = '';

    /** Months shown by default; the page links to all of them. */
    public const int DEFAULT_MONTHS = 6;

    /** The "Så läser du sidan" panel, remembered closed per user and company (User::hidden_panels). */
    public const string HELP_PANEL = 'guide-dates-help';

    public function mount(): void
    {
        $this->showAll = request()->boolean('alla');
        $visa = request()->query('visa');
        // A tampered ?visa[]= is treated as no filter, never as an error.
        $this->filter = is_string($visa) ? (DeadlineFilter::tryFrom($visa)->value ?? '') : '';
    }

    #[Renderless]
    public function rememberHelp(bool $open): void
    {
        $this->user()->setPanelHidden(self::HELP_PANEL, $this->company(), ! $open);
    }

    public function issueLink(IssueDeadlineFeed $issue): void
    {
        // A link for a company that has turned the guide off would answer 404 (DeadlineFeedController).
        abort_unless($this->company()->guide_enabled, 404);

        $this->newLink = $issue($this->user(), $this->company());
        $this->toast(__('Kalenderlänken är skapad. Kopiera den nu, den visas bara en gång.'));
    }

    public function revokeLink(RevokeDeadlineFeed $revoke): void
    {
        $this->newLink = null;
        $revoke($this->user(), $this->company())
            ? $this->toast(__('Kalenderlänken är avstängd. Kalendern hämtar inga fler datum.'))
            : $this->toast(__('Det fanns ingen kalenderlänk att stänga av.'));
    }

    public function render(CompanyDeadlines $deadlines, MoneyNotYours $moneyNotYours): View
    {
        $company = $this->company();

        if (! $company->guide_enabled) {
            return view('livewire.guide.my-dates-off', ['company' => $company]);
        }

        $today = CarbonImmutable::today();
        // Two weeks back as well, so a date that has just passed is still in sight, marked as passed.
        $from = $today->subDays(14)->startOfDay();
        // One computation for both the summary of the year (fifteen months) and the list (six, or all fifteen).
        $year = $deadlines->upcoming($company, $from);
        $until = $from->addMonthsNoOverflow($this->showAll ? CompanyDeadlines::MONTHS : self::DEFAULT_MONTHS);
        $window = array_values(array_filter($year, fn (Deadline $deadline): bool => $deadline->date->lessThanOrEqualTo($until)));
        $filter = DeadlineFilter::tryFrom($this->filter);
        $shown = $filter === null ? $window : array_values(array_filter($window, fn (Deadline $deadline): bool => $filter->matches($deadline)));

        $counts = [];

        foreach (DeadlineFilter::cases() as $case) {
            $counts[$case->value] = count(array_filter($window, fn (Deadline $deadline): bool => $case->matches($deadline)));
        }

        $months = [];
        // A flag that repeats in a series (every F-skatt month) is shown on its first row only; a one-off keeps it.
        $flagged = [];
        // "Vad är det här?" on the first row of each kind.
        $explained = [];
        // The reason is written once per series: the next F-skatt month says the same as the first.
        $described = [];
        $lastReason = [];

        foreach ($shown as $deadline) {
            $months[$deadline->date->format('Y-m')][] = $deadline;
            $series = strstr($deadline->key, ':', true) ?: $deadline->key;

            if ($deadline->legalVerify && ! isset($flagged[$series])) {
                $flagged[$series] = $deadline->key;
            }

            $explained[DeadlineCategory::of($deadline)->value] ??= $deadline->key;

            if (($lastReason[$series] ?? null) !== $deadline->appliesBecause) {
                $described[] = $deadline->key;
                $lastReason[$series] = $deadline->appliesBecause;
            }
        }

        // Momsbufferten (stage 3): only for those who may see the reports, as the figures are the ledger's.
        $money = $this->user()->hasTeamPermission($company, 'reports:view') ? $moneyNotYours->summary($company, $today) : ['figures' => null, 'vat' => []];

        $firstYear = FiscalYear::query()->orderBy('start_date')->value('start_date');
        $firstYear = $firstYear === null ? null : CarbonImmutable::parse((string) $firstYear);

        return view('livewire.guide.my-dates', [
            'company' => $company,
            'today' => $today,
            'months' => $months,
            'shown' => $shown,
            'filterCase' => $filter,
            'counts' => $counts,
            'windowCount' => count($window),
            'lines' => YearAtAGlance::lines($company, $year, $today),
            'flagged' => array_values($flagged),
            'explained' => array_values($explained),
            'described' => $described,
            'until' => $until,
            'largeEmployer' => AgiDates::isLarge($company),
            'moneyFigures' => $money['figures'],
            'moneyVat' => $money['vat'],
            'setAsideUrl' => Guide::article('moms-och-skatt', 'satt-undan-pengar', false) === null ? null : Guide::url('moms-och-skatt', 'satt-undan-pengar'),
            'helpHidden' => $this->user()->hidesPanel(self::HELP_PANEL, $company),
            // Counted on the whole window, whatever the filter, so every filter runs the same queries.
            'reported' => DeadlineCompletion::done($company, $window),
            // Dates of a year before the first one in NONOX can still be ahead (REGLER.md review 2026-11-04).
            'missingEarlierYear' => $firstYear !== null && $firstYear->greaterThan($today->subMonthsNoOverflow(16)) ? $firstYear : null,
            'feed' => DeadlineCalendarFeed::query()->where('user_id', $this->user()->id)->whereNull('revoked_at')->latest('id')->first(),
            'articleUrl' => fn (Deadline $deadline): ?string => self::articleUrl($deadline->article),
            'categoryUrl' => fn (DeadlineCategory $category): ?string => self::articleUrl($category->article()),
            'missedUrl' => Guide::article('viktiga-datum', 'om-du-missar-ett-datum', false) === null ? null : Guide::url('viktiga-datum', 'om-du-missar-ett-datum'),
        ]);
    }

    /** The page's own address with the filter and the span, for the GET links. */
    public function link(?string $filter, ?bool $all = null): string
    {
        return route('guide.dates', array_filter(['visa' => $filter, 'alla' => ($all ?? $this->showAll) ? 1 : null]));
    }

    private static function articleUrl(?string $article): ?string
    {
        if ($article === null || ! str_contains($article, '/')) {
            return null;
        }

        [$section, $slug] = explode('/', $article, 2);

        return Guide::article($section, $slug, false) === null ? null : Guide::url($section, $slug);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
