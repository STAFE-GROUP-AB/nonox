<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningHost;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka för signering" (ADR-046, core since ADR-075 §7): renders any
 * Signable's PDF once and keeps those exact bytes with their SHA-256, makes
 * a 256-bit link token (only its hash is stored), revokes any earlier open
 * link for the same document, and e-mails the link (queued, the company's
 * name as sender name, replies to the company). One open link per document.
 *
 * Who may send is the document's own rule (Signable::authorizeSigning): a
 * quote needs invoices:create and invoices:send and must be open (signing
 * turns it into an order); an agreement needs crm:manage. A document whose
 * module is off cannot be sent; a quote needs no module, so quotes are
 * signed whether or not CRM is on. The link expires at the end of the
 * document's last valid day, else after 30 days, and never later than 90.
 *
 * Input: email, expires_on (optional date), create_invoice (quotes:
 * also make a draft invoice once signed), deal_id (an open CRM deal of the
 * account that is won when the document is signed; only while CRM is on).
 *
 * Every send makes an envelope (ADR-046 amendment, gap 1). __invoke sends a
 * one-signer envelope exactly as a link was always sent. envelope() sends
 * one with several signers ("Fler undertecknare"): up to ten signers with a
 * step each (the same step signs in parallel; only the first step's links
 * go out now) and up to ten copy recipients. void() withdraws an envelope.
 */
final class SendForSigning
{
    public const int DEFAULT_DAYS = SigningDeadlines::DEFAULT_DAYS;

    public const int MAX_DAYS = SigningDeadlines::MAX_DAYS;

    private const string ZONE = 'Europe/Stockholm';

    /** The document is locked before its links, as in SignAtLink; a deadlock is retried. */
    private const int ATTEMPTS = SignAtLink::ATTEMPTS;

