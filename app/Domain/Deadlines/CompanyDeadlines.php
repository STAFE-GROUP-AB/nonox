<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Providers\AgiDeadlineProvider;
use App\Domain\Deadlines\Providers\EuSalesListProvider;
use App\Domain\Deadlines\Providers\PreliminaryTaxProvider;
use App\Domain\Deadlines\Providers\VatDeadlineProvider;
use App\Domain\Deadlines\Providers\YearEndDeadlineProvider;
use App\Models\Company;
use App\Modules\Contracts\ProvidesDeadlines;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Mina datum (ADR-051, stage 2): every date that applies to a company, from
 * the core rules and from the modules that are on for it (ProvidesDeadlines),
 * sorted by date. The rules are docs/foretagsguiden/datum/REGLER.md, one
 * provider per kind of date. Computed from the company's settings and
 * fiscal years, never stored; the page says so and asks the user to check
 * the date with Skatteverket.
 */
final class CompanyDeadlines
{
    /** How far ahead Mina datum looks by default. */
    public const int MONTHS = 15;

    /** @var list<class-string<DeadlineProvider>> */
    private const array CORE = [VatDeadlineProvider::class, EuSalesListProvider::class, AgiDeadlineProvider::class, PreliminaryTaxProvider::class, YearEndDeadlineProvider::class];

    /** @var array<string, list<Deadline>> */
    private array $memo = [];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRegistry $modules,
        private readonly ModuleManager $manager,
    ) {}

    /**
     * The deadlines from $from (inclusive) for $months months, earliest first.
     *
     * @return list<Deadline>
     */
    public function upcoming(Company $company, CarbonImmutable $from, int $months = self::MONTHS): array
    {
        $from = $from->startOfDay();
        $to = $from->addMonthsNoOverflow($months);
        // Översikt asks twice in one request (Att göra and Nästa datum): the same company, settings and span are computed once.
        $memo = $company->id.':'.$from->toDateString().':'.$months.':'.md5(serialize($company->getAttributes()));

        if (isset($this->memo[$memo])) {
            return $this->memo[$memo];
        }

        $deadlines = CurrentCompany::run($company, fn (): array => FiscalCalendar::sharing(function () use ($company, $from, $to): array {
            $all = [];

            foreach ($this->providers($company) as $provider) {
                array_push($all, ...$provider->deadlines($company, $from, $to));
            }

            return $all;
        }));

        $deadlines = array_values(array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->date->betweenIncluded($from, $to)));
        usort($deadlines, fn (Deadline $a, Deadline $b): int => [$a->date->toDateString(), $a->isRecommendation, $a->title] <=> [$b->date->toDateString(), $b->isRecommendation, $b->title]);

        return $this->memo[$memo] = $deadlines;
    }

    /** @return list<DeadlineProvider> */
    private function providers(Company $company): array
    {
        $providers = array_map(fn (string $class): DeadlineProvider => $this->container->make($class), self::CORE);

        foreach ($this->modules->all() as $key => $module) {
            if ($module instanceof ProvidesDeadlines && $this->manager->isEnabled($key, $company)) {
                $providers[] = $module->deadlineProvider();
            }
        }

        return $providers;
    }
}
