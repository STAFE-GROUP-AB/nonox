<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\EraseSigner;
use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Livewire\Company\SigningPrivacy;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\SendWizard;
use App\Modules\Signing\Livewire\TemplatePage;
use App\Modules\Signing\Livewire\TemplatesPage;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\SigningModule;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * Templates with roles (ADR-046 amendment 2026-11-18, gap 8, package S14):
 * saving the wizard as a template, sending from one with the roles mapped to
 * recipients, quote and agreement templates, who may change what, archiving,
 * and what the database holds. The PDF engine is the fake one (A4 pages).
 */

function s14Store(User $user, Company $company, string $title = 'Samarbetsavtal'): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's14');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal.pdf', $title);
    } finally {
        @unlink($path);
    }
}

/** @return array<string, mixed> */
function s14Input(string $name = 'Samarbetsavtal', array $extra = []): array
{
    return [
        'name' => $name,
        'document_type' => 'DOCUMENT',
        'roles' => [
            ['label' => 'Kund', 'step' => 2, 'verification' => 'EMAIL_CODE', 'internal' => false],
            ['label' => 'Säljare', 'step' => 1, 'verification' => 'NONE', 'internal' => true],
        ],
        'fields' => [
            ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Kundens underskrift', 'required' => true],
            ['signer' => 0, 'type' => 'DATE', 'page' => 1, 'x' => 30000, 'y' => 5000, 'width' => 12000, 'height' => 2000, 'label' => 'Kundens datum', 'required' => true],
            ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 2, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => 'Säljarens underskrift', 'required' => true],
        ],
        ...$extra,
    ];
}

function s14Template(User $user, Company $company, string $name = 'Samarbetsavtal', array $extra = []): SigningTemplate
{
    return app(ManageSigningTemplates::class)->create($user, $company, s14Input($name, $extra), s14Store($user, $company, $name));
}

function s14Quote(User $user, Company $company): SalesDocument
{
    $customer = app(SaveCustomer::class)($user, $company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);

    return app(SaveSalesDocument::class)($user, $company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
});

it('saves the send wizard as a template: the signers become roles, the fields follow their role, and no address is kept', function () {
    $document = s14Store($this->owner, $this->company);

    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])
        ->set('signers.0.email', 'kund@example.test')->set('signers.0.role', 'Kund')
        ->call('addSigner')->set('signers.1.email', 'saljare@example.test')->set('signers.1.role', 'Säljare')->set('signers.1.step', 1)->set('signers.0.step', 2)
        ->call('next')->assertSet('step', 3)
        ->set('new.signer', 0)->call('addField')
        ->set('new.signer', 1)->set('new.preset', 'top-left')->call('addField')
        ->call('next')->call('next')->call('next')->assertSet('step', 6)
        ->set('templateName', 'Mitt avtal')->call('saveAsTemplate')->assertHasNoErrors();

    $template = SigningTemplate::query()->sole();
    $roles = $template->roles()->get();

    expect($template->name)->toBe('Mitt avtal')
        ->and($template->document_type)->toBe('DOCUMENT')
        ->and($template->sha256)->toBe($document->sha256)
        ->and($template->path)->not->toBe($document->path)
        ->and(Storage::disk('local')->exists((string) $template->path))->toBeTrue()
        ->and($roles->pluck('label')->all())->toBe(['Kund', 'Säljare'])
        ->and($roles->pluck('key')->all())->toBe(['kund', 'saljare'])
        ->and($roles->pluck('step')->all())->toBe([2, 1])
        ->and(SigningField::query()->where('template_id', $template->id)->orderBy('position')->pluck('role_key')->all())->toBe(['kund', 'saljare'])
        ->and(SigningField::query()->whereNotNull('envelope_id')->count())->toBe(0)
        ->and(json_encode($template->toArray()).json_encode($roles->toArray()))->not->toContain('@');

    $wizard->assertSet('templateId', $template->id);
});

