<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountType;
use App\Domain\Overview\TodoItem;
use App\Models\Account;
use App\Models\Voucher;
use App\Modules\Boardroom\Domain\ShareCapitalCheck;
use App\Modules\Boardroom\Support\BoardroomTodos;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The register against the books (ADR-070 §3, the 2081 comparison):
 * registered shares times the quota value against 2081 Aktiekapital, as a
 * todo on Översikt naming both figures. Nothing is posted.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->a = Boardroom::person($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $this->a, 1000, 2_500_000, '2026-03-02');
    $this->today = CarbonImmutable::parse('2026-06-30');
});

it('raises a todo naming both figures while 2081 differs from the register', function () {
    $todos = app(BoardroomTodos::class)->todos($this->owner, $this->company, $this->today);

    expect($todos)->toHaveCount(1)
        ->and($todos[0])->toBeInstanceOf(TodoItem::class)
        ->and($todos[0]->title)->toBe('Aktiekapitalet i aktieboken stämmer inte med bokföringen')
        ->and($todos[0]->subtitle)->toContain(Money::ofOre(2_500_000)->format())->toContain('1 000 aktier')->toContain('Konto 2081: '.Money::ofOre(0)->format())
        ->and($todos[0]->url)->toBe(route('share-register.index'));
});

it('clears the todo when the share capital is booked on 2081, and posts nothing itself', function () {
    $vouchers = Voucher::query()->count();
    Bookkeeping::post($this->owner, $this->company, 2_500_000, 1930, 2081, '2026-03-02');

    $check = ShareCapitalCheck::at(ShareRegister::at($this->today), $this->today);

    expect($check->matches())->toBeTrue()
        ->and($check->ledgerCapitalOre)->toBe(2_500_000)
        ->and($check->registerCapitalOre)->toBe(2_500_000)
        ->and($check->quotaValueOre)->toBe(2_500)
        ->and(app(BoardroomTodos::class)->todos($this->owner, $this->company, $this->today))->toBe([])
        ->and(Voucher::query()->count())->toBe($vouchers + 1);
});

it('counts only registered capital: an unregistered new issue is shown apart (AB-R42, G.12)', function () {
    Bookkeeping::post($this->owner, $this->company, 2_500_000, 1930, 2081, '2026-03-02');
    Account::query()->create(['number' => 2082, 'name' => 'Ej registrerat aktiekapital', 'type' => AccountType::LiabilityEquity->value, 'is_active' => true]);
    $b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
    Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'effective_date' => '2026-05-02', 'share_from' => 1001, 'share_to' => 1200, 'to_holder_id' => $b->id, 'capital_change_ore' => 500_000]);
    Bookkeeping::post($this->owner, $this->company, 500_000, 1930, 2082, '2026-05-02');

    $check = ShareCapitalCheck::at(ShareRegister::at($this->today), $this->today);

    expect($check->matches())->toBeTrue()
        ->and($check->pendingCapitalOre)->toBe(500_000)
        ->and($check->registeredShares)->toBe(1000);
});

it('shows the comparison on the register page with both figures', function () {
    $this->actingAs($this->owner)->get('/aktiebok?datum=2026-06-30')
        ->assertOk()
        ->assertSee('Aktiekapitalet mot bokföringen')
        ->assertSee('1 000 × '.Money::ofOre(2_500)->format().' = '.Money::ofOre(2_500_000)->format())
        ->assertSee('Konto 2081');
});

it('gives no todo to someone who cannot see the register', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(app(BoardroomTodos::class)->todos($bookkeeper, $this->company->fresh(), $this->today))->toBe([]);
});
