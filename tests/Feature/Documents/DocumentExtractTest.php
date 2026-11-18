<?php

declare(strict_types=1);

use App\Actions\Documents\LinkDocument;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Documents\DocumentExtract;
use App\Domain\Documents\DocumentLinkType;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Tests\Feature\Documents\DocumentSupport;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * GDPR art. 15 (ADR-081 E19, review P2-4): the registerutdrag of a customer,
 * supplier or employee lists the company documents linked to them, with
 * title, class, versions, dates and the files for someone allowed to open
 * them. Personnel documents only with payroll:view-sensitive.
 */

beforeEach(function () {
    $this->root = DocumentSupport::useTemporaryDisk();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

afterEach(fn () => DocumentSupport::removeTemporaryDisk($this->root));

it('lists the documents linked to a customer with versions and files', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunden Exempel AB', 'payment_terms_days' => 30]);
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Ramavtal med kunden']);
    app(LinkDocument::class)($this->owner, $document, 'customer', $customer->id);
    DocumentSupport::store($this->owner, $this->company, ['title' => 'Inte kopplat']);

    $json = $this->get(route('privacy.export.customer', $customer))->assertOk()->json();

    expect($json['dokument'])->toHaveCount(1)
        ->and($json['dokument'][0]['rubrik'])->toBe('Ramavtal med kunden')
        ->and($json['dokument'][0]['sparas_som'])->toBe('OTHER')
        ->and($json['dokument'][0]['versioner'][0]['sha256'])->toBe($document->currentVersion()->firstOrFail()->sha256)
        ->and($json['dokument'][0]['versioner'][0]['fil'])->toBe(route('documents.file', [$document->id, $document->current_version_id]))
        ->and($json['dokument_utan_behorighet'])->toBe(0);
});

it('lists the documents linked to a supplier', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Leverantören Exempel AB', 'payment_terms_days' => 30]);
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Leveransavtal']);
    app(LinkDocument::class)($this->owner, $document, 'supplier', $supplier->id);

    expect($this->get(route('privacy.export.supplier', $supplier))->assertOk()->json('dokument.0.rubrik'))->toBe('Leveransavtal');
});

it('lists an employee\'s personnel documents to the payroll administrator', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    $admin = Bookkeeping::member($this->company, 'payroll_admin');
    $employee = Payroll::employee($admin, $this->company);
    $document = DocumentSupport::store($admin, $this->company, ['title' => 'Anställningsavtal', 'retention_class' => 'PERSONNEL', 'contains_personal_data' => '1']);
    app(LinkDocument::class)($admin, $document, 'employee', $employee->id);

    $json = $this->actingAs($admin)->get(route('privacy.export.employee', $employee))->assertOk()->json();

    expect($json['dokument'][0]['rubrik'])->toBe('Anställningsavtal')
        ->and($json['dokument'][0]['versioner'][0]['fil'])->not->toBeNull();
});

it('counts, but never names, linked documents the requester may not read', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunden Exempel AB', 'payment_terms_days' => 30]);
    $document = DocumentSupport::store($this->owner, $this->company, ['title' => 'Hemligt ramavtal']);
    app(LinkDocument::class)($this->owner, $document, 'customer', $customer->id);
    $user = Bookkeeping::member($this->company, 'bookkeeper');
    // A member who may update customers but not read documents.
    $this->company->users()->updateExistingPivot($user->id, ['role' => 'approver']);

    $json = DocumentExtract::for(DocumentLinkType::Customer, $customer->id, $user->fresh(), $this->company);

    expect($json['dokument'])->toBe([])
        ->and($json['dokument_utan_behorighet'])->toBe(1)
        ->and(json_encode($json))->not->toContain('Hemligt ramavtal');
});
