<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\SalesDocument;
use App\Modules\Portal\Livewire\CustomerPortalPanel;
use App\Modules\Portal\Livewire\InvoicePortalPanel;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * The customer portal's tenancy (ADR-075 §8): every portal query is scoped
 * to the company and the customer of the chosen contact. Another customer
 * in the same company, and another company, answer 404; a person who is a
 * contact of two customers sees each only in its own portal; no route takes
 * a company or customer id from the request.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    Storage::fake('local');
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    Portal::enable($this->a, $this->ownerA);
    Portal::enable($this->b, $this->ownerB);

    $this->alpha = Portal::customer($this->a, $this->ownerA, 'Alfa AB');
    $this->beta = Portal::customer($this->a, $this->ownerA, 'Beta AB', ['email' => 'beta@example.test']);
    $this->gamma = Portal::customer($this->b, $this->ownerB, 'Gamma AB', ['email' => 'gamma@example.test']);

    $this->invoiceAlpha = Portal::invoice($this->a, $this->ownerA, $this->alpha);
    $this->invoiceBeta = Portal::invoice($this->a, $this->ownerA, $this->beta);
    $this->invoiceGamma = Portal::invoice($this->b, $this->ownerB, $this->gamma);

    // One person: a contact of Alfa and of Beta in company A, and of Gamma in company B.
    $this->contactAlpha = Portal::contact($this->a, $this->ownerA, $this->alpha);
    $this->contactBeta = Portal::contact($this->a, $this->ownerA, $this->beta);
    $this->contactGamma = Portal::contact($this->b, $this->ownerB, $this->gamma);
    $this->keyA = Portal::key($this->a);
    $this->keyB = Portal::key($this->b);
});

it('keeps one identity for the person and asks which portal to open', function () {
    expect($this->contactAlpha->portal_identity_id)->toBe($this->contactBeta->portal_identity_id)->toBe($this->contactGamma->portal_identity_id);

    Portal::signIn($this, $this->contactAlpha);
    $this->withSession([PortalSession::CONTACT => null]);
    $this->get(route('portal.choose'))->assertOk()->assertSee('Alfa AB')->assertSee('Beta AB')->assertSee('Gamma AB');
    $this->get(route('portal.home', ['portalKey' => $this->keyA]))->assertRedirect(route('portal.choose'));
});

it('shows a contact of two customers each only in its own portal', function () {
    Portal::signIn($this, $this->contactAlpha);
    $show = fn (string $key, int $number) => $this->get(route('portal.invoices.show', ['portalKey' => $key, 'number' => $number]));

    $this->get(route('portal.invoices', ['portalKey' => $this->keyA]))->assertOk()->assertSee('Alfa AB')->assertDontSee($this->invoiceBeta->title());
    $show($this->keyA, $this->invoiceAlpha->number)->assertOk();
    $show($this->keyA, $this->invoiceBeta->number)->assertNotFound();

    $this->post(route('portal.enter'), ['contact' => $this->contactBeta->id])->assertRedirect(route('portal.home', ['portalKey' => $this->keyA]));
    $show($this->keyA, $this->invoiceBeta->number)->assertOk();
    $show($this->keyA, $this->invoiceAlpha->number)->assertNotFound();
});

it('answers 404 for another company\'s documents, threads and attachments, whatever the address says', function () {
    Portal::signIn($this, $this->contactAlpha);

    // Gamma's invoice number in company A's portal, and company A's contact choosing B's contact id.
    $this->get(route('portal.invoices.show', ['portalKey' => $this->keyA, 'number' => $this->invoiceGamma->number]))->assertStatus($this->invoiceGamma->number === $this->invoiceAlpha->number ? 200 : 404);

    $quote = CurrentCompany::run($this->b, fn (): SalesDocument => app(SaveSalesDocument::class)($this->ownerB, $this->b, SalesDocument::QUOTE, ['customer_id' => $this->gamma->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'B', 'quantity' => '1', 'price' => '10', 'vat_rate' => 2500]]]));
    $quote->forceFill(['sent_at' => now()])->save();
    $this->get(route('portal.quotes.show', ['portalKey' => $this->keyA, 'document' => $quote->id]))->assertNotFound();

    $thread = CurrentCompany::run($this->b, fn () => PortalThread::query()->create(['customer_id' => $this->gamma->id, 'contact_id' => $this->contactGamma->id, 'subject' => 'B:s fråga', 'last_message_at' => now()]));
    $this->get(route('portal.questions.show', ['portalKey' => $this->keyA, 'thread' => $thread->id]))->assertNotFound();
    $this->get(route('portal.questions.attachment', ['portalKey' => $this->keyA, 'thread' => $thread->id, 'attachment' => 1]))->assertNotFound();

    // An unknown key, and a key of a company the person has no portal in, are 404 too.
    $this->get(route('portal.home', ['portalKey' => str_repeat('a', 16)]))->assertNotFound();
});

it('takes no company or customer id from the request on any portal route', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'kundportal'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect($route->parameterNames())->each->not->toBeIn(['company', 'companyId', 'customer', 'customerId', 'team']);
    }
});

it('scopes every portal model to its company', function () {
    $models = [PortalContact::class, PortalInvitation::class, PortalSettings::class, PortalThread::class, PortalMessage::class, PortalAttachment::class, PortalEvent::class, PortalPaymentNote::class];

    CurrentCompany::run($this->b, function (): void {
        PortalEvent::query()->create(['customer_id' => $this->gamma->id, 'contact_id' => $this->contactGamma->id, 'event' => 'VIEWED_STATEMENT']);
        PortalPaymentNote::query()->create(['invoice_id' => $this->invoiceGamma->id, 'contact_id' => $this->contactGamma->id, 'paid_on' => '2026-06-14']);
    });

    CurrentCompany::run($this->a, function () use ($models): void {
        foreach ($models as $model) {
            expect($model::query()->where('company_id', $this->b->id)->exists())->toBeFalse();
        }

        expect(PortalContact::query()->whereKey($this->contactGamma->id)->exists())->toBeFalse();
    });
});

it('answers 404 in the company\'s own panels for another company\'s customer or invoice', function () {
    $this->actingAs($this->ownerA);
    CurrentCompany::set($this->a);

    Livewire::test(CustomerPortalPanel::class, ['customerId' => $this->gamma->id])->assertNotFound();
    Livewire::test(InvoicePortalPanel::class, ['invoiceId' => $this->invoiceGamma->id])->assertNotFound();
    Livewire::test(CustomerPortalPanel::class, ['customerId' => $this->alpha->id])->assertOk()->assertSee('anna@kontorshuset.test');
});
