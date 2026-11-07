<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Actions\Company\ChangeReportingFramework;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityAnswers;
use App\Domain\AnnualReport\K3EligibilityStatus;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokslut → Regelverk (ADR-059, addendum 2026-11-07, S1-P11): for one fiscal
 * year, whether the company may use K2 or must use K3, the answers NONOX
 * needs for that, and the K3 accounting policies from the first K3 year.
 * Everything that saving would change is shown before it is saved (spec
 * Behaviour 1). The component only reads the domain classes and calls
 * SaveFrameworkChoice and SavePriorYearSizeFigures.
 */
class ClosingFrameworkPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    public string $framework = '';

    public string $reason = '';

    public bool $frameworkConfirmed = false;

    /** @var array<string, string> question => yes|no|'' */
    public array $answers = [];

    public bool $buildingClassified = false;

    /** @var array<int, string> account numbers ticked in the form */
    public array $buildingAccounts = [];

    /** @var array<string, string> policy key => value|'' */
    public array $policies = [];

    /** @var array<string, string> */
    public array $prior = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);

        if ($this->year !== 0) {
            // Another company's year, or one that does not exist, answers 404 (AGENTS.md §13.2).
            FiscalYear::query()->findOrFail($this->year);
        } else {
            $first = ChangeReportingFramework::eligibleYears($this->company())->first();
            $this->year = $first instanceof FiscalYear ? $first->id : (int) FiscalYear::query()->orderByDesc('start_date')->value('id');
        }

        $this->loadSaved();
    }

    public function updatedYear(): void
    {
        $this->loadSaved();
    }

    public function updatedFramework(): void
    {
        $this->frameworkConfirmed = false;
    }

    public function save(SaveFrameworkChoice $save): void
    {
        $this->resetErrorBag();
        $year = $this->fiscalYear();
        $before = $year->reporting_framework;

        try {
            $year = $save($this->user(), $this->company(), $year->id, $this->input());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (AuthorizationException) {
            $this->toast(__('Du har inte behörighet att ändra regelverket eller svaren för företaget.'), 'error');

            return;
        }

        $this->loadSaved();
        $this->toast($year->reporting_framework !== $before
            ? __('Regelverket :framework gäller nu från räkenskapsåret :year. Svaren och principerna är sparade.', ['framework' => $year->reporting_framework->value, 'year' => $year->label()])
            : __('Svaren och principerna för räkenskapsåret :year är sparade.', ['year' => $year->label()]));
    }

    public function savePriorFigures(SavePriorYearSizeFigures $save): void
    {
        $this->resetErrorBag();

        try {
            $figures = $save($this->user(), $this->company(), $this->prior);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (AuthorizationException) {
            $this->toast(__('Du har inte behörighet att fylla i storleksuppgifter.'), 'error');

            return;
        }

        $this->prior = [];
        $this->toast(__('Storleksuppgifterna för :from–:to är sparade.', ['from' => $figures->start_date->toDateString(), 'to' => $figures->end_date->toDateString()]));
    }

    public function render(): View
    {
        $company = $this->company();
        $fiscalYear = $this->fiscalYear();
        $chosen = ReportingFramework::tryFrom($this->framework) ?? $fiscalYear->reporting_framework;
        $eligibility = K3Eligibility::forYear($fiscalYear, SaveFrameworkChoice::answers($this->input()));
        $years = FiscalYear::query()->orderByDesc('start_date')->get();
        $previous = $years->first(fn (FiscalYear $year): bool => $year->end_date->isSameDay($fiscalYear->start_date->subDay()));
        $eligible = ChangeReportingFramework::eligibleYears($company);
        $canChangeYear = $eligible->contains('id', $fiscalYear->id);
        $frameworkChanges = $chosen !== $fiscalYear->reporting_framework;
        $yearsToChange = $frameworkChanges && $canChangeYear ? ChangeReportingFramework::yearsToChange($chosen, $fiscalYear, $eligible) : collect();
        $firstK3 = SaveFrameworkChoice::isFirstK3Year($fiscalYear, $previous, $chosen);
        $policies = K3Policies::forYear($fiscalYear);
        $transition = $previous instanceof FiscalYear && $previous->reporting_framework !== ReportingFramework::K3;
        $policyKeys = array_values(array_filter(K3PolicyKey::cases(), fn (K3PolicyKey $key): bool => $transition || ! $key->transitionOnly()));
        $canAnswer = $this->user()->hasTeamPermission($company, 'fiscal-years:manage') && $fiscalYear->isOpen();
        $canChangeFramework = $this->user()->can('update', $company) && $canChangeYear;
        $k2Blocked = $chosen === ReportingFramework::K2 && $eligibility->status === K3EligibilityStatus::K3Required;

        return view('livewire.accounting.closing-framework', [
            'company' => $company,
            'fiscalYear' => $fiscalYear,
            'years' => $years,
            'chosen' => $chosen,
            'frameworks' => array_values(array_filter(ReportingFramework::cases(), fn (ReportingFramework $framework): bool => ReportingFrameworkRules::refusal($company->legal_form, $framework) === null)),
            'eligibility' => $eligibility,
            'frameworkChanges' => $frameworkChanges,
            'yearsToChange' => $yearsToChange,
            'conditions' => $frameworkChanges ? ReportingFrameworkRules::conditions($company->legal_form, $chosen) : null,
            'firstK3' => $firstK3,
            'policies' => $policies,
            'policyKeys' => $policyKeys,
            'missingPolicies' => $chosen === ReportingFramework::K3 ? $policies->missing($transition) : [],
            'canAnswer' => $canAnswer,
            'canChangeFramework' => $canChangeFramework,
            'canChangeYear' => $canChangeYear,
            'canEnterFigures' => $this->user()->hasTeamPermission($company, 'fiscal-years:manage'),
            'k2Blocked' => $k2Blocked,
            'changes' => $this->changes($fiscalYear, $chosen, $yearsToChange->map(fn (FiscalYear $year): string => $year->label())->values()->all(), $firstK3, $policies),
        ]);
    }

    /**
     * What saving would do, in plain Swedish, before anything is saved.
     *
     * @param  array<int, string>  $yearLabels
     * @return list<string>
     */
    private function changes(FiscalYear $fiscalYear, ReportingFramework $chosen, array $yearLabels, bool $firstK3, K3Policies $policies): array
    {
        $changes = [];

        if ($chosen !== $fiscalYear->reporting_framework && $yearLabels !== []) {
            $changes[] = __('Regelverket byts från :from till :to för räkenskapsåren :years. Tidigare år behåller sitt regelverk.', ['from' => $fiscalYear->reporting_framework->value, 'to' => $chosen->value, 'years' => implode(', ', $yearLabels)]);
        }

        $before = K3EligibilityAnswers::forYear($fiscalYear)->keys();
        $after = SaveFrameworkChoice::answers($this->input())->keys();
        $changed = count(array_diff($after, $before)) + count(array_diff($before, $after));

        if ($changed > 0) {
            $changes[] = $changed === 1
                ? __('En ändring av svaren sparas för :year.', ['year' => $fiscalYear->label()])
                : __(':count ändringar av svaren sparas för :year.', ['count' => $changed, 'year' => $fiscalYear->label()]);
        }

        if ($firstK3) {
            foreach ($this->policies as $key => $value) {
                $policyKey = K3PolicyKey::tryFrom($key);

                if ($policyKey !== null && $value !== '' && $policies->row($policyKey)?->value !== $value) {
                    $changes[] = __('Principen ”:policy” sparas från räkenskapsåret :year och gäller även de följande åren.', ['policy' => $policyKey->label(), 'year' => $fiscalYear->label()]);
                }
            }
        }

        return $changes;
    }

    /**
     * @return array{framework: string, reason: string, framework_confirmed: bool, answers: array<string, ?string>, building_classified: bool, building_accounts: list<int|string>, policies: array<string, ?string>}
     */
    private function input(): array
    {
        return [
            'framework' => $this->framework,
            'reason' => $this->reason,
            'framework_confirmed' => $this->frameworkConfirmed,
            'answers' => $this->answers,
            'building_classified' => $this->buildingClassified,
            'building_accounts' => array_values($this->buildingAccounts),
            'policies' => $this->policies,
        ];
    }

    private function loadSaved(): void
    {
        $fiscalYear = $this->fiscalYear();
        $saved = K3EligibilityAnswers::forYear($fiscalYear);
        $policies = K3Policies::forYear($fiscalYear);

        $this->framework = $fiscalYear->reporting_framework->value;
        $this->reason = '';
        $this->frameworkConfirmed = false;
        $this->answers = array_map(fn (bool $yes): string => $yes ? 'yes' : 'no', $saved->answers);
        $this->buildingClassified = $saved->buildingClassified;
        $this->buildingAccounts = array_map(fn (int $number): string => (string) $number, $saved->buildingAccounts);
        $this->policies = [];

        foreach (K3PolicyKey::cases() as $key) {
            $this->policies[$key->value] = (string) $policies->row($key)?->value;
        }
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::query()->findOrFail($this->year);
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
