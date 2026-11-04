<?php

declare(strict_types=1);

use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Peppol\Actions\AcceptPeppolDocument;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Livewire\InvoicePeppolPanel;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;
use Tests\Support\PeppolFixtures;

/*
 * AGENTS.md §13.2 for Peppol (ADR-050): company A never sees B's Peppol
 * participant or sendings; B's invoice answers 404 on A's page; a document
 * the access point delivers for B's account lands in B's inbox only, and a
 * document addressed to B never enters A's inbox, whatever account it came
 * through.
 */

beforeEach(function () {
    LogAccessPoint::reset();
    Storage::fake('local');

    [$this->ownerA, $this->a] = PeppolFixtures::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    // The golden invoice is addressed to 0007:5566778808, which is B.
    $this->b->forceFill(['org_number' => '556677-8808', 'vat_number' => 'SE556677880801'])->save();

    Peppol::ready($this->a, $this->ownerA, 'acct-A', receive: true);
    Peppol::ready($this->b->fresh(), $this->ownerB, 'acct-B', receive: true);

    CurrentCompany::set($this->b);
    $this->actingAs($this->ownerB);
    $customer = PeppolFixtures::customer($this->ownerB, $this->b, ['peppol_id' => '0007:5599000006']);
    $this->invoiceB = PeppolFixtures::invoice($this->ownerB, $this->b, $customer, [['description' => 'Arbete', 'quantity' => '1', 'unit' => 'st', 'price' => '1 000', 'vat_rate' => 2500]]);
    app(SendInvoiceViaPeppol::class)($this->ownerB, $this->invoiceB);

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
});

afterEach(fn () => LogAccessPoint::reset());

function tenancyInbound(): InboundDocument
{
    return new InboundDocument('doc-b', (string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml')));
}

it('scopes participants and sendings to the current company', function () {
    expect(PeppolParticipant::query()->pluck('company_id')->all())->toBe([$this->a->id])
        ->and(PeppolTransmission::query()->count())->toBe(0)
        ->and(CompanyScope::bypass(fn () => PeppolTransmission::query()->where('company_id', $this->b->id)->count()))->toBe(1)
        ->and(PeppolTransmission::query()->find(CompanyScope::bypass(fn () => PeppolTransmission::query()->value('id'))))->toBeNull();
});

it('answers 404 for the other company\'s invoice on the Peppol panel', function () {
    Livewire::test(InvoicePeppolPanel::class, ['invoice' => $this->invoiceB])->assertNotFound();
});

it('puts a document from B\'s account in B\'s inbox only', function () {
    LogAccessPoint::$inbox['acct-B'] = [tenancyInbound()];
    $this->artisan('peppol:sync')->assertSuccessful();

    expect(InboxMessage::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => InboxMessage::query()->count()))->toBe(1);
});

it('never takes a document addressed to B into A, even through A\'s account', function () {
    LogAccessPoint::$inbox['acct-A'] = [tenancyInbound()];
    $this->artisan('peppol:sync')->assertSuccessful();

    expect(app(AcceptPeppolDocument::class)($this->a, tenancyInbound()))->toBeNull()
        ->and(InboxMessage::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => InboxMessage::query()->count()))->toBe(0);
});
