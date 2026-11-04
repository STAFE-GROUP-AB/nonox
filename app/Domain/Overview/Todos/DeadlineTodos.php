<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\DeadlineAudience;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The dates on Mina datum within two weeks (ADR-051, stage 2), for the
 * owner and those who look after the company, unless it has turned
 * Företagsguiden off. VAT and the employer declaration are left out: they
 * have their own items, which also know whether the period is reported.
 */
final class DeadlineTodos implements TodoProvider
{
    public const int DAYS = 14;

    /** Types that already have an item of their own in Att göra (CompanyTasks). */
    private const array ELSEWHERE = [DeadlineType::Vat, DeadlineType::Agi];

    public function __construct(private readonly CompanyDeadlines $deadlines) {}

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $company->guide_enabled || ! DeadlineAudience::includes($user, $company)) {
            return [];
        }

        $items = [];

        foreach ($this->deadlines->upcoming($company, $today) as $deadline) {
            if (in_array($deadline->type, self::ELSEWHERE, true) || $deadline->daysLeft($today) > self::DAYS) {
                continue;
            }

            $items[] = new TodoItem(
                "deadline:{$deadline->key}",
                'calendar',
                $deadline->title,
                $deadline->isRecommendation ? __('Rekommendation från Företagsguiden.') : $deadline->appliesBecause,
                route('guide.dates'),
                TodoItem::PRIORITY_CLOSING,
                $deadline->date,
            );
        }

        return $items;
    }

    public function invalidatedBy(): array
    {
        return [Company::class, FiscalYear::class];
    }
}
