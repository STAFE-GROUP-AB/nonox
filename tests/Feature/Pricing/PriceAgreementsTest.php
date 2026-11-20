<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\DeletePriceAgreement;
use App\Actions\Pricing\NewPriceAgreementVersion;
use App\Actions\Pricing\RemovePriceAgreementItem;
use App\Actions\Pricing\ReopenPriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SendPriceAgreementForSigning;
use App\Actions\Pricing\TerminatePriceAgreement;
use App\Domain\Overview\Todos\PriceAgreementTodos;
use App\Domain\Pricing\PriceAgreementActivation;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Models\AuditLog;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * ADR-082 P5: price agreements (Prisavtal). A draft is edited freely; sent
 * for signing it freezes; signed (or activated by hand with a reason) it
 * becomes ACTIVE and prices documents through the unchanged resolver. A
 * change is a new version that supersedes the old one, which ends the day
 * before. Only a draft may be deleted, at every level. pricing:expire ends
 * agreements whose last day has passed; Översikt reminds 30 days before.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->other = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-2', 'name' => 'Mutter', 'unit' => 'st', 'price' => '50', 'vat_rate' => 2500]);
    $this->customer = Crm::customer($this->company, $this->owner, 'Byggbolaget AB', ['email' => 'inkop@example.com', 'org_number' => '556036-0793']);
});

/** @param  array<string, mixed>  $extra */
function p5Agreement(array $extra = []): PriceAgreement
{
    $test = test();

    return app(SavePriceAgreement::class)($test->owner, $test->company, [
        'customer_id' => $test->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01', ...$extra,
    ]);
}

/** @param  array<string, mixed>  $input */
function p5Term(PriceAgreement $agreement, array $input = []): PriceAgreementItem
{
    $test = test();

    return app(SavePriceAgreementItem::class)($test->owner, $test->company, $agreement, [
        'target' => 'article', 'article_id' => $test->article->id, 'mode' => 'price', 'price' => '90', ...$input,
    ]);
}

function p5Active(array $extra = [], array $term = []): PriceAgreement
{
    $test = test();
    $agreement = p5Agreement($extra);
    p5Term($agreement, $term);

    return app(ActivatePriceAgreement::class)($test->owner, $test->company, $agreement, 'Undertecknat på papper.');
}

function p5Price(string $date, ?int $articleId = null): ?int
{
    $test = test();
    $context = new PriceContext((int) $test->company->id, $date, 'SEK', $test->customer->id);

    return app(PriceResolver::class)->resolveMany($context, [new PriceRequest('r', $articleId ?? $test->article->id)])['r']->unitPriceMinor;
}

function p5Refused(Closure $write): void
{
    try {
        DB::transaction($write);
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('cannot be deleted');

        return;
    }

    test()->fail('The database accepted the write.');
}

it('numbers agreements PA-1, PA-2 per company and starts each as a draft', function () {
    $first = p5Agreement();
    $second = p5Agreement(['title' => 'Tillägg']);

    expect($first->reference())->toBe('PA-1')
        ->and($second->reference())->toBe('PA-2')
        ->and($first->status)->toBe(PriceAgreementStatus::Draft)
        ->and(AuditLog::query()->where('action', 'price_agreement.created')->count())->toBe(2);
});

it('refuses a customer, list or article of another company as not found', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, fn () => Crm::customer($otherCompany, $otherOwner, 'Hemlig AB'));
    $foreignArticle = CurrentCompany::run($otherCompany, fn () => app(SaveArticle::class)($otherOwner, $otherCompany, ['number' => 'X', 'name' => 'Hemlig', 'unit' => 'st', 'price' => '1', 'vat_rate' => 2500]));
    $foreignList = CurrentCompany::run($otherCompany, fn () => app(SavePriceList::class)($otherOwner, $otherCompany, ['code' => 'X', 'name' => 'X', 'kind' => 'STANDARD', 'currency' => 'SEK']));
    CurrentCompany::set($this->company);

    expect(fn () => p5Agreement(['customer_id' => $foreign->id]))->toThrow(ValidationException::class, 'Kunden finns inte.');

    $agreement = p5Agreement();
    expect(fn () => p5Term($agreement, ['article_id' => $foreignArticle->id]))->toThrow(ValidationException::class, 'Artikeln finns inte.')
        ->and(fn () => p5Term($agreement, ['mode' => 'discount', 'discount' => '5', 'base_price_list_id' => $foreignList->id]))->toThrow(ValidationException::class, 'Prislistan finns inte.');
});

