<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Console;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Mail\ForgottenCheckOutMail;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Reminds people who are still checked in after the company's setting
 * (default 12 hours), and their managers, once per check-in. It never checks
 * anyone out: only a person or a manager's correction does that.
 */
class RemindForgottenCheckOutsCommand extends Command
{
    protected $signature = 'personalliggare:remind';

    protected $description = 'Påminner om glömda utcheckningar i personalliggaren (checkar aldrig ut någon)';

    public function handle(ModuleManager $modules): int
    {
        $sent = 0;

        foreach ($modules->companiesWith(StaffLedgerModule::KEY) as $companyId) {
            $company = Company::query()->find($companyId);

            // Nobody works in an archived company (ADR-024).
            if (! $company instanceof Company || $company->isArchived()) {
                continue;
            }

            $this->info("Kontrollerar {$company->name}...");
            $sent += CurrentCompany::run($company, fn (): int => $this->remind($company, $modules));
        }

        $this->comment("Skickade {$sent} påminnelser.");

        return self::SUCCESS;
    }

    private function remind(Company $company, ModuleManager $modules): int
    {
        $hours = StaffLedgerSettings::int($modules->settings(StaffLedgerModule::KEY, $company), 'reminder_after_hours');
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $forgotten = array_values(array_filter(LedgerQuery::present(), fn (Shift $shift): bool => $shift->start !== null && $shift->start <= $cutoff && $shift->startEntry !== null));

        if ($forgotten === []) {
            return 0;
        }

        $entryIds = array_map(fn (Shift $shift): string => (string) $shift->startEntry?->id, $forgotten);
        $reminded = AuditLog::query()->forCompany($company)
            ->where('action', 'staff_ledger.checkout_reminder')
            ->where('subject_type', (new Entry)->getMorphClass())
            ->whereIn('subject_id', $entryIds)
            ->pluck('subject_id')
            ->all();

        $forgotten = array_values(array_filter($forgotten, fn (Shift $shift): bool => ! in_array((string) $shift->startEntry?->id, $reminded, true)));

        if ($forgotten === []) {
            return 0;
        }

        $locations = Location::query()->whereIn('id', array_map(fn (Shift $shift): int => $shift->locationId, $forgotten))->pluck('name', 'id');
        $people = Person::query()->whereIn('id', array_filter(array_map(fn (Shift $shift): ?int => $shift->personId, $forgotten)))->get()->keyBy('id');
        $sent = 0;
        $lines = [];

        foreach ($forgotten as $shift) {
            $line = ['name' => $shift->personName, 'location' => (string) ($locations[$shift->locationId] ?? ''), 'since' => $shift->start?->format('Y-m-d H:i') ?? ''];
            $lines[] = $line;
            $email = $shift->personId === null ? null : $people->get($shift->personId)?->email;

            if (is_string($email) && $email !== '') {
                Mail::to($email)->send(new ForgottenCheckOutMail([$line], $company->name, true));
                $sent++;
            }

            Audit::record('staff_ledger.checkout_reminder', $shift->startEntry, ['hours' => $hours], $company);
        }

        $managers = $company->allUsers()->filter(fn (User $user): bool => $user->hasTeamPermission($company, 'staff-ledger:manage'));

        foreach ($managers as $manager) {
            Mail::to($manager->email)->send(new ForgottenCheckOutMail($lines, $company->name, false));
            $sent++;
        }

        return $sent;
    }
}
