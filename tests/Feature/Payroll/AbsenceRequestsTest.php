<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AbsenceRequests;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\MyAbsence;
use App\Modules\Payroll\Mail\AbsenceDecidedMail;
use App\Modules\Payroll\Mail\AbsenceRequestedMail;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id, 'weekly_hours' => '37,5']);
    $this->requests = app(AbsenceRequests::class);
    $this->runs = app(PayrollRuns::class);
});

it('lets an employee apply for vacation on Min sida and tells payroll staff', function () {
    $this->actingAs($this->worker);

    Livewire::test(MyAbsence::class)
        ->call('open', 'VACATION')
        ->set('absence.date_from', '2026-06-15')
        ->set('absence.date_to', '2026-06-19')
        ->assertSee('4 semesterdagar')
        ->call('send')
        ->assertDispatched('toast')
        ->assertSee('Väntar på svar');

    $absence = Absence::query()->sole();
    expect($absence->status)->toBe('REQUESTED')
        ->and($absence->requested_by)->toBe($this->worker->id)
        ->and($absence->hours)->toBeNull();
    Mail::assertSent(AbsenceRequestedMail::class, fn ($mail) => $mail->hasTo($this->owner->email));
});

it('counts the absence in the run only once approved, with the employment\'s hours per day', function () {
    $absence = $this->requests->request($this->worker, $this->company, ['type' => 'VACATION', 'date_from' => '2026-06-15', 'date_to' => '2026-06-19']);
    $run = $this->runs->create($this->owner, $this->company, '2026-06');
    $this->runs->calculate($this->owner, $run);

    expect($run->fresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and(PayslipLine::query()->where('description', 'Semestertillägg')->exists())->toBeFalse();

    $this->actingAs($this->owner);
    Livewire::test(AbsenceRequestList::class)
        ->assertSee('Anna Kock')
        ->assertSet("hours.{$absence->id}", '7.5')
        ->call('approve', $absence->id)
        ->assertDispatched('toast');

    expect($absence->fresh()->status)->toBe('APPROVED')
        ->and((string) $absence->fresh()->hours)->toStartWith('7.5')
        ->and($run->fresh()->status)->toBe(PayrollRun::DRAFT);
    Mail::assertSent(AbsenceDecidedMail::class, fn ($mail) => $mail->hasTo($this->worker->email) && $mail->approved);

    $this->runs->calculate($this->owner, $run->fresh());
    expect(PayslipLine::query()->where('description', 'Semestertillägg')->exists())->toBeTrue();
});

it('refuses with a reason the employee sees', function () {
    $absence = $this->requests->request($this->worker, $this->company, ['type' => 'VACATION', 'date_from' => '2026-07-06', 'date_to' => '2026-07-10']);

    expect(fn () => $this->requests->reject($this->owner, $absence, ' '))->toThrow(ValidationException::class);

    $this->requests->reject($this->owner, $absence, 'Fullbokat den veckan, ta gärna veckan efter.');
    Mail::assertSent(AbsenceDecidedMail::class, fn ($mail) => ! $mail->approved && $mail->reason === 'Fullbokat den veckan, ta gärna veckan efter.');

    $this->actingAs($this->worker)->get(route('payroll.portal.home'))
        ->assertSee('Inte godkänd')
        ->assertSee('Fullbokat den veckan');
});

it('keeps requests to their owner and to payroll staff', function () {
    $absence = $this->requests->request($this->worker, $this->company, ['type' => 'SICK', 'date_from' => '2026-06-02', 'date_to' => '2026-06-03']);

    expect(fn () => $this->requests->cancel($this->colleague, $absence))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->requests->approve($this->worker, $absence))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->requests->request($this->worker, $this->company, ['type' => 'SICK', 'date_from' => '2026-07-01', 'date_to' => '2026-07-02']))->toThrow(ValidationException::class, 'när det händer');

    $this->requests->cancel($this->worker, $absence);
    expect(Absence::query()->count())->toBe(0);
});

it('does not put absence into a month whose run is approved, nor let anyone approve their own', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    $run = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->calculate($this->owner, $run);
    $this->runs->approve($admin, $run->fresh());
    $late = $this->requests->request($this->worker, $this->company, ['type' => 'SICK', 'date_from' => '2026-05-28', 'date_to' => '2026-06-01']);

    expect(fn () => $this->requests->approve($this->owner, $late))->toThrow(ValidationException::class, 'redan attesterad');

    Payroll::employee($this->owner, $this->company, ['first_name' => 'Ägaren', 'personal_number' => '199001011239', 'user_id' => $this->owner->id]);
    $own = $this->requests->request($this->owner, $this->company, ['type' => 'VACATION', 'date_from' => '2026-07-06', 'date_to' => '2026-07-07']);

    expect(fn () => $this->requests->approve($this->owner, $own))->toThrow(ValidationException::class, 'egen ansökan');
});

it('shows the number of open requests in the payroll menu', function () {
    $this->requests->request($this->worker, $this->company, ['type' => 'VAB', 'date_from' => '2026-06-02', 'date_to' => '2026-06-02']);

    $this->actingAs($this->owner)->get(route('payroll.requests'))->assertOk()->assertSee('Ansökningar (1)');
});
