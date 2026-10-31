<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Personalliggare → Översikt: who is on site now per location (refreshed
 * every 15 seconds), and deviations: forgotten check-outs and long shifts.
 * "Checked in without a schedule" needs schedule data that NONOX does not
 * have yet (TODO, ADR-022).
 */
class OverviewPage extends Component
{
    use InCurrentCompany;

    public function mount(): void
    {
        abort_unless($this->allowed('staff-ledger:view'), 403);
    }

    public function render(ModuleManager $modules): View
    {
        $settings = $modules->settings(StaffLedgerModule::KEY, $this->company());
        $reminderHours = StaffLedgerSettings::int($settings, 'reminder_after_hours');
        $longHours = StaffLedgerSettings::int($settings, 'long_shift_hours');
        $now = CarbonImmutable::now();

        $locations = Location::query()->whereNull('archived_at')->withCount(['devices as active_devices_count' => fn ($query) => $query->whereNull('revoked_at')->whereNotNull('token_hash')])->orderBy('name')->get();
        $present = collect(LedgerQuery::present());
        $recent = LedgerQuery::shifts(null, $now->subDays(7), $now);

        $forgotten = $present->filter(fn (Shift $shift): bool => $shift->start !== null && $shift->start->diffInHours($now) >= $reminderHours)->values();
        $long = collect($recent)->filter(fn (Shift $shift): bool => $shift->end !== null && ($shift->hours() ?? 0) >= $longHours)->values();
        $missingStart = collect($recent)->filter(fn (Shift $shift): bool => $shift->start === null)->values();

        $verification = AuditLog::query()->forCompany($this->company())->where('action', 'staff_ledger.verified')->latest('id')->first(['payload', 'created_at']);

        return view('staff-ledger::livewire.overview', [
            'locations' => $locations,
            'presentByLocation' => $present->groupBy(fn (Shift $shift): int => $shift->locationId),
            'forgotten' => $forgotten,
            'long' => $long,
            'missingStart' => $missingStart,
            'reminderHours' => $reminderHours,
            'longHours' => $longHours,
            'verification' => $verification,
            'now' => $now,
            'canManage' => $this->allowed('staff-ledger:manage'),
        ]);
    }
}
