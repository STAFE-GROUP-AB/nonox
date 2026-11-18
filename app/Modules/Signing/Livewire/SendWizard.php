<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\RestrictsEnvelopes;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\Supplier;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\DeleteSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Signering → Skicka dokument (ADR-046 amendment, gap 10, package S7): the
 * send wizard. Dokument (the PDF is posted by an ordinary form first, see
 * UploadController, or an earlier upload is picked, or the wizard is opened
 * from a quote's or an agreement's panel with "Fler undertecknare") →
 * Undertecknare och steg → Kontroller → Tid och påminnelser → Granska och
 * skicka. A step for fields in the PDF comes with the field editor (S8).
 *
 * The wizard sends through the engine's own SendForSigning::envelope with
 * one to ten signers, so the same rules, limits and checks apply as for
 * "Fler undertecknare" in the panel. A standalone document defaults to a
 * code to the signer's e-mail (owner decision 9); a quote or an agreement
 * to none, as before. Only a standalone document can be confidential.
 */
class SendWizard extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int LAST_STEP = 5;

    /** How many customers or suppliers the pickers of step 1 list; more are said to be cut. */
    public const int PICKER_LIMIT = 500;

    /** The step names, in order. */
    public const array STEPS = [1 => 'Dokument', 2 => 'Undertecknare', 3 => 'Kontroller', 4 => 'Tid och påminnelser', 5 => 'Granska och skicka'];

    #[Locked]
    public int $step = 1;

    /** QUOTE, AGREEMENT or DOCUMENT when a document is chosen; null on the first step of a new upload. */
    #[Locked]
    public ?string $type = null;

    #[Locked]
    public ?string $documentId = null;

    /** @var list<array{email: string, step: int|string, verification: string, access_code: string}> */
    public array $signers = [];

    /** @var list<array{email: string, name: string}> */
    public array $copies = [];

    public bool $notifyCopies = false;

    public string $expiresOn = '';

    public bool $autoReminders = true;

    public string $reminderDays = '3';

    public bool $confidential = false;

    public string $dealId = '';

    public bool $createInvoice = false;

    public function mount(?string $type = null, ?string $documentId = null): void
    {
        abort_unless($this->allowed(SigningModule::SEND), 404);

        if ($type === null || $documentId === null) {
            return;
        }

        abort_unless(in_array($type, [SigningRequest::QUOTE, 'AGREEMENT', SigningDocument::SIGNING_TYPE], true), 404);
        $this->type = $type;
        $this->documentId = $documentId;

        $document = $this->document();
        $document->authorizeSigning($this->user());
        abort_unless($document->signingIsOpen(), 404);

        $standalone = $document instanceof RestrictsEnvelopes;
        $this->signers = [['email' => $this->defaultEmail($document), 'step' => 1, 'verification' => $standalone ? SignerCheck::EmailCode->value : SignerCheck::None->value, 'access_code' => '']];
        $deals = $this->deals($document);
        $this->dealId = $deals->count() === 1 ? (string) $deals->first()?->id : '';
        $this->step = 2;
    }

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->type === null) {
            return;
        }

        if ($this->step === 2) {
            $this->validate([
                'signers' => ['required', 'array', 'min:1', 'max:'.SigningEnvelope::MAX_SIGNERS],
                'signers.*.email' => ['required', 'email', 'max:255'],
                'signers.*.step' => ['required', 'integer', 'min:1', 'max:'.SigningEnvelope::MAX_STEP],
                'copies' => ['array', 'max:'.SigningEnvelope::MAX_COPIES],
                'copies.*.email' => ['required', 'email', 'max:255'],
                'copies.*.name' => ['nullable', 'string', 'max:120'],
            ], [], ['signers.*.email' => __('e-post'), 'signers.*.step' => __('steg'), 'copies.*.email' => __('e-post'), 'copies.*.name' => __('namn')]);
        }

        $this->step = min(self::LAST_STEP, $this->step + 1);
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function addSigner(): void
    {
        if (count($this->signers) >= SigningEnvelope::MAX_SIGNERS) {
            return;
        }

        $last = $this->signers === [] ? 0 : (int) end($this->signers)['step'];
        $this->signers[] = ['email' => '', 'step' => min(SigningEnvelope::MAX_STEP, max(1, $last + 1)), 'verification' => $this->defaultCheck(), 'access_code' => ''];
    }

    public function removeSigner(int $index): void
    {
        if (count($this->signers) <= 1) {
            return;
        }

        $this->signers = array_values(array_filter($this->signers, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    /** "I ordning": the signers sign one after the other, in the order they are listed. */
    public function inOrder(): void
    {
        foreach (array_keys($this->signers) as $index) {
            $this->signers[$index]['step'] = min(SigningEnvelope::MAX_STEP, $index + 1);
        }
    }

    /** "Alla samtidigt": everyone is on step 1. */
    public function allAtOnce(): void
    {
        foreach (array_keys($this->signers) as $index) {
            $this->signers[$index]['step'] = 1;
        }
    }

    public function addCopy(): void
    {
        if (count($this->copies) < SigningEnvelope::MAX_COPIES) {
            $this->copies[] = ['email' => '', 'name' => ''];
        }
    }

    public function removeCopy(int $index): void
    {
        $this->copies = array_values(array_filter($this->copies, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    /** "Föreslå kod" for one signer: ten characters that are easy to read out over the phone. */
    public function suggestCode(int $index): void
    {
        if (! isset($this->signers[$index])) {
            return;
        }

        $this->resetErrorBag("signers.{$index}.access_code");
        $this->signers[$index]['access_code'] = SignerChecks::suggestAccessCode();
    }

    /** "Ta bort" an uploaded document that has not been sent: the file goes with it. */
    public function deleteDocument(string $id, DeleteSigningDocument $delete): void
    {
        abort_unless(Str::isUuid($id), 404);
        $delete($this->user(), $this->company(), SigningDocument::query()->findOrFail($id));

        $this->toast(__('Dokumentet är borttaget.'));
    }

    public function send(SendForSigning $send): void
    {
        $this->resetErrorBag();
        $document = $this->document();

        try {
            $envelope = $send->envelope($this->user(), $this->company(), $document, [
                'signers' => $this->signers,
                'copies' => $this->copies,
                'notify_copies' => $this->notifyCopies,
                'expires_on' => $this->expiresOn === '' ? null : $this->expiresOn,
                'auto_reminders' => $this->autoReminders,
                'reminder_days' => $this->reminderDays,
                'confidential' => $document instanceof RestrictsEnvelopes && $this->confidential,
                'deal_id' => $this->dealId === '' ? null : (int) $this->dealId,
                'create_invoice' => $document instanceof SalesDocument && $this->createInvoice,
            ], minSigners: 1);
        } catch (ValidationException $exception) {
            $this->step = $this->stepOf(array_keys($exception->errors()));

            throw $exception;
        }

        Toast::flash(count($this->signers) === 1
            ? __('Dokumentet är skickat för signering. Undertecknaren har fått sin länk.')
            : __('Dokumentet är skickat för signering. Undertecknarna i första steget har fått sin länk; :count undertecknare totalt.', ['count' => count($this->signers)]));

        $this->redirectRoute('signing.envelope', ['envelope' => $envelope->id]);
    }

    public function render(): View
    {
        $company = $this->company();
        $user = $this->user();
        $document = $this->type === null ? null : $this->document();
        $first = $this->type === null;

        return view('signing-module::livewire.wizard', [
            'document' => $document,
            'steps' => self::STEPS,
            'isQuote' => $document instanceof SalesDocument,
            'standalone' => $document instanceof RestrictsEnvelopes || $first,
            'checks' => SignerCheck::choosable(),
            'intervals' => SigningDeadlines::INTERVALS,
            'minDays' => SigningDeadlines::MIN_DAYS,
            'maxDays' => SigningDeadlines::MAX_DAYS,
            'maxReminders' => SigningDeadlines::MAX_REMINDERS,
            'maxSigners' => SigningEnvelope::MAX_SIGNERS,
            'maxCopies' => SigningEnvelope::MAX_COPIES,
            'maxStep' => SigningEnvelope::MAX_STEP,
            'deals' => $document === null ? collect() : $this->deals($document),
            'canInvoice' => $document instanceof SalesDocument && $user->hasTeamPermission($company, 'invoices:create'),
            'customers' => $first && $user->can('viewAny', [Customer::class, $company]) ? Customer::query()->orderBy('name')->limit(self::PICKER_LIMIT + 1)->get(['id', 'name']) : collect(),
            'suppliers' => $first && $user->can('viewAny', [Supplier::class, $company]) ? Supplier::query()->orderBy('name')->limit(self::PICKER_LIMIT + 1)->get(['id', 'name']) : collect(),
            'pickerLimit' => self::PICKER_LIMIT,
            'recent' => $first ? $this->unsent($company->id) : collect(),
            'pages' => $document instanceof SigningDocument ? $document->page_count : null,
        ]);
    }

    /** The chosen document, in the company; a quote needs invoices:view, an agreement CRM, a standalone document nothing more. */
    private function document(): Signable
    {
        // A key that is not a uuid (or a number for an agreement) is no document: 404, never a database error.
        abort_unless($this->type === 'AGREEMENT' ? ctype_digit((string) $this->documentId) : Str::isUuid((string) $this->documentId), 404);

        $document = match ($this->type) {
            SigningRequest::QUOTE => SalesDocument::query()->where('kind', SalesDocument::QUOTE)->findOrFail((string) $this->documentId),
            'AGREEMENT' => Agreement::query()->findOrFail((int) $this->documentId),
            SigningDocument::SIGNING_TYPE => SigningDocument::query()->findOrFail((string) $this->documentId),
            default => abort(404),
        };

        abort_unless(SendForSigning::moduleOn($document, $this->company()), 404);

        if ($document instanceof SigningDocument) {
            // An upload is its uploader's until it is sent (it may be a confidential contract); signing:manage may take it over.
            abort_unless($document->uploaded_by === $this->user()->id || $this->allowed(SigningModule::MANAGE), 404);
        } else {
            abort_unless(Gate::allows('view', $document), 403);
        }

        return $document;
    }

    private function defaultCheck(): string
    {
        return $this->type === SigningDocument::SIGNING_TYPE ? SignerCheck::EmailCode->value : SignerCheck::None->value;
    }

    private function defaultEmail(Signable $document): string
    {
        return match (true) {
            $document instanceof SalesDocument => (string) ($document->sent_to ?? Customer::query()->whereKey($document->customer_id)->value('email')),
            $document instanceof Agreement => (string) Account::query()->whereKey($document->account_id)->value('email'),
            default => '',
        };
    }

    /**
     * The step whose fields a validation error names, so the wizard opens
     * where the mistake is.
     *
     * @param  list<string>  $keys
     */
    private function stepOf(array $keys): int
    {
        $key = (string) ($keys[0] ?? '');

        return match (true) {
            str_contains($key, 'verification'), str_contains($key, 'access_code') => 3,
            str_starts_with($key, 'signers'), str_starts_with($key, 'copies') => 2,
            $key === 'document' => 5,
            default => 4,
        };
    }

    /** @return Collection<int, Deal> */
    private function deals(Signable $document): Collection
    {
        $company = $this->company();

        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company) || ! ($document instanceof SalesDocument || $document instanceof Agreement)) {
            return collect();
        }

        $accountId = $document instanceof Agreement ? $document->account_id : Account::query()->where('customer_id', $document->customer_id)->value('id');

        if ($accountId === null || ! $this->user()->can('viewAny', [Deal::class, $company])) {
            return collect();
        }

        return Deal::query()->where('account_id', $accountId)->where('status', DealStatus::Open->value)->orderBy('title')->get(['id', 'title']);
    }

    /**
     * Uploaded documents that have never been sent, newest first: a document
     * picked up again. One query.
     *
     * @return Collection<int, SigningDocument>
     */
    private function unsent(int $companyId): Collection
    {
        return SigningDocument::query()
            ->whereNull('archived_at')
            ->when(! $this->allowed(SigningModule::MANAGE), fn ($query) => $query->where('uploaded_by', $this->user()->id))
            ->whereRaw("id::text not in (select signable_id from signing_envelopes where company_id = ? and document_type = 'DOCUMENT' and signable_id is not null)", [$companyId])
            ->latest('created_at')
            ->limit(15)
            ->get(['id', 'title', 'page_count', 'created_at']);
    }
}