it('validates the terms: no fixed price for all articles, no duplicate step, a named list in the same currency', function () {
    $agreement = p5Agreement();
    $euro = app(SavePriceList::class)($this->owner, $this->company, ['code' => 'EUR', 'name' => 'Euro', 'kind' => 'STANDARD', 'currency' => 'EUR']);
    p5Term($agreement);

    expect(fn () => p5Term($agreement, ['target' => 'all', 'article_id' => null]))->toThrow(ValidationException::class, 'Alla artiklar kan bara få en rabatt')
        ->and(fn () => p5Term($agreement, ['price' => '80']))->toThrow(ValidationException::class, 'redan ett pris för artikeln')
        ->and(fn () => p5Term($agreement, ['article_id' => $this->other->id, 'mode' => 'discount', 'discount' => '5', 'base_price_list_id' => $euro->id]))->toThrow(ValidationException::class, 'Välj en lista i avtalets valuta');

    $all = p5Term($agreement, ['target' => 'all', 'article_id' => null, 'mode' => 'discount', 'discount' => '7,5']);
    expect($all->applies_to_all)->toBeTrue()->and($all->discount_bp)->toBe(750);
});

it('activates by hand only with a reason, and keeps the reason on the agreement, never in the audit log', function () {
    $agreement = p5Agreement();
    p5Term($agreement);

    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, ' '))->toThrow(ValidationException::class);

    app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, 'Kunden skrev under på papper den 1 juni.');
    $agreement->refresh();
    $audit = AuditLog::query()->where('action', 'price_agreement.activated')->firstOrFail();

    expect($agreement->status)->toBe(PriceAgreementStatus::Active)
        ->and($agreement->activation_method)->toBe(PriceAgreementActivation::Manual)
        ->and($agreement->activation_reason)->toBe('Kunden skrev under på papper den 1 juni.')
        ->and($agreement->activated_by)->toBe($this->owner->id)
        ->and($agreement->signed_at)->toBeNull()
        ->and(json_encode($audit->payload))->not->toContain('papper');
});

it('refuses to activate an agreement without terms, one that has ended, or one overlapping another', function () {
    $empty = p5Agreement();
    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $empty, 'På papper.'))->toThrow(ValidationException::class, 'Lägg in minst ett pris');

    $past = p5Agreement(['valid_from' => '2026-01-01', 'valid_to' => '2026-05-31']);
    p5Term($past);
    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $past, 'På papper.'))->toThrow(ValidationException::class, 'har redan passerat');

    p5Active(['valid_from' => '2026-06-01', 'valid_to' => '2026-12-31']);
    $overlapping = p5Agreement(['valid_from' => '2026-12-01']);
    p5Term($overlapping);
    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $overlapping, 'På papper.'))->toThrow(ValidationException::class, 'Två avtal för samma kund kan inte gälla samma dag');
});

