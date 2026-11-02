<?php

declare(strict_types=1);

use App\Modules\Pos\Actions\CreateDailyDraft;
use App\Modules\Pos\Actions\RecordPosSale;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Livewire\PosPage;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosLogEntry;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Modules\Pos\Models\PosWebhookMessage;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Pos;

/*
 * AGENTS.md §13.2 for Kassakopplingar: company A never sees B's tills,
 * sales, lines or log, through Eloquent, the page or its actions; B's ids
 * answer 404; a webhook to A's address only touches A; A's daily voucher
 * holds only A's sales.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-03-11 08:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Pos::company();
        $this->actingAs($owner);
        $connection = Pos::connection($company, $owner);
        $sale = app(RecordPosSale::class)($connection, app(ZettleDriver::class)->parseSale(Pos::purchase(
            $side === 'a' ? 'a0000000-0000-1000-8000-000000000001' : 'b0000000-0000-1000-8000-000000000001',
            [Pos::product("{$name}vara", '1', 8000)],
            [['type' => 'IZETTLE_CASH', 'amount' => 10000]],
            10000,
            2000,
            '2026-03-10T09:00:00.000+0000',
        )), PosSale::WEBHOOK);
        $connection->log('info', "{$name} loggrad");
        $this->{$side} = ['owner' => $owner, 'company' => $company, 'connection' => $connection, 'sale' => $sale, 'row' => $sale->rows->first()];
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every till model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([PosConnection::class, PosSale::class, PosSaleRow::class, PosLogEntry::class]);

it('shows only the own company on the page and answers 404 for the other\'s records', function () {
    $this->get(route('pos.index'))->assertOk()->assertSee('Alfavara')->assertDontSee('Betavara')->assertDontSee('Beta loggrad');

    Livewire::test(PosPage::class)->set("articleFor.{$this->b['row']->id}", 'V-1')->call('linkRow', $this->b['row']->id)->assertNotFound();
    Livewire::test(PosPage::class)->call('ignoreRow', $this->b['row']->id)->assertNotFound();
    Livewire::test(PosPage::class)->call('askDisconnect', $this->b['connection']->id)->assertNotFound();
    Livewire::test(PosPage::class)->call('edit', $this->b['connection']->id)->assertNotFound();
    Livewire::test(PosPage::class)->call('fetchSales', $this->b['connection']->id)->assertNotFound();

    expect(CompanyScope::bypass(fn () => PosSaleRow::query()->find($this->b['row']->id)->status))->toBe(PosSaleRow::UNMATCHED);
});

it('lets a webhook to A\'s address touch only A', function () {
    $envelope = Pos::envelope(Pos::purchase('a0000000-0000-1000-8000-000000000002', [], [['type' => 'IZETTLE_CASH', 'amount' => 100]], 100, 20), 'msg-a');

    $this->call('POST', route('pos.webhook.zettle', ['token' => $this->a['connection']->webhook_token]), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_IZETTLE_SIGNATURE' => $envelope['signature'],
    ], $envelope['body'])->assertOk();

    expect(CompanyScope::bypass(fn () => PosWebhookMessage::query()->pluck('company_id')->all()))->toBe([$this->a['company']->id]);
});

it('puts only the own company\'s sales in its daily voucher', function () {
    $voucher = app(CreateDailyDraft::class)($this->a['owner'], $this->a['company'], CarbonImmutable::parse('2026-03-10'));

    expect($voucher->company_id)->toBe($this->a['company']->id)
        ->and(CompanyScope::bypass(fn () => PosSale::query()->find($this->b['sale']->id)->voucher_id))->toBeNull()
        ->and($this->a['sale']->fresh()->voucher_id)->toBe($voucher->id);
});
