<?php

declare(strict_types=1);

/*
 * INK2 1.4/1.5 (fields 7132/7133), the basis for särskild löneskatt on
 * pension costs, and INK2S 4.21 (field 8022), the pension costs within 3.8.
 * Field codes from SKV's field tables INK2_SKV2002-34-01-26-04 and
 * INK2S_SKV2002-34-01-26-04 (2026P4): 7132 and 7133 may not both be filled
 * in. Lag (1991:687) om särskild löneskatt på pensionskostnader 1–2 §§:
 * 24,26 % on the basis, computed on accrual (bokföringsmässiga grunder).
 * The proposal is the booked net of BAS 74xx; every figure below is computed
 * by hand (BS-AUD-BOK-14, #87).
 */

use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Domain\IncomeTax\Ink2;
use App\Livewire\Accounting\IncomeTaxPage;
use App\Models\Account;
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

    foreach ([7411, 7412, 7533, 2514] as $number) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
    }

    // Sales 100 000; pension premiums 10 000 (7412) + 2 000 (7411); SLP 2 911,20 on 7533.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 1_000_000, 7412, 1930, '2026-06-01');
    Bookkeeping::post($this->owner, $this->company, 200_000, 7411, 1930, '2026-06-01');
    Bookkeeping::post($this->owner, $this->company, 291_120, 7533, 2514, '2026-12-31');
});

it('proposes 1.4 and 4.21 from the booked pension costs, without the särskild löneskatt itself', function () {
    $ink2 = Ink2::for($this->company, $this->year, null);

    // 74xx: 10 000 + 2 000 = 12 000. 7533 (SLP) is not a pension cost in 74xx.
    // 3.8 personalkostnader: 12 000 + 2 911,20 = 14 911.
    // Result 100 000 - 14 911,20 = 85 088,80 → 85 089; 1.4 does not change it.
    expect($ink2->ink2())->toMatchArray([7132 => 12_000, 7104 => 85_089])
        ->and($ink2->ink2())->not->toHaveKey(7133)
        ->and($ink2->ink2s())->toMatchArray([8022 => 12_000, 7650 => 85_089, 7670 => 85_089])
        ->and($ink2->ink2r())->toMatchArray([7514 => 14_911])
        ->and($ink2->warnings())->toBe([]);
});

it('lets a person adjust the basis and the pension costs', function () {
    // E.g. 2 000 of the premiums is a gruppsjukförsäkring outside the basis (lag 1991:687 2 § andra stycket).
    $this->returns->save($this->owner, $this->year, ['7132' => '10 000', '8022' => '12 000'], null, null);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2())->toMatchArray([7132 => 10_000])
        ->and($ink2->ink2s())->toMatchArray([8022 => 12_000])
        ->and($ink2->taxableKronor())->toBe(85_089);
});

it('takes a negative basis in 1.5 instead of 1.4, and never both', function () {
    $this->returns->save($this->owner, $this->year, ['7133' => '3 000'], null, null);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2())->toMatchArray([7133 => 3_000])
        ->and($ink2->ink2())->not->toHaveKey(7132)
        ->and(fn () => $this->returns->save($this->owner, $this->year, ['7132' => '1 000', '7133' => '3 000'], null, null))
        ->toThrow(ValidationException::class);
});

it('warns when pension costs are reported in 4.21 but the basis in 1.4 is empty', function () {
    $this->returns->save($this->owner, $this->year, ['7132' => '0'], null, null);
    $ink2 = Ink2::for($this->company, $this->year, IncomeTaxReturn::query()->sole());

    expect($ink2->ink2())->not->toHaveKeys([7132, 7133])
        ->and($ink2->ink2s())->toMatchArray([8022 => 12_000])
        ->and($ink2->warnings())->toHaveCount(1);
});

it('carries 7132 and 8022 to the SRU file', function () {
    $zipContent = $this->returns->sruZip($this->owner, $this->year);
    $path = (string) tempnam(sys_get_temp_dir(), 'srupen');
    file_put_contents($path, $zipContent);
    $zip = new ZipArchive;
    $zip->open($path);
    $blanketter = (string) $zip->getFromName('BLANKETTER.SRU');
    $zip->close();
    unlink($path);

    expect($blanketter)->toContain("#UPPGIFT 7132 12000\n")
        ->and($blanketter)->toContain("#UPPGIFT 8022 12000\n");
});

it('shows the fields and the proposal on the page', function () {
    Livewire::test(IncomeTaxPage::class)
        ->assertSee('Underlag för särskild löneskatt på pensionskostnader (7132)')
        ->assertSee('Pensionskostnader (som ingår i p. 3.8) (8022)')
        ->set('adjustments.7132', '11 000')
        ->call('save')
        ->assertDispatched('toast');

    expect(IncomeTaxReturn::query()->sole()->adjustments)->toBe([7132 => 11_000]);
});
