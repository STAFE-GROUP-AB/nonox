<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use Carbon\CarbonImmutable;

/**
 * The staff ledger's part of "Att göra" on Översikt: people still checked in
 * longer than the reminder setting, the same rule as the ledger's own
 * overview. Nobody is checked out automatically.
 */
final class StaffLedgerTodos implements TodoProvider
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'staff-ledger:manage')) {
            return [];
        }

        $hours = StaffLedgerSettings::int($this->modules->settings(StaffLedgerModule::KEY, $company), 'reminder_after_hours');
        $now = CarbonImmutable::now();
        $forgotten = count(array_filter(LedgerQuery::present(), fn (Shift $shift): bool => $shift->start !== null && $shift->start->diffInHours($now) >= $hours));

        if ($forgotten === 0) {
            return [];
        }

        return [new TodoItem(
            'staff-ledger-forgotten',
            'clock',
            $forgotten === 1 ? __('1 glömd utcheckning i personalliggaren') : __(':count glömda utcheckningar i personalliggaren', ['count' => $forgotten]),
            __('Incheckad mer än :hours timmar. Rätta med en ny post.', ['hours' => $hours]),
            route('staff-ledger.overview'),
            TodoItem::PRIORITY_STAFF_LEDGER,
        )];
    }

    public function invalidatedBy(): array
    {
        return [Entry::class];
    }
}
