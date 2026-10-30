<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Modules\Ai\Agents\SupplierInvoiceAgent;
use App\Modules\Ai\Agents\VoucherAgent;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Livewire\AiSettingsForm;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

const TEST_OPENAI_KEY = 'sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(AiModule::KEY, $this->company, $this->owner);
    $this->supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'org_number' => '556036-0793', 'payment_terms_days' => 30, 'default_account' => 4010]);
});

function withOpenAiKey(): void
{
    app(ModuleManager::class)->updateSettings(AiModule::KEY, test()->company, test()->owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString(TEST_OPENAI_KEY)]]);
}

function invoicePdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('faktura.pdf', "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF");
}

/** @return array<string, mixed> what the agent answers for the grocery invoice */
function invoiceAnswer(array $overrides = []): array
{
    return [
        'supplier_name' => 'Grossisten AB', 'supplier_org_number' => '556036-0793', 'supplier_vat_number' => '', 'supplier_bankgiro' => '', 'supplier_plusgiro' => '', 'supplier_iban' => '',
        'supplier_address' => '', 'supplier_postal_code' => '', 'supplier_city' => '', 'supplier_email' => '', 'supplier_phone' => '',
        'invoice_number' => '7001', 'ocr' => '7001234', 'invoice_date' => '2026-03-02', 'due_date' => '2026-04-01', 'currency' => 'SEK',
        'total' => 1568.0, 'vat_total' => 168.0, 'is_credit_note' => false, 'vat_treatment' => 'DOMESTIC', 'description' => 'Livsmedel och dryck',
        'rows' => [
            ['account' => 4010, 'amount_excl_vat' => 1000.0, 'vat_rate_percent' => 12, 'description' => 'Livsmedel'],
            ['account' => 4010, 'amount_excl_vat' => 400.0, 'vat_rate_percent' => 25, 'description' => 'Dryck'],
            ['account' => 9999, 'amount_excl_vat' => 1.0, 'vat_rate_percent' => 25, 'description' => 'Konto som inte finns'],
        ],
        'notes' => 'Förfallodagen är svår att läsa.',
        ...$overrides,
    ];
}

it('checks a new key with the provider and keeps it encrypted per provider', function () {
    Http::fake([
        'api.openai.com/v1/models' => Http::response(['data' => []]),
        'api.anthropic.com/v1/models' => Http::response(['error' => ['type' => 'authentication_error']], 401),
    ]);

    Livewire::test(AiSettingsForm::class, ['company' => $this->company])
        ->assertSee('OpenAI (ChatGPT)')
        ->assertSee('Anthropic (Claude)')
        ->assertSee('Google (Gemini)')
        ->assertSee('platform.openai.com')
        ->set('apiKey', 'inte-en-nyckel')
        ->call('save')
        ->assertHasErrors('apiKey')
        ->set('apiKey', TEST_OPENAI_KEY)
        ->call('save')
        ->assertDispatched('toast')
        ->assertSee('…ABCD')
        ->assertDontSee(TEST_OPENAI_KEY)
        ->set('provider', 'ANTHROPIC')
        ->assertSee('console.anthropic.com')
        ->set('apiKey', 'sk-ant-abcdefghijklmnopqrstuvwxyz0123456789')
        ->call('save')
        ->assertHasErrors('apiKey');

    $settings = app(ModuleManager::class)->settings(AiModule::KEY, $this->company);
    expect($settings['provider'])->toBe('OPENAI')
        ->and(Crypt::decryptString($settings['api_keys']['OPENAI']))->toBe(TEST_OPENAI_KEY)
        ->and($settings['api_keys'])->not->toHaveKey('ANTHROPIC');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/models' && $request->hasHeader('Authorization', 'Bearer '.TEST_OPENAI_KEY));
});

