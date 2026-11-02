<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Modules\Payroll\Models\Employee;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\Actions\OpenControlVisit;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\RecordKioskEvents;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\Actions\TimeProposals;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Models\TimeProposal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The last three weeks of the staff ledger at the restaurant and the café:
 * a paired kiosk by the entrance, a PIN and a QR card for everyone, and
 * every shift checked in and out at the kiosk as it happened. Some of the
 * everyday mishaps are in it too: a forgotten check-out entered afterwards
 * by hand, one still open from yesterday, a double tap marked as wrong and
 * a check-out at closing time corrected to the real time. At the
 * restaurant the ledger's hours are proposed as time reports, and those of
 * the hourly staff approved for November's payroll.
 *
 * Each event is written at the moment it happened (the clock is set to it),
 * so the ledger reads as one kept in real time.
 */
final class StaffLedgerExtras extends DemoExtra
{
    private const int DAYS = 20;

    /** [days back from yesterday, person index]: double tap, forgotten check-out, late check-out, forgotten and left open. */
    private const array MISHAPS = [[8, 1], [5, 2], [3, 1], [0, 3]];

    public function industries(): array
    {
        return ['bokforing-for-restauranger', 'bokforing-for-cafeer'];
    }

    public function schedule(DemoContext $context): void
    {
        $location = Location::query()->orderBy('id')->first();

        if (! $location instanceof Location) {
            return;
        }

        $restaurant = $context->slug === 'bokforing-for-restauranger';
        $first = $context->end->subDays(self::DAYS);

        $context->on($first, 1, fn () => $this->setUp($context, $location, $first->setTime(8, 5)));

        for ($day = $first; $day <= $context->end; $day = $day->addDay()) {
            // The restaurant is closed on Mondays (DemoIndustries).
            if ($restaurant && $day->isMonday()) {
                continue;
            }

            $date = $day;
            $context->on($date, 80, fn () => $this->day($context, $location, $date));
        }

        if ($restaurant) {
            $context->on($context->end, 85, fn () => $this->timeReports($context));
        }
    }

    /**
     * The kiosk and the persons' credentials, set up the morning the
     * period starts. The restaurant's waitress Lina is linked to her login.
     */
    private function setUp(DemoContext $context, Location $location, CarbonImmutable $at): void
    {
        $this->at($at, function () use ($context, $location): void {
            [, $code] = app(KioskDevices::class)->register($context->user, $context->company, $location, 'Surfplattan vid entrén');
            app(KioskDevices::class)->pair($code) ?? throw new RuntimeException('The demo kiosk could not be paired.');

            $credentials = app(PersonCredentials::class);

            foreach ($this->people() as $person) {
                $credentials->setPin($context->user, $context->company, $person, self::pin($person));
                $credentials->renewQr($context->user, $context->company, $person);
            }

            $lina = PortalExtras::user();
            $person = Person::query()->where('first_name', PortalExtras::EMPLOYEE)->first();

            if ($lina !== null && $person instanceof Person && $context->company->hasUser($lina)) {
                app(SavePerson::class)($context->user, $context->company, [
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'email' => PortalExtras::EMAIL,
                    'language' => $person->language,
                    'employee_id' => $person->employee_id,
                    'user_id' => $lina->id,
                    'is_manager' => $person->is_manager,
                ], $person);
            }
        });
    }

    /** Everyone working the day checks in and out at the kiosk; and the day's mishaps. */
    private function day(DemoContext $context, Location $location, CarbonImmutable $day): void
    {
        $device = Device::query()->where('location_id', $location->id)->whereNotNull('paired_at')->whereNull('revoked_at')->firstOrFail();
        $back = (int) $context->end->diffInDays($day, true);
        $shifts = [];

        foreach ($this->people() as $index => $person) {
            $key = "{$context->slug}-{$person->id}-{$day->toDateString()}";

            // About five days a week each; the manager and those with a mishap that day always.
            $mishap = in_array([$back, $index], self::MISHAPS, true);

            if (! $person->is_manager && ! $mishap && PayrollExtras::pick("works-{$key}", 0, 6) < 2) {
                continue;
            }

            $late = $index % 2 === 1;
            $start = $day->setTime($late ? 15 : 9, $late ? 30 : 45)->addMinutes(PayrollExtras::pick("in-{$key}", -12, 9));
            $end = $start->addMinutes($late ? PayrollExtras::pick("length-{$key}", 360, 420) : PayrollExtras::pick("length-{$key}", 405, 510));
            $shifts[] = [$person, $start, $end, $index];
        }

        $events = [];

        foreach ($shifts as [$person, $start, $end, $index]) {
            $method = $index % 3 === 0 ? CheckInMethod::KioskQr : CheckInMethod::KioskPin;
            $events[] = [$start, $person, Direction::In, $method];

            // A double tap at the kiosk, eight days back.
            if ($back === 8 && $index === 1) {
                $events[] = [$start->addSeconds(4), $person, Direction::In, $method];
            }

            $forgot = ($back === 5 && $index === 2) || ($back === 0 && $index === 3);

            if (! $forgot) {
                // Three days back someone checks out only at closing time.
                $events[] = [$back === 3 && $index === 1 ? $day->setTime(23, 52) : $end, $person, Direction::Out, $method];
            }
        }

        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($events as [$at, $person, $direction, $method]) {
            $this->kiosk($device, $person, $direction, $at, $method);
        }

        $this->mishaps($context, $location, $day, $back, $shifts);
    }

