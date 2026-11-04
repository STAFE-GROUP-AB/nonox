<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\QuoteFingerprint;
use App\Modules\Crm\Domain\SigningEventType;
use App\Modules\Crm\Domain\SigningStatus;
use App\Modules\Crm\Domain\SigningToken;
use App\Modules\Crm\Mail\SigningRequestMail;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\SigningEvent;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Support\SignedDocuments;
use App\Modules\Crm\Support\SigningPdfs;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka för signering" (ADR-046): renders the document's PDF once and
 * keeps those exact bytes with their SHA-256, makes a 256-bit link token
 * (only its hash is stored), revokes any earlier open link for the same
 * document, and e-mails the link (queued, the company's name as sender
 * name, replies to the company). One open link per document.
 *
 * A quote needs invoices:create and invoices:send and must be open: signing
 * turns it into an order, and sending it is what invoices:send allows. An
 * agreement needs crm:manage. The link expires at the end of the quote's
 * last valid day, else after 30 days, and never later than 90 days.
 *
 * Input: email, expires_on (optional date), create_invoice (quotes:
 * also make a draft invoice once signed), deal_id (an open deal of the
 * account that is won when the document is signed).
 */
final class SendForSigning
{
    public const int DEFAULT_DAYS = 30;

    public const int MAX_DAYS = 90;

    /**
     * @param  array<string, mixed>  $input
     * @return array{request: SigningRequest, url: string}
     */
    public function __invoke(User $user, Company $company, SalesDocument|Agreement $document, array $input): array
    {
        abort_unless(app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company), 404);
        $this->authorize($user, $document);

