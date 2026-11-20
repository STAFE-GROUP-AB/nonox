<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ArchiveCustomerPriceGroup;
use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\AssignCustomerPrices;
use App\Actions\Pricing\RetirePriceListItem;
use App\Actions\Pricing\SaveCustomerPriceGroup;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * ADR-082 P4: the actions behind the price screens. Every id they take must
 * belong to the company, items of one key never overlap in time, and used
 * prices are ended or archived instead of deleted.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->save = fn (array $input = [], ?PriceList $list = null): PriceList => app(SavePriceList::class)($this->owner, $this->company, [
        'code' => 'ATER', 'name' => 'Återförsäljare', 'kind' => 'STANDARD', 'currency' => 'SEK', ...$input,
    ], $list);
    $this->item = fn (PriceList $list, array $input = [], ?PriceListItem $item = null, ?PriceListItem $replaces = null): PriceListItem => app(SavePriceListItem::class)($this->owner, $this->company, $list, [
        'article_id' => $this->article->id, 'mode' => 'price', 'price' => '90,00', ...$input,
    ], $item, $replaces);

    // Another company with its own article, unit, list and group.
    [$this->otherOwner, $this->otherCompany] = Bookkeeping::company();
    CurrentCompany::run($this->otherCompany, function () {
        $this->otherArticle = app(SaveArticle::class)($this->otherOwner, $this->otherCompany, ['number' => 'X-1', 'name' => 'Hemlig', 'unit' => 'st', 'price' => '5', 'vat_rate' => 2500]);
        $this->otherList = PriceList::create(['code' => 'HEMLIG', 'name' => 'Hemlig', 'created_by' => $this->otherOwner->id]);
        $this->otherGroup = CustomerPriceGroup::create(['code' => 'HEMLIG', 'name' => 'Hemlig grupp']);
        $this->otherUnit = Unit::query()->first();
        $this->otherCustomer = Crm::customer($this->otherCompany, $this->otherOwner, 'Hemlig kund');
    });
    CurrentCompany::set($this->company);
});

it('creates a standard list, upper-cases the code and audits ids and enums only', function () {
    $list = ($this->save)(['code' => ' ater ', 'is_default_for_currency' => true]);

    expect($list->code)->toBe('ATER')
        ->and($list->is_default_for_currency)->toBeTrue()
        ->and($list->created_by)->toBe($this->owner->id);

    $audit = AuditLog::query()->where('action', 'price_list.created')->latest('id')->first();
    expect($audit->payload)->toBe(['kind' => 'STANDARD', 'currency' => 'SEK']);
});

it('refuses a duplicate code, a bad currency and a campaign without a period', function () {
    ($this->save)();

    expect(fn () => ($this->save)(['name' => 'Annan']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'B', 'currency' => 'EU']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-30', 'valid_to' => '2026-06-01']))->toThrow(ValidationException::class);
});

it('keeps one default list per currency by moving the flag', function () {
    $first = ($this->save)(['code' => 'A', 'is_default_for_currency' => true]);
    $second = ($this->save)(['code' => 'B', 'is_default_for_currency' => true]);
    $euro = ($this->save)(['code' => 'E', 'currency' => 'EUR', 'is_default_for_currency' => true]);

    expect($first->fresh()->is_default_for_currency)->toBeFalse()
        ->and($second->fresh()->is_default_for_currency)->toBeTrue()
        ->and($euro->fresh()->is_default_for_currency)->toBeTrue()
        ->and(fn () => ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'is_default_for_currency' => true]))->toThrow(ValidationException::class);
});

it('derives a list from the base price or another list of the same currency, never in a loop or across companies', function () {
    $base = ($this->save)(['code' => 'BAS']);
    $derived = ($this->save)(['code' => 'D', 'derived' => true, 'derived_percent' => '-12,5', 'rounding_rule' => 'WHOLE']);
    $chain = ($this->save)(['code' => 'C', 'derived' => true, 'derived_percent' => '5', 'based_on_list_id' => $derived->id]);

    expect($derived->derived_percent_bp)->toBe(-1250)
        ->and($derived->based_on_list_id)->toBeNull()
        ->and($chain->based_on_list_id)->toBe($derived->id)
        ->and(fn () => ($this->save)(['code' => 'D', 'derived' => true, 'derived_percent' => '5', 'based_on_list_id' => $chain->id], $derived))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'X', 'derived' => true, 'derived_percent' => '5', 'based_on_list_id' => $this->otherList->id]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'Y', 'currency' => 'EUR', 'derived' => true, 'derived_percent' => '5', 'based_on_list_id' => $base->id]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['code' => 'Z', 'derived' => true, 'derived_percent' => 'abc']))->toThrow(ValidationException::class);
});

