<?php

declare(strict_types=1);

use App\Actions\Company\AccountingFirmLink;
use App\Actions\Company\UpdateAccountingFirmSettings;
use App\Actions\Company\UpdateCompanyBranding;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Mail\EmployeePortalInvitationMail;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Min sida and the client's documents under an accounting firm's brand
 * (ADR-030): employees see the firm; payslips stay the employer's.
 */

beforeEach(function () {
    Storage::fake(Brand::DISK);
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    $this->firmOwner = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Byrå AB']))->create();
    $this->firm = $this->firmOwner->currentCompany();
    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm, true, true);
    app(UpdateCompanyBranding::class)($this->firmOwner, $this->firm->fresh(), ['brand_color' => '#0B5D4B'], UploadedFile::fake()->image('byra.png', 240, 80));

    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['name' => 'Kund AB', 'brand_color' => '#7A1F2B'])->save();
    Payroll::setUp($this->company, $this->owner);
    $consultant = Bookkeeping::member($this->company, 'accountant');
    $this->firm->users()->attach($consultant, ['role' => 'admin']);
    app(AccountingFirmLink::class)->link($this->owner, $this->company, $this->firm->id);

    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Payroll::employee($this->owner, $this->company, ['user_id' => $this->worker->id]);
});

it('shows employees Min sida in the firm\'s logo and colours', function () {
    $this->actingAs($this->worker)->get(route('payroll.portal.home'))->assertOk()
        ->assertSee('Byrå AB · för Kund AB')
        ->assertSee(route('teams.firm-logo', $this->company->id), false)
        ->assertSee('--accent-500: 11 93 75;', false);

    $this->actingAs($this->worker)->get(route('teams.firm-logo', $this->company->id))->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('keeps the employer\'s own brand on the payslip', function () {
    $runs = app(PayrollRuns::class);
    $run = $runs->create($this->owner, $this->company, '2026-04');
    $runs->calculate($this->owner, $run);

    $html = PayslipPdf::html(Payslip::query()->sole());

    expect($html)->toContain('#7A1F2B')->not->toContain('#0B5D4B')->not->toContain('Byrå AB');
});

it('sends the Min sida invitation in the firm\'s brand', function () {
    Mail::fake();
    $bo = Payroll::employee($this->owner, $this->company, ['first_name' => 'Bo', 'personal_number' => '199001011239', 'email' => 'bo@example.test']);

    app(InviteEmployeeToPortal::class)($this->owner, $this->company, $bo);

    $invitation = TeamInvitation::query()->where('email', 'bo@example.test')->sole();
    expect((new EmployeePortalInvitationMail($invitation, 'Bo'))->render())->toContain('Byrå AB · för Kund AB');
});
