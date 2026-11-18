<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Accounting\VoucherList;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10: a limited member sees a voucher only when every row with a
 * cost centre or project is inside the scope and at least one row is, and
 * books only such vouchers. Anything else answers 404.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

it('lists only the vouchers inside the scope', function () {
    $listed = Livewire::test(VoucherList::class, ['fiscalYearId' => null])->viewData('vouchers')->pluck('id')->all();

    expect($listed)->toEqualCanonicalizing([$this->fixture->vouchers['inside']->id, $this->fixture->vouchers['project']->id]);
});

it('shows a voucher inside the scope without the company\'s account balances', function () {
    $this->get(route('accounting.vouchers.show', $this->fixture->vouchers['inside']))
        ->assertOk()
        ->assertViewHas('balances', []);
});

it('answers 404 for every voucher outside the scope', function (string $key) {
    $voucher = $this->fixture->vouchers[$key];

    $this->get(route('accounting.vouchers.show', $voucher))->assertNotFound();
    $this->get(route('accounting.vouchers.create', ['kopia' => $voucher->id]))->assertNotFound();
})->with(['outside', 'mixed', 'none']);

it('refuses the hash-chain check, which reads the whole ledger', function () {
    Livewire::test(VoucherList::class)->call('checkLedger')->assertNotFound();
});

it('books only inside the scope', function () {
    $save = fn (array $rows) => app(SaveDraftVoucher::class)($this->member, $this->fixture->company, [
        'voucher_date' => '2026-03-02', 'description' => 'Kassa', 'series' => 'A', 'rows' => $rows,
    ]);

    expect(fn () => $save([['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100, 'cost_center' => '20']]))
        ->toThrow(ValidationException::class, 'måste höra till de enheter du når')
        ->and(fn () => $save([['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]))
        ->toThrow(ValidationException::class, 'minst en rad');

    $draft = $save([['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100, 'cost_center' => '10']]);
    $posted = app(PostVoucher::class)($this->member, $draft);

    expect($posted->isPosted())->toBeTrue();
});

it('offers only the member\'s cost centres and projects, and no balances, in the editor', function () {
    $editor = Livewire::test(VoucherEditor::class);

    expect($editor->viewData('costCenters')->pluck('code')->all())->toBe(['10'])
        ->and($editor->viewData('projects')->pluck('code')->all())->toBe(['P1'])
        ->and($editor->get('balances'))->toBeNull();
});

it('answers 404 when posting a draft outside the scope', function () {
    $draft = app(SaveDraftVoucher::class)($this->fixture->owner, $this->fixture->company, [
        'voucher_date' => '2026-03-02', 'description' => 'Syd', 'series' => 'A',
        'rows' => [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100, 'cost_center' => '20']],
    ]);

    expect(fn () => app(PostVoucher::class)($this->member, $draft))->toThrow(AuthorizationException::class);

    $this->get(route('accounting.vouchers.edit', $draft))->assertNotFound();
});

it('lets an unlimited member see every voucher', function () {
    $this->actingAs($this->fixture->owner);

    expect(Livewire::test(VoucherList::class, ['fiscalYearId' => null])->viewData('vouchers')->total())->toBe(count($this->fixture->vouchers));
});

it('runs the voucher list in the same number of queries for a short and a long history', function () {
    $count = function (): int {
        DimensionScope::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(VoucherList::class, ['fiscalYearId' => null]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // A first render warms caches that are read once per request (modules, the member's companies).
    $count();
    $short = $count();

    CurrentCompany::run($this->fixture->company, function (): void {
        foreach (range(1, 8) as $index) {
            app(PostVoucher::class)($this->fixture->owner, app(SaveDraftVoucher::class)($this->fixture->owner, $this->fixture->company, [
                'voucher_date' => '2026-03-03', 'description' => "Kassa {$index}", 'series' => 'A',
                'rows' => [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100, 'cost_center' => $index % 2 === 0 ? '10' : '20']],
            ]));
        }
    });

    expect($count())->toBe($short)
        ->and(Voucher::query()->count())->toBe(count($this->fixture->vouchers) + 8);
});