it('fills in a supplier invoice from the PDF through the chosen provider and saves nothing', function () {
    withOpenAiKey();
    SupplierInvoiceAgent::fake([invoiceAnswer()]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertDispatched('toast')
        ->assertSet('supplierId', (string) $this->supplier->id)
        ->assertSet('invoiceNumber', '7001')
        ->assertSet('ocr', '7001234')
        ->assertSet('invoiceDate', '2026-03-02')
        ->assertSet('dueDate', '2026-04-01')
        ->assertSet('total', "1\u{a0}568,00")
        ->assertSet('vat', '168,00')
        ->assertSet('rows', [
            ['account' => '4010', 'amount' => "1\u{a0}000,00", 'vat_rate' => '1200', 'description' => 'Livsmedel', 'cost_center' => '', 'project' => ''],
            ['account' => '4010', 'amount' => '400,00', 'vat_rate' => '2500', 'description' => 'Dryck', 'cost_center' => '', 'project' => ''],
        ])
        ->assertSee('Förfallodagen är svår att läsa.');

    SupplierInvoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->first() instanceof Document
        && str_contains($prompt->prompt, '4010 ')
        && $prompt->model === 'gpt-5-mini'
        && $prompt->provider->driver() === 'openai');

    expect(SupplierInvoice::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'ai.document_analyzed')->sole();
    expect($audit->payload)->toMatchArray(['kind' => 'supplier_invoice', 'provider' => 'OPENAI', 'model' => 'gpt-5-mini'])
        ->and(json_encode($audit->payload))->not->toContain('7001');
});

it('uses the company\'s chosen model and provider', function () {
    app(ModuleManager::class)->updateSettings(AiModule::KEY, $this->company, $this->owner, ['provider' => 'ANTHROPIC', 'model' => 'claude-sonnet-5-5', 'api_keys' => ['ANTHROPIC' => Crypt::encryptString('sk-ant-abcdefghijklmnopqrstuvwxyz0123456789')]]);
    SupplierInvoiceAgent::fake([invoiceAnswer()]);

    Livewire::test(SupplierInvoiceEditor::class)->set('file', invoicePdf())->call('analyze')->assertSet('invoiceNumber', '7001');

    SupplierInvoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'claude-sonnet-5-5' && $prompt->provider->driver() === 'anthropic');
});

it('creates and links a supplier that is not in the register when the person ticks it', function () {
    withOpenAiKey();
    SupplierInvoiceAgent::fake([invoiceAnswer(['supplier_name' => 'Scandinavian Photo', 'supplier_org_number' => '', 'supplier_vat_number' => 'SE556679651101', 'supplier_bankgiro' => '5050-1055', 'supplier_city' => 'Stockholm', 'supplier_email' => 'order@example.test'])]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertSet('supplierId', '')
        ->assertSee('Scandinavian Photo finns inte i leverantörsregistret.')
        ->assertSee('Org.nr 556679-6511')
        ->assertSet('createSupplier', true)
        ->call('save');

    $supplier = Supplier::query()->where('name', 'Scandinavian Photo')->sole();
    expect($supplier->org_number)->toBe('556679-6511')
        ->and($supplier->bankgiro)->toBe('5050-1055')
        ->and($supplier->email)->toBe('order@example.test')
        ->and($supplier->payment_terms_days)->toBe(30)
        ->and(SupplierInvoice::query()->sole()->supplier_id)->toBe($supplier->id);
});

it('leaves the register alone when the person unticks it', function () {
    withOpenAiKey();
    SupplierInvoiceAgent::fake([invoiceAnswer(['supplier_org_number' => '556677-8899', 'supplier_name' => 'Okänd AB'])]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->set('createSupplier', false)
        ->call('save')
        ->assertHasErrors('supplier_id');

    expect(Supplier::query()->where('name', 'Okänd AB')->exists())->toBeFalse();
});

it('says in Swedish when there is no key or the provider refuses, and keeps the form as it was', function () {
    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertDispatched('toast', message: 'NONOX AI saknar leverantör eller API-nyckel. Välj leverantör och lägg in nyckeln under Företagsinställningar → Moduler → NONOX AI.', type: 'error')
        ->assertSet('invoiceNumber', '');

    withOpenAiKey();
    SupplierInvoiceAgent::fake(fn () => throw RateLimitedException::forProvider('openai'));

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertDispatched('toast', message: 'OpenAI (ChatGPT) tar inte emot fler analyser just nu. Försök igen om en stund.', type: 'error')
        ->assertSet('invoiceNumber', '');
});

it('shows the analyse button only where NONOX AI is on and the user may use it', function () {
    $this->get(route('payables.invoices.create'))->assertSee('Analysera');

    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver)->get(route('payables.invoices.create'))->assertDontSee('Analysera');

    app(ModuleManager::class)->disable(AiModule::KEY, $this->company, $this->owner, true);
    $this->actingAs($this->owner)->get(route('payables.invoices.create'))->assertDontSee('Analysera');
});

it('proposes a balanced voucher from a receipt and posts nothing', function () {
    withOpenAiKey();
    VoucherAgent::fake([[
        'document_type' => 'RECEIPT', 'date' => '2026-03-05', 'description' => 'Kontorsmaterial, Pappershandeln', 'counterparty' => 'Pappershandeln', 'total' => 125.0, 'vat_total' => 25.0,
        'rows' => [
            ['account' => 6110, 'debit' => 100.0, 'credit' => 0.0, 'description' => 'Kontorsmaterial'],
            ['account' => 2641, 'debit' => 25.0, 'credit' => 0.0, 'description' => ''],
            ['account' => 1930, 'debit' => 0.0, 'credit' => 125.0, 'description' => ''],
        ],
        'notes' => '',
    ]]);

    $component = Livewire::test(VoucherEditor::class)
        ->set('uploads', [UploadedFile::fake()->image('kvitto.jpg', 400, 600)])
        ->call('analyze')
        ->assertSet('voucherDate', '2026-03-05')
        ->assertSet('description', 'Kontorsmaterial, Pappershandeln')
        ->assertDontSee('obetald leverantörsfaktura');

    $rows = $component->get('rows');
    expect(array_column(array_slice($rows, 0, 3), 'account'))->toBe(['6110', '2641', '1930'])
        ->and($rows[2]['credit'])->toBe('125,00')
        ->and(Voucher::query()->count())->toBe(0);

    VoucherAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->first() instanceof Image);
});