it('freezes an agreement that has left draft in the model, policy and actions, and the database refuses deleting it', function () {
    $agreement = p5Active();
    $item = $agreement->items()->firstOrFail();

    expect(fn () => $agreement->fresh()->update(['title' => 'Annat']))->toThrow(LogicException::class)
        ->and(fn () => $item->update(['price_minor' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $item->delete())->toThrow(LogicException::class)
        ->and(fn () => PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $this->other->id, 'price_minor' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $agreement->fresh()->delete())->toThrow(LogicException::class)
        ->and($this->owner->can('changeTerms', $agreement->fresh()))->toBeFalse()
        ->and($this->owner->can('delete', $agreement->fresh()))->toBeFalse()
        ->and(fn () => p5Term($agreement->fresh(), ['article_id' => $this->other->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemovePriceAgreementItem::class)($this->owner, $this->company, $agreement->fresh(), $item))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeletePriceAgreement::class)($this->owner, $this->company, $agreement->fresh()))->toThrow(AuthorizationException::class);

    p5Refused(fn () => DB::table('price_agreement_items')->where('id', $item->id)->delete());
    p5Refused(fn () => DB::table('price_agreements')->where('id', $agreement->id)->delete());

    expect(PriceAgreementItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('lets a draft and its terms be deleted', function () {
    $draft = p5Agreement();
    $item = p5Term($draft);
    p5Term($draft, ['article_id' => $this->other->id]);

    app(RemovePriceAgreementItem::class)($this->owner, $this->company, $draft, $item);
    expect($draft->items()->count())->toBe(1);

    app(DeletePriceAgreement::class)($this->owner, $this->company, $draft);
    expect(PriceAgreement::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(PriceAgreementItem::query()->where('price_agreement_id', $draft->id)->exists())->toBeFalse();

    // A draft deleted straight in the database is allowed too; the trigger only guards the rest.
    $raw = p5Agreement();
    expect(DB::table('price_agreements')->where('id', $raw->id)->delete())->toBe(1);
});

it('prices an invoice row and a quote row through the editors once activated, and not before', function () {
    $agreement = p5Agreement(['valid_from' => '2026-06-01']);
    p5Term($agreement);
    p5Term($agreement, ['article_id' => $this->other->id, 'mode' => 'discount', 'discount' => '10']);
    $rows = [
        ['article' => 'A-1', 'description' => 'Skruv', 'quantity' => '2', 'vat_rate' => 2500],
        ['article' => 'A-2', 'description' => 'Mutter', 'quantity' => '1', 'vat_rate' => 2500],
    ];

    $before = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'rows' => $rows]);
    expect($before->rows->first()->unit_price_ore->ore)->toBe(10000)
        ->and($before->rows->first()->price_source)->toBe(PriceSource::Base);

    app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, 'Undertecknat på papper.');

    $invoice = app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-15', 'rows' => $rows]);
    [$screw, $nut] = [$invoice->rows[0], $invoice->rows[1]];

    expect($screw->unit_price_ore->ore)->toBe(9000)
        ->and($screw->price_source)->toBe(PriceSource::Agreement)
        ->and($screw->price_explanation['agreement_id'])->toBe($agreement->id)
        ->and($nut->unit_price_ore->ore)->toBe(4500)
        ->and($nut->price_source)->toBe(PriceSource::Agreement);

    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [$rows[0]]]);
    expect($quote->rows->first()->unit_price_ore->ore)->toBe(9000)
        ->and($quote->rows->first()->price_source)->toBe(PriceSource::Agreement);
});

it('makes a new version from an active agreement that supersedes it the day before it starts', function () {
    $old = p5Active(['valid_from' => '2026-01-01']);
    $new = app(NewPriceAgreementVersion::class)($this->owner, $this->company, $old);

    expect($new->status)->toBe(PriceAgreementStatus::Draft)
        ->and($new->supersedes_id)->toBe($old->id)
        ->and($new->reference())->toBe('PA-2')
        ->and($new->valid_from->toDateString())->toBe('2026-06-16')
        ->and($new->items()->count())->toBe(1)
        // Asking again opens the same draft.
        ->and(app(NewPriceAgreementVersion::class)($this->owner, $this->company, $old)->id)->toBe($new->id);

    $item = $new->items()->firstOrFail();
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $new, ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '85'], $item);
    app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-07-01'], $new);
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $new->fresh(), 'Ny prisnivå, undertecknad på papper.');

    $old->refresh();
    expect($old->status)->toBe(PriceAgreementStatus::Superseded)
        ->and($old->valid_to->toDateString())->toBe('2026-06-30')
        ->and(p5Price('2026-06-30'))->toBe(9000)
        ->and(p5Price('2026-07-01'))->toBe(8500);
});

