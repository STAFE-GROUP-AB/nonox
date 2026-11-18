<?php

declare(strict_types=1);

use App\Actions\Documents\AddDocumentVersion;
use App\Actions\Documents\LinkDocument;
use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Documents\DocumentArchive;
use App\Livewire\Documents\DocumentPage;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;

/*
 * Dokument (ADR-081 gap 9, E19): the list with search and filters, the
 * upload form and the document page, with Swedish toasts and a fixed number
 * of queries whatever the number of documents, versions and links.
 */

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('shows an empty archive with the upload button at /dokument', function () {
    $this->get('/dokument')->assertOk()->assertSee('Inga dokument ännu')->assertSee('Ladda upp dokument');
});

it('uploads a document from the form and shows a Swedish toast', function () {
    Livewire::test(DocumentArchive::class)
        ->set('showForm', true)
        ->set('form.title', 'Bygglov för förrådet')
        ->set('form.category', 'PERMIT')
        ->set('form.retention_class', 'CONTRACT')
        ->set('form.contract_ends_on', '2030-12-31')
        ->set('form.contract_retention_years', '1')
        ->set('form.contains_personal_data', '0')
        ->set('form.tags', 'bygglov, förråd')
        ->set('upload', DocumentSupport::pdf('bygglov.pdf', 'Beslut om bygglov för förråd'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Dokumentet Bygglov för förrådet är sparat.')
        ->assertSee('Bygglov för förrådet')
        ->assertSee('2031-12-31');

    expect(Document::query()->where('title', 'Bygglov för förrådet')->value('retain_until'))->not->toBeNull();
});

it('shows the validation errors in Swedish when the form is incomplete', function () {
    Livewire::test(DocumentArchive::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['title', 'contains_personal_data']);
});

it('filters by text, category and class, kept in the address', function () {
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Hyresavtal kontor', 'category' => 'AGREEMENT']);
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Miljötillstånd', 'category' => 'PERMIT']);

    $this->get('/dokument?sok=hyres')->assertSee('Hyresavtal kontor')->assertDontSee('Miljötillstånd');
    $this->get('/dokument?kategori=PERMIT')->assertSee('Miljötillstånd')->assertDontSee('Hyresavtal kontor');
    $this->get('/dokument?klass=ACCOUNTING')->assertSee('Inga dokument matchar');
});

it('hides the upload form from the auditor and the page from the approver', function () {
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Policy för attest']);

    $this->actingAs(Bookkeeping::member($this->company, 'auditor'))->get('/dokument')->assertOk()->assertSee('Policy för attest')->assertDontSee('Ladda upp dokument');
    $this->actingAs(Bookkeeping::member($this->company, 'approver'))->get('/dokument')->assertForbidden();
});

it('shows Dokument in the navigation for those who may see it', function () {
    $this->get('/oversikt')->assertSee(route('documents.index'), false);
    $this->actingAs(Bookkeeping::member($this->company, 'approver'))->get('/oversikt')->assertDontSee(route('documents.index'), false);
});

it('lists documents in a fixed number of queries', function () {
    $queries = function (int $documents): int {
        while (Document::query()->count() < $documents) {
            $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Avtal '.Document::query()->count(), 'tags' => 'a, b']);
            app(AddDocumentVersion::class)($this->owner, $document, DocumentSupport::pdf('v2.pdf', 'Andra versionen av dokumentet'));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DocumentArchive::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $queries(1);

    expect($queries(8))->toBe($queries(2));
});

it('shows a document with many versions and links in a fixed number of queries', function () {
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Ramavtal']);
    $customers = collect(range(1, 4))->map(fn (int $n) => app(SaveCustomer::class)($this->owner, $this->company, ['name' => "Kund {$n} AB", 'payment_terms_days' => 30]));

    $queries = function (int $links, int $versions) use ($document, $customers): int {
        foreach ($customers->take($links) as $customer) {
            app(LinkDocument::class)($this->owner, $document, 'customer', $customer->id);
        }

        while (DocumentVersion::query()->where('document_id', $document->id)->count() < $versions) {
            app(AddDocumentVersion::class)($this->owner, $document, DocumentSupport::pdf('ny.pdf', 'En ny version av ramavtalet'));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(DocumentPage::class, ['document' => $document->fresh()]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $queries(1, 1);

    expect($queries(4, 5))->toBe($queries(1, 1));
});

it('edits, versions, holds and deletes a document from its page with Swedish toasts', function () {
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Gammal rubrik', 'tags' => 'gammal']);

    $this->get(route('documents.show', $document))->assertOk()->assertSee('Gammal rubrik')->assertSee('Radera dokumentet');

    $page = Livewire::test(DocumentPage::class, ['document' => $document])
        ->call('edit')
        ->assertSet('form.tags', 'gammal')
        ->set('form.title', 'Ny rubrik')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Dokumentets uppgifter är sparade.')
        ->set('newVersion', DocumentSupport::pdf('v2.pdf', 'Den andra versionen av dokumentet'))
        ->assertDispatched('toast', message: 'Version 2 är sparad. De tidigare versionerna finns kvar.')
        ->set('holdReason', 'SKATTEREVISION')
        ->call('setHold', true)
        ->assertDispatched('toast', message: 'Bevarandespärren är satt. Dokumentet kan inte raderas förrän den hävs.')
        ->assertSee('Dokumentet har bevarandespärr');

    $page->call('setHold', false)->call('deleteDocument')->assertRedirect(route('documents.index'));

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse();
});

it('links a document to a customer from its page', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunden Exempel AB', 'payment_terms_days' => 30]);
    $document = DocumentSupport::store($this->owner, $this->company);

    Livewire::test(DocumentPage::class, ['document' => $document])
        ->set('linkType', 'customer')
        ->assertSee('Kunden Exempel AB')
        ->set('linkId', (string) $customer->id)
        ->call('addLink')
        ->assertDispatched('toast', message: 'Kopplingen är sparad.')
        ->assertSee('Kund:');
});

it('serves a PDF version inline in the sandbox and logs the opening', function () {
    $document = DocumentSupport::store($this->owner, $this->company);

    $this->get(route('documents.file', [$document, $document->current_version_id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(AuditLog::query()->where('action', 'document.file_opened')->where('subject_id', $document->id)->exists())->toBeTrue();
});