    /**
     * The corrections a manager makes the morning after: the forgotten
     * check-out entered by hand, the double tap marked as wrong and the
     * late check-out set to the real time. Yesterday's forgotten check-out
     * is left for the manual's "glömt att checka ut".
     *
     * @param  list<array{0: Person, 1: CarbonImmutable, 2: CarbonImmutable, 3: int}>  $shifts
     */
    private function mishaps(DemoContext $context, Location $location, CarbonImmutable $day, int $back, array $shifts): void
    {
        $morning = $day->addDay()->setTime(9, 10);

        foreach ($shifts as [$person, $start, $end, $index]) {
            if ($back === 5 && $index === 2) {
                $this->at($morning, fn () => app(RecordManualEntry::class)($context->user, $context->company, $person, $location, Direction::Out, $end, 'Glömde checka ut, gick enligt kassans utloggning.'));
            }

            if ($back === 8 && $index === 1) {
                $double = Entry::query()->where('person_id', $person->id)->where('type', EntryType::CheckIn->value)->whereDate('occurred_at', $day->toDateString())->orderByDesc('id')->firstOrFail();
                $this->at($morning, fn () => app(CorrectEntry::class)($context->user, $context->company, $double, CorrectionKind::Void, null, 'Dubbelregistrering: tryckte två gånger på kiosken.'));
            }

            if ($back === 3 && $index === 1) {
                $late = Entry::query()->where('person_id', $person->id)->where('type', EntryType::CheckOut->value)->whereDate('occurred_at', $day->toDateString())->orderByDesc('id')->firstOrFail();
                $this->at($morning, fn () => app(CorrectEntry::class)($context->user, $context->company, $late, CorrectionKind::Time, $end, 'Checkade ut först vid stängning, gick enligt schemat.'));
            }
        }

        // A control visit exported from the app the week before last.
        if ($back === 9) {
            $this->at($day->setTime(14, 20), fn () => app(OpenControlVisit::class)->inApp($context->user, $context->company, 'pdf'));
        }
    }

    /**
     * The ledger's hours for the last two weeks proposed as time reports;
     * the hourly staff's are approved for November's payroll (the October
     * run paid hours up to the cut-off), the monthly staff's are left to
     * review.
     */
    private function timeReports(DemoContext $context): void
    {
        $this->at($context->end->setTime(23, 59), function () use ($context): void {
            $proposals = app(TimeProposals::class);
            $created = $proposals->propose($context->user, $context->company, $context->end->subDays(13), $context->end);
            $hourly = Employee::query()->whereIn('id', array_map(fn (TimeProposal $proposal): int => $proposal->employee_id, $created))->get()
                ->filter(fn (Employee $employee): bool => $employee->employments()->where('salary_form', 'HOURLY')->exists())
                ->pluck('id')
                ->all();

            foreach ($created as $proposal) {
                if (in_array($proposal->employee_id, $hourly, true)) {
                    $proposals->approve($context->user, $context->company, $proposal);
                }
            }
        });
    }

    /** One event as the kiosk sends it: the next number in its sequence and the next link in its hash chain. */
    private function kiosk(Device $device, Person $person, Direction $direction, CarbonImmutable $at, CheckInMethod $method): void
    {
        $this->at($at, function () use ($device, $person, $direction, $at, $method): void {
            $device->refresh();
            $sequence = $device->last_sequence + 1;
            $uuid = (string) Str::uuid();
            $event = [
                'uuid' => $uuid,
                'direction' => $direction->value,
                'method' => $method->value,
                'occurred_at' => $at->toIso8601String(),
                'person_id' => $person->id,
                'sequence' => $sequence,
                'previous_client_hash' => $device->last_client_hash,
            ];
            $event['client_hash'] = hash('sha256', ($device->last_client_hash ?? '').RecordKioskEvents::clientCanonical($event, $device->location_id, $person->id, $uuid, $sequence));

            $result = app(RecordKioskEvents::class)($device, [$event], $at->toIso8601String());

            if ($result['accepted'] === []) {
                throw new RuntimeException('The demo kiosk event was not accepted: '.json_encode($result['rejected']));
            }
        });
    }

    /** @return list<Person> the ledger's people who are still employed, in a fixed order */
    private function people(): array
    {
        $ended = Employee::query()->where('status', 'TERMINATED')->pluck('id')->all();

        return array_values(Person::query()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (Person $person): bool => in_array($person->employee_id, $ended, true))
            ->all());
    }

    /** A four-digit code that is the same each time for the same person and never a trivial one. */
    private static function pin(Person $person): string
    {
        for ($try = 0; ; $try++) {
            $pin = sprintf('%04d', PayrollExtras::pick("pin-{$person->id}-{$try}", 1000, 9999));

            if (! Pin::isTrivial($pin)) {
                return $pin;
            }
        }
    }

    /**
     * Runs $work with the clock at $moment, so what it writes carries the
     * time it happened.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function at(CarbonImmutable $moment, Closure $work): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
