<?php

declare(strict_types=1);

use App\Actions\IncomeTax\BusinessIncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\Ink4;
use App\Livewire\Accounting\IncomeTaxPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\IncomeTaxReturn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Inkomstdeklaration 4 of a handelsbolag (ADR-053): INK4R, INK4S and one
 * INK4DU per partner from the books and the partners' shares.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => LegalForm::Hb, 'org_number' => '969000-0006', 'legal_name' => 'Ek & Björk HB', 'postal_code' => '111 22', 'city' => 'Stockholm'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->returns = app(BusinessIncomeTaxReturns::class);

    foreach ([2010 => 'Eget kapital delägare 1', 2013 => 'Övriga egna uttag delägare 1', 2019 => 'Årets resultat delägare 1', 2020 => 'Eget kapital delägare 2', 2028 => 'Övriga insättningar delägare 2'] as $number => $name) {
        Account::query()->create(['number' => $number, 'name' => $name, 'type' => AccountType::LiabilityEquity, 'is_active' => true]);
    }

    // Sales 200 000 + VAT, costs 50 000; partner 1 takes out 30 000, partner 2 puts in 10 000.
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 25_000_000, 3001 => -20_000_000, 2611 => -5_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-04-01', [5010 => 5_000_000, 1930 => -5_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-06-01', [2013 => 3_000_000, 1930 => -3_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-07-01', [1930 => 1_000_000, 2028 => -1_000_000]);

    $this->partners = [
        ['position' => '1', 'name' => 'Eva Ek', 'identity' => '19790101-1234', 'legal_person' => false, 'share' => '60'],
        ['position' => '2', 'name' => 'Björk AB', 'identity' => '556677-8899', 'legal_person' => true, 'share' => '40'],
    ];
});

function ink4(object $test): Ink4
{
    return Ink4::for($test->company->fresh(), $test->year, IncomeTaxReturn::query()->where('form', 'INK4')->first(), IncomeTaxPartner::query()->where('fiscal_year_id', $test->year->id)->get());
}

it('fills INK4R with the partners’ equity from the books', function () {
    $fields = ink4($this)->ink4r();

    expect($fields)->toMatchArray([
        7410 => 200_000, 7513 => 50_000, 7450 => 150_000,
        7281 => 180_000, 7371 => 50_000,
        7311 => -20_000, 7312 => 150_000, 7314 => 130_000,
    ])->and($fields)->not->toHaveKey(7310)->not->toHaveKey(7550);
});

it('applies the INK4S adjustments and proposes the pension costs', function () {
    Account::query()->create(['number' => 7410, 'name' => 'Pensionsförsäkringspremier', 'type' => AccountType::Expense, 'is_active' => true]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-08-01', [7410 => 400_000, 1930 => -400_000]);
    $this->returns->saveInk4($this->owner, $this->year, ['7653' => '2 000', '7754' => '500'], true, false);
    $ink4 = ink4($this);

    expect($ink4->ink4s())->toMatchArray([7650 => 146_000, 7653 => 2_000, 7754 => 500, 7670 => 147_500, 8022 => 4_000, 8040 => 'X', 8045 => 'X']);
});

it('saves the partners with their identities encrypted and splits the surplus by share', function () {
    $this->returns->savePartners($this->owner, $this->year, $this->partners);
    $blocks = ink4($this)->ink4du();

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['fields'])->toMatchArray([7011 => '20260101', 7012 => '20261231', 8100 => '197901011234', 8101 => 90_000, 8104 => 30_000])
        ->and(array_keys($blocks[0]['fields']))->toBe([7011, 7012, 8100, 8101, 8104])
        ->and($blocks[1]['fields'])->toMatchArray([8100 => '165566778899', 8101 => 60_000, 8103 => 10_000])
        ->and((string) DB::table('income_tax_partners')->where('position', 1)->value('identity'))->not->toContain('197901011234')
        ->and(json_encode(AuditLog::query()->where('action', 'income_tax.partners_saved')->sole()->payload))->not->toContain('1979')->not->toContain('Eva');
});

