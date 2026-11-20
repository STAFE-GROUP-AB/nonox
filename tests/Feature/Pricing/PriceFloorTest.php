<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendSalesDocument;
use App\Actions\Pricing\DecidePriceFloorApproval;
use App\Actions\Pricing\RequestPriceFloorApproval;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\ArticleCostBook;
use App\Domain\Pricing\DocumentMargin;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceFloorApprovalStatus;
use App\Domain\Pricing\PriceFloorDocument;
use App\Domain\Pricing\PriceFloorFingerprint;
use App\Domain\Pricing\PriceFloorGuard;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Domain\Pricing\RowMargin;
use App\Domain\Units\ArticleUnits;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PriceFloorApproval;
use App\Models\SalesDocument;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\PricingScenario;

/*
 * ADR-082 §9 (P7): cost per row from the costing, below-cost and below-floor
 * checks, the company's floor (off by default), and the approval of a
 * document's manual prices under it before it is sent or issued.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

    $this->scenario = PricingScenario::build([
        'articles' => ['A1' => ['price_ore' => 10000], 'A2' => ['price_ore' => 20000], 'K1' => ['price_ore' => 10000, 'unit' => 'kg']],
        'customer' => [],
    ]);
    $this->owner = $this->scenario->owner;
    $this->company = $this->scenario->company;
    $this->customer = $this->scenario->customer;
    $this->writer = Bookkeeping::member($this->company, 'bookkeeper');
    $this->approver = Bookkeeping::member($this->company, 'accountant');
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
    $this->actingAs($this->owner);

    // A1 costs 80,00 to buy; A2 has no known cost.
    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 8000]);
    Article::query()->whereKey($this->scenario->articles['K1'])->update(['purchase_price_ore' => 8000]);
});

function p7Floor(?string $percent = '15', PriceFloorPolicy $policy = PriceFloorPolicy::RequireApproval): void
{
    app(SetPriceFloor::class)(test()->owner, test()->company->fresh(), $percent, $policy);
    test()->company = test()->company->fresh();
}

/** @param  array<string, mixed>  $row */
function p7Row(array $row = []): array
{
    return ['article' => 'A1', 'description' => 'Artikel A1', 'quantity' => '1', 'vat_rate' => 2500, ...$row];
}

/** @param  list<array<string, mixed>>  $rows */
function p7Draft(array $rows, ?Invoice $draft = null): Invoice
{
    $s = test();

    return app(SaveDraftInvoice::class)($s->writer, $s->company, ['customer_id' => $s->customer->id, 'invoice_date' => '2026-03-15', 'rows' => $rows], $draft);
}

/** A manual price of 90,00 on A1: 11,1 % over its cost of 80,00, under a 15 % floor. */
function p7UnderFloor(array $extra = []): array
{
    return p7Row(['price' => '90,00', 'price_source' => 'MANUAL', ...$extra]);
}

it('knows a row is under cost or under the floor without rounding the margin', function () {
    // 15 % floor on a cost of 8 000: the lowest price is 9 411,76 öre.
    expect(RowMargin::of(9411, 8000, 1500)->belowFloor)->toBeTrue()
        ->and(RowMargin::of(9412, 8000, 1500)->belowFloor)->toBeFalse()
        ->and(RowMargin::of(7999, 8000, 1500)->belowCost)->toBeTrue()
        ->and(RowMargin::of(8000, 8000, null)->belowCost)->toBeFalse()
        ->and(RowMargin::of(8000, 8000, null)->belowFloor)->toBeFalse();
});

it('does not check a row without a known cost, a return, or a company without a floor', function () {
    expect(RowMargin::of(1000, null, 1500))->belowFloor->toBeFalse()->belowCost->toBeFalse()
        ->and(RowMargin::of(-5000, 8000, 1500))->belowFloor->toBeFalse()->belowCost->toBeFalse()
        ->and(RowMargin::of(0, 8000, 1500))->belowCost->toBeTrue()->belowFloor->toBeTrue()
        ->and(RowMargin::of(9000, 8000, null))->belowFloor->toBeFalse();
});

it('gives a row its contribution and contribution margin', function () {
    $row = RowMargin::of(10000, 8000, null);

    expect($row->contributionOre())->toBe(2000)->and($row->marginBp())->toBe(2000);
});

