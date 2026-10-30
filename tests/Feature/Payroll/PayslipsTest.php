<?php

declare(strict_types=1);

use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Livewire\MyPayslips;
use App\Modules\Payroll\Mail\EmployeePortalInvitationMail;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id]);
    $this->runs = app(PayrollRuns::class);
    $this->run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $this->run);
    $this->payslip = Payslip::query()->sole();
});

it('gives payroll staff the payslip as a PDF', function () {
    $this->actingAs($this->owner)->get(route('payroll.payslips.pdf', $this->payslip))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('shows the employee their own payslip only once the run is approved', function () {
    $this->actingAs($this->worker)->get(route('payroll.portal.payslip', $this->payslip))->assertNotFound();

    $this->runs->approve($this->admin, $this->run->fresh());

    $this->actingAs($this->worker)->get(route('payroll.portal.payslip', $this->payslip))->assertOk();
    $this->actingAs($this->colleague)->get(route('payroll.portal.payslip', $this->payslip))->assertNotFound();
});

it('answers 404 for another company\'s payslip', function () {
    [$stranger] = Bookkeeping::company();

    $this->actingAs($stranger)->get(route('payroll.payslips.pdf', $this->payslip))->assertNotFound();
});

it('lists only the employee\'s own payslips from approved runs', function () {
    CurrentCompany::set($this->company);
    $this->actingAs($this->worker);

    Livewire::test(MyPayslips::class)->assertSee('Inga lönebesked');

    $this->runs->approve($this->admin, $this->run->fresh());

    Livewire::test(MyPayslips::class)->assertSee('Lön april 2026');

    $this->actingAs($this->colleague);
    Livewire::test(MyPayslips::class)->assertDontSee('april 2026');
});

it('links a login to one employee only, and only a member of the company', function () {
    [$stranger] = Bookkeeping::company();

    expect(fn () => Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id, 'personal_number' => '199001011239']))
        ->toThrow(ValidationException::class, 'redan till en annan anställd')
        ->and(fn () => Payroll::employee($this->owner, $this->company, ['user_id' => $stranger->id, 'personal_number' => '199001011239']))
        ->toThrow(ValidationException::class, 'inte medlem');
});

it('keeps employees in the portal, away from the rest of the system', function () {
    $this->actingAs($this->worker);

    foreach (['dashboard', 'payroll.runs.index', 'invoicing.invoices.index', 'teams.create'] as $name) {
        $this->get(route($name))->assertRedirect(route('payroll.portal.home'));
    }

    $this->get(route('payroll.portal.home'))
        ->assertOk()
        ->assertSee('Min sida')
        ->assertDontSee('Verifikationer')
        ->assertDontSee('Fakturor');

    $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
});

it('tells an employee without a linked login what to do', function () {
    $this->actingAs($this->colleague)->get(route('payroll.portal.home'))
        ->assertOk()
        ->assertSee('Ditt konto är inte kopplat till någon anställd');
});

it('invites an employee by email with login instructions, and links the login on acceptance', function () {
    Mail::fake();
    $bo = Payroll::employee($this->owner, $this->company, ['first_name' => 'Bo', 'personal_number' => '199001011239', 'email' => 'bo@example.test']);
    $this->actingAs($this->owner);

    Livewire::test(Employees::class)
        ->call('invite', $bo->id)
        ->assertDispatched('toast')
        ->assertSee('Skicka inbjudan igen');

    $invitation = TeamInvitation::query()->where('email', 'bo@example.test')->sole();
    Mail::assertSent(EmployeePortalInvitationMail::class, fn (EmployeePortalInvitationMail $mail): bool => $mail->hasTo('bo@example.test'));
    expect($invitation->role)->toBe('employee')
        ->and($bo->fresh()->portal_invited_at)->not->toBeNull();

    $html = (new EmployeePortalInvitationMail($invitation, 'Bo'))->render();
    expect($html)->toContain('engångskod')->toContain('/anstalld')->not->toMatch('/\d kr\b/');

    $bosLogin = User::factory()->create(['email' => 'bo@example.test']);
    $this->actingAs($bosLogin)->get(URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]))->assertRedirect();

    expect(CurrentCompany::run($this->company, fn () => $bo->fresh()->user_id))->toBe($bosLogin->id)
        ->and($bosLogin->fresh()->teamRole($this->company->fresh())?->key)->toBe('employee');

    $this->actingAs($bosLogin->fresh())->get(route('dashboard'))->assertRedirect(route('payroll.portal.home'));
});

it('links an existing member at once and refuses an employee without email', function () {
    Mail::fake();
    $bo = Payroll::employee($this->owner, $this->company, ['first_name' => 'Bo', 'personal_number' => '199001011239', 'email' => $this->colleague->email]);
    $cy = Payroll::employee($this->owner, $this->company, ['first_name' => 'Cy', 'personal_number' => '198502021234']);
    $invite = app(InviteEmployeeToPortal::class);

    expect($invite($this->owner, $this->company, $bo))->toBeFalse()
        ->and(CurrentCompany::run($this->company, fn () => $bo->fresh()->user_id))->toBe($this->colleague->id)
        ->and(fn () => $invite($this->owner, $this->company, $cy))->toThrow(ValidationException::class, 'e-postadressen')
        ->and(fn () => $invite($this->worker, $this->company, $cy))->toThrow(AuthorizationException::class);

    Mail::assertNothingSent();
});

it('lays out the payslip with every column, the employer\'s cost and a preview mark before approval', function () {
    $html = PayslipPdf::html($this->payslip);

    expect($html)
        ->toContain('April 2026')
        ->toContain('Löneart')->toContain('Antal')->toContain('À-pris')->toContain('Belopp')
        ->toContain('Summa lön och ersättningar')
        ->toContain('Tabell 33, kolumn 1')
        ->toContain('19900615-XXXX')
        ->toContain('Arbetsgivaravgifter 31,42 %')
        ->toContain('UTKAST')
        ->toContain('Förhandsgranskning');

    $this->runs->approve($this->admin, $this->run->fresh());

    expect(PayslipPdf::html($this->payslip->fresh()))->not->toContain('UTKAST');
});