it('starts a send from a template: a copy of the PDF, one signer row per role, the fields on their role, and the envelope remembers the template', function () {
    $template = s14Template($this->owner, $this->company, 'Samarbetsavtal', ['deadline_days' => 14, 'auto_reminders' => true, 'reminder_days' => 2, 'copies' => [['email' => 'arkiv@foretaget.test', 'name' => 'Arkivet', 'language' => 'sv']]]);
    $before = SigningDocument::query()->count();

    Livewire::test(SendWizard::class)->call('startFromTemplate', $template->id)->assertRedirect();

    $copy = SigningDocument::query()->latest('created_at')->firstOrFail();
    expect(SigningDocument::query()->count())->toBe($before + 1)
        ->and($copy->sha256)->toBe($template->sha256)->and($copy->path)->not->toBe($template->path)->and($copy->title)->toBe('Samarbetsavtal');

    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->assertSet('step', 2)
        ->assertSet('templateId', $template->id)
        ->assertSet('signers.0.role', 'Kund')->assertSet('signers.0.email', '')->assertSet('signers.0.step', 2)->assertSet('signers.0.verification', 'EMAIL_CODE')
        ->assertSet('signers.1.role', 'Säljare')->assertSet('signers.1.step', 1)
        ->assertSet('internalSigners', [1])
        ->assertSet('expiresOn', '2026-06-29')->assertSet('reminderDays', '2')
        ->assertSet('copies.0.email', 'arkiv@foretaget.test')
        ->assertCount('fields', 3)
        ->assertSee('Mall: Samarbetsavtal');

    // The company's own role is suggested a member; the address is the member's.
    $wizard->call('suggestMember', 1, (string) $this->owner->id)->assertSet('signers.1.email', $this->owner->email);
    $wizard->call('suggestMember', 0, (string) $this->owner->id)->assertSet('signers.0.email', '');

    $wizard->set('signers.0.email', 'kund@example.test')
        ->call('next')->assertSet('step', 3)->assertHasNoErrors()
        ->call('next')->assertSet('step', 4)
        ->call('next')->call('next')->assertSet('step', 6)
        ->call('send')->assertHasNoErrors();

    $envelope = SigningEnvelope::query()->sole();
    $kund = SigningRequest::query()->where('envelope_id', $envelope->id)->where('email', 'kund@example.test')->sole();
    $saljare = SigningRequest::query()->where('envelope_id', $envelope->id)->where('email', mb_strtolower($this->owner->email))->sole();

    expect($envelope->template_id)->toBe($template->id)
        ->and($kund->step)->toBe(2)->and($kund->verification->value)->toBe('EMAIL_CODE')
        ->and($saljare->step)->toBe(1)
        ->and(SigningField::query()->where('signing_request_id', $kund->id)->orderBy('position')->pluck('label')->all())->toBe(['Kundens underskrift', 'Kundens datum'])
        ->and(SigningField::query()->where('signing_request_id', $saljare->id)->pluck('label')->all())->toBe(['Säljarens underskrift'])
        ->and(DB::table('signing_envelopes')->where('id', $envelope->id)->value('deadline_at'))->not->toBeNull();
});

it('gives another document the roles and the defaults of a template but never its fields', function () {
    $template = s14Template($this->owner, $this->company);
    $path = tempnam(sys_get_temp_dir(), 's14');
    file_put_contents($path, PdfEngineFixtures::pdf(3));
    $document = app(StoreSigningDocument::class)($this->owner, $this->company, $path, 'Annat.pdf', 'Annat avtal');
    @unlink($path);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])
        ->call('useTemplate', $template->id)
        ->assertSet('templateId', $template->id)
        ->assertCount('signers', 2)->assertSet('signers.0.role', 'Kund')
        ->assertCount('fields', 0);
});

