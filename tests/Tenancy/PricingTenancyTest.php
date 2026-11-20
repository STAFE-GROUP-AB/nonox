<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for prices (ADR-082 P1): company A never sees company B's
 * price lists, items, groups, agreements, update runs or base price history,
 * not by a query, not by id (404, not 403), not through a policy, and not
 * through a reference that points across companies.
 */

beforeEach(function () {
    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();

        CurrentCompany::run($company, function () use ($owner, $company, $side): void {
            $article = app(SaveArticle::class)($owner, $company, ['number' => "H-{$side}", 'name' => 'Hemlig hylla', 'unit' => 'st', 'price' => '149', 'vat_rate' => 2500]);
            app(SaveArticle::class)($owner, $company, ['number' => "H-{$side}", 'name' => 'Hemlig hylla', 'unit' => 'st', 'price' => '159', 'vat_rate' => 2500], $article);
            $list = PriceList::create(['code' => 'HEMLIG', 'name' => "Hemlig lista {$side}", 'created_by' => $owner->id]);
            $item = PriceListItem::create(['price_list_id' => $list->id, 'article_id' => $article->id, 'price_minor' => 12_300]);
            $group = CustomerPriceGroup::create(['code' => 'HEMLIG', 'name' => "Hemlig grupp {$side}", 'price_list_id' => $list->id]);
            $customer = Crm::customer($company, $owner, "Hemlig kund {$side}");
            $agreement = PriceAgreement::create(['number' => 1, 'customer_id' => $customer->id, 'title' => "Hemligt avtal {$side}", 'valid_from' => '2026-01-01', 'created_by' => $owner->id]);
            $agreementItem = PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $article->id, 'price_minor' => 9_900]);
            $run = PriceUpdateRun::create(['kind' => 'PERCENT', 'scope' => ['price_list_ids' => [$list->id]], 'rule' => ['percent_bp' => 400], 'effective_date' => '2027-01-01', 'created_by' => $owner->id]);

            $this->{$side} = compact('owner', 'company', 'article', 'list', 'item', 'group', 'customer', 'agreement', 'agreementItem', 'run');
        });
    }

    CurrentCompany::forget();
    CurrentCompany::set($this->a['company']);
});

/** @return array<class-string, string> each pricing model and the key of its record in the fixture */
function p1Records(): array
{
    return [
        PriceList::class => 'list',
        PriceListItem::class => 'item',
        CustomerPriceGroup::class => 'group',
        PriceAgreement::class => 'agreement',
        PriceAgreementItem::class => 'agreementItem',
        PriceUpdateRun::class => 'run',
    ];
}

it('lists only the company\'s own prices', function () {
    foreach (p1Records() as $model => $key) {
        expect($model::query()->pluck('id')->all())->toBe([$this->a[$key]->id], $model);
    }

    expect(ArticlePriceHistory::query()->pluck('article_id')->unique()->values()->all())->toBe([$this->a['article']->id])
        ->and(ArticlePriceHistory::query()->count())->toBe(2)
        ->and(PriceList::query()->pluck('name')->all())->toBe(['Hemlig lista a']);
});

it('answers 404 for another company\'s price records by id', function () {
    $user = Bookkeeping::member($this->a['company'], 'admin');

    foreach (p1Records() as $model => $key) {
        Route::middleware(['web', 'auth'])->get('/p1-tenancy/'.class_basename($model).'/{record}', fn (string $record) => $model::findOrFail($record)->getKey());

        $this->actingAs($user)->get('/p1-tenancy/'.class_basename($model)."/{$this->b[$key]->id}")->assertNotFound();
        $this->actingAs($user)->get('/p1-tenancy/'.class_basename($model)."/{$this->a[$key]->id}")->assertOk();
    }
});

it('refuses another company\'s records through the policies, even to an admin', function () {
    $admin = Bookkeeping::member($this->a['company'], 'admin');

    foreach ([$this->b['list'], $this->b['group'], $this->b['agreement']] as $foreign) {
        expect($admin->can('view', $foreign))->toBeFalse()
            ->and($admin->can('update', $foreign))->toBeFalse();
    }

    foreach ([$this->a['list'], $this->a['group'], $this->a['agreement']] as $own) {
        expect($admin->can('view', $own))->toBeTrue();
    }
});

it('never follows a reference into another company', function () {
    // References written past the actions (which validate them, P4/P5) still lead nowhere.
    DB::table('customers')->where('id', $this->a['customer']->id)->update(['price_list_id' => $this->b['list']->id, 'price_group_id' => $this->b['group']->id]);
    DB::table('customer_price_groups')->where('id', $this->a['group']->id)->update(['price_list_id' => $this->b['list']->id]);
    DB::table('price_agreement_items')->where('id', $this->a['agreementItem']->id)->update(['article_id' => $this->b['article']->id]);

    $customer = Customer::query()->findOrFail($this->a['customer']->id);

    expect($customer->priceList)->toBeNull()
        ->and($customer->priceGroup)->toBeNull()
        ->and(CustomerPriceGroup::query()->findOrFail($this->a['group']->id)->priceList)->toBeNull()
        ->and(PriceAgreementItem::query()->findOrFail($this->a['agreementItem']->id)->article)->toBeNull()
        ->and(PriceList::query()->findOrFail($this->a['list']->id)->items()->pluck('id')->all())->toBe([$this->a['item']->id])
        ->and(Article::query()->findOrFail($this->a['article']->id)->priceHistory()->count())->toBe(2);
});

it('writes the base price history in the company the article belongs to', function () {
    $own = CurrentCompany::run($this->b['company'], fn () => ArticlePriceHistory::query()->get());

    expect($own->pluck('company_id')->unique()->all())->toBe([$this->b['company']->id])
        ->and($own->pluck('article_id')->unique()->all())->toBe([$this->b['article']->id])
        ->and($own->pluck('changed_by')->unique()->all())->toBe([$this->b['owner']->id]);
});
