<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Livewire\Signing\SigningPanel;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Modules\Crm\Actions\RunAgreementReminders;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Livewire\AgreementCard;
use App\Modules\Crm\Livewire\QuoteFromTemplate;
use App\Modules\Crm\Livewire\QuoteTemplatesPage;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\QuoteTemplate;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for CRM stage 3 (ADR-046): company A never sees B's
 * agreements, quote templates or signing links through Eloquent, the pages,
 * the API or the daily run; B's ids answer 404; the public page shows a
 * link's own company only, found by its token alone.
 */

beforeEach(function () {
    Connect::setUp();
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Crm::enable($company, $owner);
        $customer = Crm::customer($company, $owner, "{$name} Kund AB");
        $account = Crm::accountFor($customer);
        app(SaveArticle::class)($owner, $company, ['number' => 'A1', 'name' => "{$name}artikel", 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
        $agreement = app(SaveAgreement::class)($owner, $company, ['account_id' => $account->id, 'title' => "{$name}avtalet", 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'end_date' => '2026-07-31', 'notice_months' => 0, 'value_period' => 'MONTH']);
        $template = app(SaveQuoteTemplate::class)($owner, $company, ['name' => "{$name}mallen", 'validity_days' => 30, 'rows' => [['article' => 'A1']]]);
        $quote = app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => "{$name}tjänst", 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        $sent = app(SendForSigning::class)($owner, $company, $quote, ['email' => "kund@{$side}.test"]);
        $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
        $signing = $sent['request'];
        $this->{$side} = compact('owner', 'company', 'customer', 'account', 'agreement', 'template', 'quote', 'signing', 'token');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s agreements, templates or signing links through Eloquent', function () {
    expect(Agreement::query()->pluck('title')->all())->toBe(['Alfaavtalet'])
        ->and(QuoteTemplate::query()->pluck('name')->all())->toBe(['Alfamallen'])
        ->and(SigningRequest::query()->pluck('id')->all())->toBe([$this->a['signing']->id])
        ->and(SigningEvent::query()->pluck('signing_request_id')->unique()->values()->all())->toBe([$this->a['signing']->id]);
});

it('answers 404 for B\'s records on the pages and in the components', function () {
    $this->get(route('crm.agreements'))->assertOk()->assertSee('Alfaavtalet')->assertDontSee('Betaavtalet');
    $this->get(route('crm.agreements.show', $this->a['agreement']))->assertOk();
    $this->get(route('crm.agreements.show', $this->b['agreement']))->assertNotFound();
    $this->get(route('signing.file', ['signing' => $this->b['signing']->id, 'file' => 'dokument']))->assertNotFound();
    $this->get(route('signing.file', ['signing' => $this->a['signing']->id, 'file' => 'dokument']))->assertOk();
    $this->get(route('crm.quoteTemplates'))->assertOk()->assertSee('Alfamallen')->assertDontSee('Betamallen');

    Livewire::test(QuoteTemplatesPage::class)->call('edit', $this->b['template']->id)->assertNotFound();
    Livewire::test(QuoteFromTemplate::class)->set('templateId', (string) $this->b['template']->id)->set('customerId', (string) $this->a['customer']->id)->call('create')->assertNotFound();
    Livewire::test(QuoteFromTemplate::class)->set('templateId', (string) $this->a['template']->id)->set('customerId', (string) $this->b['customer']->id)->call('create')->assertHasErrors('customer_id');
    Livewire::test(SigningPanel::class, ['quoteId' => $this->a['quote']->id])->call('revoke', $this->b['signing']->id)->assertNotFound();
    Livewire::test(AgreementCard::class, ['agreementId' => $this->a['agreement']->id])->set('form.account_id', (string) $this->b['account']->id)->call('save')->assertHasErrors('account_id');

    Livewire::test(SigningPanel::class, ['quoteId' => $this->b['quote']->id])->assertNotFound();
});

it('shows a public link\'s own company only, whichever company is signed in', function () {
    $this->get("/signera/{$this->b['token']}")->assertOk()->assertSee('Betatjänst')->assertSee('Beta Kund AB')->assertDontSee('Alfa');
    $this->get("/signera/{$this->a['token']}")->assertOk()->assertSee('Alfatjänst')->assertDontSee('Beta');
});

it('shows only A\'s agreements and signing links over the API, and 404 for B\'s ids', function () {
    $key = Connect::apiKey($this->a['owner'], $this->a['company'], ['crm:view', 'crm:manage', 'invoices:view', 'invoices:create', 'invoices:send']);

    $this->withToken($key)->getJson('/api/v1/crm/agreements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alfaavtalet');
    $this->withToken($key)->getJson("/api/v1/crm/agreements/{$this->b['agreement']->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/crm/agreements/{$this->b['agreement']->id}/signing")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/crm/agreements/{$this->b['agreement']->id}", ['title' => 'X'])->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/quotes/{$this->b['quote']->id}/signing")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/quotes/{$this->a['quote']->id}/signing")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token_hash');
    $this->withToken($key)->withHeader('Idempotency-Key', 'tvars-signering')->postJson("/api/v1/quotes/{$this->b['quote']->id}/signing", ['email' => 'x@y.test'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 'tvars-avtal')
        ->postJson('/api/v1/crm/agreements', ['account_id' => $this->b['account']->id, 'title' => 'X', 'kind' => 'SERVICE', 'start_date' => '2026-06-01', 'notice_months' => 0, 'value_period' => 'MONTH'])
        ->assertUnprocessable();
});

it('runs the agreement reminders for one company without touching the other', function () {
    expect(app(RunAgreementReminders::class)($this->a['company'])[1])->toBe(1);

    CurrentCompany::set($this->b['company']);
    expect(Activity::query()->where('subject', 'like', 'Avtalet%')->count())->toBe(0)
        ->and($this->b['agreement']->fresh()?->reminded_for_end_date)->toBeNull();
});