it('refuses a version that does not start after the agreement it changes', function () {
    $old = p5Active(['valid_from' => '2026-06-01']);
    $new = app(NewPriceAgreementVersion::class)($this->owner, $this->company, $old);

    expect(fn () => app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01'], $new))->not->toThrow(Exception::class)
        ->and(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $new->fresh(), 'På papper.'))->toThrow(ValidationException::class, 'En ny version måste börja gälla efter 2026-06-01');
});

it('terminates an active agreement to a day not in the past; it prices up to that day only', function () {
    $agreement = p5Active(['valid_from' => '2026-06-01']);

    expect(fn () => app(TerminatePriceAgreement::class)($this->owner, $this->company, $agreement, '2026-06-14'))->toThrow(ValidationException::class, 'bakåt i tiden');

    app(TerminatePriceAgreement::class)($this->owner, $this->company, $agreement, '2026-06-30');
    $agreement->refresh();

    expect($agreement->status)->toBe(PriceAgreementStatus::Terminated)
        ->and($agreement->lastDay()?->toDateString())->toBe('2026-06-30')
        ->and(p5Price('2026-06-30'))->toBe(9000)
        ->and(p5Price('2026-07-01'))->toBe(10000);
});

it('sends for signing, which freezes the draft, and activates it when the customer signs at the link', function () {
    $agreement = p5Agreement();
    p5Term($agreement);

    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'inkop@example.com']);
    $agreement->refresh();

    expect($agreement->status)->toBe(PriceAgreementStatus::AwaitingSignature)
        ->and($sent['request']->document_type)->toBe('PRICE')
        ->and($sent['request']->signable_id)->toBe((string) $agreement->id)
        ->and($this->owner->can('changeTerms', $agreement))->toBeFalse();

    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    $this->get("/signera/{$token}")->assertOk()->assertSee('Prisavtal PA-1');
    SigningConsentFixture::give($sent['request']);
    $this->post("/signera/{$token}", ['name' => 'Eva Exempelsson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Eva Exempelsson', 'document_sha256' => $sent['request']->document_sha256, ...SigningPdfs::acceptanceFields($sent['request'])])->assertOk();
    CurrentCompany::set($this->company);

    $agreement->refresh();
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Signed)
        ->and($agreement->status)->toBe(PriceAgreementStatus::Active)
        ->and($agreement->activation_method)->toBe(PriceAgreementActivation::Signed)
        ->and($agreement->signed_at)->not->toBeNull()
        ->and($agreement->document_sha256)->toBe($sent['request']->document_sha256)
        ->and(p5Price('2026-06-15'))->toBe(9000);
});

it('asks the signer of a business customer to confirm the right to enter it for the customer', function () {
    $agreement = p5Agreement();

    expect($agreement->signingAcceptance())->toBe('Jag har läst och godkänner prisavtalet och har rätt att ingå det för Byggbolaget AB.');
});

it('refuses to send a draft without terms and keeps it a draft', function () {
    $agreement = p5Agreement();

    expect(fn () => app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'inkop@example.com']))->toThrow(ValidationException::class, 'Lägg in minst ett pris')
        ->and($agreement->fresh()->status)->toBe(PriceAgreementStatus::Draft);
});

it('takes an agreement awaiting a signature back to draft and revokes every open link', function () {
    $agreement = p5Agreement();
    p5Term($agreement);
    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'inkop@example.com']);

    app(ReopenPriceAgreement::class)($this->owner, $this->company, $agreement);

    expect($agreement->fresh()->status)->toBe(PriceAgreementStatus::Draft)
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Revoked)
        ->and(SigningRequest::query()->where('document_type', 'PRICE')->where('status', SigningStatus::Pending->value)->exists())->toBeFalse();

    // The terms can change again.
    p5Term($agreement->fresh(), ['article_id' => $this->other->id]);
    expect($agreement->items()->count())->toBe(2);
});