it('costs a row from the purchase price, converted to the row\'s unit', function () {
    $id = $this->scenario->articles['K1'];
    $book = ArticleCostBook::for($this->company, [$id]);

    expect($book->costOf($id, '2', 'kg'))->toBe(16000)
        ->and($book->costOf($id, '500', 'g'))->toBe(4000)
        ->and($book->costOf($id, '1', 'st'))->toBeNull()
        ->and($book->costOf(999999, '1', 'kg'))->toBeNull();
});

it('uses a conversion the article has to cost a row in another unit', function () {
    $article = Article::query()->whereKey($this->scenario->articles['A1'])->firstOrFail();
    ArticleUnits::saveManual($article->load('stockUnit'), 'förp', BigRational::of('1/10'));

    expect(ArticleCostBook::for($this->company, [$article->id])->costOf($article->id, '1', 'förp'))->toBe(80000);
});

it('adds up the contribution of a document over the rows whose cost is known', function () {
    $margin = DocumentMargin::assess($this->company, [
        ['article_id' => $this->scenario->articles['A1'], 'quantity' => '2', 'unit' => 'st', 'net_ore' => 20000, 'manual' => false],
        ['article_id' => $this->scenario->articles['A2'], 'quantity' => '1', 'unit' => 'st', 'net_ore' => 20000, 'manual' => false],
        ['article_id' => null, 'quantity' => '1', 'unit' => 'st', 'net_ore' => 5000, 'manual' => false],
    ]);

    expect($margin->netOre)->toBe(20000)
        ->and($margin->costOre)->toBe(16000)
        ->and($margin->contributionOre())->toBe(4000)
        ->and($margin->marginBp())->toBe(2000)
        ->and($margin->uncosted)->toBe(2);
});

it('is off by default and costs nothing while it is off', function () {
    expect(PriceFloor::of($this->company)->isOn())->toBeFalse()
        ->and($this->company->price_floor_policy)->toBe(PriceFloorPolicy::Warn);

    $invoice = p7Draft([p7UnderFloor()]);

    expect(PriceFloorGuard::applies($this->company, $invoice))->toBeFalse();

    $issued = app(IssueInvoice::class)($this->owner, $invoice);

    expect($issued->number)->not->toBeNull();
});

it('only warns when the policy is to warn', function () {
    p7Floor('15', PriceFloorPolicy::Warn);

    $issued = app(IssueInvoice::class)($this->owner, p7Draft([p7UnderFloor()]));

    expect($issued->number)->not->toBeNull();
});

it('refuses to issue an invoice with a manual price under the floor until it is approved', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);

    expect(fn () => app(IssueInvoice::class)($this->owner, $invoice))->toThrow(ValidationException::class, 'lägsta täckningsgrad');

    expect($invoice->fresh()->isDraft())->toBeTrue();
});

it('names the lines and the floor but never a cost in the refusal', function () {
    p7Floor();
    $invoice = p7Draft([p7Row(), p7UnderFloor()]);

    try {
        app(IssueInvoice::class)($this->owner, $invoice);
        $this->fail('Expected a refusal.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['invoice'][0];

        expect($message)->toContain('rad 2')->toContain('15 %')->not->toContain('80')->not->toContain('kostar');
    }
});

it('issues once someone else has approved the version, and refuses again when a row changes', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);

    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    expect($approval->fresh()->status)->toBe(PriceFloorApprovalStatus::Approved)
        ->and(PriceFloorGuard::state($this->company, $invoice->fresh())->cleared())->toBeTrue();

    // A changed price is another version.
    $changed = p7Draft([p7UnderFloor(['price' => '85,00'])], $invoice);

    expect(PriceFloorGuard::state($this->company, $changed)->cleared())->toBeFalse()
        ->and(fn () => app(IssueInvoice::class)($this->owner, $changed))->toThrow(ValidationException::class);
});

it('issues an approved invoice', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    $issued = app(IssueInvoice::class)($this->owner, $invoice->fresh());

    expect($issued->number)->not->toBeNull();
});

