<?php

declare(strict_types=1);

use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasMonth;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\Saas;

/*
 * ADR-080 §3: contract versions and corrections are append-only, and a
 * closed month with its customer rows is frozen — through Eloquent, the query
 * builder and raw SQL alike. Each attempt runs in a savepoint so the test's
 * transaction survives the refusal.
 */

beforeEach(function () {
    Saas::at('2026-01-15');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Saas::enable($this->company, $this->owner);
    $this->agreement = Saas::agreement($this->owner, $this->company, Saas::customer($this->owner, $this->company), '500');

    Saas::at('2026-02-12');
    app(CloseSaasMonth::class)($this->owner, $this->company, '2026-01');
    Saas::at('2026-02-20');
    Saas::reprice($this->owner, $this->company, $this->agreement->fresh(), '400', '2026-01-01');
});

/** The database's refusal, or null when the statement went through. */
function saasRefusal(callable $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException|LogicException $exception) {
        return $exception->getMessage();
    }

    return null;
}

it('refuses to change or delete a contract version', function () {
    $version = SaasContractVersion::query()->firstOrFail();
    $customer = $version->customer_id;

    expect(saasRefusal(fn () => $version->forceFill(['customer_id' => 999])->save()))->toContain('cannot be changed')
        ->and(saasRefusal(fn () => $version->delete()))->toContain('cannot be deleted')
        ->and(saasRefusal(fn () => SaasContractVersion::query()->whereKey($version->id)->update(['effective_from' => '2025-01-01'])))->toContain('append-only')
        ->and(saasRefusal(fn () => SaasContractVersion::query()->whereKey($version->id)->delete()))->toContain('append-only')
        ->and(saasRefusal(fn () => DB::update('update saas_contract_versions set lines = ? where id = ?', ['[]', $version->id])))->toContain('append-only')
        ->and(saasRefusal(fn () => DB::delete('delete from saas_contract_versions where id = ?', [$version->id])))->toContain('append-only')
        ->and(saasRefusal(fn () => DB::statement('truncate saas_contract_versions')))->not->toBeNull()
        ->and(SaasContractVersion::query()->whereKey($version->id)->value('customer_id'))->toBe($customer);
});

it('refuses to change or delete a correction', function () {
    $correction = SaasCorrection::query()->sole();

    expect(saasRefusal(fn () => $correction->forceFill(['amount_ore' => 0])->save()))->toContain('cannot be changed')
        ->and(saasRefusal(fn () => SaasCorrection::query()->whereKey($correction->id)->update(['amount_ore' => 0])))->toContain('append-only')
        ->and(saasRefusal(fn () => DB::delete('delete from saas_corrections where id = ?', [$correction->id])))->toContain('append-only')
        ->and(saasRefusal(fn () => DB::statement('truncate saas_corrections')))->not->toBeNull()
        ->and(SaasCorrection::query()->sole()->amount_ore)->toBe(-10_000);
});

it('freezes a closed month and its customer rows', function () {
    $january = Saas::month('2026-01-01');
    $row = SaasCustomerMonth::query()->where('month', '2026-01-01')->sole();

    expect(saasRefusal(fn () => $january->forceFill(['mrr_ore' => 1])->save()))->toContain('cannot be changed')
        ->and(saasRefusal(fn () => $january->delete()))->toContain('cannot be deleted')
        ->and(saasRefusal(fn () => SaasMonth::query()->whereKey($january->id)->update(['status' => 'OPEN'])))->toContain('closed month cannot be changed')
        ->and(saasRefusal(fn () => DB::update('update saas_months set mrr_ore = 1 where id = ?', [$january->id])))->toContain('closed month cannot be changed')
        ->and(saasRefusal(fn () => DB::delete('delete from saas_months where id = ?', [$january->id])))->toContain('cannot be deleted')
        ->and(saasRefusal(fn () => $row->forceFill(['mrr_ore' => 1])->save()))->toContain('the month is closed')
        ->and(saasRefusal(fn () => DB::update('update saas_customer_months set mrr_ore = 1 where id = ?', [$row->id])))->toContain('the month is closed')
        ->and(saasRefusal(fn () => DB::delete('delete from saas_customer_months where month = ?', ['2026-01-01'])))->toContain('the month is closed')
        ->and(saasRefusal(fn () => DB::insert("insert into saas_customer_months (company_id, month, customer_id, state, lines) values (?, '2026-01-01', 999999, 'ACTIVE', '[]')", [$this->company->id])))->toContain('the month is closed')
        ->and(saasRefusal(fn () => DB::statement('truncate saas_months cascade')))->not->toBeNull()
        ->and(Saas::month('2026-01-01')->mrr_ore)->toBe(50_000)
        ->and(SaasCustomerMonth::query()->where('month', '2026-01-01')->sole()->mrr_ore)->toBe(50_000);
});

it('lets an open month be recomputed', function () {
    $february = Saas::month('2026-02-01');

    expect($february->isClosed())->toBeFalse()
        ->and(saasRefusal(fn () => SaasCustomerMonth::query()->where('month', '2026-02-01')->delete()))->toBeNull();

    Saas::recompute($this->company);

    expect(Saas::month('2026-02-01')->mrr_ore)->toBe(40_000);
});