it('refuses another company\'s group on a campaign and another company\'s list on a group or customer', function () {
    $own = ($this->save)(['code' => 'LIST']);
    $customer = Crm::customer($this->company, $this->owner, 'Egen kund');

    expect(fn () => ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'group_ids' => [$this->otherGroup->id]]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'G', 'price_list_id' => $this->otherList->id]))->toThrow(ValidationException::class)
        ->and(fn () => app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, $this->otherGroup->id, null))->toThrow(ValidationException::class)
        ->and(fn () => app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, $this->otherList->id))->toThrow(ValidationException::class)
        ->and(fn () => app(AssignCustomerPrices::class)($this->owner, $this->company, $this->otherCustomer, null, $own->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->save)(['code' => 'ZZ'], $this->otherList))->toThrow(ModelNotFoundException::class);
});

it('targets a campaign at chosen groups', function () {
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'ater', 'name' => 'Återförsäljare']);
    $campaign = ($this->save)(['code' => 'SOMMAR', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'group_ids' => [$group->id]]);

    expect($campaign->priceGroups->pluck('id')->all())->toBe([$group->id])
        ->and(DB::table('price_list_price_group')->where('price_list_id', $campaign->id)->value('company_id'))->toBe($this->company->id);

    ($this->save)(['code' => 'SOMMAR', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'group_ids' => []], $campaign);

    expect($campaign->fresh()->priceGroups)->toHaveCount(0);
});

it('does not change currency or VAT basis once the list has prices', function () {
    $list = ($this->save)();
    ($this->item)($list);

    expect(fn () => ($this->save)(['currency' => 'EUR'], $list))->toThrow(ValidationException::class)
        ->and(fn () => ($this->save)(['prices_include_vat' => true], $list))->toThrow(ValidationException::class)
        ->and(($this->save)(['name' => 'Nytt namn'], $list)->name)->toBe('Nytt namn');
});

it('adds a price, a discount and a quantity ladder, and reads Swedish number formats', function () {
    $list = ($this->save)();

    $net = ($this->item)($list, ['price' => '1 234,50']);
    $ladder = ($this->item)($list, ['min_quantity' => '10', 'mode' => 'discount', 'discount' => '12,5']);

    expect($net->price_minor)->toBe(123450)->and($net->discount_bp)->toBeNull()->and($net->min_quantity_milli)->toBe(0)
        ->and($ladder->discount_bp)->toBe(1250)->and($ladder->price_minor)->toBeNull()->and($ladder->min_quantity_milli)->toBe(10000);

    expect(fn () => ($this->item)($list, ['min_quantity' => '5', 'price' => '-1']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['min_quantity' => '5', 'price' => 'abc']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['min_quantity' => '5', 'mode' => 'discount', 'discount' => '101']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['min_quantity' => 'tio']))->toThrow(ValidationException::class);

    $audit = AuditLog::query()->where('action', 'price_list_item.created')->latest('id')->first();
    expect(collect($audit->payload)->keys()->sort()->values()->all())->toBe(['article_id', 'discount_bp', 'price_list_id', 'price_minor']);
});

it('refuses another company\'s article and unit, and an inactive or archived target', function () {
    $list = ($this->save)();

    expect(fn () => ($this->item)($list, ['article_id' => $this->otherArticle->id]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['unit_id' => $this->otherUnit->id]))->toThrow(ValidationException::class);

    $archived = ($this->save)(['code' => 'OLD']);
    app(ArchivePriceList::class)($this->owner, $this->company, $archived);

    expect(fn () => ($this->item)($archived))->toThrow(ValidationException::class);
});

it('accepts a unit that converts to the article\'s and refuses one that does not', function () {
    $list = ($this->save)();
    $metre = Unit::query()->where('code', 'm')->first() ?? Unit::query()->where('code', 'kg')->firstOrFail();

    expect(fn () => ($this->item)($list, ['unit_id' => $metre->id]))->toThrow(ValidationException::class);

    $pieces = Unit::query()->where('code', 'st')->first();

    if ($pieces !== null) {
        expect(($this->item)($list, ['unit_id' => $pieces->id])->unit_id)->toBe($pieces->id);
    }
});

it('refuses overlapping periods for the same article, unit and step but not for another step', function () {
    $list = ($this->save)();
    ($this->item)($list, ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);

    expect(fn () => ($this->item)($list, ['valid_from' => '2026-06-01', 'valid_to' => '2026-12-31']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['valid_from' => null, 'valid_to' => null]))->toThrow(ValidationException::class)
        ->and(($this->item)($list, ['valid_from' => '2026-07-01'])->valid_from->toDateString())->toBe('2026-07-01')
        ->and(($this->item)($list, ['min_quantity' => '10', 'valid_from' => '2026-06-01'])->min_quantity_milli)->toBe(10000);
});