it('lets prices from a list or the base price through: only typed prices need approval', function () {
    p7Floor();
    // The base price 100,00 is 20 % over cost; a base price under the floor is still not a typed one.
    Article::query()->whereKey($this->scenario->articles['A1'])->update(['purchase_price_ore' => 9500]);

    $issued = app(IssueInvoice::class)($this->owner, p7Draft([p7Row()]));

    expect($issued->number)->not->toBeNull();
});

it('holds an invoice in another currency for its typed prices but never a credit invoice', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);

    expect(PriceFloorGuard::applies($this->company, $invoice))->toBeTrue();

    $foreign = $invoice->replicate();
    $foreign->invoice_currency = 'EUR';
    $credit = $invoice->replicate();
    $credit->kind = Invoice::CREDIT;

    expect(PriceFloorGuard::applies($this->company, $foreign))->toBeTrue()
        ->and(PriceFloorGuard::applies($this->company, $credit))->toBeFalse();
});

it('holds a quote or an order from being sent until its prices are approved', function () {
    p7Floor();
    $document = app(SaveSalesDocument::class)($this->writer, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-03-15',
        'rows' => [p7UnderFloor()],
    ]);

    expect(fn () => app(SendSalesDocument::class)($this->owner, $document, 'kund@example.com'))->toThrow(ValidationException::class, 'lägsta täckningsgrad');

    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Quote, $document->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    app(SendSalesDocument::class)($this->owner, $document->fresh(), 'kund@example.com');

    expect($document->fresh()->sent_at)->not->toBeNull();
});

it('asks for nothing when no price is under the floor', function () {
    p7Floor();
    $invoice = p7Draft([p7Row()]);

    expect(fn () => app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id))
        ->toThrow(ValidationException::class);
});

it('keeps one open request per document and withdraws the one for an earlier version', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $first = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    $again = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);

    expect($again->id)->toBe($first->id);

    $changed = p7Draft([p7UnderFloor(['price' => '85,00'])], $invoice);
    $second = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $changed->id);

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe(PriceFloorApprovalStatus::Withdrawn)
        ->and($second->fingerprint)->toBe(PriceFloorFingerprint::of($changed->fresh()));
});

it('does not let the person who wrote the document or asked for the approval approve it', function () {
    p7Floor();

    // The accountant wrote the invoice and asked: another accountant must approve.
    $own = app(SaveDraftInvoice::class)($this->accountant, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [p7UnderFloor()]]);
    $ownApproval = app(RequestPriceFloorApproval::class)($this->accountant, $this->company, PriceFloorDocument::Invoice, $own->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->accountant, $this->company, $ownApproval->id, true))->toThrow(ValidationException::class, 'skrivit');

    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $ownApproval->id, true);

    expect($ownApproval->fresh()->status)->toBe(PriceFloorApprovalStatus::Approved);

    // The bookkeeper wrote it and the accountant only asked: the accountant may not approve either, the other one may.
    $theirs = p7Draft([p7UnderFloor()]);
    $asked = app(RequestPriceFloorApproval::class)($this->accountant, $this->company, PriceFloorDocument::Invoice, $theirs->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->accountant, $this->company, $asked->id, true))->toThrow(ValidationException::class, 'begärt');

    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $asked->id, true);

    expect($asked->fresh()->status)->toBe(PriceFloorApprovalStatus::Approved);
});

it('does not let someone who changed an invoice draft approve it', function () {
    p7Floor();
    $draft = p7Draft([p7UnderFloor()]);
    app(SaveDraftInvoice::class)($this->accountant, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [p7UnderFloor()]], $draft);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $draft->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->accountant, $this->company, $approval->id, true))->toThrow(ValidationException::class, 'skrivit');
});

it('lets the writer approve their own prices when the company allows one person to do both', function () {
    p7Floor();
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $accountant = $this->accountant;
    $invoice = app(SaveDraftInvoice::class)($accountant, $this->company->fresh(), ['customer_id' => $this->customer->id, 'invoice_date' => '2026-03-15', 'rows' => [p7UnderFloor()]]);
    $approval = app(RequestPriceFloorApproval::class)($accountant, $this->company->fresh(), PriceFloorDocument::Invoice, $invoice->id);

    app(DecidePriceFloorApproval::class)($accountant, $this->company->fresh(), $approval->id, true);

    expect($approval->fresh()->status)->toBe(PriceFloorApprovalStatus::Approved);
});

