<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Livewire\Pricing\CustomerPrices;
use App\Livewire\Pricing\PriceAgreementEditor;
use App\Livewire\Pricing\PriceAgreements;
use App\Livewire\Signing\SigningPanel;
use App\Models\PriceAgreement;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * ADR-082 P5: the Prisavtal screens. Thin Livewire over the actions, with a
 * Swedish toast for every step, filters in the address, the same rights as
 * the policy (prices:view to see, price-agreements:manage to change), a
 * fixed number of queries for the list, and another company's agreement a
 * 404 on every page.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->customer = Crm::customer($this->company, $this->owner, 'Byggbolaget AB', ['email' => 'inkop@example.com']);
    $this->makeAgreement = fn (array $extra = []): PriceAgreement => app(SavePriceAgreement::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'title' => 'Ramavtal', 'currency' => 'SEK', 'valid_from' => '2026-06-01', ...$extra,
    ]);
});

it('shows the list and the agreement to whoever sees prices, and the forms only to whoever manages agreements', function () {
    $agreement = ($this->makeAgreement)();

    $this->get('/prisavtal')->assertOk()->assertSee('PA-1')->assertSee('Nytt prisavtal');
    $this->get("/prisavtal/{$agreement->id}")->assertOk()->assertSee('Ramavtal')->assertSee('Nytt pris')->assertSee('Aktivera utan signering');
    $this->get("/prisavtal/{$agreement->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor)->get('/prisavtal')->assertOk()->assertSee('PA-1')->assertDontSee('Nytt prisavtal');
    $this->get("/prisavtal/{$agreement->id}")->assertOk()->assertDontSee('Aktivera utan signering')->assertDontSee('Nytt pris');

    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver)->get('/prisavtal')->assertForbidden();
    $this->get("/prisavtal/{$agreement->id}")->assertForbidden();
    $this->get("/prisavtal/{$agreement->id}/pdf")->assertForbidden();
});

it('makes a draft from the form and opens it with a toast after the redirect', function () {
    Livewire::test(PriceAgreements::class)
        ->call('create')
        ->set('customerSearch', 'Bygg')
        ->assertSee('Byggbolaget AB')
        ->call('chooseCustomer', $this->customer->id)
        ->set('form.title', 'Ramavtal 2026')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('pricing.agreements.show', PriceAgreement::query()->firstOrFail()->id));

    expect(session('toasts')[0]['message'] ?? null)->toBe('Prisavtalet PA-1 är skapat som utkast. Lägg in priserna och skicka det sedan för signering.');
});

it('opens the form for a customer from a link, as the customer page and the CRM card do', function () {
    Livewire::withQueryParams(['ny' => 1, 'kund' => $this->customer->id])
        ->test(PriceAgreements::class)
        ->assertSet('showForm', true)
        ->assertSet('form.customer_id', (string) $this->customer->id);
});

it('filters by status and finds an agreement by its number', function () {
    ($this->makeAgreement)(['title' => 'Första']);
    $active = ($this->makeAgreement)(['title' => 'Andra', 'valid_from' => '2027-01-01']);
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $active, ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '90']);
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $active, 'På papper.');

    Livewire::withQueryParams(['status' => 'ACTIVE'])->test(PriceAgreements::class)->assertSee('Andra')->assertDontSee('Första');
    Livewire::withQueryParams([])->test(PriceAgreements::class)->set('status', '')->set('search', 'PA-1')->assertSee('Första')->assertDontSee('Andra');
});