it('makes templates for quotes and agreements with roles and defaults only, and applies one to a quote', function () {
    Livewire::test(TemplatePage::class)
        ->set('name', 'Standardoffert')->call('setType', 'QUOTE')
        ->set('roles.0.label', 'Kund')->set('roles.0.step', 1)
        ->call('addRole')->set('roles.1.label', 'Säljare')->set('roles.1.internal', true)
        ->set('deadlineDays', '10')->set('reminderDays', '7')
        ->call('addCopy')->set('copies.0.email', 'ekonomi@foretaget.test')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('signing.templates'));

    $template = SigningTemplate::query()->sole();
    expect($template->document_type)->toBe('QUOTE')->and($template->path)->toBeNull()->and($template->roles()->count())->toBe(2)->and($template->deadline_days)->toBe(10)->and($template->reminder_days)->toBe(7)
        ->and(SigningField::query()->where('template_id', $template->id)->count())->toBe(0);

    $quote = s14Quote($this->owner, $this->company);

    Livewire::test(SendWizard::class, ['type' => 'QUOTE', 'documentId' => $quote->id])
        ->assertSee('Använd en mall')
        ->call('useTemplate', $template->id)
        ->assertSet('signers.0.role', 'Kund')->assertSet('signers.0.email', 'inkop@kontorshuset.test')
        ->assertSet('signers.1.role', 'Säljare')->assertSet('expiresOn', '2026-06-25')
        ->set('signers.1.email', 'saljare@foretaget.test')->set('signers.1.step', 2)
        ->call('next')->call('next')->call('next')->call('next')->assertSet('step', 6)
        ->call('send')->assertHasNoErrors();

    expect(SigningEnvelope::query()->sole()->template_id)->toBe($template->id);
});

it('refuses a template of another document type, an archived template and one that does not exist', function () {
    $template = s14Template($this->owner, $this->company);
    $quote = s14Quote($this->owner, $this->company);

    Livewire::test(SendWizard::class, ['type' => 'QUOTE', 'documentId' => $quote->id, 'template' => $template->id])->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'QUOTE', 'documentId' => $quote->id])->call('useTemplate', 'not-a-uuid')->assertNotFound();

    app(ManageSigningTemplates::class)->archive($this->owner, $this->company, $template);
    $document = s14Store($this->owner, $this->company);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])->call('useTemplate', $template->id)->assertNotFound();
    Livewire::test(SendWizard::class)->call('startFromTemplate', $template->id)->assertNotFound();
    expect(fn () => app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template->refresh()))->toThrow(ValidationException::class);
});

it('validates the template: a name, one to ten roles each named once, steps, checks, and fields that fit the pages', function () {
    $manage = app(ManageSigningTemplates::class);
    $document = s14Store($this->owner, $this->company);

    foreach ([
        ['name' => ''],
        ['roles' => []],
        ['roles' => [['label' => 'A', 'step' => 1], ['label' => 'a', 'step' => 2]]],
        ['roles' => [['label' => 'A', 'step' => 11]]],
        ['roles' => [['label' => 'A', 'step' => 1, 'verification' => 'BANKID']]],
        ['deadline_days' => 1],
        ['reminder_days' => 5],
        ['copies' => [['email' => 'x@example.test'], ['email' => 'X@example.test']]],
        ['fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 59000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Utanför'], ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'B']]],
    ] as $broken) {
        expect(fn () => $manage->create($this->owner, $this->company, s14Input('Mall', $broken), $document))->toThrow(ValidationException::class);
    }

    // A signer without a signature field is refused as at a send, and a confidential quote template is too.
    expect(fn () => $manage->create($this->owner, $this->company, s14Input('Mall', ['fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Bara kund']]]), $document))->toThrow(ValidationException::class, 'minst ett underskriftsfält')
        ->and(fn () => $manage->create($this->owner, $this->company, ['name' => 'Offert', 'document_type' => 'QUOTE', 'roles' => [['label' => 'Kund', 'step' => 1]], 'confidential' => true]))->toThrow(ValidationException::class, 'konfidentiella')
        ->and(fn () => $manage->create($this->owner, $this->company, s14Input('Mall')))->toThrow(ValidationException::class)
        ->and(SigningTemplate::query()->count())->toBe(0);
});

