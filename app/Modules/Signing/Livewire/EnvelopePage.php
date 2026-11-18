<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Actions\Signing\ChangeSigningDeadline;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Models\Supplier;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Support\EnvelopeVisibility;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\WorkOrders\Models\ChangeOrder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Signering → one envelope (ADR-046 amendment, gap 10, package S7): who has
 * signed and who is next, every signer's check, the files, the event
 * timeline, and, while the envelope is out and the member may act on it,
 * Påminn nu, Ändra sista dag and Dra tillbaka. The actions are the engine's
 * own (SigningReminders, ChangeSigningDeadline, SendForSigning::void), so a
 * document's own sending rules apply. An envelope the member may not see
 * answers 404, as another company's does.
 */
class EnvelopePage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Locked]
    public string $envelopeId = '';

    /** The new last day while it is being changed; null when the form is closed. */
    public ?string $newDeadline = null;

    public function mount(string $envelopeId): void
    {
        $this->envelopeId = $envelopeId;
        $this->envelope();
    }

    public function remind(int $requestId, SigningReminders $reminders): void
    {
        $signer = SigningRequest::query()->where('envelope_id', $this->envelope()->id)->findOrFail($requestId);
        $reminders->manual($this->user(), $this->company(), $signer);

        $this->toast(__('Påminnelsen är skickad till :email.', ['email' => $signer->email]));
    }

    /** "Lås upp": a signer locked out by failed checks may try again; gated like Påminn nu. */
    public function unlock(int $requestId, SignerChecks $checks): void
    {
        $envelope = $this->envelope();
        $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->findOrFail($requestId);
        $document = app(SignableTypes::class)->documentFor($signer);
        abort_unless($document instanceof Signable && EnvelopeVisibility::moduleOn($this->company()) && $this->mayAct($document, $envelope), 403);

        $checks->unlock($this->user(), $this->company(), $signer);

        $this->toast(__('Länken är upplåst. Undertecknaren kan försöka igen.'));
    }

    public function editDeadline(): void
    {
        $envelope = $this->envelope();
        $this->resetErrorBag();
        $this->newDeadline = $envelope->deadline_at?->setTimezone(config('app.timezone'))->format('Y-m-d') ?? '';
    }

    public function cancelDeadline(): void
    {
        $this->resetErrorBag();
        $this->newDeadline = null;
    }

    public function changeDeadline(ChangeSigningDeadline $change): void
    {
        $this->resetErrorBag();
        $envelope = $change($this->user(), $this->company(), $this->envelope(), (string) $this->newDeadline);
        $this->newDeadline = null;

        $this->toast(__('Sista dag att signera är ändrad till :date. De som kan signera nu har fått ett mejl om det.', ['date' => $envelope->deadline_at?->setTimezone(config('app.timezone'))->format('Y-m-d')]));
    }

    public function voidEnvelope(SendForSigning $send): void
    {
        $send->void($this->user(), $this->company(), $this->envelope());

        $this->toast(__('Dokumentet är tillbakadraget. Ingen kan längre signera det.'));
    }

    public function render(): View
    {
        $envelope = $this->envelope();
        $company = $this->company();
        $user = $this->user();

        /** @var Collection<int, SigningRequest> $signers */
        $signers = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->get();
        $document = $signers->isEmpty() ? null : app(SignableTypes::class)->documentFor($signers->first());
        $moduleOn = EnvelopeVisibility::moduleOn($company);
        $status = $envelope->effectiveStatus();
        $emails = $signers->pluck('email', 'id');

        return view('signing-module::livewire.envelope', [
            'envelope' => $envelope->loadMissing('creator:id,name'),
            'status' => $status,
            'signers' => $signers,
            'copies' => SigningCopyRecipient::query()->where('envelope_id', $envelope->id)->orderBy('id')->get(['id', 'email', 'name', 'sent_copy_at']),
            'events' => SigningEvent::query()->where('envelope_id', $envelope->id)->orderBy('created_at')->orderBy('id')->get(['id', 'signing_request_id', 'event', 'created_at']),
            'emails' => $emails,
            'typeLabel' => EnvelopeVisibility::typeLabel($envelope->document_type),
            'canAct' => $moduleOn && $status !== EnvelopeStatus::Voided && ! $envelope->status->isFinished() && $document instanceof Signable && $this->mayAct($document, $envelope),
            'link' => $this->underlying($envelope, $document),
            'register' => $this->register($document),
            'zone' => config('app.timezone'),
            'minDays' => SigningDeadlines::MIN_DAYS,
            'maxDays' => SigningDeadlines::MAX_DAYS,
            'user' => $user,
        ]);
    }

    private function envelope(): SigningEnvelope
    {
        // The company scope makes another company's envelope a 404; the visibility rule hides what this member may not see.
        $envelope = SigningEnvelope::query()->findOrFail($this->envelopeId);
        abort_unless(EnvelopeVisibility::allows($this->user(), $this->company(), $envelope), 404);

        return $envelope;
    }

    private function mayAct(Signable $document, SigningEnvelope $envelope): bool
    {
        try {
            SendForSigning::authorizeAct($document, $this->user(), $envelope);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Where the member opens the underlying quote, agreement or ÄTA, when
     * they may see it.
     *
     * @return array{label: string, url: string}|null
     */
    private function underlying(SigningEnvelope $envelope, ?Signable $document): ?array
    {
        $company = $this->company();
        $user = $this->user();
        $modules = app(ModuleManager::class);

        return match (true) {
            $envelope->sales_document_id !== null && $user->hasTeamPermission($company, 'invoices:view') => ['label' => __('Öppna offerten'), 'url' => route('invoicing.quotes.show', ['document' => $envelope->sales_document_id])],
            $envelope->agreement_id !== null && $modules->isEnabled(CrmModule::KEY, $company) && $user->hasTeamPermission($company, 'crm:view') => ['label' => __('Öppna avtalet'), 'url' => route('crm.agreements.show', $envelope->agreement_id)],
            $document instanceof ChangeOrder && $user->hasTeamPermission($company, 'work-orders:view') => ['label' => __('Öppna arbetsordern'), 'url' => route('workOrders.show', $document->work_order_id)],
            default => null,
        };
    }

    /**
     * The customer or the supplier a standalone document is linked to, only
     * when the member may see that register.
     *
     * @return array{customer: ?string, supplier: ?string}
     */
    private function register(?Signable $document): array
    {
        if (! $document instanceof SigningDocument) {
            return ['customer' => null, 'supplier' => null];
        }

        $company = $this->company();
        $user = $this->user();

        return [
            'customer' => $document->customer_id !== null && $user->can('viewAny', [Customer::class, $company]) ? Customer::query()->whereKey($document->customer_id)->value('name') : null,
            'supplier' => $document->supplier_id !== null && $user->can('viewAny', [Supplier::class, $company]) ? Supplier::query()->whereKey($document->supplier_id)->value('name') : null,
        ];
    }
}
