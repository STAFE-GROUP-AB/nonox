<?php

declare(strict_types=1);

/*
 * ADR-075 §7 data fix: quote links that ADR-046 closed while CRM was off do
 * not come back to life when signing moves to core. Pending, unexpired
 * quote links in a company without CRM are revoked (no delete), with an
 * event and an audit entry of ids only; a CRM company is untouched; a
 * second run changes nothing.
 */

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\RevokeQuoteLinksClosedWithCrm;
use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
});

function quoteLink(bool $withCrm): SigningRequest
{
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    test()->actingAs($owner);

    if ($withCrm) {
        Crm::enable($company, $owner);
    }

    $customer = app(SaveCustomer::class)($owner, $company, ['name' => 'Exempelkunden AB', 'email' => 'inkop@exempel.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, [
        'customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
    ]);

    return app(SendForSigning::class)($owner, $company, $quote, ['email' => 'inkop@exempel.test'])['request'];
}

it('revokes the pending quote links of companies without CRM, keeps them, and leaves CRM companies alone', function () {
    $closed = quoteLink(withCrm: false);
    $open = quoteLink(withCrm: true);

    expect(app(RevokeQuoteLinksClosedWithCrm::class)())->toBe(1);

    [$closedNow, $openNow] = CompanyScope::bypass(fn (): array => [SigningRequest::query()->find($closed->id), SigningRequest::query()->find($open->id)]);

    expect($closedNow->status)->toBe(SigningStatus::Revoked)
        ->and($closedNow->revoked_at)->not->toBeNull()
        ->and($openNow->status)->toBe(SigningStatus::Pending)
        ->and(CompanyScope::bypass(fn () => SigningEvent::query()->where('signing_request_id', $closed->id)->where('event', SigningEventType::Revoked->value)->count()))->toBe(1);

    $log = CompanyScope::bypass(fn () => AuditLog::query()->where('action', 'crm.signing_revoked')->where('company_id', $closed->company_id)->latest('id')->firstOrFail());
    expect($log->payload)->toEqual(['signing_request_id' => $closed->id, 'reason' => 'closed_without_crm']);

    // Idempotent.
    expect(app(RevokeQuoteLinksClosedWithCrm::class)())->toBe(0);
});

it('leaves an expired quote link as it is', function () {
    $link = quoteLink(withCrm: false);
    $this->travel(40)->days();

    expect(app(RevokeQuoteLinksClosedWithCrm::class)())->toBe(0)
        ->and(CompanyScope::bypass(fn () => SigningRequest::query()->find($link->id))->status)->toBe(SigningStatus::Pending);
});
