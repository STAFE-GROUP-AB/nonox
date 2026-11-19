<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\RestrictsEnvelopes;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningHost;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
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
use Illuminate\Auth\Access\AuthorizationException;
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
 *
 * Each signer can be checked before the document is shown (gap 3, S3):
 * verification NONE (the default, as before), EMAIL_CODE, or ACCESS_CODE
 * with access_code, which is stored hashed only.
 *
 * Fields in the PDF (gap 2, S6): an optional `fields` list (SigningFields)
 * places fields for the signers before the envelope's first event, after
 * which they are frozen. A field names its signer by index: 0 for the one
 * signer of __invoke, the index in `signers` for envelope().
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
        private readonly SigningFields $fields,
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
            'verification' => ['nullable', 'string', 'in:'.self::checkValues()],
            'access_code' => ['nullable', 'string'],
        ], [], ['email' => __('e-post'), 'expires_on' => __('sista dag att signera'), 'verification' => __('kontroll av undertecknaren')])->validate();

        // Hashed before the transaction (bcrypt is slow on purpose); the plain code is never stored or logged.
        $check = SignerCheck::from((string) ($validated['verification'] ?? SignerCheck::None->value));
        $accessCode = $check === SignerCheck::AccessCode ? SignerChecks::hashAccessCode($input['access_code'] ?? null, 'access_code') : null;

        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);
        $reminders = $this->reminders($validated);
        $fields = SigningFields::validate(is_array($input['fields'] ?? null) ? $input['fields'] : [], 1);
        // Fields need the page sizes: the PDF is made and measured now, before the quota and the transaction.
        $measured = $fields === [] ? null : CurrentCompany::run($company, fn (): array => $this->fields->measure($document, $fields));

        MailQuota::take($company, 'documents');

        $token = SigningToken::generate();

        $request = DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $token, $reminders, $check, $accessCode, $fields, $measured): SigningRequest {
            $document = $this->lockOpenDocument($document);
            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $file = $this->storePdf($company, $document, $measured);
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
                'verification' => $check->value,
                'access_code_hash' => $accessCode,
            ]);

            // Fields are placed before the envelope's first event; the trigger freezes them from then on.
            $this->fields->place($envelope, [$request], $fields, $measured);

            $this->chain->record($request, SigningEventType::Sent, $user->id, payload: $check->required() ? ['verification' => $check->value] : null);
            Audit::record('crm.signing_sent', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'sales_document_id' => $request->sales_document_id, 'agreement_id' => $request->agreement_id, 'signable_id' => $request->signable_id, 'deal_id' => $dealId, 'verification' => $check->value], $company, $user);

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
     * Input: signers (2–10 of email and step 1–10, distinct addresses; one
     * is allowed when $minSigners is 1, as the send wizard of ADR-046 S7
     * does), copies (0–10 of email, name), notify_copies, expires_on,
     * create_invoice, deal_id (as for one signer; every signer row carries
     * them, and they apply once everyone has signed), confidential (only a
     * document that restricts its envelopes, RestrictsEnvelopes).
     *
     * @param  array<string, mixed>  $input
     */
    public function envelope(User $user, Company $company, Signable $document, array $input, int $minSigners = 2): SigningEnvelope
    {
        abort_unless($document->signingCompanyId() === $company->id && self::moduleOn($document, $company), 404);
        $document->authorizeSigning($user);

        $validated = $this->validateEnvelope($input, $minSigners, $document instanceof RestrictsEnvelopes);
        $confidential = (bool) ($validated['confidential'] ?? false);

        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);

        $signerInput = array_values(array_filter((array) $validated['signers'], is_array(...)));
        $copyInput = array_values(array_filter((array) ($validated['copies'] ?? []), is_array(...)));
        $signers = collect($signerInput)->map(function (array $signer, int $index): array {
            $check = SignerCheck::from((string) ($signer['verification'] ?? SignerCheck::None->value));

            return [
                'email' => mb_strtolower(trim((string) $signer['email'])),
                'step' => (int) $signer['step'],
                'verification' => $check->value,
                // Hashed before the transaction; the plain code is never stored or logged.
                'access_code_hash' => $check === SignerCheck::AccessCode ? SignerChecks::hashAccessCode($signer['access_code'] ?? null, "signers.{$index}.access_code") : null,
            ];
        })->values();
        $copies = collect($copyInput)->map(fn (array $copy): array => [
            'email' => mb_strtolower(trim((string) $copy['email'])),
            'name' => blank($copy['name'] ?? null) ? null : trim((string) $copy['name']),
        ])->values();
        $notify = (bool) ($validated['notify_copies'] ?? false) && $copies->isNotEmpty();
        $reminders = $this->reminders($validated);
        $first = (int) $signers->min('step');
        $fields = SigningFields::validate(is_array($input['fields'] ?? null) ? $input['fields'] : [], $signers->count());
        $measured = $fields === [] ? null : CurrentCompany::run($company, fn (): array => $this->fields->measure($document, $fields));

        foreach (range(1, $signers->where('step', $first)->count() + ($notify ? $copies->count() : 0)) as $ignored) {
            MailQuota::take($company, 'documents', 'signers');
        }

        $result = DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $signers, $copies, $first, $reminders, $confidential, $fields, $measured): array {
            $document = $this->lockOpenDocument($document);
            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $file = $this->storePdf($company, $document, $measured);
            $envelope = $this->envelopes->create($document, $file, $expires, $user->id, $reminders, $confidential);
            $envelope->forceFill(['current_step' => $first])->save();
            $opened = [];
            $created = [];

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
                    'verification' => $signer['verification'],
                    'access_code_hash' => $signer['access_code_hash'],
                ]);

                $created[] = $request;
            }

            // Fields are placed before the envelope's first event (the first link); the trigger freezes them from then on.
            $this->fields->place($envelope, $created, $fields, $measured);

            foreach ($created as $request) {
                if ($request->step === $first) {
                    $opened[] = ['request_id' => $request->id, 'email' => $request->email, 'url' => $this->envelopes->issue($request, $user->id)];
                }
            }

            foreach ($copies as $copy) {
                SigningCopyRecipient::create(['envelope_id' => $envelope->id, ...$copy]);
            }

            Audit::record('crm.signing_envelope_sent', $envelope, ['envelope_id' => $envelope->id, 'document_type' => $envelope->document_type, 'sales_document_id' => $envelope->sales_document_id, 'agreement_id' => $envelope->agreement_id, 'signable_id' => $envelope->signable_id, 'deal_id' => $dealId, 'signers' => $signers->count(), 'steps' => $signers->pluck('step')->unique()->count(), 'copies' => $copies->count(), 'confidential' => $confidential], $company, $user);

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
        self::authorizeAct($document, $user, $request->envelope_id === null ? null : SigningEnvelope::query()->find($request->envelope_id));

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
        self::authorizeAct($document, $user, $envelope);

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

    /**
     * May the user act on an envelope that is out (revoke a link, remind,
     * change the last day, withdraw)? The document's own sending rights, and
     * for a document that restricts its envelopes (ADR-046 S7) its rule for
     * the envelope: one's own with the sending right, another's with the
     * managing right.
     *
     * @throws AuthorizationException
     */
    public static function authorizeAct(Signable $document, User $user, ?SigningEnvelope $envelope): void
    {
        $document->authorizeSigning($user, revoke: true);

        if ($envelope instanceof SigningEnvelope && $document instanceof RestrictsEnvelopes) {
            $document->authorizeEnvelope($user, $envelope);
        }
    }

    /** The signer checks a sender may choose (ADR-046 amendment, gap 3), for the in: rule. */
    private static function checkValues(): string
    {
        return implode(',', array_map(fn (SignerCheck $check): string => $check->value, SignerCheck::choosable()));
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
    private function validateEnvelope(array $input, int $minSigners = 2, bool $mayBeConfidential = false): array
    {
        $validated = Validator::make($input, [
            'signers' => ['required', 'array', 'min:'.$minSigners, 'max:'.SigningEnvelope::MAX_SIGNERS],
            'signers.*.email' => ['required', 'email', 'max:255'],
            'signers.*.step' => ['required', 'integer', 'min:1', 'max:'.SigningEnvelope::MAX_STEP],
            'signers.*.verification' => ['nullable', 'string', 'in:'.self::checkValues()],
            'signers.*.access_code' => ['nullable', 'string'],
            'copies' => ['nullable', 'array', 'max:'.SigningEnvelope::MAX_COPIES],
            'copies.*.email' => ['required', 'email', 'max:255'],
            'copies.*.name' => ['nullable', 'string', 'max:120', 'regex:/^[^\p{C}]*$/u'],
            'notify_copies' => ['sometimes', 'boolean'],
            'confidential' => ['sometimes', 'boolean', $mayBeConfidential ? 'nullable' : 'declined'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'auto_reminders' => ['sometimes', 'boolean'],
            'reminder_days' => ['nullable'],
            'create_invoice' => ['sometimes', 'boolean'],
            'deal_id' => ['nullable', 'integer'],
            'fields' => ['nullable', 'array'],
        ], [
            'signers.min' => $minSigners > 1 ? __('Lägg till minst två undertecknare, eller skicka till en.') : __('Lägg till minst en undertecknare.'),
            'confidential.declined' => __('Bara dokument som laddats upp under Signering kan vara konfidentiella.'),
            'signers.max' => __('Högst :max undertecknare.', ['max' => SigningEnvelope::MAX_SIGNERS]),
            'copies.max' => __('Högst :max kopiemottagare.', ['max' => SigningEnvelope::MAX_COPIES]),
        ], [
            'signers.*.email' => __('e-post'),
            'signers.*.step' => __('steg'),
            'signers.*.verification' => __('kontroll av undertecknaren'),
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

    /**
     * The PDF that is sent, stored once. With fields (ADR-046 S6) it is the PDF already measured before the
     * transaction, as long as the document (now locked) is still what was measured.
     *
     * @param  ?array{bytes: string, name: string, sha256: string, fingerprint: string}  $measured
     * @return array{path: string, name: string, sha256: string}
     *
     * @throws ValidationException
     */
    private function storePdf(Company $company, Signable $document, ?array $measured = null): array
    {
        if ($measured !== null && ! hash_equals($measured['fingerprint'], $document->signingFingerprint())) {
            throw ValidationException::withMessages(['fields' => __('Dokumentet ändrades medan det skickades. Försök igen.')]);
        }

        $pdf = $measured ?? $document->signingDocument();
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
