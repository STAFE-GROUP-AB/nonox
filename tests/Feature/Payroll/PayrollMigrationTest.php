<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\MovePayrollData;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Livewire\PayrollMigrationPage;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayrollMigration;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use App\Support\Files\InlineFile;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

function personalNumber(string $nine): string
{
    return '19'.$nine.Luhn::checkDigit($nine);
}

function employeesCsv(string $bad = ''): string
{
    return "anstallningsnummer;fornamn;efternamn;personnummer;anstalld_fran;anstallningsform;loneform;lon;skattetabell;kolumn;clearingnummer;kontonummer\n"
        .'17;Anna;Kock;'.personalNumber('900615123').";2024-02-01;tillsvidare;manadslon;32 000,00;33;1;8327;9934567890\n"
        .'23;Bertil;Disk;'.personalNumber('850101123').";2025-08-15;timanstalld;timlon;165,50;33;1;;\n"
        .$bad;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(AddPayrollAccounts::class)($this->owner, $this->company);
    $this->move = app(MovePayrollData::class);
    $this->move->start($this->owner, $this->company, 2026);
});

it('reads the employees only when every row is right', function () {
    expect(fn () => $this->move->employees($this->owner, $this->company, employeesCsv("31;Cecilia;Kassa;19000000-0000;2026-01-01;tillsvidare;manadslon;30 000;33;1\n")))
        ->toThrow(ValidationException::class, 'Rad 4');

    expect(Employee::query()->count())->toBe(0)
        ->and($this->move->employees($this->owner, $this->company, employeesCsv()))->toBe(2)
        ->and($this->move->employees($this->owner, $this->company, employeesCsv()))->toBe(2)
        ->and(Employee::query()->count())->toBe(2)
        ->and(Employment::query()->where('salary_form', 'HOURLY')->sole()->hourly_rate_ore->ore)->toBe(16_550)
        ->and(fn () => $this->move->employees($this->owner, $this->company, "namn;lon\nAnna;1"))->toThrow(ValidationException::class, 'saknas på första raden');
});

it('carries year-to-date totals and vacation balances over', function () {
    $this->move->employees($this->owner, $this->company, employeesCsv());
    $this->move->yearToDate($this->owner, $this->company, "anstallningsnummer;bruttolon;skatt;avgiftsunderlag;arbetsgivaravgifter;engangsbelopp\n17;128 000,00;29 900,00;128 000,00;40 217,60;5 000,00\n23;12 000;2 400;12 000;3 770,40;\n");
    $this->move->vacation($this->owner, $this->company, "anstallningsnummer;semesterar_start;intjanade_dagar;uttagna_dagar;sparade_dagar\n17;2026-04-01;25;3;2\n");
    $anna = Employee::query()->where('first_name', 'Anna')->sole();
    $values = Accumulator::query()->where('employee_id', $anna->id)->where('year', 2026)->pluck('value', 'key');
    $balance = app(VacationLedger::class)->overview(collect([$anna]), collect([$anna->id => Employment::query()->where('employee_id', $anna->id)->sole()]), CarbonImmutable::today(),
        app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company), RuleSet::for(CarbonImmutable::today()), CarbonImmutable::today())[$anna->id];

    expect($values['gross_ytd'])->toBe(12_800_000)
        ->and($values['tax_ytd'])->toBe(2_990_000)
        ->and($values['contributions_ytd'])->toBe(4_021_760)
        ->and($values['one_off_ytd'])->toBe(500_000)
        ->and($balance['earned'])->toBe(25)
        ->and($balance['taken'])->toBe(3)
        ->and($balance['saved'])->toBe(2)
        ->and($balance['left'])->toBe(24)
        ->and(fn () => $this->move->vacation($this->owner, $this->company, "anstallningsnummer;semesterar_start;intjanade_dagar;uttagna_dagar;sparade_dagar\n17;2026-01-01;25;3;2\n"))->toThrow(ValidationException::class, 'månad 4');
});

it('keeps the old payslips for the archive and the employee', function () {
    $this->move->employees($this->owner, $this->company, employeesCsv());

    expect($this->move->payslips($this->owner, $this->company, [UploadedFile::fake()->create('17_2026-03.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('23_2026-03.pdf', 20, 'application/pdf')]))->toBe(2)
        ->and(fn () => $this->move->payslips($this->owner, $this->company, [UploadedFile::fake()->create('lonebesked.pdf', 20, 'application/pdf')]))->toThrow(ValidationException::class, 'anställningsnummer_ÅÅÅÅ-MM.pdf');

    $old = ImportedPayslip::query()->where('period', '2026-03')->firstOrFail();

    $this->get(route('payroll.imported-payslip', $old))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Security-Policy', InlineFile::CONTENT_SECURITY_POLICY);
});

it('blocks payroll runs until the totals agree with the bookkeeping', function () {
    $runs = app(PayrollRuns::class);
    $this->move->employees($this->owner, $this->company, employeesCsv());
    $run = $runs->create($this->owner, $this->company, '2026-05');

    expect(fn () => $runs->calculate($this->owner, $run))->toThrow(ValidationException::class, 'Flytten av lönedata');

    $this->move->yearToDate($this->owner, $this->company, "anstallningsnummer;bruttolon;skatt;avgiftsunderlag;arbetsgivaravgifter\n17;128 000;29 900;128 000;40 217,60\n");

    expect($this->move->reconciliation($this->company)['agrees'])->toBeFalse()
        ->and(fn () => $this->move->complete($this->owner, $this->company))->toThrow(ValidationException::class, 'stämmer inte med bokföringen');

    Bookkeeping::post($this->owner, $this->company, 2_990_000, 7010, 2710, '2026-04-25', 'A');
    Bookkeeping::post($this->owner, $this->company, 4_021_760, 7510, 2731, '2026-04-25', 'A');

    expect($this->move->reconciliation($this->company)['agrees'])->toBeTrue();

    $this->move->complete($this->owner, $this->company);

    expect(PayrollMigration::query()->sole()->status)->toBe('COMPLETE')
        ->and($runs->calculate($this->owner, $run->fresh())->status)->toBe('CALCULATED')
        ->and(fn () => $this->move->yearToDate($this->owner, $this->company, "anstallningsnummer;bruttolon;skatt;avgiftsunderlag;arbetsgivaravgifter\n17;1;1;1;1\n"))->toThrow(ValidationException::class, 'Starta flytten');
});

it('takes the files on the migration page', function () {
    Livewire::test(PayrollMigrationPage::class)
        ->assertSee('Flytten pågår för 2026')
        ->set('employeesFile', UploadedFile::fake()->createWithContent('anstallda.csv', employeesCsv()))
        ->call('importEmployees')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('2 inlästa');

    $this->get(route('payroll.migration.template', 'anstallda'))->assertOk()->assertSee('anstallningsnummer;fornamn');
    $this->get(route('payroll.migration'))->assertOk();
});