it('changes the roles and the defaults of a template: a renamed role keeps its fields, a removed role takes its fields with it', function () {
    $template = s14Template($this->owner, $this->company);
    $manage = app(ManageSigningTemplates::class);

    Livewire::test(TemplatePage::class, ['templateId' => $template->id])
        ->assertSet('name', 'Samarbetsavtal')->assertSet('roles.0.key', 'kund')
        ->set('roles.0.label', 'Beställare')->set('deadlineDays', '20')
        ->call('save')->assertHasNoErrors();

    $template->refresh();
    expect($template->roles()->pluck('label', 'key')->all())->toBe(['kund' => 'Beställare', 'saljare' => 'Säljare'])
        ->and($template->deadline_days)->toBe(20)
        ->and(SigningField::query()->where('template_id', $template->id)->where('role_key', 'kund')->count())->toBe(2);

    Livewire::test(TemplatePage::class, ['templateId' => $template->id])->call('removeRole', 1)->call('save')->assertHasNoErrors();

    expect($template->roles()->pluck('key')->all())->toBe(['kund'])
        ->and(SigningField::query()->where('template_id', $template->id)->pluck('role_key')->unique()->all())->toBe(['kund']);

    // The file and the type are fixed, in the model and in the database.
    expect(fn () => $template->forceFill(['sha256' => str_repeat('a', 64)])->save())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_templates')->where('id', $template->id)->update(['page_count' => 9])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_templates')->where('id', $template->id)->update(['document_type' => 'QUOTE', 'path' => null])))->toThrow(QueryException::class);

    // "Uppdatera mallen" in the wizard replaces the roles, the fields and the defaults.
    $copy = $manage->start($this->owner, $this->company, $template->refresh());
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->set('signers.0.email', 'kund@example.test')->set('signers.0.role', 'Motpart')
        ->call('next')->set('selected', 0)->call('removeField', 1)
        ->call('next')->call('next')->call('next')
        ->call('updateTemplate')->assertHasNoErrors();

    expect($template->roles()->pluck('label', 'key')->all())->toBe(['kund' => 'Motpart'])
        ->and(SigningField::query()->where('template_id', $template->id)->count())->toBe(1);
});

it('lets the creator change a template and signing:manage everyone\'s, and nobody without signing:send see them', function () {
    $template = s14Template($this->owner, $this->company);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $admin = Bookkeeping::member($this->company, 'admin');
    $manage = app(ManageSigningTemplates::class);

    $this->actingAs($auditor);
    Livewire::test(TemplatesPage::class)->assertNotFound();
    $this->get(route('signing.templates'))->assertNotFound();

    $this->actingAs($bookkeeper);
    Livewire::test(TemplatesPage::class)->assertOk()->assertSee('Samarbetsavtal')->assertDontSeeHtml('wire:click="archive(');
    Livewire::test(TemplatePage::class, ['templateId' => $template->id])->assertNotFound();
    expect(fn () => $manage->archive($bookkeeper, $this->company, $template))->toThrow(AuthorizationException::class);

    $own = s14Template($bookkeeper, $this->company, 'Min mall');
    Livewire::test(TemplatesPage::class)->assertSee('Min mall');
    $manage->archive($bookkeeper, $this->company, $own);
    expect($own->refresh()->isArchived())->toBeTrue();

    $this->actingAs($admin);
    $manage->archive($admin, $this->company, $template);
    Livewire::test(TemplatePage::class, ['templateId' => $own->id])->assertOk();
    $manage->restore($admin, $this->company, $template);
    expect($template->refresh()->isArchived())->toBeFalse();

    // The module must be on.
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->actingAs($this->owner);
    $this->get(route('signing.templates'))->assertNotFound();
    expect(fn () => $manage->archive($this->owner, $this->company, $template))->toThrow(NotFoundHttpException::class);
});