it('gives the last partner the rounding remainder so the shares add up', function () {
    $this->returns->savePartners($this->owner, $this->year, [
        ['position' => '1', 'name' => 'A', 'identity' => '19790101-1234', 'share' => '33,33'],
        ['position' => '2', 'name' => 'B', 'identity' => '556677-8899', 'legal_person' => true, 'share' => '33,33'],
        ['position' => '3', 'name' => 'C', 'identity' => '556677-8899', 'legal_person' => true, 'share' => '33,34'],
    ]);
    $shares = collect(ink4($this)->ink4du())->map(fn (array $block): int => $block['fields'][8101]);

    expect($shares->all())->toBe([49_995, 49_995, 50_010])->and($shares->sum())->toBe(150_000);
});

it('refuses a wrong personnummer, a wrong organisationsnummer and a bad share', function (array $partner, string $field) {
    try {
        $this->returns->savePartners($this->owner, $this->year, [$partner]);
        $this->fail('No validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'personnummer without century' => [['position' => '1', 'name' => 'A', 'identity' => '790101-1234', 'share' => '50'], 'partners.0.identity'],
    'personnummer with a wrong check digit' => [['position' => '1', 'name' => 'A', 'identity' => '19790101-1235', 'share' => '50'], 'partners.0.identity'],
    'organisationsnummer' => [['position' => '1', 'name' => 'A', 'identity' => '556677-8890', 'legal_person' => true, 'share' => '50'], 'partners.0.identity'],
    'share above 100' => [['position' => '1', 'name' => 'A', 'identity' => '19790101-1234', 'share' => '101'], 'partners.0.share'],
    'share with three decimals' => [['position' => '1', 'name' => 'A', 'identity' => '19790101-1234', 'share' => '33,333'], 'partners.0.share'],
]);

it('writes INK4R, INK4S and one INK4DU block per partner, only when the shares add up to 100 %', function () {
    $this->returns->savePartners($this->owner, $this->year, [['position' => '1', 'name' => 'Eva Ek', 'identity' => '19790101-1234', 'share' => '60']]);
    expect(fn () => $this->returns->sruZip($this->owner, $this->year))->toThrow(ValidationException::class);

    $this->returns->savePartners($this->owner, $this->year, $this->partners);
    $blanketter = Bookkeeping::unzipSru($this->returns->sruZip($this->owner, $this->year))['BLANKETTER.SRU'];
    $blocks = preg_split('/(?=#BLANKETT )/', $blanketter, -1, PREG_SPLIT_NO_EMPTY);

    expect(substr_count($blanketter, "#BLANKETT INK4R-2026P4\n"))->toBe(1)
        ->and(substr_count($blanketter, "#BLANKETT INK4S-2026P4\n"))->toBe(1)
        ->and(substr_count($blanketter, "#BLANKETT INK4DU-2026P4\n"))->toBe(2)
        ->and($blanketter)->toContain('#IDENTITET 169690000006 ')
        ->and($blocks[2])->toContain("#UPPGIFT 7011 20260101\n")->not->toContain('#UPPGIFT 0 ')
        ->and($blocks[2])->toContain("#UPPGIFT 8100 197901011234\n")->toContain("#UPPGIFT 8101 90000\n")->toContain("#UPPGIFT 8104 30000\n")
        ->and($blocks[3])->toContain("#UPPGIFT 8100 165566778899\n")->toContain("#UPPGIFT 8103 10000\n")
        ->and($blanketter)->toContain("#UPPGIFT 7311 -20000\n")
        ->and($blanketter)->toEndWith("#BLANKETTSLUT\n#FIL_SLUT\n");
});

it('shows the INK4 flow with the partners and the note that partners file N3A or N3B themselves', function () {
    $this->returns->savePartners($this->owner, $this->year, $this->partners);

    Livewire::test(IncomeTaxPage::class)->assertOk()
        ->assertSee('Delägare')
        ->assertSee('NONOX tar inte fram N3A eller N3B.')
        ->assertSee('Första sidan av Inkomstdeklaration 4 kan inte lämnas med filöverföring.')
        ->assertSet('partners.0.identity', '197901011234')
        ->assertSet('partners.1.identity', '5566778899')
        ->call('addPartner')->assertSet('partners.2.position', '3')
        ->call('removePartner', 2)->call('savePartners')->assertDispatched('toast', message: 'Delägarna är sparade.');
});

it('starts a new year from last year’s partners', function () {
    $this->returns->savePartners($this->owner, $this->year, $this->partners);
    $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

    Livewire::withQueryParams(['ar' => $next->id])->test(IncomeTaxPage::class)->assertSet('partners.0.name', 'Eva Ek')->assertSet('partners.1.share', '40');
});