it('does not count the item itself, an archived one or another list as an overlap', function () {
    $list = ($this->save)();
    $other = ($this->save)(['code' => 'ANNAN']);
    $first = ($this->item)($list, ['valid_from' => '2026-07-01']);

    expect(($this->item)($list, ['valid_from' => '2026-07-01', 'price' => '95'], $first)->price_minor)->toBe(9500)
        ->and(($this->item)($other, ['valid_from' => '2026-07-01'])->price_list_id)->toBe($other->id);

    $first->update(['archived_at' => now()]);

    expect(($this->item)($list, ['valid_from' => '2026-03-01'])->exists)->toBeTrue();
});

it('lets a new price follow a running one by ending the old one the day before', function () {
    $list = ($this->save)();
    $old = ($this->item)($list, ['valid_from' => '2026-01-01']);

    $new = ($this->item)($list, ['price' => '110', 'valid_from' => '2026-07-01'], null, $old);

    expect($old->fresh()->valid_to->toDateString())->toBe('2026-06-30')
        ->and($new->valid_to)->toBeNull();

    expect(fn () => ($this->item)($list, ['price' => '120', 'valid_from' => '2026-01-01'], null, $old))->toThrow(ValidationException::class);
});

it('deletes a price that has not started, ends a running one and archives an ended one', function () {
    $list = ($this->save)();
    $retire = app(RetirePriceListItem::class);

    $future = ($this->item)($list, ['valid_from' => '2026-08-01']);
    $running = ($this->item)($list, ['min_quantity' => '5', 'valid_from' => '2026-01-01']);
    $ended = ($this->item)($list, ['min_quantity' => '9', 'valid_from' => '2026-01-01', 'valid_to' => '2026-02-01']);
    $today = ($this->item)($list, ['min_quantity' => '12', 'valid_from' => '2026-06-15']);

    expect($retire($this->owner, $this->company, $future))->toBe(RetirePriceListItem::DELETED)
        ->and(PriceListItem::query()->whereKey($future->id)->exists())->toBeFalse()
        ->and($retire($this->owner, $this->company, $running))->toBe(RetirePriceListItem::ENDED)
        ->and($running->fresh()->valid_to->toDateString())->toBe('2026-06-14')
        ->and($retire($this->owner, $this->company, $ended))->toBe(RetirePriceListItem::ARCHIVED)
        ->and($ended->fresh()->archived_at)->not->toBeNull()
        ->and($retire($this->owner, $this->company, $today))->toBe(RetirePriceListItem::ARCHIVED);
});

it('refuses to archive a list that customers, groups or other lists still use', function () {
    $list = ($this->save)(['code' => 'ATER']);
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'Grupp', 'price_list_id' => $list->id]);
    $follower = ($this->save)(['code' => 'F', 'derived' => true, 'derived_percent' => '3', 'based_on_list_id' => $list->id]);
    $customer = Crm::customer($this->company, $this->owner, 'Kund');
    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, $list->id);
    $archive = app(ArchivePriceList::class);

    expect(fn () => $archive($this->owner, $this->company, $list))->toThrow(ValidationException::class);

    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, null);
    $follower->update(['archived_at' => now()]);
    $group->update(['price_list_id' => null]);

    expect($archive($this->owner, $this->company, $list)->archived_at)->not->toBeNull()
        ->and($archive($this->owner, $this->company, $list, false)->archived_at)->toBeNull();
});

it('refuses to archive a group with customers or one a campaign is aimed at', function () {
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'Grupp']);
    $customer = Crm::customer($this->company, $this->owner, 'Kund');
    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, $group->id, null);
    $archive = app(ArchiveCustomerPriceGroup::class);

    expect(fn () => $archive($this->owner, $this->company, $group))->toThrow(ValidationException::class);

    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, null);
    $campaign = ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30', 'group_ids' => [$group->id]]);

    expect(fn () => $archive($this->owner, $this->company, $group))->toThrow(ValidationException::class);

    ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30'], $campaign);

    expect($archive($this->owner, $this->company, $group)->archived_at)->not->toBeNull();
});

it('assigns group and own list to a customer', function () {
    $list = ($this->save)();
    $group = app(SaveCustomerPriceGroup::class)($this->owner, $this->company, ['code' => 'G', 'name' => 'Grupp', 'price_list_id' => $list->id]);
    $customer = Crm::customer($this->company, $this->owner, 'Kund');

    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, $group->id, $list->id);

    expect($customer->fresh()->only(['price_group_id', 'price_list_id']))->toBe(['price_group_id' => $group->id, 'price_list_id' => $list->id]);

    $campaign = ($this->save)(['code' => 'K', 'kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']);

    expect(fn () => app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, $campaign->id))->toThrow(ValidationException::class);
});

