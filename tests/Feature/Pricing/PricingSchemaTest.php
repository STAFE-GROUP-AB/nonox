<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\RecurringPriceMode;
use App\Models\Article;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceListPriceGroup;
use App\Models\PriceUpdateRun;
use App\Models\SalesDocumentRow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * ADR-082 §Data model (P1): the tables, the database checks that keep a price
 * line well-formed whatever writes it, the defaults that leave every existing
 * company as it is, and the new columns on customers, documents and rows.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A1', 'name' => 'Hylla', 'unit' => 'st', 'price' => '149', 'vat_rate' => 2500]);
    $this->list = PriceList::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'created_by' => $this->owner->id]);
    $this->customer = Crm::customer($this->company, $this->owner);
});

/** Runs a write that the database must refuse, in its own savepoint so the test's transaction survives. */
function p1Refused(Closure $write): void
{
    expect(fn () => DB::transaction($write))->toThrow(QueryException::class);
}

/** @param  array<string, mixed>  $attributes */
function p1Item(object $test, array $attributes): PriceListItem
{
    return PriceListItem::create(['price_list_id' => $test->list->id, 'article_id' => $test->article->id, ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function p1AgreementItem(object $test, array $attributes): PriceAgreementItem
{
    $agreement = PriceAgreement::query()->firstOrCreate(['number' => 1], [
        'customer_id' => $test->customer->id, 'title' => 'Ramavtal', 'valid_from' => '2026-01-01', 'created_by' => $test->owner->id,
    ]);

    return PriceAgreementItem::create(['price_agreement_id' => $agreement->id, ...$attributes]);
}

it('starts a price list as a plain SEK list without VAT, rounding or channel', function () {
    $list = $this->list->fresh();

    expect($list->kind)->toBe(PriceListKind::Standard)
        ->and($list->currency)->toBe('SEK')
        ->and($list->prices_include_vat)->toBeFalse()
        ->and($list->is_default_for_currency)->toBeFalse()
        ->and($list->rounding_rule)->toBe(PriceRounding::None)
        ->and($list->channel)->toBeNull()
        ->and($list->based_on_list_id)->toBeNull();
});

it('leaves existing customers, recurring invoices, quotes and rows exactly as they were', function () {
    $customer = $this->customer->fresh();

    expect($customer->price_group_id)->toBeNull()
        ->and($customer->price_list_id)->toBeNull()
        ->and(DB::scalar("select column_default from information_schema.columns where table_name = 'recurring_invoices' and column_name = 'price_mode'"))->toContain('FIXED')
        ->and(DB::scalar("select column_default from information_schema.columns where table_name = 'sales_documents' and column_name = 'currency'"))->toContain('SEK');

    foreach (['invoice_rows', 'sales_document_rows', 'recurring_invoice_rows', 'crm_quote_template_rows', 'work_order_material_entries'] as $table) {
        $columns = collect(DB::select('select column_name, is_nullable, data_type from information_schema.columns where table_name = ? and column_name in (?, ?)', [$table, 'price_source', 'price_explanation']))
            ->mapWithKeys(fn (object $column): array => [$column->column_name => [$column->is_nullable, $column->data_type]])
            ->all();

        expect($columns)->toBe(['price_explanation' => ['YES', 'jsonb'], 'price_source' => ['YES', 'character varying']], $table);
    }
});

it('takes exactly one of a price and a discount on a list item', function () {
    expect(p1Item($this, ['price_minor' => 12_500])->price_minor)->toBe(12_500)
        ->and(p1Item($this, ['discount_bp' => 1_000, 'min_quantity_milli' => 10_000])->discount_bp)->toBe(1_000);

    p1Refused(fn () => p1Item($this, ['price_minor' => 100, 'discount_bp' => 100, 'min_quantity_milli' => 20_000]));
    p1Refused(fn () => p1Item($this, ['min_quantity_milli' => 30_000]));
});

it('refuses a negative price, a discount above 100 %, a negative quantity and a period that ends before it starts', function () {
    p1Refused(fn () => p1Item($this, ['price_minor' => -1]));
    p1Refused(fn () => p1Item($this, ['discount_bp' => 10_001]));
    p1Refused(fn () => p1Item($this, ['discount_bp' => -1]));
    p1Refused(fn () => p1Item($this, ['price_minor' => 1, 'min_quantity_milli' => -1]));
    p1Refused(fn () => p1Item($this, ['price_minor' => 1, 'valid_from' => '2026-06-01', 'valid_to' => '2026-05-31']));

    expect(p1Item($this, ['price_minor' => 0, 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-01'])->price_minor)->toBe(0);
});

it('allows one live item per list, article, unit, step and start date, and another after archiving', function () {
    $first = p1Item($this, ['price_minor' => 100, 'valid_from' => '2026-01-01']);

    p1Refused(fn () => p1Item($this, ['price_minor' => 200, 'valid_from' => '2026-01-01']));
    p1Item($this, ['price_minor' => 200, 'valid_from' => '2026-07-01']);
    p1Item($this, ['price_minor' => 90, 'valid_from' => '2026-01-01', 'min_quantity_milli' => 10_000]);

    p1Item($this, ['price_minor' => 50]);
    p1Refused(fn () => p1Item($this, ['price_minor' => 60]));

    $first->update(['archived_at' => now()]);

    expect(p1Item($this, ['price_minor' => 300, 'valid_from' => '2026-01-01'])->price_minor)->toBe(300);
});

it('keeps at most one default list per currency, and a campaign always has both dates', function () {
    PriceList::create(['code' => 'SEK', 'name' => 'Standard', 'is_default_for_currency' => true, 'created_by' => $this->owner->id]);
    PriceList::create(['code' => 'EUR', 'name' => 'Euro', 'currency' => 'EUR', 'is_default_for_currency' => true, 'created_by' => $this->owner->id]);

    p1Refused(fn () => PriceList::create(['code' => 'SEK2', 'name' => 'Standard 2', 'is_default_for_currency' => true, 'created_by' => $this->owner->id]));
    p1Refused(fn () => PriceList::create(['code' => 'SOMMAR', 'name' => 'Sommar', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'created_by' => $this->owner->id]));
    p1Refused(fn () => PriceList::create(['code' => 'X', 'name' => 'X', 'currency' => 'sek', 'created_by' => $this->owner->id]));
    p1Refused(fn () => PriceList::create(['code' => 'ATER', 'name' => 'Dubblett', 'created_by' => $this->owner->id]));

    $campaign = PriceList::create(['code' => 'SOMMAR', 'name' => 'Sommar', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'channel' => 'POS', 'created_by' => $this->owner->id]);

    expect($campaign->kind)->toBe(PriceListKind::Campaign)
        ->and($campaign->fresh()->channel?->value)->toBe('POS');
});

it('derives a list from another list but never from itself', function () {
    $derived = PriceList::create(['code' => 'ATER2', 'name' => 'Följer', 'based_on_list_id' => $this->list->id, 'derived_percent_bp' => -1_200, 'rounding_rule' => 'WHOLE', 'created_by' => $this->owner->id]);

    expect($derived->basedOn->is($this->list))->toBeTrue()
        ->and($derived->fresh()->rounding_rule)->toBe(PriceRounding::Whole);

    p1Refused(fn () => DB::table('price_lists')->where('id', $this->list->id)->update(['based_on_list_id' => $this->list->id]));
});

it('aims a campaign at customer price groups, and gives a customer a group and a list', function () {
    $group = CustomerPriceGroup::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'price_list_id' => $this->list->id]);
    $campaign = PriceList::create(['code' => 'VAR', 'name' => 'Vår', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31', 'created_by' => $this->owner->id]);

    $campaign->priceGroups()->attach($group->id, ['company_id' => $this->company->id]);
    $this->customer->update(['price_group_id' => $group->id, 'price_list_id' => $this->list->id]);

    expect($campaign->priceGroups()->pluck('customer_price_groups.id')->all())->toBe([$group->id])
        ->and(PriceListPriceGroup::query()->sole()->company_id)->toBe($this->company->id)
        ->and($this->customer->fresh()->priceGroup->is($group))->toBeTrue()
        ->and($this->customer->fresh()->priceList->is($this->list))->toBeTrue()
        ->and($group->customers()->pluck('id')->all())->toBe([$this->customer->id]);
});

it('gives an agreement item exactly one target and exactly one of price and discount', function () {
    expect(p1AgreementItem($this, ['article_id' => $this->article->id, 'price_minor' => 41_200])->price_minor)->toBe(41_200)
        ->and(p1AgreementItem($this, ['applies_to_all' => true, 'discount_bp' => 500])->applies_to_all)->toBeTrue()
        ->and(p1AgreementItem($this, ['applies_to_all' => true, 'discount_bp' => 300, 'base_price_list_id' => $this->list->id])->basePriceList->is($this->list))->toBeTrue();

    p1Refused(fn () => p1AgreementItem($this, ['price_minor' => 1]));
    p1Refused(fn () => p1AgreementItem($this, ['article_id' => $this->article->id, 'applies_to_all' => true, 'price_minor' => 1]));
    p1Refused(fn () => p1AgreementItem($this, ['article_id' => $this->article->id]));
    p1Refused(fn () => p1AgreementItem($this, ['article_id' => $this->article->id, 'price_minor' => 1, 'discount_bp' => 1]));
    p1Refused(fn () => p1AgreementItem($this, ['article_id' => $this->article->id, 'price_minor' => 1, 'base_price_list_id' => $this->list->id]));
    p1Refused(fn () => p1AgreementItem($this, ['article_id' => $this->article->id, 'price_minor' => 1, 'max_quantity_milli' => 0]));
});

it('numbers price agreements per company and starts them as a draft that warns', function () {
    $agreement = PriceAgreement::create(['number' => 12, 'customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'valid_from' => '2026-01-01', 'created_by' => $this->owner->id])->fresh();

    expect($agreement->reference())->toBe('PA-12')
        ->and($agreement->status)->toBe(PriceAgreementStatus::Draft)
        ->and($agreement->price_floor_policy)->toBe(PriceFloorPolicy::Warn)
        ->and($agreement->allow_campaigns)->toBeFalse()
        ->and($agreement->currency)->toBe('SEK');

    p1Refused(fn () => PriceAgreement::create(['number' => 12, 'customer_id' => $this->customer->id, 'title' => 'Dubblett', 'valid_from' => '2026-01-01', 'created_by' => $this->owner->id]));
    p1Refused(fn () => PriceAgreement::create(['number' => 13, 'customer_id' => $this->customer->id, 'title' => 'Baklänges', 'valid_from' => '2026-06-01', 'valid_to' => '2026-05-01', 'created_by' => $this->owner->id]));
    p1Refused(fn () => DB::table('price_agreements')->where('id', $agreement->id)->update(['status' => 'PAUSED']));
});

it('accepts only known price sources on rows, and a recurring price mode and quote currency of the right form', function () {
    foreach (['invoice_rows', 'sales_document_rows', 'recurring_invoice_rows', 'crm_quote_template_rows', 'work_order_material_entries'] as $table) {
        $check = (string) DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', ["{$table}_price_source_check"]);

        foreach (PriceSource::cases() as $source) {
            expect($check)->toContain("'{$source->value}'");
        }
    }

    $agreement = app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'name' => 'Hyra', 'interval_months' => 1, 'next_invoice_date' => '2026-07-01',
        'rows' => [['description' => 'Hylla', 'quantity' => '1', 'price' => '149', 'vat_rate' => 2500]],
    ]);

    expect($agreement->fresh()->price_mode)->toBe(RecurringPriceMode::Fixed);

    p1Refused(fn () => DB::table('recurring_invoices')->where('id', $agreement->id)->update(['price_mode' => 'SOMETIMES']));

    expect(array_map(fn (PriceSource $source): string => $source->value, PriceSource::cases()))
        ->toBe(['MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'])
        ->and(RecurringPriceMode::from('FOLLOW_PRICES'))->toBe(RecurringPriceMode::FollowPrices);
});

it('keeps a price update run with ids only, as a preview to begin with', function () {
    $run = PriceUpdateRun::create([
        'kind' => 'PERCENT', 'scope' => ['price_list_ids' => [$this->list->id], 'article_ids' => [$this->article->id]],
        'rule' => ['percent_bp' => 400], 'rounding_rule' => 'ENDS_IN_9', 'effective_date' => '2027-01-01', 'created_by' => $this->owner->id,
    ])->fresh();

    expect($run->status->value)->toBe('PREVIEW')
        ->and($run->rounding_rule)->toBe(PriceRounding::EndsIn9)
        ->and($run->scope)->toEqual(['price_list_ids' => [$this->list->id], 'article_ids' => [$this->article->id]]);

    p1Refused(fn () => DB::table('price_update_runs')->where('id', $run->id)->update(['kind' => 'GUESS']));
    p1Refused(fn () => DB::table('price_update_runs')->where('id', $run->id)->update(['status' => 'MAYBE']));
});

it('stores the row price explanation as structured data', function () {
    $article = Article::query()->findOrFail($this->article->id);

    expect($article->priceHistory()->count())->toBe(1);

    $row = new SalesDocumentRow(['price_source' => PriceSource::Agreement, 'price_explanation' => ['source' => 'AGREEMENT', 'agreement_id' => 3, 'tier_min_milli' => 10_000]]);

    expect($row->price_source)->toBe(PriceSource::Agreement)
        ->and($row->getAttributes()['price_explanation'])->toBe('{"source":"AGREEMENT","agreement_id":3,"tier_min_milli":10000}');
});

it('indexes the customers of a price group and of a price list', function () {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'customers'"))->pluck('indexdef')->implode("\n");

    expect($indexes)->toContain('(company_id, price_group_id)')
        ->and($indexes)->toContain('(company_id, price_list_id)');
});

it('refers a customer only to price groups and lists that exist', function () {
    p1Refused(fn () => DB::table('customers')->where('id', $this->customer->id)->update(['price_list_id' => 999_999_999]));
    p1Refused(fn () => DB::table('customers')->where('id', $this->customer->id)->update(['price_group_id' => 999_999_999]));

    expect(Customer::query()->findOrFail($this->customer->id)->price_list_id)->toBeNull();
});