it('needs prices:approve-below-floor to decide', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->writer, $this->company, $approval->id, true))->toThrow(AuthorizationException::class);
});

it('needs a reason to reject and keeps the invoice held', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, false))->toThrow(ValidationException::class);

    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, false, 'För lågt.');

    $state = PriceFloorGuard::state($this->company, $invoice->fresh());

    expect($state->rejected())->toBeTrue()
        ->and($state->approval->reason)->toBe('För lågt.')
        ->and($state->cleared())->toBeFalse();
});

it('refuses to approve a version that has changed since it was asked for', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);

    p7Draft([p7UnderFloor(['price' => '85,00'])], $invoice);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true))->toThrow(ValidationException::class, 'ändrats');

    expect($approval->fresh()->status)->toBe(PriceFloorApprovalStatus::Pending);
});

it('does not decide an approval twice', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, true);

    expect(fn () => app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, false, 'Nej'))->toThrow(ValidationException::class);
});

it('writes ids and field names only to the audit log', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);
    app(DecidePriceFloorApproval::class)($this->approver, $this->company, $approval->id, false, 'Hemlig anledning med namn Anna.');

    $entries = AuditLog::query()->whereIn('action', ['price_floor.changed', 'price_floor.requested', 'price_floor.rejected'])->get();

    expect($entries->pluck('action')->all())->toContain('price_floor.changed', 'price_floor.requested', 'price_floor.rejected')
        ->and(json_encode($entries->pluck('payload')->all()))->not->toContain('Anna');
});

it('sets the floor with permission and switches it off with no percentage', function () {
    $company = app(SetPriceFloor::class)($this->owner, $this->company, '12,5', PriceFloorPolicy::Warn);

    expect($company->min_contribution_bp)->toBe(1250)->and(PriceFloor::of($company)->label())->toBe('12,5 %');

    $company = app(SetPriceFloor::class)($this->owner, $company, '', PriceFloorPolicy::Warn);

    expect($company->min_contribution_bp)->toBeNull()->and(PriceFloor::of($company)->isOn())->toBeFalse();

    expect(fn () => app(SetPriceFloor::class)($this->writer, $this->company, '10', PriceFloorPolicy::Warn))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetPriceFloor::class)($this->owner, $this->company, '100', PriceFloorPolicy::Warn))->toThrow(ValidationException::class)
        ->and(fn () => app(SetPriceFloor::class)($this->owner, $this->company, 'abc', PriceFloorPolicy::Warn))->toThrow(ValidationException::class);
});

it('does not find another company\'s approval', function () {
    p7Floor();
    $invoice = p7Draft([p7UnderFloor()]);
    $approval = app(RequestPriceFloorApproval::class)($this->writer, $this->company, PriceFloorDocument::Invoice, $invoice->id);

    [$otherOwner, $otherCompany] = Bookkeeping::company();

    expect(fn () => app(DecidePriceFloorApproval::class)($otherOwner, $otherCompany, $approval->id, true))->toThrow(ModelNotFoundException::class);

    CurrentCompany::set($otherCompany);
    expect(PriceFloorApproval::query()->count())->toBe(0);
});

it('checks a document in the same number of queries whatever the number of rows', function () {
    p7Floor();
    $template = Article::query()->whereKey($this->scenario->articles['A1'])->firstOrFail();
    $rows = [];

    foreach (range(1, 120) as $i) {
        $copy = $template->replicate();
        $copy->number = "C{$i}";
        $copy->purchase_price_ore = 5000 + $i;
        $copy->save();
        $rows[] = p7Row(['article' => "C{$i}", 'description' => "C{$i}", 'price' => '55,00', 'price_source' => 'MANUAL']);
    }

    $short = p7Draft(array_slice($rows, 0, 1));
    $long = p7Draft($rows);

    $count = function (Invoice $invoice): int {
        $invoice = $invoice->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        PriceFloorGuard::state(test()->company, $invoice);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect(PriceFloorGuard::state($this->company, $short->fresh())->needed)->toBeTrue()
        ->and($count($long))->toBe($count($short));
});
