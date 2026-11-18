<?php

declare(strict_types=1);

use App\Actions\Group\EditIntercompanyTags;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\RefreshIntercompanyTags;
use App\Actions\Group\TagIntercompanyRows;
use App\Actions\Group\UnmarkIntercompanyCounterparty;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanySide;
use App\Domain\Group\IntercompanyTagSource;
use App\Livewire\Group\IntercompanyPage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for intercompany counterparties and row tags (ADR-060,
 * addendum 2026-11-07, 2.3; ADR-081 E23). Company A and company B are both
 * group companies and both mark each other; Olle owns A and is an
 * administrator in B too. Working in A, nothing he does reads, writes or
 * reveals B's markings, tags, customers or voucher rows: B's ids answer 404,
 * and the database refuses a tag or marking that points across companies.
 * Nothing is ever written into B's books.
 */

function e23TenancyOrg(string $serial): string
{
    $nine = '559997'.str_pad($serial, 3, '0', STR_PAD_LEFT);

    return substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
}

/** @return array{customer: Customer, marking: IntercompanyCounterparty, tag: IntercompanyRowTag} */
function e23TenancyCompanyWithTags(User $user, Company $company, string $customerName, string $customerOrg): array
{
    return CurrentCompany::run($company, function () use ($user, $company, $customerName, $customerOrg): array {
        $customer = app(SaveCustomer::class)($user, $company, ['name' => $customerName, 'org_number' => $customerOrg, 'payment_terms_days' => 30]);
        app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-03-02',
            'rows' => [['description' => 'Tjänster inom koncernen', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
        ]));
        $marking = app(MarkIntercompanyCounterparty::class)($user, $company, IntercompanySide::Customer, $customer->id);
        app(RefreshIntercompanyTags::class)($user, $company);
        Bookkeeping::postRows($user, $company, '2026-03-03', [1930 => 10_000_00, 2393 => -10_000_00]);

        return ['customer' => $customer, 'marking' => $marking, 'tag' => IntercompanyRowTag::query()->orderBy('id')->firstOrFail()];
    });
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    [$this->olle, $this->a] = Bookkeeping::company();
    [$this->bOwner, $this->b] = Bookkeeping::company();
    $this->b->users()->attach($this->olle, ['role' => 'admin']);
    $this->olle = $this->olle->fresh();

    $this->inB = e23TenancyCompanyWithTags($this->bOwner, $this->b, 'Hemliga Kunden B AB', e23TenancyOrg('201'));
    $this->bTags = CurrentCompany::run($this->b, fn (): array => IntercompanyRowTag::query()->orderBy('id')->get()->map(fn (IntercompanyRowTag $tag): array => $tag->only(['id', 'voucher_row_id', 'kind', 'counterparty_org_number', 'source']))->all());
    $this->inA = e23TenancyCompanyWithTags($this->olle, $this->a, 'Kunden A AB', e23TenancyOrg('202'));

    $this->olle->switchCompany($this->a);
    CurrentCompany::set($this->a);
    $this->actingAs($this->olle->fresh());
});

