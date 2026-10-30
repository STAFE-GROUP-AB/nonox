<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Models\AuditLog;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Livewire\AiSettingsForm;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

const TEST_CORE_KEY = 'gzc_test_abcdefghijklmnopqrstuvwxyz0123456789ABCD';

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

function withCoreKey(): void
{
    app(ModuleManager::class)->updateSettings(AiModule::KEY, test()->company, test()->owner, ['api_key' => Crypt::encryptString(TEST_CORE_KEY)]);
}

function invoicePdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('faktura.pdf', "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF");
}

/** @return array<string, mixed> what Core answers for the grocery invoice */
function coreInvoiceAnswer(): array
{
    return [
        'id' => 'job-1', 'status' => 'succeeded',
        'output' => [
            'supplier_name' => 'Grossisten AB', 'supplier_org_number' => '556036-0793', 'supplier_vat_number' => '', 'supplier_bankgiro' => '', 'supplier_plusgiro' => '', 'supplier_iban' => '',
            'invoice_number' => '7001', 'ocr' => '7001234', 'invoice_date' => '2026-03-02', 'due_date' => '2026-04-01', 'currency' => 'SEK',
            'total' => 1568.0, 'vat_total' => 168.0, 'is_credit_note' => false, 'vat_treatment' => 'DOMESTIC', 'description' => 'Livsmedel och dryck',
            'rows' => [
                ['account' => 4010, 'amount_excl_vat' => 1000.0, 'vat_rate_percent' => 12, 'description' => 'Livsmedel'],
                ['account' => 4010, 'amount_excl_vat' => 400.0, 'vat_rate_percent' => 25, 'description' => 'Dryck'],
                ['account' => 9999, 'amount_excl_vat' => 1.0, 'vat_rate_percent' => 25, 'description' => 'Konto som inte finns'],
            ],
            'notes' => 'Förfallodagen är svår att läsa.',
        ],
        'meta' => ['confidence' => 0.91, 'units' => 1, 'quality' => 'standard'],
    ];
}

it('keeps the company\'s API key encrypted and shows only its ending', function () {
    Livewire::test(AiSettingsForm::class, ['company' => $this->company])
        ->assertSee('GoZero Core AI')
        ->set('apiKey', 'not-a-key')
        ->call('save')
        ->assertHasErrors('api_key')
        ->set('apiKey', TEST_CORE_KEY)
        ->call('save')
        ->assertDispatched('toast')
        ->assertSee('…ABCD')
        ->assertDontSee(TEST_CORE_KEY);

    $stored = app(ModuleManager::class)->settings(AiModule::KEY, $this->company)['api_key'];
    expect($stored)->not->toContain('gzc_')
        ->and(Crypt::decryptString($stored))->toBe(TEST_CORE_KEY);
});

it('fills in a supplier invoice from the PDF through GoZero Core and saves nothing', function () {
    withCoreKey();
    Http::fake(['api.gozerocore.com/v1/analyze' => Http::response(coreInvoiceAnswer())]);

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
        ->assertSee('Säkerhet: 91 %')
        ->assertSee('Förfallodagen är svår att läsa.');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer '.TEST_CORE_KEY)
            && $request->hasHeader('Idempotency-Key')
            && $body['options']['mode'] === 'sync'
            && $body['options']['language'] === 'sv'
            && $body['documents'][0]['mime'] === 'application/pdf'
            && str_contains($body['text'][0], '4010 ')
            && $body['output_schema']['type'] === 'object'
            && isset($body['output_schema']['properties']['rows']);
    });

    expect(SupplierInvoice::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'ai.document_analyzed')->sole();
    expect($audit->payload)->toMatchArray(['kind' => 'supplier_invoice', 'job_id' => 'job-1', 'units' => 1])
        ->and(json_encode($audit->payload))->not->toContain('7001');
});

it('polls a job Core runs in the background', function () {
    withCoreKey();
    Sleep::fake();
    app(ModuleManager::class)->updateSettings(AiModule::KEY, $this->company, $this->owner, ['quality' => 'high']);
    Http::fake([
        'api.gozerocore.com/v1/analyze' => Http::response(['id' => 'job-2', 'status' => 'queued', 'poll_url' => '/v1/jobs/job-2'], 202),
        'api.gozerocore.com/v1/jobs/job-2' => Http::sequence()->push(['id' => 'job-2', 'status' => 'running'])->push([...coreInvoiceAnswer(), 'id' => 'job-2']),
    ]);

    Livewire::test(SupplierInvoiceEditor::class)->set('file', invoicePdf())->call('analyze')->assertSet('invoiceNumber', '7001');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/analyze') && $request->data()['options']['mode'] === 'async' && $request->data()['options']['quality'] === 'high');
    Sleep::assertSleptTimes(2);
});

it('explains a missing or refused key in Swedish and keeps the form as it was', function () {
    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertDispatched('toast', message: 'NONOX AI saknar API-nyckel. Lägg in företagets nyckel under Företagsinställningar → Moduler → NONOX AI.', type: 'error')
        ->assertSet('invoiceNumber', '');

    withCoreKey();
    Http::fake(['api.gozerocore.com/v1/analyze' => Http::response(['error' => ['type' => 'authentication_error', 'code' => 'key.invalid']], 401)]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('file', invoicePdf())
        ->call('analyze')
        ->assertDispatched('toast', type: 'error')
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
    withCoreKey();
    Http::fake(['api.gozerocore.com/v1/analyze' => Http::response([
        'id' => 'job-3', 'status' => 'succeeded',
        'output' => [
            'date' => '2026-03-05', 'description' => 'Kontorsmaterial, Pappershandeln', 'counterparty' => 'Pappershandeln', 'total' => 125.0, 'vat_total' => 25.0,
            'rows' => [
                ['account' => 6110, 'debit' => 100.0, 'credit' => 0.0, 'description' => 'Kontorsmaterial'],
                ['account' => 2641, 'debit' => 25.0, 'credit' => 0.0, 'description' => ''],
                ['account' => 1930, 'debit' => 0.0, 'credit' => 125.0, 'description' => ''],
            ],
            'notes' => '',
        ],
        'meta' => ['confidence' => 0.88, 'units' => 1],
    ])]);

    $component = Livewire::test(VoucherEditor::class)
        ->set('uploads', [UploadedFile::fake()->image('kvitto.jpg', 400, 600)])
        ->call('analyze')
        ->assertSet('voucherDate', '2026-03-05')
        ->assertSet('description', 'Kontorsmaterial, Pappershandeln');

    $rows = $component->get('rows');
    expect(array_column(array_slice($rows, 0, 3), 'account'))->toBe(['6110', '2641', '1930'])
        ->and($rows[2]['credit'])->toBe('125,00')
        ->and(Voucher::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->data()['images'][0]['mime'] === 'image/jpeg');
});
