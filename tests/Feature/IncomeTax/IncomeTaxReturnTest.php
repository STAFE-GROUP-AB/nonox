<?php

declare(strict_types=1);

use App\Actions\Closing\YearEndClosing;
use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\IncomeTax\Ink2;
use App\Livewire\Accounting\IncomeTaxPage;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556000-0001', 'postal_code' => '123 45', 'city' => 'Skattstad', 'legal_name' => 'Testbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->returns = app(IncomeTaxReturns::class);

    // Sales 100 000,40, other external costs 20 000, tax 16 480.
    Bookkeeping::post($this->owner, $this->company, 10_000_040, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
    app(YearEndClosing::class)->bookTax($this->owner, $this->year, '16 480');
});

it('fills INK2R from the books in whole kronor', function () {
    $ink2 = Ink2::for($this->company, $this->year, null);
    $fields = $ink2->ink2r();

    expect($ink2->period())->toBe('2026P4')
        ->and($ink2->identity())->toBe('165560000001')
        ->and($fields[7011])->toBe('20260101')
        ->and($fields[7410])->toBe(100_000)
        ->and($fields[7513])->toBe(20_000)
        ->and($fields[7528])->toBe(16_480)
        ->and($fields[7450])->toBe(63_520)
        ->and($fields[7281])->toBe(80_000)
        ->and($fields[7368])->toBe(16_480)
        ->and($fields[7302])->toBe(63_520)
        ->and($fields)->not->toHaveKey(7550);
});

it('adds the tax back and applies the adjustments in INK2S and INK2', function () {
    $this->returns->save($this->owner, $this->year, ['7653' => '1 500', '7754' => '500'], true, false);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2s())->toMatchArray([7650 => 63_520, 7651 => 16_480, 7653 => 1_500, 7754 => 500, 7670 => 81_000, 8040 => 'X', 8045 => 'X'])
        ->and($ink2->ink2())->toMatchArray([7104 => 81_000])
        ->and(fn () => $this->returns->save($this->owner, $this->year, ['7653' => '-5'], null, null))->toThrow(ValidationException::class);
});

it('exports the SRU files as a ZIP for a checked period only', function () {
    $zipContent = $this->returns->sruZip($this->owner, $this->year);
    $path = tempnam(sys_get_temp_dir(), 'srutest');
    file_put_contents($path, $zipContent);
    $zip = new ZipArchive;
    $zip->open($path);
    $blanketter = (string) $zip->getFromName('BLANKETTER.SRU');
    $info = (string) $zip->getFromName('INFO.SRU');
    $zip->close();
    unlink($path);

    expect($info)->toContain('#ORGNR 165560000001')
        ->and($info)->toContain('#POSTNR 12345')
        ->and($blanketter)->toContain('#BLANKETT INK2-2026P4')
        ->and($blanketter)->toContain('#BLANKETT INK2R-2026P4')
        ->and($blanketter)->toContain('#UPPGIFT 7410 100000')
        ->and($blanketter)->toEndWith("#FIL_SLUT\n");

    $later = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    expect(fn () => $this->returns->sruZip($this->owner, $later))->toThrow(ValidationException::class);
});

it('is on its own page for those who close the year', function () {
    Livewire::test(IncomeTaxPage::class)
        ->assertSet('year', $this->year->id)
        ->assertSee('Överskott av näringsverksamhet')
        ->set('adjustments.7653', '1000')
        ->call('save')
        ->assertDispatched('toast');

    $this->get(route('accounting.incomeTax.sru', $this->year))->assertOk()->assertHeader('Content-Type', 'application/zip');

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    $this->get(route('accounting.incomeTax'))->assertForbidden();
});