it('adds, changes and removes a term in a draft with toasts, and activates it with a reason', function () {
    $agreement = ($this->makeAgreement)();

    $editor = Livewire::test(PriceAgreementEditor::class, ['agreementId' => $agreement->id])
        ->call('createItem')
        ->set('articleSearch', 'Skr')
        ->call('chooseArticle', $this->article->id)
        ->set('item.price', '89,50')
        ->call('saveItem')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Priset är tillagt i avtalet.', type: 'success');

    $item = $agreement->items()->firstOrFail();
    expect($item->price_minor)->toBe(8950);

    $editor->call('editItem', $item->id)->set('item.price', '88')->call('saveItem')
        ->assertDispatched('toast', message: 'Priset i avtalet är sparat.', type: 'success');
    expect($item->fresh()->price_minor)->toBe(8800);

    $editor->call('startActivation')->set('reason', '')->call('activate')->assertHasErrors('reason');
    $editor->set('reason', 'Kunden skrev under på papper.')->call('activate')
        ->assertDispatched('toast', message: 'Prisavtalet PA-1 är aktivt. Fakturor, offerter och order till kunden får nu avtalets priser.', type: 'success')
        ->assertSee('Aktiverat för hand')
        ->assertSee('Kunden skrev under på papper.')
        ->assertDontSee('Ta bort utkastet');

    expect($agreement->fresh()->status)->toBe(PriceAgreementStatus::Active);
});

it('makes a new version and terminates from the agreement page', function () {
    $agreement = ($this->makeAgreement)();
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $agreement, ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '90']);
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $agreement, 'På papper.');

    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $agreement->id])
        ->call('startTermination')
        ->set('terminateOn', '2026-06-30')
        ->call('terminate')
        ->assertDispatched('toast', message: 'Avtalet är uppsagt och gäller till och med 2026-06-30.', type: 'success');

    $version = Livewire::test(PriceAgreementEditor::class, ['agreementId' => $agreement->id])->call('newVersion');
    $new = PriceAgreement::query()->where('supersedes_id', $agreement->id)->firstOrFail();
    $version->assertRedirect(route('pricing.agreements.show', $new->id));
});

it('deletes a draft from its page and goes back to the list', function () {
    $agreement = ($this->makeAgreement)();

    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $agreement->id])
        ->call('delete')
        ->assertRedirect(route('pricing.agreements'));

    expect(PriceAgreement::query()->whereKey($agreement->id)->exists())->toBeFalse();
});

it('sends a draft for signing from the shared signing panel and tells the agreement page', function () {
    $agreement = ($this->makeAgreement)();
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $agreement, ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '90']);

    $this->get("/prisavtal/{$agreement->id}")->assertSee('Skicka prisavtalet för signering');

    Livewire::test(SigningPanel::class, ['priceAgreementId' => $agreement->id])
        ->assertSet('email', 'inkop@example.com')
        ->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('price-agreement-changed')
        ->assertDispatched('toast', message: 'Länken för signering är skickad till inkop@example.com.', type: 'success');

    expect($agreement->fresh()->status)->toBe(PriceAgreementStatus::AwaitingSignature);

    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $agreement->id])
        ->assertSee('Väntar på underskrift')
        ->call('reopen')
        ->assertDispatched('toast', message: 'Avtalet är ett utkast igen. Länkarna för signering är återkallade.', type: 'success');
});

it('shows the customer\'s agreements on the customer\'s price tab', function () {
    ($this->makeAgreement)(['title' => 'Kundens ramavtal']);

    Livewire::test(CustomerPrices::class, ['customerId' => $this->customer->id])
        ->assertSee('Kundens ramavtal')
        ->assertSee('Utkast')
        ->assertSee('Nytt prisavtal för kunden');
});

it('shows the linked price agreements on an agreement in the CRM register', function () {
    Crm::enable($this->company, $this->owner);
    $crm = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($this->customer)->id, 'title' => 'Serviceavtal', 'kind' => 'SERVICE', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'notice_months' => 3, 'value_period' => 'MONTH']);
    ($this->makeAgreement)(['title' => 'Priser till serviceavtalet', 'crm_agreement_id' => $crm->id]);

    $this->get("/kunder/avtal/{$crm->id}")->assertOk()->assertSee('Priser till serviceavtalet')->assertSee('Nytt prisavtal för kunden');
});

it('runs the same number of queries for the list with three agreements as with thirty', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PriceAgreements::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        ($this->makeAgreement)(['title' => "Avtal {$i}"]);
    }

    // The first render fills per-request caches (the member's role, the company's modules).
    $count();
    $few = $count();

    foreach (range(4, 30) as $i) {
        ($this->makeAgreement)(['title' => "Avtal {$i}"]);
    }

    expect($count())->toBe($few);
});
