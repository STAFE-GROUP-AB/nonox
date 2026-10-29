<?php

declare(strict_types=1);

use App\Livewire\Accounting\VoucherActions;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Accounting\VoucherList;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
});

function flashed(): array
{
    return array_column((array) session(Toast::SESSION_KEY, []), 'message');
}

function rowsInput(array ...$rows): array
{
    return array_map(fn (array $row) => [
        'account' => $row[0], 'debit' => $row[1] ?? '', 'credit' => $row[2] ?? '', 'description' => $row[3] ?? '',
    ], $rows);
}

it('shows the voucher list on /verifikationer with a helpful empty state', function () {
    $this->get(route('accounting.vouchers.index'))
        ->assertOk()
        ->assertSee('Verifikationer')
        ->assertSee('Inga verifikationer ännu')
        ->assertSee('Bokföringen har inte kontrollerats ännu.')
        ->assertSee('Ny verifikation');

    expect(route('accounting.vouchers.index', absolute: false))->toBe('/verifikationer')
        ->and(route('accounting.vouchers.create', absolute: false))->toBe('/verifikationer/ny');
});

it('posts a new voucher typed the Swedish way', function () {
    Livewire::test(VoucherEditor::class)
        ->set('voucherDate', '2026-06-10')
        ->set('description', 'Kontorsmaterial')
        ->set('rows', rowsInput(['6110 Kontorsmateriel', '1 000,00'], ['2641 Debiterad ingående moms', '250'], ['1930', '', '1 250,00 kr'], ['']))
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    $voucher = CurrentCompany::run($this->company, fn () => Voucher::query()->with('rows.account')->sole());

    expect($voucher->isPosted())->toBeTrue()
        ->and($voucher->reference())->toBe('A1')
        ->and($voucher->rows->map(fn ($row) => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())
        ->toBe([[6110, 100000, 0], [2641, 25000, 0], [1930, 0, 125000]])
        ->and(flashed())->toBe(['A1 är bokförd.']);
});

it('saves a draft and continues later', function () {
    $component = Livewire::test(VoucherEditor::class)
        ->set('description', 'Halvfärdig')
        ->set('rows', rowsInput(['1930', '100']))
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = CurrentCompany::run($this->company, fn () => Voucher::query()->sole());

    $component->assertRedirect(route('accounting.vouchers.edit', $draft));
    expect($draft->isPosted())->toBeFalse()
        ->and(flashed())->toBe(['Utkastet är sparat. Du kan fortsätta senare.']);

    Livewire::test(VoucherEditor::class, ['voucher' => $draft])
        ->assertSet('description', 'Halvfärdig')
        ->set('description', 'Nästan klar')
        ->call('saveDraft')
        ->assertDispatched('toast', message: 'Utkastet är sparat.');
});

it('keeps an unbalanced voucher as a draft and explains why', function () {
    Livewire::test(VoucherEditor::class)
        ->set('description', 'Obalans')
        ->set('rows', rowsInput(['1930', '100'], ['3001', '', '99']))
        ->call('post')
        ->assertHasErrors('rows')
        ->assertSee('Skillnaden är 100 öre');

    expect(CurrentCompany::run($this->company, fn () => Voucher::query()->sole()->isPosted()))->toBeFalse();
});

it('puts row errors on the line the user sees, even with blank lines in between', function () {
    Livewire::test(VoucherEditor::class)
        ->set('description', 'Fel konto')
        ->set('rows', rowsInput(['1930', '100'], [''], ['9998', '', '100']))
        ->call('saveDraft')
        ->assertHasErrors('rows.2.account')
        ->assertSee('Rad 3: kontot finns inte i kontoplanen.');
});

it('explains amounts it cannot read', function () {
    Livewire::test(VoucherEditor::class)
        ->set('description', 'Konstigt belopp')
        ->set('rows', rowsInput(['1930', 'tolv kronor'], ['3001', '', '12']))
        ->call('saveDraft')
        ->assertHasErrors('rows.0.amount')
        ->assertSee('Rad 1: beloppet går inte att läsa. Skriv till exempel 1 234,50.');
});

it('copies a posted voucher into a new draft', function () {
    $original = Bookkeeping::post($this->owner, $this->company, 50000, 1930, 3001, '2026-06-01');

    $this->get(route('accounting.vouchers.create', ['kopia' => $original->id]))->assertOk();

    Livewire::test(VoucherEditor::class, ['copyOf' => $original])
        ->assertSet('description', 'Kopia av A1: Försäljning')
        ->assertSet('rows.0.account', '1930 Företagskonto/checkkonto/affärskonto')
        ->assertSet('rows.0.debit', '500,00');
});

it('shows a posted voucher with its rows and checksum', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 123456, 1930, 3001, '2026-06-01');

    $this->get(route('accounting.vouchers.show', $voucher))
        ->assertOk()
        ->assertSee('Verifikation A1')
        ->assertSee('1930')
        ->assertSee("1\u{00A0}234,56", escape: false)
        ->assertSee($voucher->hash)
        ->assertSee('Rätta verifikationen');
});

