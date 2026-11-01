<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

/*
 * Everything works on a phone (README, "Mobilen"). The row editors are a table
 * on larger screens and one card per row below the breakpoint, instead of a
 * table wider than the phone. scripts/mobile-overflow.mjs checks the pages in
 * a browser; this keeps the markup from quietly going back to a plain table.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
});

it('shows the rows of invoices, quotes, orders and agreements as cards below lg', function (string $route) {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('data-row-cards', false)
        ->assertSee('class="block w-full text-sm lg:table"', false)
        ->assertSee('class="hidden lg:table-header-group"', false)
        ->assertSee('lg:table-row', false);
})->with([
    'faktura' => 'invoicing.invoices.create',
    'offert' => 'invoicing.quotes.create',
    'order' => 'invoicing.orders.create',
    'avtal' => 'invoicing.agreements.create',
]);

it('shows the rows of vouchers and supplier invoices as cards below md', function (string $route) {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('data-row-cards', false)
        ->assertSee('class="block w-full text-sm md:table"', false)
        ->assertSee('class="hidden md:table-header-group"', false)
        ->assertSee('md:table-row', false);
})->with([
    'verifikation' => 'accounting.vouchers.create',
    'leverantörsfaktura' => 'payables.invoices.create',
]);

it('keeps the voucher grid keyboard-first: every field is in the grid with a label', function () {
    $html = $this->get(route('accounting.vouchers.create'))->assertOk()->getContent();

    foreach (['account', 'debit', 'credit', 'description'] as $field) {
        expect($html)->toContain(":for=\"'row-{$field}-' + index\"")
            ->and($html)->toMatch("/:id=\"'row-{$field}-' \\+ index\"[^>]*data-grid/");
    }
});

it('shows the voucher list as cards below sm', function () {
    Bookkeeping::post($this->owner, $this->company);

    $this->get(route('accounting.vouchers.index'))
        ->assertOk()
        ->assertSee('class="hidden sm:table-header-group"', false)
        ->assertSee('sm:table-row', false);
});
