<?php

declare(strict_types=1);

namespace App\Livewire\Signing;

use App\Actions\Pricing\SendPriceAgreementForSigning;
use App\Actions\Signing\ChangeSigningDeadline;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SigningReminders;
use App\Console\Commands\VerifySigningChainsCommand;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * "Skicka för signering" on a quote or an agreement (ADR-046, core since
 * ADR-075 §7): the form (address, last day, a CRM deal to win while CRM is
 * on, an invoice draft too), and every link sent for the document with its
 * status, its trail of events, the signed documents and Återkalla for an
 * open one. A quote's panel works whether or not CRM is on.
 *
 * "Fler undertecknare" (ADR-046 amendment, gap 1) sends an envelope instead:
 * up to ten signers with a step each (the same step signs in parallel) and
 * up to ten copy recipients; a quote becomes an order only when everyone
 * has signed. "Dra tillbaka" withdraws such an envelope.
 *
 * "Kontroll av undertecknaren" (gap 3, S3): per signer, none, a code to the
 * signer's e-mail, or an access code the sender gives another way. A signer
 * locked out by failed checks shows as locked, with "Lås upp".
 */
class SigningPanel extends Component
{
    use ShowsToasts;

    public const string NAME = 'signing.panel';

    #[Locked]
    public ?string $quoteId = null;

    #[Locked]
    public ?int $agreementId = null;

    /** A customer price agreement (ADR-082 P5): sent through SendPriceAgreementForSigning, which freezes the draft first. */
    #[Locked]
    public ?int $priceAgreementId = null;

    public string $email = '';

    public string $expiresOn = '';

    public string $dealId = '';

    public bool $createInvoice = false;

    /** "Fler undertecknare": the envelope form with several signers and copy recipients (ADR-046 amendment, gap 1). */
    public bool $several = false;

    /** @var list<array{email: string, step: int|string, verification?: string, access_code?: string}> */
    public array $signers = [];

    /** @var list<array{email: string, name: string}> */
    public array $copies = [];

    public bool $notifyCopies = false;

    /** The check before the document is shown (ADR-046 amendment, gap 3): NONE (the default for quotes and agreements), EMAIL_CODE or ACCESS_CODE. */
    public string $verification = 'NONE';

    /** The sender's access code; sent once with the form, stored hashed only, cleared after sending. */
    public string $accessCode = '';

    /** Reminders on send (ADR-046 amendment, gap 9): on by default, every 3 days; 'single' is one reminder two days before the last day. */
    public bool $autoReminders = true;

    public string $reminderDays = '3';

    /** The envelope whose last day is being changed, and the new day. */
    public ?string $deadlineFor = null;

    public string $newDeadline = '';

    public function mount(?string $quoteId = null, ?int $agreementId = null, ?int $priceAgreementId = null): void
    {
        $this->quoteId = $quoteId;
        $this->agreementId = $agreementId;
        $this->priceAgreementId = $priceAgreementId;
        $document = $this->document();
        abort_unless(Gate::allows('view', $document), 403);

        $this->email = (string) match (true) {
            $document instanceof SalesDocument => $document->sent_to ?? Customer::query()->whereKey($document->customer_id)->value('email'),
            $document instanceof PriceAgreement => Customer::query()->whereKey($document->customer_id)->value('email'),
            default => Account::query()->whereKey($document->account_id)->value('email'),
        };
        $deals = $this->openDeals($document);
        $this->dealId = $deals->count() === 1 ? (string) $deals->first()?->id : '';
    }

    public function send(SendForSigning $send): void
    {
        $this->resetErrorBag();
        $document = $this->document();
        $input = [
            'email' => $this->email,
            'expires_on' => $this->expiresOn === '' ? null : $this->expiresOn,
            'auto_reminders' => $this->autoReminders,
            'reminder_days' => $this->reminderDays,
            'deal_id' => $this->dealId === '' ? null : (int) $this->dealId,
            'create_invoice' => $this->createInvoice,
            'verification' => $this->verification,
            'access_code' => $this->accessCode,
        ];

        $document instanceof PriceAgreement
            ? app(SendPriceAgreementForSigning::class)($this->user(), $this->company(), $document, $input)
            : $send($this->user(), $this->company(), $document, $input);

        $this->changed($document);
        $this->accessCode = '';
        $this->toast(__('Länken för signering är skickad till :email.', ['email' => $this->email]));
    }