it('removes a template that was never used with its file, and archives one that was', function () {
    $manage = app(ManageSigningTemplates::class);
    $unused = s14Template($this->owner, $this->company, 'Oanvänd');
    $used = s14Template($this->owner, $this->company, 'Använd');
    $path = (string) $unused->path;

    $copy = $manage->start($this->owner, $this->company, $used);
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $used->id])
        ->set('signers.0.email', 'a@example.test')->set('signers.1.email', 'b@example.test')
        ->call('next')->call('next')->call('next')->call('next')->call('send')->assertHasNoErrors();

    expect(fn () => $manage->delete($this->owner, $this->company, $used))->toThrow(ValidationException::class, 'bara arkiveras');

    $manage->delete($this->owner, $this->company, $unused);

    expect(SigningTemplate::query()->pluck('name')->all())->toBe(['Använd'])
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(SigningField::query()->where('template_id', $unused->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'signing.template_deleted')->count())->toBe(1);

    $manage->archive($this->owner, $this->company, $used);
    Livewire::test(TemplatesPage::class)->assertDontSee('Använd')->set('show', 'arkiverade')->assertSee('Använd');
});

it('keeps the audit trail of a template to ids and counts, never its name, roles or addresses', function () {
    $template = s14Template($this->owner, $this->company, 'Hemligt namn', ['copies' => [['email' => 'arkiv@foretaget.test']]]);

    $payload = (string) DB::table('audit_logs')->where('action', 'signing.template_created')->value('payload');

    expect($payload)->toContain($template->id)->and($payload)->not->toContain('Hemligt namn')->not->toContain('Kund')->not->toContain('arkiv@foretaget.test');
});

it('keeps the two sides of a signing field apart in the database', function () {
    $template = s14Template($this->owner, $this->company);
    $field = SigningField::query()->where('template_id', $template->id)->firstOrFail();
    $row = collect($field->getAttributes())->except(['id'])->all();

    // Neither side, and both sides, are refused; a role the template does not have is refused.
    expect(fn () => DB::transaction(fn () => DB::table('signing_fields')->insert([...$row, 'template_id' => null, 'role_key' => null, 'options' => null, 'rules' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_fields')->insert([...$row, 'role_key' => 'finns-inte', 'options' => null, 'rules' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_fields')->insert([...$row, 'envelope_id' => (string) Str::uuid(), 'options' => null, 'rules' => null])))->toThrow(QueryException::class);

    // A template's field moves nowhere: not to another template and not to an envelope.
    expect(fn () => DB::transaction(fn () => DB::table('signing_fields')->where('id', $field->id)->update(['company_id' => $this->company->id + 1])))->toThrow(QueryException::class);
});

it('runs a fixed number of queries on the template list, however many templates there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('signing.templates'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $this->get(route('signing.templates'))->assertOk();
    s14Template($this->owner, $this->company, 'Första');
    $this->get(route('signing.templates'))->assertSee('Första');
    $few = $count();

    foreach (range(1, 6) as $number) {
        s14Template($this->owner, $this->company, "Mall {$number}");
    }

    expect($count())->toBe($few);
});

it('carries a template through the wizard\'s review: the role names, and the save form with its two buttons', function () {
    $template = s14Template($this->owner, $this->company);
    $copy = app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->assertSet('signers.0.role', 'Kund')->assertSee('Föreslå en medlem')
        ->set('signers.0.email', 'a@example.test')->set('signers.1.email', 'b@example.test')
        ->call('next')->call('next')->call('next')->call('next')
        ->assertSee('Spara som mall')->assertSee('Uppdatera mallen')
        ->call('clearTemplate')->assertSet('templateId', null)->assertDontSee('Uppdatera mallen')
        ->call('send')->assertHasNoErrors();

    expect(SigningEnvelope::query()->sole()->template_id)->toBeNull();
});