it('answers 404 for another company\'s customer, marking and tag ids, in the actions and on the page', function () {
    expect(fn () => app(MarkIntercompanyCounterparty::class)($this->olle, $this->a, IntercompanySide::Customer, $this->inB['customer']->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(UnmarkIntercompanyCounterparty::class)($this->olle, $this->a, $this->inB['marking']->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(EditIntercompanyTags::class)->change($this->olle, $this->a, $this->inB['tag']->id, IntercompanyKind::Payable))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(EditIntercompanyTags::class)->remove($this->olle, $this->a, $this->inB['tag']->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(IntercompanyPage::class)->call('choose', $this->inB['customer']->id)->assertNotFound();
    Livewire::test(IntercompanyPage::class)->call('unmark', $this->inB['marking']->id)->assertNotFound();
    Livewire::test(IntercompanyPage::class)->call('removeTag', $this->inB['tag']->id)->assertNotFound();
    Livewire::test(IntercompanyPage::class)->call('changeTag', $this->inB['tag']->id, 'PAYABLE')->assertNotFound();

    expect(CurrentCompany::run($this->b, fn () => IntercompanyCounterparty::query()->whereKey($this->inB['marking']->id)->exists()))->toBeTrue();
});

it('never shows another company\'s markings, tags or customers on the page', function () {
    $this->get(route('accounting.intercompany'))
        ->assertOk()
        ->assertSee('Kunden A AB')
        ->assertSee(e23TenancyOrg('202'))
        ->assertDontSee('Hemliga Kunden B AB')
        ->assertDontSee(e23TenancyOrg('201'));

    Livewire::test(IntercompanyPage::class)
        ->set('markSearch', 'Hemliga')
        ->assertDontSee('Hemliga Kunden B AB')
        ->set('filterOrg', e23TenancyOrg('201'))
        ->assertSee(__('Inga markerade rader i urvalet.'));
});

it('refreshes and tags in bulk only the company\'s own rows, and leaves the other company\'s tags as they were', function () {
    app(MarkIntercompanyCounterparty::class)($this->olle, $this->a, IntercompanySide::Customer, $this->inA['customer']->id, e23TenancyOrg('203'));
    app(RefreshIntercompanyTags::class)($this->olle, $this->a);
    app(TagIntercompanyRows::class)($this->olle, $this->a, ['account' => '2393', 'from' => '2026-01-01', 'to' => '2026-12-31', 'counterparty_org_number' => e23TenancyOrg('201'), 'kind' => 'LOAN_PAYABLE']);
    app(EditIntercompanyTags::class)->removeSelected($this->olle, $this->a, ['account' => '3001', 'from' => '2026-01-01', 'to' => '2026-12-31']);

    $bNow = CurrentCompany::run($this->b, fn (): array => IntercompanyRowTag::query()->orderBy('id')->get()->map(fn (IntercompanyRowTag $tag): array => $tag->only(['id', 'voucher_row_id', 'kind', 'counterparty_org_number', 'source']))->all());

    expect($bNow)->toEqual($this->bTags)
        ->and(DB::table('intercompany_row_tags')->where('company_id', $this->a->id)->whereIn('voucher_row_id', DB::table('voucher_rows')->where('company_id', $this->b->id)->select('id'))->exists())->toBeFalse()
        ->and(DB::table('intercompany_row_tags')->where('company_id', $this->b->id)->whereIn('voucher_row_id', DB::table('voucher_rows')->where('company_id', $this->a->id)->select('id'))->exists())->toBeFalse()
        ->and(IntercompanyRowTag::query()->where('kind', IntercompanyKind::LoanPayable->value)->count())->toBe(1);
});

it('refuses in the database a tag on another company\'s row and a marking of another company\'s customer', function () {
    $bRow = DB::table('voucher_rows')->where('company_id', $this->b->id)->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('intercompany_row_tags')->insert([
        'company_id' => $this->a->id, 'voucher_row_id' => $bRow, 'counterparty_org_number' => e23TenancyOrg('201'),
        'kind' => IntercompanyKind::Sales->value, 'source' => IntercompanyTagSource::Manual->value, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, 'same company')
        ->and(fn () => DB::transaction(fn () => DB::table('intercompany_counterparties')->insert([
            'company_id' => $this->a->id, 'customer_id' => $this->inB['customer']->id, 'counterparty_org_number' => e23TenancyOrg('201'), 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(Exception::class, 'same company')
        ->and(fn () => DB::transaction(fn () => DB::table('intercompany_row_tags')->where('id', $this->inA['tag']->id)->update(['voucher_row_id' => $bRow])))->toThrow(Exception::class, 'same company');
});

it('keeps no other tenant\'s id in either table', function () {
    expect(Schema::getColumnListing('intercompany_counterparties'))->toEqualCanonicalizing(['id', 'company_id', 'customer_id', 'supplier_id', 'counterparty_org_number', 'created_by', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('intercompany_row_tags'))->toEqualCanonicalizing(['id', 'company_id', 'voucher_row_id', 'counterparty_org_number', 'kind', 'source', 'tagged_by', 'created_at', 'updated_at'])
        ->and(DB::table('intercompany_counterparties')->where('company_id', $this->a->id)->pluck('counterparty_org_number')->all())->not->toContain((string) $this->b->id);
});
