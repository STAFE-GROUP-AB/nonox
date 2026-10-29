<?php

declare(strict_types=1);

use App\Domain\Accounting\VoucherTemplate;
use App\Livewire\Accounting\VoucherEditor;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

it('splits a purchase into cost, VAT and payment', function (VoucherTemplate $template, string $total, int $net, int $vat) {
    $rows = $template->rows(Money::parse($total));

    expect($rows)->toBe([
        ['account' => null, 'debit_ore' => $net, 'credit_ore' => 0],
        ['account' => 2641, 'debit_ore' => $vat, 'credit_ore' => 0],
        ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => Money::parse($total)->ore],
    ]);
})->with([
    '25 %' => [VoucherTemplate::Purchase25, '1 250', 100000, 25000],
    '12 %' => [VoucherTemplate::Purchase12, '112', 10000, 1200],
    '6 %' => [VoucherTemplate::Purchase6, '106', 10000, 600],
    'odd amount rounds the VAT' => [VoucherTemplate::Purchase25, '99,99', 7999, 2000],
]);

it('splits a sale into payment, revenue and output VAT', function () {
    expect(VoucherTemplate::Sale25->rows(Money::parse('1 250')))->toBe([
        ['account' => 1930, 'debit_ore' => 125000, 'credit_ore' => 0],
        ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 100000],
        ['account' => 2611, 'debit_ore' => 0, 'credit_ore' => 25000],
    ]);
});

it('always balances, whatever the amount', function (VoucherTemplate $template) {
    mt_srand(7);

    foreach (range(1, 50) as $attempt) {
        $rows = $template->rows(Money::ofOre(mt_rand(1, 50_000_000)));

        expect(array_sum(array_column($rows, 'debit_ore')))->toBe(array_sum(array_column($rows, 'credit_ore')));
    }
})->with(VoucherTemplate::cases());

describe('in the editor', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-06-15'));
        [$this->owner, $this->company] = Bookkeeping::company();
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
    });

    it('fills the rows from a template and asks for the cost account', function () {
        Livewire::test(VoucherEditor::class)
            ->set('template', 'inkop-25')
            ->set('templateAmount', '1 250,00')
            ->call('applyTemplate')
            ->assertHasNoErrors()
            ->assertSet('rows.0.account', '')
            ->assertSet('rows.0.debit', "1\u{00A0}000,00")
            ->assertSet('rows.1.account', '2641 Debiterad ingående moms')
            ->assertSet('rows.1.debit', '250,00')
            ->assertSet('rows.2.account', '1930 Företagskonto/checkkonto/affärskonto')
            ->assertSet('rows.2.credit', "1\u{00A0}250,00")
            ->assertSet('description', 'Inköp med 25 % moms')
            ->assertDispatched('toast', message: 'Raderna är ifyllda. Välj kostnadskonto på den första raden.');
    });

    it('posts a voucher made from a template once the cost account is chosen', function () {
        $component = Livewire::test(VoucherEditor::class)
            ->set('template', 'inkop-25')
            ->set('templateAmount', '500')
            ->call('applyTemplate')
            ->set('rows.0.account', '6110 Kontorsmateriel')
            ->call('post')
            ->assertHasNoErrors();

        expect(Voucher::query()->sole()->isPosted())->toBeTrue();
    });

    it('explains a missing template or unreadable amount', function () {
        Livewire::test(VoucherEditor::class)
            ->set('templateAmount', '100')
            ->call('applyTemplate')
            ->assertHasErrors('template')
            ->set('template', 'bankavgift')
            ->set('templateAmount', 'mycket')
            ->call('applyTemplate')
            ->assertHasErrors('templateAmount')
            ->assertSee('Beloppet går inte att läsa');
    });
});