    public function __construct(
        private readonly SignableTypes $types,
        private readonly SigningEnvelopes $envelopes,
        private readonly SigningChain $chain,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{request: SigningRequest, url: string}
     */
    public function __invoke(User $user, Company $company, Signable $document, array $input): array
    {
        abort_unless($document->signingCompanyId() === $company->id && self::moduleOn($document, $company), 404);
        $document->authorizeSigning($user);

        $validated = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'auto_reminders' => ['sometimes', 'boolean'],
            'reminder_days' => ['nullable'],
            'create_invoice' => ['sometimes', 'boolean'],
            'deal_id' => ['nullable', 'integer'],
        ], [], ['email' => __('e-post'), 'expires_on' => __('sista dag att signera')])->validate();

        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);
        $reminders = $this->reminders($validated);

        MailQuota::take($company, 'documents');

        $token = SigningToken::generate();

        $request = DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $token, $reminders): SigningRequest {
            $document = $this->lockOpenDocument($document);
            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $file = $this->storePdf($company, $document);
            $envelope = $this->envelopes->create($document, $file, $expires, $user->id, $reminders);

            $request = SigningRequest::create([
                ...$this->types->attributesFor($document),
                'envelope_id' => $envelope->id,
                'deal_id' => $dealId,
                'token_hash' => $token['hash'],
                'email' => mb_strtolower((string) $validated['email']),
                'expires_at' => $expires,
                'document_path' => $file['path'],
                'document_name' => $file['name'],
                'document_sha256' => $file['sha256'],
                'content_hash' => $document->signingFingerprint(),
                'create_invoice' => $document instanceof SalesDocument && (bool) ($validated['create_invoice'] ?? false),
                'sent_by' => $user->id,
            ]);

            $this->chain->record($request, SigningEventType::Sent, $user->id);
            Audit::record('crm.signing_sent', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'sales_document_id' => $request->sales_document_id, 'agreement_id' => $request->agreement_id, 'signable_id' => $request->signable_id, 'deal_id' => $dealId], $company, $user);

            return $request;
        }), self::ATTEMPTS);

        $url = SigningHost::link($token['token']);

        // A test company's mail never leaves NONOX (ADR-032). Queued after commit, so a rolled-back send mails nothing.
        if (! $company->isTestCompany()) {
            Mail::to($request->email)->queue(new SigningRequestMail($request->id, $company->id, $url));
        }

        return ['request' => $request, 'url' => $url];
    }

    /**
     * "Fler undertecknare": an envelope with several signers and copy
     * recipients. Only the first step's signers get their link now; a later
     * step gets its links when the step before is signed (SignAtLink).
     *
     * Input: signers (2–10 of email and step 1–10, distinct addresses),
     * copies (0–10 of email, name), notify_copies, expires_on,
     * create_invoice, deal_id (as for one signer; every signer row carries
     * them, and they apply once everyone has signed).
     *
     * @param  array<string, mixed>  $input
     */
    public function envelope(User $user, Company $company, Signable $document, array $input): SigningEnvelope
    {
        abort_unless($document->signingCompanyId() === $company->id && self::moduleOn($document, $company), 404);
        $document->authorizeSigning($user);

        $validated = $this->validateEnvelope($input);

        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);

        $signerInput = array_values(array_filter((array) $validated['signers'], is_array(...)));
        $copyInput = array_values(array_filter((array) ($validated['copies'] ?? []), is_array(...)));
        $signers = collect($signerInput)->map(fn (array $signer): array => [
            'email' => mb_strtolower(trim((string) $signer['email'])),
            'step' => (int) $signer['step'],
        ])->values();
        $copies = collect($copyInput)->map(fn (array $copy): array => [
            'email' => mb_strtolower(trim((string) $copy['email'])),
            'name' => blank($copy['name'] ?? null) ? null : trim((string) $copy['name']),
        ])->values();
        $notify = (bool) ($validated['notify_copies'] ?? false) && $copies->isNotEmpty();
        $reminders = $this->reminders($validated);
        $first = (int) $signers->min('step');

        foreach (range(1, $signers->where('step', $first)->count() + ($notify ? $copies->count() : 0)) as $ignored) {
            MailQuota::take($company, 'documents', 'signers');
        }

        $result = DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $signers, $copies, $first, $reminders): array {
            $document = $this->lockOpenDocument($document);
            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $file = $this->storePdf($company, $document);
            $envelope = $this->envelopes->create($document, $file, $expires, $user->id, $reminders);
            $envelope->forceFill(['current_step' => $first])->save();
            $opened = [];

            foreach ($signers as $signer) {
                $request = SigningRequest::create([
                    ...$this->types->attributesFor($document),
                    'envelope_id' => $envelope->id,
                    'step' => $signer['step'],
                    'deal_id' => $dealId,
                    // The column needs a value; this signer's links live in signing_link_tokens, and nobody holds this one.
                    'token_hash' => SigningToken::generate()['hash'],
                    'email' => $signer['email'],
                    'expires_at' => $expires,
                    'document_path' => $file['path'],
                    'document_name' => $file['name'],
                    'document_sha256' => $file['sha256'],
                    'content_hash' => $document->signingFingerprint(),
                    'create_invoice' => $document instanceof SalesDocument && (bool) ($validated['create_invoice'] ?? false),
                    'sent_by' => $user->id,
                ]);

                if ($signer['step'] === $first) {
                    $opened[] = ['request_id' => $request->id, 'email' => $request->email, 'url' => $this->envelopes->issue($request, $user->id)];
                }
            }

            foreach ($copies as $copy) {
                SigningCopyRecipient::create(['envelope_id' => $envelope->id, ...$copy]);
            }

            Audit::record('crm.signing_envelope_sent', $envelope, ['envelope_id' => $envelope->id, 'document_type' => $envelope->document_type, 'sales_document_id' => $envelope->sales_document_id, 'agreement_id' => $envelope->agreement_id, 'signable_id' => $envelope->signable_id, 'deal_id' => $dealId, 'signers' => $signers->count(), 'steps' => $signers->pluck('step')->unique()->count(), 'copies' => $copies->count()], $company, $user);

            return ['envelope' => $envelope, 'opened' => $opened];
        }), self::ATTEMPTS);

        /** @var SigningEnvelope $envelope */
        $envelope = $result['envelope'];

        // A test company's mail never leaves NONOX (ADR-032). Queued after commit, so a rolled-back send mails nothing.
        if (! $company->isTestCompany()) {
            foreach ($result['opened'] as $link) {
                Mail::to($link['email'])->queue(new SigningRequestMail($link['request_id'], $company->id, $link['url']));
            }

            if ($notify) {
                foreach ($copies as $copy) {
                    Mail::to($copy['email'])->queue(new SigningEnvelopeMail($envelope->id, $company->id, SigningEnvelopeMail::NOTICE));
                }

                CurrentCompany::run($company, fn () => SigningCopyRecipient::query()->where('envelope_id', $envelope->id)->whereNull('notified_at')->update(['notified_at' => now()]));
            }
        }

        return $envelope;
    }

    public function revoke(User $user, Company $company, SigningRequest $request): SigningRequest
    {
        $document = $request->document();
        abort_unless($document instanceof Signable, 404);
        $document->authorizeSigning($user, revoke: true);

        return DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $request): SigningRequest {
            $request->document(lock: true);
            $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SigningStatus::Pending) {
                throw ValidationException::withMessages(['signing' => __('Länken är redan använd eller återkallad.')]);
            }

            $this->revokeRequest($user, $company, $locked, 'REVOKED_BY_SENDER');

            return $locked;
        }), self::ATTEMPTS);
    }

    /**
     * "Dra tillbaka": withdraws an envelope that is out. Every open link is
     * revoked and answers that the document has been withdrawn. Needs the
     * document's own sending rights, as revoking a link does.
     */
    public function void(User $user, Company $company, SigningEnvelope $envelope): SigningEnvelope
    {
        $request = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->first();
        $document = $request?->document();
        abort_unless($document instanceof Signable, 404);
        $document->authorizeSigning($user, revoke: true);

        return DB::transaction(fn (): SigningEnvelope => CurrentCompany::run($company, function () use ($user, $company, $envelope, $request): SigningEnvelope {
            $request->document(lock: true);
            $pending = SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Pending->value)->orderBy('step')->orderBy('id')->lockForUpdate()->first();
            $locked = SigningEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EnvelopeStatus::Out || ! $pending instanceof SigningRequest) {
                throw ValidationException::withMessages(['signing' => __('Dokumentet är redan signerat, avböjt eller tillbakadraget.')]);
            }

            $this->revokeRequest($user, $company, $pending, 'ENVELOPE_VOIDED');

            return $locked->refresh();
        }), self::ATTEMPTS);
    }

    /** The document's module (when it has one) must be on in the company. */
    public static function moduleOn(Signable $document, Company|int $company): bool
    {
        $module = $document->signingModule();

        return $module === null || app(ModuleManager::class)->isEnabled($module, $company);
    }

    /**
     * The reminder choice of a send: off unless the sender switched it on
     * (ADR-046 principle 9; the signing panel's tick box is on by default,
     * the API and the ÄTA flow never switch it on), every 1, 2, 3 or 7 days
     * (default 3, owner decision 13) or once before the deadline.
     *
     * @param  array<string, mixed>  $validated
     * @return array{auto: bool, interval: ?int}
     */
    private function reminders(array $validated): array
    {
        $auto = (bool) ($validated['auto_reminders'] ?? false);

        if (! $auto) {
            return ['auto' => false, 'interval' => null];
        }

        $given = $validated['reminder_days'] ?? null;

        return ['auto' => true, 'interval' => array_key_exists('reminder_days', $validated) && $given !== null && $given !== '' ? SigningDeadlines::interval($given) : SigningDeadlines::DEFAULT_INTERVAL];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateEnvelope(array $input): array
    {
        $validated = Validator::make($input, [
            'signers' => ['required', 'array', 'min:2', 'max:'.SigningEnvelope::MAX_SIGNERS],
            'signers.*.email' => ['required', 'email', 'max:255'],
            'signers.*.step' => ['required', 'integer', 'min:1', 'max:'.SigningEnvelope::MAX_STEP],
            'copies' => ['nullable', 'array', 'max:'.SigningEnvelope::MAX_COPIES],
            'copies.*.email' => ['required', 'email', 'max:255'],
            'copies.*.name' => ['nullable', 'string', 'max:120', 'regex:/^[^\p{C}]*$/u'],
            'notify_copies' => ['sometimes', 'boolean'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'auto_reminders' => ['sometimes', 'boolean'],
            'reminder_days' => ['nullable'],
            'create_invoice' => ['sometimes', 'boolean'],
            'deal_id' => ['nullable', 'integer'],
        ], [
            'signers.min' => __('Lägg till minst två undertecknare, eller skicka till en.'),
            'signers.max' => __('Högst :max undertecknare.', ['max' => SigningEnvelope::MAX_SIGNERS]),
            'copies.max' => __('Högst :max kopiemottagare.', ['max' => SigningEnvelope::MAX_COPIES]),
        ], [
            'signers.*.email' => __('e-post'),
            'signers.*.step' => __('steg'),
            'copies.*.email' => __('e-post'),
            'copies.*.name' => __('namn'),
            'expires_on' => __('sista dag att signera'),
        ])->validate();

        /** @var list<array{email: string}> $signers */
        $signers = $validated['signers'];
        /** @var list<array{email: string}> $copies */
        $copies = $validated['copies'] ?? [];
        $signerEmails = array_map(fn (array $signer): string => mb_strtolower(trim($signer['email'])), $signers);
        $copyEmails = array_map(fn (array $copy): string => mb_strtolower(trim($copy['email'])), $copies);

        if (count(array_unique($signerEmails)) !== count($signerEmails)) {
            throw ValidationException::withMessages(['signers' => __('Varje undertecknare behöver en egen e-postadress.')]);
        }

        if (count(array_unique($copyEmails)) !== count($copyEmails) || array_intersect($signerEmails, $copyEmails) !== []) {
            throw ValidationException::withMessages(['copies' => __('En kopiemottagare kan inte stå två gånger eller också vara undertecknare.')]);
        }

        return $validated;
    }

    /**
     * The document, locked, still open, and not a signed quote.
     *
     * @throws ValidationException
     */
    private function lockOpenDocument(Signable $document): Signable
    {
        $document = $this->types->documentFor(new SigningRequest($this->types->attributesFor($document)), lock: true);
        abort_unless($document instanceof Signable, 404);

        if ($document instanceof SalesDocument && SignedDocuments::isQuoteSigned($document->id)) {
            throw ValidationException::withMessages(['document' => SignedDocuments::awaitsSeveralSigners($document, $this->types)
                ? __('Offerten väntar på signering av flera, och någon har redan signerat. Dra tillbaka signeringen först om offerten ska skickas igen.')
                : __('Offerten är redan signerad.')]);
        }

        if (! $document->signingIsOpen()) {
            throw ValidationException::withMessages(['document' => $document instanceof SalesDocument
                ? __(':title är inte längre öppen.', ['title' => $document->signingTitle()])
                : __('Avtalet är redan signerat.')]);
        }

        return $document;
    }

    /** @return array{path: string, name: string, sha256: string} */
    private function storePdf(Company $company, Signable $document): array
    {
        $pdf = $document->signingDocument();
        $path = "crm-signing/{$company->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk(SigningRequest::DISK)->put($path, $pdf['bytes']);

        return ['path' => $path, 'name' => $pdf['name'], 'sha256' => hash('sha256', $pdf['bytes'])];
    }

    private function expiry(Signable $document, mixed $given, CarbonImmutable $today): CarbonImmutable
    {
        return SigningDeadlines::resolve($document, $given, $today);
    }

    /** The deal to win on signing: an open deal of the document's CRM account, only while CRM is on. */
    private function deal(Signable $document, mixed $dealId): ?int
    {
        if ($dealId === null || $dealId === '') {
            return null;
        }

        $accountId = match (true) {
            ! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $document->signingCompanyId()) => null,
            $document instanceof Agreement => $document->account_id,
            $document instanceof SalesDocument => Account::query()->where('customer_id', $document->customer_id)->value('id'),
            default => null,
        };

        $deal = $accountId === null ? null : Deal::query()->whereKey((int) $dealId)->where('status', DealStatus::Open->value)->where('account_id', $accountId)->first();

        if (! $deal instanceof Deal) {
            throw ValidationException::withMessages(['deal_id' => __('Affären finns inte, är avslutad eller hör till en annan kund.')]);
        }

        return $deal->id;
    }

    private function revokeOpen(User $user, Company $company, Signable $document): void
    {
        $open = SigningRequest::query()
            ->where('document_type', $document->signingType())
            ->where($this->types->column($document->signingType()), $document->signingKey())
            ->where('status', SigningStatus::Pending->value)
            ->lockForUpdate()
            ->get();
        $done = [];

        foreach ($open as $request) {
            if (in_array($request->id, $done, true)) {
                continue;
            }

            $done = [...$done, ...$this->revokeRequest($user, $company, $request, 'RESENT')];
        }
    }

    /**
     * Revokes one link and withdraws its envelope (revoking the envelope's
     * other open links).
     *
     * @return list<int> every request revoked
     */
    private function revokeRequest(User $user, Company $company, SigningRequest $request, string $reason): array
    {
        $request->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => now()])->save();
        $this->chain->record($request, SigningEventType::Revoked, $user->id);
        Audit::record('crm.signing_revoked', $request, ['signing_request_id' => $request->id], $company, $user);

        return [$request->id, ...$this->envelopes->revoked($request, $user->id, $reason)];
    }
}