it('never lets another PDF change a template\'s fields: the button is hidden and the update refuses fields server-side', function () {
    $template = s14Template($this->owner, $this->company);
    $path = tempnam(sys_get_temp_dir(), 's14');
    file_put_contents($path, PdfEngineFixtures::pdf(3));
    $other = app(StoreSigningDocument::class)($this->owner, $this->company, $path, 'Annat.pdf', 'Annat avtal');
    @unlink($path);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $other->id])
        ->call('useTemplate', $template->id)
        ->assertSet('templateFieldsApplied', false)
        ->set('signers.0.email', 'a@example.test')->set('signers.0.role', 'Motpart')
        ->call('next')->call('next')->call('next')->call('next')
        ->assertDontSee('Uppdatera mallen')
        ->call('updateTemplate')->assertHasNoErrors();

    // Roles and defaults were updated, the template's own fields were kept.
    expect($template->roles()->pluck('label')->all())->toContain('Motpart')
        ->and(SigningField::query()->where('template_id', $template->id)->count())->toBe(3);

    expect(fn () => app(ManageSigningTemplates::class)->update($this->owner, $this->company, $template->refresh(), [...s14Input('Samarbetsavtal'), 'document_sha256' => $other->sha256]))
        ->toThrow(ValidationException::class, 'mallens egen PDF')
        ->and(SigningField::query()->where('template_id', $template->id)->count())->toBe(3);

    $copy = app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template->refresh());
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->assertSet('templateFieldsApplied', true)->assertSet('step', 2);
});

it('keeps a confidential template from everyone but its creator and signing:manage', function () {
    $creator = Bookkeeping::member($this->company, 'bookkeeper');
    $other = Bookkeeping::member($this->company, 'bookkeeper');
    $admin = Bookkeeping::member($this->company, 'admin');
    $secret = s14Template($creator, $this->company, 'Hemlig mall', ['confidential' => true]);
    $manage = app(ManageSigningTemplates::class);

    $this->actingAs($other);
    Livewire::test(TemplatesPage::class)->assertDontSee('Hemlig mall')->call('begin', $secret->id)->assertNotFound();
    Livewire::test(SendWizard::class)->assertDontSee('Hemlig mall')->call('startFromTemplate', $secret->id)->assertNotFound();
    expect(fn () => $manage->start($other, $this->company, $secret))->toThrow(NotFoundHttpException::class);
    $document = s14Store($other, $this->company);
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])->assertDontSee('Hemlig mall')->call('useTemplate', $secret->id)->assertNotFound();

    foreach ([$creator, $admin] as $member) {
        $this->actingAs($member);
        Livewire::test(TemplatesPage::class)->assertSee('Hemlig mall');
        expect($manage->start($member, $this->company, $secret))->toBeInstanceOf(SigningDocument::class);
    }
});

it('puts a template\'s default copy recipients in the register extract and removes them on erasure, with ids only in the audit log', function () {
    s14Template($this->owner, $this->company, 'Mall A', ['copies' => [['email' => 'Arkiv@Foretaget.test', 'name' => 'Arkivet', 'language' => 'sv'], ['email' => 'annan@foretaget.test']]]);
    s14Template($this->owner, $this->company, 'Mall B', ['copies' => [['email' => 'arkiv@foretaget.test']]]);
    s14Template($this->owner, $this->company, 'Mall C', ['copies' => [['email' => 'ingen@foretaget.test']]]);

    $extract = DataSubjectExport::signer('arkiv@foretaget.test');
    expect(collect($extract['mallar_som_kopiemottagare'])->pluck('namn')->sort()->values()->all())->toBe(['Mall A', 'Mall B'])
        ->and(collect($extract['mallar_som_kopiemottagare'])->firstWhere('namn', 'Mall A')['namn_pa_kopiemottagaren'])->toBe('Arkivet');

    $result = app(EraseSigner::class)($this->owner, $this->company, 'arkiv@foretaget.test');

    expect($result['deleted'])->toBe(0)
        ->and(SigningTemplate::query()->where('name', 'Mall A')->sole()->copies)->toEqual([['email' => 'annan@foretaget.test', 'name' => null, 'language' => 'sv']])
        ->and(SigningTemplate::query()->where('name', 'Mall B')->sole()->copies)->toBe([])
        ->and(SigningTemplate::query()->where('name', 'Mall C')->sole()->copies)->toHaveCount(1)
        ->and(DataSubjectExport::signer('arkiv@foretaget.test')['mallar_som_kopiemottagare'])->toBe([]);

    $payloads = DB::table('audit_logs')->whereIn('action', ['signing.template_copy_erased', 'privacy.erased'])->pluck('payload')->implode(' ');
    expect($payloads)->not->toContain('arkiv@foretaget.test')->not->toContain('Mall A')->and(DB::table('audit_logs')->where('action', 'signing.template_copy_erased')->count())->toBe(2);

    // The settings page finds the address through a template alone.
    $page = Livewire::test(SigningPrivacy::class, ['company' => $this->company])->set('email', 'annan@foretaget.test')->call('search');
    $page->assertSee('Förvald kopiemottagare i mallar')->assertSee('Mall A');
});

