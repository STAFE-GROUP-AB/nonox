<?php

declare(strict_types=1);

use App\Actions\Documents\AttachDocumentToVoucher;
use App\Actions\Documents\LinkDocument;
use App\Actions\Documents\StoreDocument;
use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Documents\DocumentArchive;
use App\Livewire\Documents\DocumentPage;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentTag;
use App\Models\DocumentVersion;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the document archive (ADR-081 E19): company A never
 * sees B's documents, versions, tags or links through Eloquent, the list,
 * the document page, the file route or the actions; B's ids answer 404 and
 * B's records cannot be linked from A.
 */

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();

    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $customer = app(SaveCustomer::class)($owner, $company, ['name' => "Kund {$side}", 'payment_terms_days' => 30]);
        $document = DocumentSupport::store($owner, $company, ['title' => "Hemligt avtal {$side}", 'tags' => "tagg{$side}"], DocumentSupport::pdf("{$side}.pdf", "Hemligt innehåll i dokument {$side}"));
        $link = app(LinkDocument::class)($owner, $document, 'customer', $customer->id);
        $voucher = Bookkeeping::post($owner, $company);
        $this->{$side} = compact('owner', 'company', 'customer', 'document', 'link', 'voucher');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('never shows B\'s documents, versions, tags or links through Eloquent', function () {
    expect(Document::query()->pluck('id')->all())->toBe([$this->a['document']->id])
        ->and(DocumentVersion::query()->pluck('document_id')->all())->toBe([$this->a['document']->id])
        ->and(DocumentTag::query()->pluck('tag')->all())->toBe(['tagga'])
        ->and(DocumentLink::query()->pluck('id')->all())->toBe([$this->a['link']->id]);
});

it('cannot find B\'s document by id from A', function () {
    Document::query()->findOrFail($this->b['document']->id);
})->throws(ModelNotFoundException::class);

it('answers 404 for B\'s document page and files', function () {
    $this->get(route('documents.show', $this->b['document']->id))->assertNotFound();
    $this->get(route('documents.file', [$this->b['document']->id, $this->b['document']->current_version_id]))->assertNotFound();
    // A's document with B's version id.
    $this->get(route('documents.file', [$this->a['document']->id, $this->b['document']->current_version_id]))->assertNotFound();
});

it('does not list or find B\'s documents on A\'s list, by search either', function () {
    $this->get('/dokument')->assertOk()->assertSee('Hemligt avtal a')->assertDontSee('Hemligt avtal b');
    $this->get('/dokument?sok=taggb')->assertDontSee('Hemligt avtal b');
    $this->get('/dokument?sok=innehåll')->assertSee('Hemligt avtal a')->assertDontSee('Hemligt avtal b');

    Livewire::test(DocumentArchive::class)->assertDontSee('Hemligt avtal b');
});

it('answers 404 when A\'s page is asked for B\'s link or B\'s document', function () {
    Livewire::test(DocumentPage::class, ['document' => $this->a['document']])->call('removeLink', $this->b['link']->id)->assertNotFound();

    Livewire::test(DocumentPage::class, ['document' => $this->b['document']])->assertNotFound();
});

it('refuses B\'s customer, B\'s fiscal year and B\'s voucher in A\'s actions', function () {
    expect(fn () => app(LinkDocument::class)($this->a['owner'], $this->a['document'], 'customer', $this->b['customer']->id))
        ->toThrow(ValidationException::class, 'Välj en post i företaget')
        ->and(fn () => app(StoreDocument::class)($this->a['owner'], $this->a['company'], ['title' => 'X', 'category' => 'OTHER', 'retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $this->b['voucher']->fiscal_year_id, 'contains_personal_data' => '0'], DocumentSupport::pdf()))
        ->toThrow(ValidationException::class, 'Välj ett räkenskapsår i företaget');

    $accounting = DocumentSupport::store($this->a['owner'], $this->a['company'], ['retention_class' => 'ACCOUNTING', 'fiscal_year_id' => $this->a['voucher']->fiscal_year_id]);

    // B's voucher has the same reference (A1) in B's series; A's lookup only sees A's.
    $attachment = app(AttachDocumentToVoucher::class)($this->a['owner'], $accounting, $this->b['voucher']->reference());

    expect($attachment->voucher_id)->toBe($this->a['voucher']->id);
});
