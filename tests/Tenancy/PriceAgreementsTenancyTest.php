<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\DeletePriceAgreement;
use App\Actions\Pricing\NewPriceAgreementVersion;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SendPriceAgreementForSigning;
use App\Actions\Pricing\TerminatePriceAgreement;
use App\Console\Commands\ExpirePriceAgreementsCommand;
use App\Domain\Overview\Todos\PriceAgreementTodos;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Livewire\Pricing\PriceAgreementEditor;
use App\Livewire\Pricing\PriceAgreements;
use App\Livewire\Signing\SigningPanel;
use App\Models\PriceAgreement;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the price agreement screens and actions (ADR-082 P5):
 * company A never sees, opens, changes, sends or ends company B's price
 * agreements; by id it is a 404, never a 403; the daily command and the
 * overview stay inside each company.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();

    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();

        CurrentCompany::run($company, function () use ($owner, $company, $side): void {
            $article = app(SaveArticle::class)($owner, $company, ['number' => "A-{$side}", 'name' => 'Hylla', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
            $customer = Crm::customer($company, $owner, "Hemlig kund {$side}", ['email' => "{$side}@example.com"]);
            $draft = app(SavePriceAgreement::class)($owner, $company, ['customer_id' => $customer->id, 'title' => "Hemligt utkast {$side}", 'currency' => 'SEK', 'valid_from' => '2026-06-01', 'valid_to' => '2026-07-01']);
            app(SavePriceAgreementItem::class)($owner, $company, $draft, ['target' => 'article', 'article_id' => $article->id, 'mode' => 'price', 'price' => '90']);
            $active = app(SavePriceAgreement::class)($owner, $company, ['customer_id' => $customer->id, 'title' => "Hemligt avtal {$side}", 'currency' => 'SEK', 'valid_from' => '2026-01-01', 'valid_to' => '2026-05-31']);
            app(SavePriceAgreementItem::class)($owner, $company, $active, ['target' => 'article', 'article_id' => $article->id, 'mode' => 'price', 'price' => '80']);
            $active->forceFill(['status' => PriceAgreementStatus::Active])->save();

            $this->{$side} = compact('owner', 'company', 'article', 'customer', 'draft', 'active');
        });
    }

    CurrentCompany::forget();
    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('answers 404 for another company\'s agreement on every page', function () {
    $this->get("/prisavtal/{$this->b['draft']->id}")->assertNotFound();
    $this->get("/prisavtal/{$this->b['draft']->id}/pdf")->assertNotFound();
    $this->get('/prisavtal')->assertOk()->assertSee('Hemligt utkast a')->assertDontSee('Hemligt utkast b')->assertDontSee('Hemlig kund b');
});

it('refuses every Livewire entry point for another company\'s agreement', function () {
    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $this->b['draft']->id])->assertNotFound();
    Livewire::test(SigningPanel::class, ['priceAgreementId' => $this->b['draft']->id])->assertNotFound();

    $bItem = CurrentCompany::run($this->b['company'], fn () => $this->b['draft']->items()->firstOrFail());
    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $this->a['draft']->id])->call('editItem', $bItem->id)->assertNotFound();
    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $this->a['draft']->id])->call('removeItem', $bItem->id)->assertNotFound();
    Livewire::test(PriceAgreementEditor::class, ['agreementId' => $this->a['draft']->id])->call('createItem')->call('chooseArticle', $this->b['article']->id)->assertNotFound();

    Livewire::test(PriceAgreements::class)->call('create')->set('customerSearch', 'Hemlig')->assertSee('Hemlig kund a')->assertDontSee('Hemlig kund b');
    Livewire::test(PriceAgreements::class)->call('create')->call('chooseCustomer', $this->b['customer']->id)->assertNotFound();

    expect(CurrentCompany::run($this->b['company'], fn () => $this->b['draft']->items()->count()))->toBe(1);
});

it('refuses every action on another company\'s agreement as not found', function () {
    $user = $this->a['owner'];
    $company = $this->a['company'];
    $foreign = CurrentCompany::run($this->b['company'], fn () => PriceAgreement::query()->findOrFail($this->b['draft']->id));
    $foreignActive = CurrentCompany::run($this->b['company'], fn () => PriceAgreement::query()->findOrFail($this->b['active']->id));

    expect(fn () => app(SavePriceAgreement::class)($user, $company, ['customer_id' => $this->a['customer']->id, 'title' => 'X', 'currency' => 'SEK', 'valid_from' => '2026-06-01'], $foreign))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SavePriceAgreementItem::class)($user, $company, $foreign, ['target' => 'all', 'mode' => 'discount', 'discount' => '5']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ActivatePriceAgreement::class)($user, $company, $foreign, 'På papper.'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SendPriceAgreementForSigning::class)($user, $company, $foreign, ['email' => 'x@example.com']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeletePriceAgreement::class)($user, $company, $foreign))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(TerminatePriceAgreement::class)($user, $company, $foreignActive, '2026-12-31'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(NewPriceAgreementVersion::class)($user, $company, $foreignActive))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SavePriceAgreement::class)($user, $company, ['customer_id' => $this->b['customer']->id, 'title' => 'X', 'currency' => 'SEK', 'valid_from' => '2026-06-01']))->toThrow(ValidationException::class, 'Kunden finns inte.')
        ->and($user->can('view', $foreign))->toBeFalse()
        ->and($user->can('update', $foreign))->toBeFalse();
});

it('keeps the daily command and the overview inside each company', function () {
    Artisan::call(ExpirePriceAgreementsCommand::class);

    CurrentCompany::run($this->b['company'], fn () => expect(PriceAgreement::query()->findOrFail($this->b['active']->id)->status)->toBe(PriceAgreementStatus::Ended));
    CurrentCompany::set($this->a['company']);
    expect(PriceAgreement::query()->findOrFail($this->a['active']->id)->status)->toBe(PriceAgreementStatus::Ended);

    // Activate A's draft (runs out 2026-07-01): A's overview names only A's agreement.
    app(ActivatePriceAgreement::class)($this->a['owner'], $this->a['company'], $this->a['draft'], 'På papper.');
    $todos = app(PriceAgreementTodos::class)->todos($this->a['owner'], $this->a['company'], CarbonImmutable::parse('2026-06-15'));

    expect($todos)->toHaveCount(1)
        ->and($todos[0]->title)->toContain('Hemlig kund a')
        ->and(app(PriceAgreementTodos::class)->todos($this->b['owner'], $this->b['company'], CarbonImmutable::parse('2026-06-15')))->toBe([]);
});