it('moves the company\'s own role with its row when a signer row is removed', function () {
    $template = s14Template($this->owner, $this->company);
    $copy = app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->assertSet('internalSigners', [1])
        ->call('removeSigner', 0)
        ->assertSet('internalSigners', [0])->assertSet('signers.0.role', 'Säljare')
        ->call('addSigner')->call('removeSigner', 0)
        ->assertSet('internalSigners', []);
});

it('fixes the template of an envelope in the database, requires the same company, and counts only active templates against the limit', function () {
    $template = s14Template($this->owner, $this->company);
    $copy = app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->set('signers.0.email', 'a@example.test')->set('signers.1.email', 'b@example.test')
        ->call('next')->call('next')->call('next')->call('next')->call('send')->assertHasNoErrors();
    $envelope = SigningEnvelope::query()->sole();
    $second = s14Template($this->owner, $this->company, 'Andra');

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['template_id' => $second->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['template_id' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['template_id' => (string) Str::uuid()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_templates')->where('id', $template->id)->update(['bytes' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_templates')->where('id', $template->id)->update(['file_name' => 'x.pdf'])))->toThrow(QueryException::class);

    // The limit counts active templates only: archived ones do not use it up.
    DB::table('signing_templates')->where('company_id', $this->company->id)->update(['archived_at' => now()]);
    $rows = [];

    foreach (range(1, SigningTemplate::MAX_PER_COMPANY - 1) as $number) {
        $rows[] = ['id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'name' => "Fyllnad {$number}", 'document_type' => 'QUOTE', 'language' => 'sv', 'created_at' => now(), 'updated_at' => now()];
    }

    DB::table('signing_templates')->insert($rows);

    expect(s14Template($this->owner, $this->company, 'Den tvåhundrade')->name)->toBe('Den tvåhundrade')
        ->and(fn () => s14Template($this->owner, $this->company, 'Över gränsen'))->toThrow(ValidationException::class, 'Det finns redan 200 mallar');
});

it('still updates an envelope whose template row is gone, and sends only with a template the sender may see', function () {
    $template = s14Template($this->owner, $this->company);
    $copy = app(ManageSigningTemplates::class)->start($this->owner, $this->company, $template);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $copy->id, 'template' => $template->id])
        ->set('signers.0.email', 'a@example.test')->set('signers.1.email', 'b@example.test')
        ->call('next')->call('next')->call('next')->call('next')->call('send')->assertHasNoErrors();
    $envelope = SigningEnvelope::query()->sole();

    // A delete that raced the send: the envelope keeps its id, the row is gone, later updates must not fail.
    DB::table('signing_templates')->where('id', $template->id)->delete();
    DB::table('signing_envelopes')->where('id', $envelope->id)->update(['current_step' => 2, 'reminder_interval_days' => 2]);

    expect(DB::table('signing_envelopes')->where('id', $envelope->id)->value('current_step'))->toBe(2);

    // A confidential template of someone else is not remembered by a sender who may not see it.
    $creator = Bookkeeping::member($this->company, 'bookkeeper');
    $other = Bookkeeping::member($this->company, 'bookkeeper');
    $secret = s14Template($creator, $this->company, 'Hemlig', ['confidential' => true]);
    $document = s14Store($other, $this->company, 'Eget');

    $sent = app(SendForSigning::class)->envelope($other, $this->company, $document, [
        'signers' => [['email' => 'c@example.test', 'step' => 1]],
        'template_id' => $secret->id,
    ], minSigners: 1);

    expect($sent->refresh()->template_id)->toBeNull();
});