it('corrects a voucher from its page', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 50000, 1930, 3001, '2026-06-01');

    Livewire::test(VoucherActions::class, ['voucher' => $voucher])
        ->set('correcting', true)
        ->set('reason', 'Fel konto')
        ->call('correct')
        ->assertRedirect();

    expect(flashed())->toBe(['A1 är rättad med A2.'])
        ->and($voucher->fresh()->corrected_by_voucher_id)->not->toBeNull();
});

it('deletes a draft after confirmation', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2026-06-01');

    Livewire::test(VoucherActions::class, ['voucher' => $draft])
        ->call('deleteDraft')
        ->assertRedirect(route('accounting.vouchers.index'));

    expect(flashed())->toBe(['Utkastet är borttaget.'])
        ->and(Voucher::query()->find($draft->id))->toBeNull();
});

it('lists vouchers with amounts and finds them by reference', function () {
    Bookkeeping::post($this->owner, $this->company, 10000, date: '2026-06-01');
    Bookkeeping::post($this->owner, $this->company, 20000, date: '2026-06-02');

    Livewire::test(VoucherList::class)
        ->assertSee('A1')
        ->assertSee('A2')
        ->assertSee("200,00\u{00A0}kr", escape: false)
        ->set('search', 'A2')
        ->assertSee('A2')
        ->assertDontSee('>A1<', escape: false);
});

it('lists vouchers with a fixed number of queries', function () {
    $queries = function (int $count): int {
        foreach (range(1, $count) as $i) {
            Bookkeeping::post($this->owner, $this->company, 100 * $i, date: '2026-06-01');
        }

        Livewire::test(VoucherList::class); // warm up: loads the user's current company once

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(VoucherList::class);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    expect($queries(3))->toBe($queries(12));
});

it('checks the ledger on request', function () {
    Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');

    Livewire::test(VoucherList::class)
        ->call('checkLedger')
        ->assertDispatched('toast', message: 'Bokföringen är kontrollerad: 1 verifikationer, allt stämmer.')
        ->assertSee('Bokföringen är intakt.');
});

it('answers 404 for another company\'s voucher', function () {
    $other = Company::factory()->create();
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = Bookkeeping::post($otherOwner, $otherCompany, date: '2026-06-01');

    $this->actingAs($this->owner->fresh());

    $this->get(route('accounting.vouchers.show', $foreign))->assertNotFound();
    $this->get(route('accounting.vouchers.edit', $foreign))->assertNotFound();
    $this->get(route('accounting.vouchers.create', ['kopia' => $foreign->id]))->assertNotFound();
});

it('lets an auditor read but not write', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');
    $auditor = User::factory()->create();
    $this->company->users()->attach($auditor, ['role' => 'auditor']);
    $auditor->switchCompany($this->company->fresh());
    $this->actingAs($auditor->fresh());

    $this->get(route('accounting.vouchers.index'))->assertOk()->assertDontSee('Ny verifikation');
    $this->get(route('accounting.vouchers.show', $voucher))->assertOk()->assertDontSee('Rätta verifikationen');
    $this->get(route('accounting.vouchers.create'))->assertForbidden();
});

it('never lets a posted voucher be opened for editing', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, date: '2026-06-01');

    $this->get(route('accounting.vouchers.edit', $voucher))->assertForbidden();
});