        $validated = Validator::make($input, [
            'email' => ['required', 'email', 'max:255'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'create_invoice' => ['sometimes', 'boolean'],
            'deal_id' => ['nullable', 'integer'],
        ], [], ['email' => __('e-post'), 'expires_on' => __('sista dag att signera')])->validate();

        $today = CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
        $expires = $this->expiry($document, $validated['expires_on'] ?? null, $today);

        MailQuota::take($company, 'documents');

        $token = SigningToken::generate();

        $request = DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $document, $validated, $expires, $token): SigningRequest {
            if ($document instanceof SalesDocument) {
                $document = SalesDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

                if (SignedDocuments::isQuoteSigned($document->id)) {
                    throw ValidationException::withMessages(['document' => __('Offerten är redan signerad.')]);
                }

                if (! $document->isOpen()) {
                    throw ValidationException::withMessages(['document' => __(':title är inte längre öppen.', ['title' => $document->title()])]);
                }
            } else {
                $document = Agreement::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

                if ($document->signed_at !== null) {
                    throw ValidationException::withMessages(['document' => __('Avtalet är redan signerat.')]);
                }
            }

            $dealId = $this->deal($document, $validated['deal_id'] ?? null);

            $this->revokeOpen($user, $company, $document);

            $pdf = SigningPdfs::document($document);
            $path = "crm-signing/{$company->id}/".Str::uuid()->toString().'.pdf';
            Storage::disk(SigningRequest::DISK)->put($path, $pdf['bytes']);

            $request = SigningRequest::create([
                'document_type' => $document instanceof SalesDocument ? SigningRequest::QUOTE : SigningRequest::AGREEMENT,
                'sales_document_id' => $document instanceof SalesDocument ? $document->id : null,
                'agreement_id' => $document instanceof Agreement ? $document->id : null,
                'deal_id' => $dealId,
                'token_hash' => $token['hash'],
                'email' => mb_strtolower((string) $validated['email']),
                'expires_at' => $expires,
                'document_path' => $path,
                'document_name' => $pdf['name'],
                'document_sha256' => hash('sha256', $pdf['bytes']),
                'content_hash' => $document instanceof SalesDocument ? QuoteFingerprint::for($document) : QuoteFingerprint::forAgreement($document),
                'create_invoice' => $document instanceof SalesDocument && (bool) ($validated['create_invoice'] ?? false),
                'sent_by' => $user->id,
            ]);

            SigningEvent::create(['signing_request_id' => $request->id, 'event' => SigningEventType::Sent, 'user_id' => $user->id]);
            Audit::record('crm.signing_sent', $request, ['signing_request_id' => $request->id, 'document_type' => $request->document_type, 'sales_document_id' => $request->sales_document_id, 'agreement_id' => $request->agreement_id, 'deal_id' => $dealId], $company, $user);

            return $request;
        }));

        $url = route('crm.signing.show', ['token' => $token['token']]);

        // A test company's mail never leaves NONOX (ADR-032). Queued after commit, so a rolled-back send mails nothing.
        if (! $company->isTestCompany()) {
            Mail::to($request->email)->queue(new SigningRequestMail($request->id, $company->id, $url));
        }

        return ['request' => $request, 'url' => $url];
    }

    public function revoke(User $user, Company $company, SigningRequest $request): SigningRequest
    {
        $document = $request->isQuote() ? $request->quote : $request->agreement;
        abort_unless($document !== null, 404);
        $this->authorize($user, $document, revoke: true);

        return DB::transaction(fn (): SigningRequest => CurrentCompany::run($company, function () use ($user, $company, $request): SigningRequest {
            $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SigningStatus::Pending) {
                throw ValidationException::withMessages(['signing' => __('Länken är redan använd eller återkallad.')]);
            }

            $this->revokeRequest($user, $company, $locked);

            return $locked;
        }));
    }

    private function authorize(User $user, SalesDocument|Agreement $document, bool $revoke = false): void
    {
        if ($document instanceof Agreement) {
            Gate::forUser($user)->authorize('update', $document);

            return;
        }

        // Revoking a link is allowed after the quote has closed too; sending needs an open quote.
        if (! $revoke) {
            Gate::forUser($user)->authorize('update', $document);
        }

        Gate::forUser($user)->authorize('send', $document);
        abort_unless($user->hasTeamPermission(Company::query()->findOrFail($document->company_id), 'invoices:create'), 403);
    }

    private function expiry(SalesDocument|Agreement $document, mixed $given, CarbonImmutable $today): CarbonImmutable
    {
        $latest = $today->addDays(self::MAX_DAYS);

        if (is_string($given) && $given !== '') {
            $day = CarbonImmutable::parse($given, LogActivity::ZONE)->startOfDay();

            if ($day->lessThan($today) || $day->greaterThan($latest)) {
                throw ValidationException::withMessages(['expires_on' => __('Sista dag att signera ska vara idag eller inom :days dagar.', ['days' => self::MAX_DAYS])]);
            }

            // A quote cannot be signed after its last valid day.
            if ($document instanceof SalesDocument && $document->until_date !== null && $day->toDateString() > $document->until_date->toDateString()) {
                throw ValidationException::withMessages(['expires_on' => __('Sista dag att signera kan inte vara efter offertens giltighetsdag (:date).', ['date' => $document->until_date->toDateString()])]);
            }

            return $day->endOfDay();
        }

        $until = $document instanceof SalesDocument ? $document->until_date : null;

        if ($until !== null && $until->greaterThanOrEqualTo($today->toDateString())) {
            $day = CarbonImmutable::parse($until->toDateString(), LogActivity::ZONE);

            return ($day->greaterThan($latest) ? $latest : $day)->endOfDay();
        }

        return $today->addDays(self::DEFAULT_DAYS)->endOfDay();
    }

    /** The deal to win on signing: an open deal of the document's account. */
    private function deal(SalesDocument|Agreement $document, mixed $dealId): ?int
    {
        if ($dealId === null || $dealId === '') {
            return null;
        }

        $accountId = $document instanceof Agreement
            ? $document->account_id
            : Account::query()->where('customer_id', $document->customer_id)->value('id');

        $deal = Deal::query()->whereKey((int) $dealId)->where('status', DealStatus::Open->value)->where('account_id', $accountId)->first();

        if (! $deal instanceof Deal) {
            throw ValidationException::withMessages(['deal_id' => __('Affären finns inte, är avslutad eller hör till en annan kund.')]);
        }

        return $deal->id;
    }

    private function revokeOpen(User $user, Company $company, SalesDocument|Agreement $document): void
    {
        $open = SigningRequest::query()
            ->where($document instanceof SalesDocument ? 'sales_document_id' : 'agreement_id', $document->id)
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
