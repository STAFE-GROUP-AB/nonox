<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Signable;
use App\Domain\Signing\SignableTypes;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
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
 */
final class SendForSigning
{
    public const int DEFAULT_DAYS = 30;

    public const int MAX_DAYS = 90;

    private const string ZONE = 'Europe/Stockholm';

    public function __construct(private readonly SignableTypes $types) {}

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
            'create_invoice' => ['sometimes', 'boolean'],
            'deal_id' => ['nullable', 'integer'],
        ], [], ['email' => __('e-post'), 'expires_on' => __('sista dag att signera')])->validate();

        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);

        MailQuota::take($company, 'documents');

        $token = SigningToken::generate();

        $request = DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $token): SigningRequest {
            $document = $this->types->documentFor(new SigningRequest($this->types->attributesFor($document)), lock: true);
            abort_unless($document instanceof Signable, 404);

            if ($document instanceof SalesDocument && SignedDocuments::isQuoteSigned($document->id)) {
                throw ValidationException::withMessages(['document' => __('Offerten är redan signerad.')]);
            }

            if (! $document->signingIsOpen()) {
                throw ValidationException::withMessages(['document' => $document instanceof SalesDocument
                    ? __(':title är inte längre öppen.', ['title' => $document->signingTitle()])
                    : __('Avtalet är redan signerat.')]);
            }

            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $pdf = $document->signingDocument();
            $path = "crm-signing/{$company->id}/".Str::uuid()->toString().'.pdf';
            Storage::disk(SigningRequest::DISK)->put($path, $pdf['bytes']);

            $request = SigningRequest::create([
                ...$this->types->attributesFor($document),
                'deal_id' => $dealId,
                'token_hash' => $token['hash'],
                'email' => mb_strtolower((string) $validated['email']),
                'expires_at' => $expires,
                'document_path' => $path,
                'document_name' => $pdf['name'],
                'document_sha256' => hash('sha256', $pdf['bytes']),
                'content_hash' => $document->signingFingerprint(),
                'create_invoice' => $document instanceof SalesDocument && (bool) ($validated['create_invoice'] ?? false),
                'sent_by' => $user->id,
            ]);

            SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Sent, 'user_id' => $user->id]);
            Audit::record('crm.signing_sent', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'sales_document_id' => $request->sales_document_id, 'agreement_id' => $request->agreement_id, 'signable_id' => $request->signable_id, 'deal_id' => $dealId], $company, $user);

            return $request;
        }));

        $url = route('signing.show', ['token' => $token['token']]);

        // A test company's mail never leaves NONOX (ADR-032). Queued after commit, so a rolled-back send mails nothing.
        if (! $company->isTestCompany()) {
            Mail::to($request->email)->queue(new SigningRequestMail($request->id, $company->id, $url));
        }

        return ['request' => $request, 'url' => $url];
    }

    public function revoke(User $user, Company $company, SigningRequest $request): SigningRequest
    {
        $document = $request->document();
        abort_unless($document instanceof Signable, 404);
        $document->authorizeSigning($user, revoke: true);

        return DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $request): SigningRequest {
            $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SigningStatus::Pending) {
                throw ValidationException::withMessages(['signing' => __('Länken är redan använd eller återkallad.')]);
            }

            $this->revokeRequest($user, $company, $locked);

            return $locked;
        }));
    }

    /** The document's module (when it has one) must be on in the company. */
    public static function moduleOn(Signable $document, Company|int $company): bool
    {
        $module = $document->signingModule();

        return $module === null || app(ModuleManager::class)->isEnabled($module, $company);
    }

    private function expiry(Signable $document, mixed $given, CarbonImmutable $today): CarbonImmutable
    {
        $latest = $today->addDays(self::MAX_DAYS);
        $until = $document->signingValidUntil();

        if (is_string($given) && $given !== '') {
            $day = CarbonImmutable::parse($given, self::ZONE)->startOfDay();

            if ($day->lessThan($today) || $day->greaterThan($latest)) {
                throw ValidationException::withMessages(['expires_on' => __('Sista dag att signera ska vara idag eller inom :days dagar.', ['days' => self::MAX_DAYS])]);
            }

            // A quote cannot be signed after its last valid day.
            if ($until !== null && $day->toDateString() > $until->toDateString()) {
                throw ValidationException::withMessages(['expires_on' => __('Sista dag att signera kan inte vara efter offertens giltighetsdag (:date).', ['date' => $until->toDateString()])]);
            }

            return $day->endOfDay();
        }

        if ($until !== null && $until->greaterThanOrEqualTo($today->toDateString())) {
            $day = CarbonImmutable::parse($until->toDateString(), self::ZONE);

            return ($day->greaterThan($latest) ? $latest : $day)->endOfDay();
        }

        return $today->addDays(self::DEFAULT_DAYS)->endOfDay();
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

        foreach ($open as $request) {
            $this->revokeRequest($user, $company, $request);
        }
    }

    private function revokeRequest(User $user, Company $company, SigningRequest $request): void
    {
        $request->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => now()])->save();
        SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Revoked, 'user_id' => $user->id]);
        Audit::record('crm.signing_revoked', $request, ['signing_request_id' => $request->id], $company, $user);
    }
}
