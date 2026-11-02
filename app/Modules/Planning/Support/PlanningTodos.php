<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;

/**
 * Planering's part of "Att göra" on Översikt: drafts within the period the
 * company wants published ahead (default 14 days) that nobody has published.
 * Requests from staff waiting for an answer. Days with published shifts
 * in the month of the next payroll run that are not yet sent to payroll.
 * Five queries.
 */
final class PlanningTodos implements TodoProvider
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'planning:manage')) {
            return [];
        }

        $ahead = PlanningSettings::int(PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company)), 'publish_ahead_days');
        $drafts = ShiftQuery::startingOn($today, $today->addDays(max(0, $ahead)))->where('status', Shift::DRAFT)->count();

        $todos = [];

        if ($drafts > 0) {
            $todos[] = new TodoItem(
                'planning-unpublished',
                'calendar',
                $drafts === 1 ? __('1 pass i schemat är inte publicerat') : __(':count pass i schemat är inte publicerade', ['count' => $drafts]),
                __('Personalen ser passen först när du publicerar. Gäller de närmaste :days dagarna.', ['days' => $ahead]),
                route('planning.schedule'),
                TodoItem::PRIORITY_STAFF_LEDGER,
            );
        }

        $requests = ShiftRequest::query()->where('status', ShiftRequest::PENDING)->count();

        if ($requests > 0) {
            $todos[] = new TodoItem(
                'planning-requests',
                'calendar',
                $requests === 1 ? __('1 förfrågan om pass väntar på svar') : __(':count förfrågningar om pass väntar på svar', ['count' => $requests]),
                __('Personalen vill ta öppna pass, byta eller lämna pass. Inget ändras förrän du godkänner.'),
                route('planning.requests'),
                TodoItem::PRIORITY_STAFF_LEDGER,
            );
        }

        $attest = $this->attestTodo($user, $company, $today);

        if ($attest !== null) {
            $todos[] = $attest;
        }

        return $todos;
    }

    /**
     * Employee days with a published shift, from the first day of the next
     * payroll run's month (the open regular run, else this month) up to
     * yesterday, not yet sent to payroll.
     */
    private function attestTodo(User $user, Company $company, CarbonImmutable $today): ?TodoItem
    {
        if (! $user->hasTeamPermission($company, 'planning:attest')) {
            return null;
        }

        $period = PayrollRun::query()->where('type', 'REGULAR')->whereIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])->orderBy('period')->value('period');
        $from = is_string($period) ? CarbonImmutable::parse("{$period}-01", LocalTimestamp::ZONE) : $today->setTimezone(LocalTimestamp::ZONE)->startOfMonth();
        $to = $from->endOfMonth()->startOfDay()->min($today->setTimezone(LocalTimestamp::ZONE)->subDay()->startOfDay());

        if ($to->lessThan($from)) {
            return null;
        }

        $days = ShiftQuery::startingOn($from, $to)
            ->where('status', Shift::PUBLISHED)
            ->whereNotNull('employee_id')
            ->get(['employee_id', 'starts_at'])
            ->map(fn (Shift $shift): string => "{$shift->employee_id}|{$shift->starts_at->toDateString()}")
            ->unique();
        $sent = Attestation::query()
            ->where('status', Attestation::SENT)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['employee_id', 'date'])
            ->map(fn (Attestation $attestation): string => "{$attestation->employee_id}|{$attestation->date->toDateString()}");
        $count = $days->diff($sent)->count();

        if ($count === 0) {
            return null;
        }

        $month = $from->translatedFormat('F Y');

        return new TodoItem(
            'planning-attest',
            'calendar',
            $count === 1 ? __('1 dag att attestera före lönen för :month', ['month' => $month]) : __(':count dagar att attestera före lönen för :month', ['count' => $count, 'month' => $month]),
            __('Jämför schemat med den faktiska tiden, attestera och skicka till lönen.'),
            route('planning.attest', ['fran' => $from->toDateString(), 'till' => $from->endOfMonth()->toDateString()]),
            TodoItem::PRIORITY_STAFF_LEDGER,
        );
    }

    public function invalidatedBy(): array
    {
        return [Shift::class, ShiftRequest::class, Attestation::class];
    }
}