it('sends an unpaid supplier invoice from bookkeeping to the supplier invoice page, where the supplier can be created', function () {
    withOpenAiKey();
    VoucherAgent::fake([['document_type' => 'SUPPLIER_INVOICE', 'date' => '2026-03-02', 'description' => 'Faktura', 'counterparty' => 'Scandinavian Photo', 'total' => 1568.0, 'vat_total' => 168.0, 'rows' => [], 'notes' => '']]);
    SupplierInvoiceAgent::fake([invoiceAnswer(['supplier_name' => 'Scandinavian Photo', 'supplier_org_number' => '556679-6511'])]);

    Livewire::test(VoucherEditor::class)
        ->set('uploads', [invoicePdf()])
        ->call('analyze')
        ->assertSee('Det här ser ut som en obetald leverantörsfaktura.')
        ->call('toSupplierInvoice')
        ->assertRedirect(route('payables.invoices.create'));

    Livewire::test(SupplierInvoiceEditor::class)
        ->assertSet('analyzeOnLoad', true)
        ->assertSet('invoiceNumber', '')
        ->call('analyzeHandoff')
        ->assertSet('analyzeOnLoad', false)
        ->assertSet('invoiceNumber', '7001')
        ->assertSee('Scandinavian Photo finns inte i leverantörsregistret.')
        ->call('save');

    $created = SupplierInvoice::query()->sole();
    expect($created->file_path)->not->toBeNull()
        ->and(Supplier::query()->find($created->supplier_id)?->name)->toBe('Scandinavian Photo')
        ->and(session()->has(SupplierInvoiceEditor::HANDOFF))->toBeFalse();
});

it('does not take over another person\'s document', function () {
    session()->put(SupplierInvoiceEditor::HANDOFF, ['file' => 'someone-elses.pdf', 'user' => 999999, 'company' => $this->company->id]);
    SupplierInvoiceAgent::fake();

    Livewire::test(SupplierInvoiceEditor::class)->assertSet('file', null)->assertSet('analyzeOnLoad', false);
    SupplierInvoiceAgent::assertNeverPrompted();
});
