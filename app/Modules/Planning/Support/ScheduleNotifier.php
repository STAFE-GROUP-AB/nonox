<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Notifications\ScheduleNotice;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends ScheduleNotice to employees: to their login (Notiser and email)
 * when they have one, otherwise by email to the address in the employee
 * register. Employees with neither are counted as not reached. Loads the
 * employees, their logins and the areas in three queries.
 */
final class ScheduleNotifier
{
    /**
     * @param  array<int, list<Shift>>  $shiftsByEmployee  employee id => the shifts to tell them about
     * @param  array<string, string>  $changes  shift id => "ny", "ändrad", … shown next to the shift
     * @return int how many employees were told
     */
    public function send(Company $company, User $actor, string $kind, string $period, array $shiftsByEmployee, array $changes = []): int
    {
        if ($shiftsByEmployee === []) {
            return 0;
        }

        $employees = Employee::query()->with('user')->whereKey(array_keys($shiftsByEmployee))->get();
        $areas = Area::query()->pluck('name', 'id');
        $notified = 0;

        foreach ($employees as $employee) {
            $lines = array_map(fn (Shift $shift): array => self::line($shift, $areas, $changes[$shift->id] ?? ''), $shiftsByEmployee[$employee->id] ?? []);
            $notice = new ScheduleNotice($kind, $company->id, (string) $company->name, $period, $lines, (string) $actor->name);

            if ($employee->user instanceof User) {
                $employee->user->notify($notice);
                $notified++;
            } elseif (is_string($employee->email) && $employee->email !== '') {
                Notification::route('mail', $employee->email)->notify($notice);
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * Sends any notice to one employee the same way: to their login, or by
     * email to the address in the register. Returns whether they were reached.
     */
    public static function deliver(Employee $employee, BaseNotification $notice): bool
    {
        if ($employee->user instanceof User) {
            $employee->user->notify($notice);

            return true;
        }

        if (is_string($employee->email) && $employee->email !== '') {
            Notification::route('mail', $employee->email)->notify($notice);

            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int|string, mixed>  $areas
     * @return array{day: string, time: string, area: ?string, change: string}
     */
    public static function line(Shift $shift, Collection $areas, string $change = ''): array
    {
        return [
            'day' => self::day($shift->starts_at),
            'time' => $shift->starts_at->format('H:i').'–'.$shift->ends_at->format('H:i'),
            'area' => $shift->area_id === null ? null : (string) $areas->get($shift->area_id),
            'change' => $change,
        ];
    }

    public static function day(CarbonImmutable $day): string
    {
        return ucfirst(self::localized($day)->isoFormat('dddd D MMMM'));
    }

    public static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to)
            ? self::day($from)
            : self::localized($from)->isoFormat('D MMM').' – '.self::localized($to)->isoFormat('D MMM YYYY');
    }

    /** The date with the app's language for day and month names. */
    public static function localized(CarbonImmutable $day): CarbonImmutable
    {
        return $day->settings(['locale' => app()->getLocale()]);
    }
}
