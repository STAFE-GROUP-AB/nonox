<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Livewire\Signing\SigningPanel;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Signing at a link is core (ADR-075 §7): any Signable document can be
 * sent, a quote is signed whether or not CRM is on, an agreement needs CRM,
 * and a module can register a type of its own (the work order, ADR-074).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

function coreSignInput(SigningRequest $request): array
{
    return ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

it('sends and signs a quote in a company that has never turned CRM on, and makes the order', function () {
    expect(app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company))->toBeFalse();

    $this->get(route('invoicing.quotes.show', ['document' => $this->quote->id]))->assertOk()->assertSeeLivewire(SigningPanel::class);

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSet('email', 'inkop@kontorshuset.test')
        ->assertDontSee('Affär som vinns vid signering')->assertSee('När kunden har signerat blir offerten en order.')
        ->call('send')->assertHasNoErrors();

    $request = SigningRequest::query()->sole();
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    expect($request->refresh()->status)->toBe(SigningStatus::Revoked);

    $this->get("/signera/{$token}")->assertOk()->assertSee('Offert');
    $this->post("/signera/{$token}", coreSignInput($sent['request']))->assertOk();
    CurrentCompany::set($this->company);

    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Signed)
        ->and($sent['request']->order_id)->not->toBeNull()
        ->and($this->quote->refresh()->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($this->get(route('signing.file', ['signing' => $sent['request']->id, 'file' => 'signeringsbevis']))->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
});

it('refuses a CRM deal while CRM is off', function () {
    expect(fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test', 'deal_id' => 1]))
        ->toThrow(ValidationException::class, 'Affären finns inte');
});

it('closes an agreement link while CRM is off: the agreement is a CRM document', function () {
    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor($this->customer);
    $agreement = app(SaveAgreement::class)($this->owner, $this->company, [
        'account_id' => $account->id, 'title' => 'Serviceavtal', 'kind' => 'SUPPORT', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31',
        'notice_months' => 1, 'auto_renew' => false, 'value' => '1 000', 'value_period' => 'MONTH', 'body_text' => 'Service varje kvartal.',
    ]);
    $sent = app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'anna@kontorshuset.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get("/signera/{$token}")->assertNotFound()->assertSee('Länken gäller inte');
    $this->post("/signera/{$token}", coreSignInput($sent['request']))->assertStatus(410);
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(fn () => app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'a@b.test']))->toThrow(NotFoundHttpException::class);
});

it('signs a document type that a module registers, kept by its key in signable_id', function () {
    $document = new class($this->company->id) implements Signable
    {
        public ?CarbonImmutable $signed = null;

        public function __construct(private readonly int $companyId) {}

        public function signingType(): string
        {
            return 'WORKORDER';
        }

        public function signingKey(): string
        {
            return 'wo-17';
        }

        public function signingCompanyId(): int
        {
            return $this->companyId;
        }

        public function signingTitle(): string
        {
            return 'Arbetsorder 17';
        }

        public function signingDocument(): array
        {
            return ['bytes' => "%PDF-1.4\n%arbetsorder\n", 'name' => 'Arbetsorder-17.pdf'];
        }

        public function signingFingerprint(): string
        {
            return hash('sha256', 'arbetsorder-17');
        }

        public function signingIsOpen(): bool
        {
            return $this->signed === null;
        }

        public function signingAcceptance(): string
        {
            return 'Jag har läst och godkänner arbetsordern.';
        }

        public function signingModule(): ?string
        {
            return null;
        }

        public function signingValidUntil(): ?CarbonImmutable
        {
            return null;
        }

        public function signingDestination(): string
        {
            return 'dashboard';
        }

        public function authorizeSigning(User $user, bool $revoke = false): void {}

        public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void
        {
            $this->signed = $signedAt;
        }
    };
    app(SignableTypes::class)->register('WORKORDER', SignableTypes::GENERIC_COLUMN, fn (string $key, bool $lock): ?Signable => $key === 'wo-17' ? $document : null);

    $sent = app(SendForSigning::class)($this->owner, $this->company, $document, ['email' => 'kund@example.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    expect($sent['request']->document_type)->toBe('WORKORDER')
        ->and($sent['request']->signable_id)->toBe('wo-17')
        ->and($sent['request']->sales_document_id)->toBeNull()
        ->and($sent['request']->document_sha256)->toBe(hash('sha256', "%PDF-1.4\n%arbetsorder\n"));

    $this->get("/signera/{$token}")->assertOk()->assertSee('Arbetsorder 17')->assertSee('Jag har läst och godkänner arbetsordern.');
    $this->post("/signera/{$token}", coreSignInput($sent['request']))->assertOk();

    expect($document->signed)->not->toBeNull()
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Signed)
        ->and($sent['request']->signed_document_path)->not->toBeNull();
});

it('prunes unsigned links in every company with signing:prune, CRM or not', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $this->travelTo(CarbonImmutable::parse('2027-09-01 10:00:00'));

    $this->artisan('signing:prune')->assertSuccessful();

    expect(SigningRequest::query()->whereKey($sent['request']->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($sent['request']->document_path))->toBeFalse();
});
