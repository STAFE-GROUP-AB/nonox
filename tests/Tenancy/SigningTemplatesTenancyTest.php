<?php

declare(strict_types=1);

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\SendWizard;
use App\Modules\Signing\Livewire\TemplatePage;
use App\Modules\Signing\Livewire\TemplatesPage;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Models\SigningTemplateRole;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * AGENTS.md §13.2 for signing templates (ADR-046 amendment, gap 8, S14):
 * company A never sees B's templates, roles or template fields, finds none
 * by id (404, not 403), cannot send from one, and the database refuses a
 * field or a role that points at another company's template.
 */

function s14TenancyTemplate(string $name): array
{
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    app(ModuleManager::class)->enable(SigningModule::KEY, $company, $owner);

    $path = tempnam(sys_get_temp_dir(), 's14t');
    file_put_contents($path, PdfEngineFixtures::pdf(1));
    $document = app(StoreSigningDocument::class)($owner, $company, $path, 'Avtal.pdf', $name);
    @unlink($path);

    $template = app(ManageSigningTemplates::class)->create($owner, $company, [
        'name' => $name,
        'roles' => [['label' => 'Kund', 'step' => 1], ['label' => 'Säljare', 'step' => 2]],
        'fields' => [
            ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => "{$name} kund"],
            ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => "{$name} säljare"],
        ],
    ], $document);

    return [$owner, $company, $template, $document];
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    [$this->ownerA, $this->companyA, $this->templateA, $this->documentA] = s14TenancyTemplate('Alfamallen');
    [$this->ownerB, $this->companyB, $this->templateB, $this->documentB] = s14TenancyTemplate('Betamallen');

    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA->refresh());
});

it('shows a company only its own templates, roles and template fields', function () {
    expect(SigningTemplate::query()->pluck('name')->all())->toBe(['Alfamallen'])
        ->and(SigningTemplateRole::query()->count())->toBe(2)
        ->and(SigningField::query()->whereNotNull('template_id')->pluck('label')->sort()->values()->all())->toBe(['Alfamallen kund', 'Alfamallen säljare'])
        ->and(SigningTemplate::query()->find($this->templateB->id))->toBeNull();

    Livewire::test(TemplatesPage::class)->assertSee('Alfamallen')->assertDontSee('Betamallen');
    $this->get(route('signing.templates'))->assertOk()->assertSee('Alfamallen')->assertDontSee('Betamallen');
});

it('answers 404 for another company\'s template on its pages, in the wizard and when starting a send', function () {
    $this->get(route('signing.template', $this->templateB->id))->assertNotFound();
    Livewire::test(TemplatePage::class, ['templateId' => $this->templateB->id])->assertNotFound();

    Livewire::test(SendWizard::class)->call('startFromTemplate', $this->templateB->id)->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $this->documentA->id])->call('useTemplate', $this->templateB->id)->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $this->documentA->id, 'template' => $this->templateB->id])->assertNotFound();

    Livewire::test(TemplatesPage::class)->call('archive', $this->templateB->id)->assertNotFound();
    Livewire::test(TemplatesPage::class)->call('remove', $this->templateB->id)->assertNotFound();
    Livewire::test(TemplatesPage::class)->call('begin', $this->templateB->id)->assertNotFound();

    expect($this->templateB->refresh()->isArchived())->toBeFalse()
        ->and(SigningDocument::query()->count())->toBe(1);
});

it('refuses the action on another company\'s template and its file never leaves it', function () {
    expect(fn () => app(ManageSigningTemplates::class)->archive($this->ownerA, $this->companyA, $this->templateB))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(ManageSigningTemplates::class)->start($this->ownerA, $this->companyA, $this->templateB))->toThrow(NotFoundHttpException::class);
});

it('does not let an envelope remember another company\'s template', function () {
    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $this->documentA->id, 'template' => $this->templateA->id])
        ->set('signers.0.email', 'a@example.test')->set('signers.1.email', 'b@example.test');

    // A template id of another company that reaches the send (it is locked in the component, so through the action).
    $document = SigningDocument::query()->findOrFail($this->documentA->id);
    app(SendForSigning::class)->envelope($this->ownerA, $this->companyA, $document, [
        'signers' => [['email' => 'a@example.test', 'step' => 1], ['email' => 'b@example.test', 'step' => 1]],
        'template_id' => $this->templateB->id,
    ]);

    expect(SigningEnvelope::query()->sole()->template_id)->toBeNull();
});

it('has the database refuse a role or a field that points at another company\'s template', function () {
    $roleB = SigningTemplateRole::query()->withoutGlobalScopes()->where('template_id', $this->templateB->id)->firstOrFail();
    $field = SigningField::query()->where('template_id', $this->templateA->id)->firstOrFail();
    $row = collect($field->getAttributes())->except(['id'])->all();

    expect(fn () => DB::transaction(fn () => DB::table('signing_template_roles')->insert(['company_id' => $this->companyA->id, 'template_id' => $this->templateB->id, 'key' => 'smuggel', 'label' => 'Smuggel', 'step' => 1, 'verification' => 'NONE', 'internal' => false, 'position' => 9])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_fields')->insert([...$row, 'company_id' => $this->companyA->id, 'template_id' => $this->templateB->id, 'role_key' => $roleB->key, 'options' => null, 'rules' => null])))->toThrow(QueryException::class);
});