    /** Opens the form for several signers, with the single address as the first signer. */
    public function severalSigners(): void
    {
        $this->resetErrorBag();
        $this->several = true;

        if ($this->signers === []) {
            $this->signers = [
                ['email' => $this->email, 'step' => 1, 'verification' => $this->verification, 'access_code' => ''],
                ['email' => '', 'step' => 2, 'verification' => 'NONE', 'access_code' => ''],
            ];
        }
    }

    public function oneSigner(): void
    {
        $this->resetErrorBag();
        $this->several = false;
    }

    public function addSigner(): void
    {
        if (count($this->signers) >= SigningEnvelope::MAX_SIGNERS) {
            return;
        }

        $last = $this->signers === [] ? 0 : (int) end($this->signers)['step'];
        $this->signers[] = ['email' => '', 'step' => min(SigningEnvelope::MAX_STEP, max(1, $last + 1)), 'verification' => 'NONE', 'access_code' => ''];
    }

    public function removeSigner(int $index): void
    {
        $this->signers = array_values(array_filter($this->signers, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
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

    public function sendSeveral(SendForSigning $send): void
    {
        $this->resetErrorBag();
        $document = $this->document();
        $input = [
            'signers' => $this->signers,
            'copies' => $this->copies,
            'notify_copies' => $this->notifyCopies,
            'expires_on' => $this->expiresOn === '' ? null : $this->expiresOn,
            'auto_reminders' => $this->autoReminders,
            'reminder_days' => $this->reminderDays,
            'deal_id' => $this->dealId === '' ? null : (int) $this->dealId,
            'create_invoice' => $this->createInvoice,
        ];

        $document instanceof PriceAgreement
            ? app(SendPriceAgreementForSigning::class)->envelope($this->user(), $this->company(), $document, $input)
            : $send->envelope($this->user(), $this->company(), $document, $input);

        $this->changed($document);
        $this->several = false;
        $this->toast(__('Dokumentet är skickat för signering. Undertecknarna i första steget har fått sin länk; :count undertecknare totalt.', ['count' => count($this->signers)]));
        $this->signers = [];
        $this->copies = [];
        $this->notifyCopies = false;
    }

    /** "Föreslå kod": a random access code of ten characters, easy to read out over the phone. */
    public function suggestCode(): void
    {
        $this->resetErrorBag('access_code');
        $this->accessCode = SignerChecks::suggestAccessCode();
    }

    /** "Föreslå kod" for one signer of "Fler undertecknare". */
    public function suggestSignerCode(int $index): void
    {
        if (! isset($this->signers[$index])) {
            return;
        }

        $this->resetErrorBag("signers.{$index}.access_code");
        $this->signers[$index]['access_code'] = SignerChecks::suggestAccessCode();
    }

    /** "Lås upp": a signer locked out by failed checks may try again (ADR-046 amendment, gap 3). */
    public function unlock(int $requestId, SignerChecks $checks): void
    {
        $request = $this->requests()->whereKey($requestId)->firstOrFail();
        $checks->unlock($this->user(), $this->company(), $request);

        $this->toast(__('Länken är upplåst. Undertecknaren kan försöka igen.'));
    }

    public function voidEnvelope(string $envelopeId, SendForSigning $send): void
    {
        $envelope = $this->envelopes()->whereKey($envelopeId)->firstOrFail();
        $send->void($this->user(), $this->company(), $envelope);

        $this->toast(__('Dokumentet är tillbakadraget. Ingen kan längre signera det.'));
    }

    /** "Påminn nu": a reminder with a fresh link to one open signer, at most once an hour. */
    public function remindNow(int $requestId, SigningReminders $reminders): void
    {
        $request = $this->requests()->whereKey($requestId)->firstOrFail();
        $reminders->manual($this->user(), $this->company(), $request);

        $this->toast(__('Påminnelsen är skickad till :email.', ['email' => $request->email]));
    }

    public function editDeadline(string $envelopeId): void
    {
        $envelope = $this->envelopes()->whereKey($envelopeId)->firstOrFail();
        $this->resetErrorBag();
        $this->deadlineFor = $envelope->id;
        $this->newDeadline = $envelope->deadline_at?->setTimezone(config('app.timezone'))->format('Y-m-d') ?? '';
    }

    public function cancelDeadline(): void
    {
        $this->resetErrorBag();
        $this->deadlineFor = null;
        $this->newDeadline = '';
    }

    public function changeDeadline(ChangeSigningDeadline $change): void
    {
        $this->resetErrorBag();
        $envelope = $this->envelopes()->whereKey((string) $this->deadlineFor)->firstOrFail();
        $envelope = $change($this->user(), $this->company(), $envelope, $this->newDeadline);

        $this->deadlineFor = null;
        $this->newDeadline = '';
        $this->toast(__('Sista dag att signera är ändrad till :date. De som kan signera nu har fått ett mejl om det.', ['date' => $envelope->deadline_at?->setTimezone(config('app.timezone'))->format('Y-m-d')]));
    }

    public function revoke(int $requestId, SendForSigning $send): void
    {
        $request = $this->requests()->whereKey($requestId)->firstOrFail();
        $send->revoke($this->user(), $this->company(), $request);

        $this->toast(__('Länken är återkallad. Den går inte längre att använda.'));
    }

    public function render(): View
    {
        $document = $this->document();
        $user = $this->user();
        $isQuote = $document instanceof SalesDocument;

        return view('livewire.signing.panel', [
            'isQuote' => $isQuote,
            'isPriceAgreement' => $document instanceof PriceAgreement,
            'crm' => app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company()),
            // Newest first; an envelope's signers together, in their order (step, then as added).
            'requests' => $this->requests()->with(['events', 'sender:id,name', 'envelope' => fn ($query) => $query->withCount('signers')->with('copies')])->latest('id')->limit(30)->get()
                ->groupBy(fn (SigningRequest $request): string => $request->envelope_id ?? "request-{$request->id}")
                ->flatMap(fn (Collection $group): Collection => $group->sortBy([['step', 'asc'], ['id', 'asc']])->values())
                ->values(),
            'maxSigners' => SigningEnvelope::MAX_SIGNERS,
            'maxCopies' => SigningEnvelope::MAX_COPIES,
            'maxStep' => SigningEnvelope::MAX_STEP,
            'checks' => SignerCheck::choosable(),
            // Signing at one of the company's external providers (ADR-046 S17), while it can be used.
            'providerChecks' => SignerChecks::providerOptions(),
            'intervals' => SigningDeadlines::INTERVALS,
            'minDays' => SigningDeadlines::MIN_DAYS,
            'maxDays' => SigningDeadlines::MAX_DAYS,
            'maxReminders' => SigningDeadlines::MAX_REMINDERS,
            'canSend' => $this->canSend($document),
            'wizardUrl' => $this->wizardUrl($document),
            'deals' => $this->openDeals($document),
            'open' => $this->isOpen($document),
            'canInvoice' => $isQuote && $user->hasTeamPermission($this->company(), 'invoices:create'),
            'now' => now(),
            'chainCheck' => $this->chainCheck(),
        ]);
    }

    private function document(): SalesDocument|Agreement|PriceAgreement
    {
        $document = match (true) {
            $this->quoteId !== null => SalesDocument::query()->where('kind', SalesDocument::QUOTE)->findOrFail($this->quoteId),
            $this->priceAgreementId !== null => PriceAgreement::query()->findOrFail($this->priceAgreementId),
            default => Agreement::query()->findOrFail((int) $this->agreementId),
        };

        abort_unless(SendForSigning::moduleOn($document, $this->company()), 404);

        return $document;
    }

    /** @return Builder<SigningRequest> */
    private function requests(): Builder
    {
        $document = $this->document();

        return SigningRequest::query()
            ->where('document_type', $document->signingType())
            ->where(app(SignableTypes::class)->column($document->signingType()), $document->signingKey());
    }

    /** @return Builder<SigningEnvelope> */
    private function envelopes(): Builder
    {
        $document = $this->document();

        return SigningEnvelope::query()
            ->where('document_type', $document->signingType())
            ->where(app(SignableTypes::class)->column($document->signingType()), $document->signingKey());
    }

    /** @return Collection<int, Deal> */
    private function openDeals(SalesDocument|Agreement|PriceAgreement $document): Collection
    {
        if ($document instanceof PriceAgreement || ! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company())) {
            return collect();
        }

        $accountId = $document instanceof Agreement ? $document->account_id : Account::query()->where('customer_id', $document->customer_id)->value('id');

        if ($accountId === null || ! $this->user()->can('viewAny', [Deal::class, $this->company()])) {
            return collect();
        }

        return Deal::query()->where('account_id', $accountId)->where('status', DealStatus::Open->value)->orderBy('title')->get(['id', 'title']);
    }

    /**
     * "Fler undertecknare" opens the send wizard of the Signering module for
     * this document (ADR-046 amendment, gaps 1 and 10) when the module is on
     * and the member may send there; otherwise the panel keeps its own form.
     */
    private function wizardUrl(Signable $document): ?string
    {
        $company = $this->company();

        // The send wizard knows quotes and agreements in the register; a price agreement keeps the panel's own form.
        if ($document instanceof PriceAgreement || ! app(ModuleManager::class)->isEnabled('signing', $company) || ! $this->user()->hasTeamPermission($company, 'signing:send')) {
            return null;
        }

        return route('signing.new', ['typ' => $document->signingType(), 'dokument' => $document->signingKey()]);
    }

    private function canSend(Signable $document): bool
    {
        try {
            $document->authorizeSigning($this->user());
        } catch (Throwable) {
            return false;
        }

        return $this->isOpen($document);
    }

    /**
     * Whether the document can be sent: an open quote not yet signed, an
     * agreement not yet signed, or a price agreement still a draft or
     * awaiting its signature (sending freezes the draft).
     */
    private function isOpen(Signable $document): bool
    {
        if ($document instanceof PriceAgreement) {
            return $document->status->isDraft() || $document->signingIsOpen();
        }

        return $document->signingIsOpen() && ! ($document instanceof SalesDocument && SignedDocuments::isQuoteSigned($document->id));
    }

    /** Tells the price agreement's page that its status has changed. */
    private function changed(Signable $document): void
    {
        if ($document instanceof PriceAgreement) {
            $this->dispatch('price-agreement-changed');
        }
    }

    /**
     * The latest nightly check of the company's signing chains
     * (signing:verify, ADR-046 amendment, gap 4): when it ran and which
     * envelopes it found changed. One query.
     *
     * @return array{at: CarbonImmutable, broken: array<string, true>}|null
     */
    private function chainCheck(): ?array
    {
        $entry = AuditLog::query()->forCompany($this->company())->where('action', VerifySigningChainsCommand::ACTION)->latest('id')->first(['payload', 'created_at']);

        if (! $entry instanceof AuditLog) {
            return null;
        }

        $broken = [];

        foreach ((array) ($entry->payload['problems'] ?? []) as $problem) {
            $broken[(string) ($problem['envelope_id'] ?? '')] = true;
        }

        /** @var CarbonImmutable $at */
        $at = $entry->created_at;

        return ['at' => $at, 'broken' => $broken];
    }

    public static function statusOf(SigningRequest $request): SigningStatus
    {
        return $request->effectiveStatus();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
