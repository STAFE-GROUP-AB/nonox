<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Support\Paxml\PayrollFileCsv;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * The CSV template for a month's payroll lines from another payroll or
 * scheduling program (L1): the documented columns in order, a file the
 * reader accepts once filled in, and a link next to the file field on the
 * payroll run's page.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('hands out the payroll line template with the documented columns, which the reader accepts once filled in', function () {
    $response = $this->get('/lon/flytt/mall/loneunderlag')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="loneunderlag.csv"');
    $template = (string) $response->getContent();

    expect($template)->toBe("\u{FEFF}anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp\r\n");

    $file = (new PayrollFileCsv)->read($template."17;;OB1;OB kväll;2026-03-03;2026-03-03;4,5;;\r\n");

    expect($file->lines)->toHaveCount(1)
        ->and($file->lines[0]->employeeRef)->toBe('17')
        ->and($file->lines[0]->code)->toBe('OB1')
        ->and($file->lines[0]->quantity)->toBe('4.5');
});

it('links the template next to the file field on an open payroll run', function () {
    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-03');

    Livewire::test(PayrollRunPage::class, ['run' => $run])
        ->assertSee('Hämta mall för CSV')
        ->assertSee(route('payroll.migration.template', 'loneunderlag'), false);
});