it('denies the bookkeeper and the auditor any change and allows the accountant', function () {
    $list = ($this->save)();
    $input = ['code' => 'NY', 'name' => 'Ny', 'kind' => 'STANDARD', 'currency' => 'SEK'];

    foreach (['bookkeeper', 'auditor'] as $role) {
        $user = Bookkeeping::member($this->company, $role);
        $company = $this->company->fresh();

        expect(fn () => app(SavePriceList::class)($user, $company, $input))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SavePriceListItem::class)($user, $company, $list, ['article_id' => $this->article->id, 'mode' => 'price', 'price' => '1']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveCustomerPriceGroup::class)($user, $company, ['code' => 'G', 'name' => 'G']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ArchivePriceList::class)($user, $company, $list))->toThrow(AuthorizationException::class);
    }

    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(app(SavePriceList::class)($accountant, $this->company->fresh(), $input)->code)->toBe('NY');
});

it('lets only a price that has not started be edited, and only the end of a running one be set', function () {
    $list = ($this->save)();
    $future = ($this->item)($list, ['valid_from' => '2026-07-01']);
    $running = ($this->item)($list, ['min_quantity' => '5', 'valid_from' => '2026-01-01']);
    $ended = ($this->item)($list, ['min_quantity' => '9', 'valid_from' => '2026-01-01', 'valid_to' => '2026-02-01']);

    expect(($this->item)($list, ['valid_from' => '2026-08-01', 'price' => '70'], $future)->price_minor)->toBe(7000);

    foreach ([['price' => '70'], ['mode' => 'discount', 'discount' => '5'], ['valid_from' => '2026-02-01'], ['valid_from' => null]] as $change) {
        expect(fn () => ($this->item)($list, ['min_quantity' => '5', 'valid_from' => '2026-01-01', ...$change], $running))->toThrow(ValidationException::class);
    }

    expect(fn () => ($this->item)($list, ['min_quantity' => '5', 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'], $running))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['min_quantity' => '9', 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-01'], $ended))->toThrow(ValidationException::class);

    ($this->item)($list, ['min_quantity' => '5', 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30'], $running);

    expect($running->fresh()->valid_to->toDateString())->toBe('2026-06-30')
        ->and($running->fresh()->price_minor)->toBe(9000);
});

it('refuses to end a price that is not the one the new price follows', function () {
    $list = ($this->save)();
    $other = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-2', 'name' => 'Mutter', 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500]);
    $running = ($this->item)($list, ['valid_from' => '2026-01-01']);
    $unrelated = ($this->item)($list, ['article_id' => $other->id, 'valid_from' => '2026-01-01']);

    expect(fn () => ($this->item)($list, ['price' => '99', 'valid_from' => '2026-07-01'], null, $unrelated))->toThrow(ValidationException::class)
        ->and(fn () => ($this->item)($list, ['min_quantity' => '10', 'price' => '99', 'valid_from' => '2026-07-01'], null, $running))->toThrow(ValidationException::class)
        ->and($unrelated->fresh()->valid_to)->toBeNull();
});

it('refuses to archive the default list of a currency', function () {
    $list = ($this->save)(['is_default_for_currency' => true]);

    expect(fn () => app(ArchivePriceList::class)($this->owner, $this->company, $list))->toThrow(ValidationException::class);

    ($this->save)(['code' => 'ANNAN', 'is_default_for_currency' => true]);

    expect(app(ArchivePriceList::class)($this->owner, $this->company, $list->fresh())->archived_at)->not->toBeNull();
});

it('refuses to make a list a campaign while other lists follow it', function () {
    $list = ($this->save)();
    ($this->save)(['code' => 'F', 'derived' => true, 'derived_percent' => '3', 'based_on_list_id' => $list->id]);

    expect(fn () => ($this->save)(['kind' => 'CAMPAIGN', 'valid_from' => '2026-06-01', 'valid_to' => '2026-06-30'], $list))->toThrow(ValidationException::class);
});

it('refuses a customer list in another currency than the customer\'s', function () {
    $euro = ($this->save)(['code' => 'EUR', 'currency' => 'EUR']);
    $customer = Crm::customer($this->company, $this->owner, 'Kund');

    expect(fn () => app(AssignCustomerPrices::class)($this->owner, $this->company, $customer, null, $euro->id))->toThrow(ValidationException::class);

    $customer->update(['currency' => 'EUR']);
    app(AssignCustomerPrices::class)($this->owner, $this->company, $customer->fresh(), null, $euro->id);

    expect($customer->fresh()->price_list_id)->toBe($euro->id);
});