it('pricing:expire ends agreements whose last day has passed, once, and leaves the rest', function () {
    $ending = p5Active(['valid_from' => '2026-06-01', 'valid_to' => '2026-06-20']);
    $running = p5Active(['valid_from' => '2026-07-01'], ['article_id' => $this->other->id]);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 06:05:00'));
    Artisan::call('pricing:expire');
    Artisan::call('pricing:expire');
    CurrentCompany::set($this->company);

    expect($ending->fresh()->status)->toBe(PriceAgreementStatus::Ended)
        ->and($running->fresh()->status)->toBe(PriceAgreementStatus::Active)
        ->and(AuditLog::query()->where('action', 'price_agreement.ended')->count())->toBe(1)
        // An ended agreement still prices documents dated inside its validity.
        ->and(p5Price('2026-06-20'))->toBe(9000);
});

it('follows the end date of a linked agreement in the CRM register, and only while CRM is on', function () {
    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor($this->customer);
    $crm = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $account->id, 'title' => 'Serviceavtal', 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'notice_months' => 3, 'value_period' => 'MONTH']);

    $agreement = p5Agreement(['valid_to' => '2027-03-31', 'crm_agreement_id' => $crm->id]);
    expect($agreement->valid_to->toDateString())->toBe('2026-12-31');

    p5Term($agreement);
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, 'Undertecknat på papper.');
    DB::table('crm_agreements')->where('id', $crm->id)->update(['end_date' => '2027-12-31']);

    Artisan::call('pricing:expire');
    CurrentCompany::set($this->company);

    expect($agreement->fresh()->valid_to->toDateString())->toBe('2027-12-31')
        // The CRM register reminds about it, so Översikt does not as well.
        ->and(app(PriceAgreementTodos::class)->todos($this->owner, $this->company, CarbonImmutable::parse('2027-12-10')))->toBe([]);
});

it('refuses a CRM agreement of another customer, and any link while CRM is off', function () {
    Crm::enable($this->company, $this->owner);
    $stranger = Crm::customer($this->company, $this->owner, 'Annan kund AB');
    $crm = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($stranger)->id, 'title' => 'Deras avtal', 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'notice_months' => 0, 'value_period' => 'MONTH']);

    expect(fn () => p5Agreement(['crm_agreement_id' => $crm->id]))->toThrow(ValidationException::class, 'gäller en annan kund');

    app(ModuleManager::class)->disable('crm', $this->company, $this->owner, confirmed: true);
    expect(fn () => p5Agreement(['crm_agreement_id' => $crm->id]))->toThrow(ValidationException::class, 'bara när CRM är påslaget');
});

it('reminds on Översikt 30 days before an agreement runs out, only those who may act, in two queries', function () {
    $soon = p5Active(['valid_from' => '2026-06-01', 'valid_to' => '2026-07-10']);
    p5Active(['valid_from' => '2026-08-01', 'valid_to' => '2026-12-31'], ['article_id' => $this->other->id]);

    DB::enableQueryLog();
    $todos = app(PriceAgreementTodos::class)->todos($this->owner, $this->company, CarbonImmutable::parse('2026-06-15'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($todos)->toHaveCount(1)
        ->and($todos[0]->title)->toBe('Prisavtalet PA-1 med Byggbolaget AB löper ut 2026-07-10')
        ->and($todos[0]->url)->toEndWith("/prisavtal/{$soon->id}")
        ->and($queries)->toBeLessThanOrEqual(3)
        ->and(app(PriceAgreementTodos::class)->todos(Bookkeeping::member($this->company, 'approver'), $this->company, CarbonImmutable::parse('2026-06-15')))->toBe([]);

    // A new version on its way silences the reminder.
    app(NewPriceAgreementVersion::class)($this->owner, $this->company, $soon);
    expect(app(PriceAgreementTodos::class)->todos($this->owner, $this->company, CarbonImmutable::parse('2026-06-15')))->toBe([]);
});
