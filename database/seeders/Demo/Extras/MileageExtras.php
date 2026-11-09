<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Mileage\CloseVehicleYear;
use App\Actions\Mileage\LogTrip;
use App\Actions\Mileage\SaveMileageRoute;
use App\Actions\Mileage\SaveVehicle;
use App\Actions\Mileage\SubmitMileageClaim;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

/**
 * Körjournal (ADR-072) for the manual's mileage pages, typed by hand as in
 * the app; nothing reads anyone's position.
 *
 * - The consultancy: the company's car with this year's odometer reading
 *   and Anna's trips in it, business and private.
 * - The joinery: Adam Kvist, the carpenter, signed in to Min sida with his
 *   own (made-up) login. His own car's trips: September approved, October
 *   sent in and waiting for approval, and this month's trips so far.
 *
 * Never in a visitor's demo.
 */
final class MileageExtras extends DemoExtra
{
    public const string EMPLOYEE = 'Adam';

    public const string EMAIL = 'adam.kvist@example.test';

    public function __construct(private readonly PayrollExtras $payroll) {}

    public function industries(): array
    {
        return ['bokforing-for-konsulter', 'bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        config(['mail.default' => 'log']);
        $previous = Carbon::getTestNow();

        try {
            $this->atomically($c, 'Körjournal', fn () => CurrentCompany::run($c->company, function () use ($c): void {
                $c->company->forceFill(['mileage_log_enabled' => true])->save();

                $c->slug === 'bokforing-for-konsulter' ? $this->consultancy($c) : $this->joinery($c);
            }));
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    private function consultancy(DemoContext $c): void
    {
        $car = app(SaveVehicle::class)($c->user, $c->company, ['kind' => 'COMPANY_CAR', 'label' => 'Volvo XC40', 'registration_number' => 'EXE 123', 'fuel' => 'ELECTRIC']);
        app(CloseVehicleYear::class)->save($c->user, $c->company, $car, $c->today->year, ['start_odometer' => 18_450]);

        $trips = [
            [33, 'BUSINESS', '46', 'Kontoret', 'Kunden Exempel AB, Solna', 'Workshop om budget'],
            [29, 'BUSINESS', '128', 'Kontoret', 'Mjukvaruhuset Exempel AB, Uppsala', 'Månadsmöte'],
            [27, 'PRIVATE', '35', null, null, null],
            [20, 'BUSINESS', '46', 'Kontoret', 'Kunden Exempel AB, Solna', 'Uppföljning av bokslutsplanen'],
            [13, 'BUSINESS', '212', 'Kontoret', 'Fastighetsbolaget Exempel AB, Västerås', 'Första mötet om ramavtal'],
            [8, 'BUSINESS', '128', 'Kontoret', 'Mjukvaruhuset Exempel AB, Uppsala', 'Månadsmöte'],
            [4, 'PRIVATE', '22', null, null, null],
            [2, 'BUSINESS', '46', 'Kontoret', 'Kunden Exempel AB, Solna', 'Workshop'],
        ];
        $odometer = 18_450 + 9_870;

        foreach ($trips as [$daysAgo, $kind, $km, $from, $to, $purpose]) {
            $odometer += (int) $km;
            $this->trip($c->user, $c, $car, $c->today->subDays($daysAgo), $kind, $km, $from, $to, $purpose, $odometer);
        }
    }

    private function joinery(DemoContext $c): void
    {
        $adam = $this->adam($c);
        app(SaveMileageRoute::class)($adam, $c->company, 'Verkstaden – Byggvaruhuset', '14');
        $car = app(SaveVehicle::class)($adam, $c->company, ['kind' => 'PRIVATE_CAR', 'label' => 'Egen Toyota Corolla', 'registration_number' => 'ABC 987']);

        $sites = [
            ['Verkstaden, Örebro', 'Ekbacksvägen 3, Örebro', 'Badrumsrenovering, material', '18'],
            ['Verkstaden, Örebro', 'Byggvaruhuset, Örebro', 'Hämta virke', '14'],
            ['Verkstaden, Örebro', 'Exempelgatan 18, Kumla', 'Altan, mätning', '42'],
            ['Verkstaden, Örebro', 'Byggvägen 4, Örebro', 'Stomkomplettering', '11'],
        ];
        $months = [
            $c->today->subMonthsNoOverflow(2)->startOfMonth(),
            $c->today->subMonthNoOverflow()->startOfMonth(),
            $c->today->startOfMonth(),
        ];

        foreach ($months as $index => $month) {
            for ($day = $month->addDays(1); $day->month === $month->month && $day < $c->today; $day = $day->addDays(3)) {
                if (! $day->isWeekday()) {
                    continue;
                }

                [$from, $to, $purpose, $km] = $sites[($day->day + $index) % count($sites)];
                $this->trip($adam, $c, $car, $day, 'BUSINESS', $km, $from, $to, $purpose, null, roundTrip: $day->day % 2 === 0);
            }
        }

        // September: sent in and approved, paid with the next salary.
        $this->at($months[1]->addDays(2), 9, function () use ($c, $adam, $months): void {
            foreach (app(SubmitMileageClaim::class)($adam, $c->company, $months[0]->format('Y-m')) as $claim) {
                app(ExpenseClaims::class)->approveMileage($c->user, $claim);
            }
        });

        // October: sent in a few days into November, waiting for approval under Lön → Ansökningar.
        $this->at($months[2]->addDays(3), 18, fn () => app(SubmitMileageClaim::class)($adam, $c->company, $months[1]->format('Y-m')));
    }

    /** Adam on Min sida: invited from his employee card and signed in with the address, as the app does it. */
    private function adam(DemoContext $c): User
    {
        $employee = $this->payroll->employeeOrFail($c, self::EMPLOYEE);

        if ($employee->email !== self::EMAIL) {
            $employee = $this->payroll->saveEmployee($c, $employee, ['email' => self::EMAIL]);
        }

        app(InviteEmployeeToPortal::class)($c->user, $c->company, $employee);
        $adam = User::query()->firstOrCreate(['email' => self::EMAIL], ['name' => "{$employee->first_name} {$employee->last_name}", 'email_verified_at' => now()]);
        $invitation = TeamInvitation::query()->where('team_id', $c->company->id)->where('email', self::EMAIL)->firstOrFail();

        app(AddsTeamMembers::class)->add($c->user, $c->company, $invitation->email, $invitation->role);
        $invitation->delete();
        $c->company->unsetRelation('users');
        CurrentCompany::run($c->company, fn (): bool => $adam->switchCompany($c->company));

        return $adam->fresh() ?? $adam;
    }

    private function trip(User $user, DemoContext $c, Vehicle $car, CarbonImmutable $day, string $kind, string $km, ?string $from, ?string $to, ?string $purpose, ?int $odometerEnd, bool $roundTrip = false): void
    {
        try {
            $this->at($day, 17, fn () => app(LogTrip::class)($user, $c->company, array_filter([
                'vehicle_id' => $car->id,
                'kind' => $kind,
                'trip_date' => $day->toDateString(),
                'distance' => $odometerEnd === null ? $km : null,
                'odometer_start' => $odometerEnd === null ? null : $odometerEnd - (int) $km,
                'odometer_end' => $odometerEnd,
                'from_place' => $from,
                'to_place' => $to,
                'purpose' => $purpose,
                'round_trip' => $roundTrip,
            ], fn (mixed $value): bool => $value !== null)));
        } catch (ValidationException $exception) {
            $c->warn('Körjournal: '.collect($exception->errors())->flatten()->implode(' '));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(CarbonImmutable $day, int $hour, callable $step): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($day->setTime($hour, 0));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
