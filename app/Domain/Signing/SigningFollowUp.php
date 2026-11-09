<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Mail\SignedDocumentMail;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\User;
use App\Notifications\SigningNotice;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * What happens in NONOX after the signer has acted (ADR-046, core since
 * ADR-075 §7).
 *
 * Signed quote: it becomes an order through the invoicing core's own
 * ProgressSalesDocument (and a draft invoice when the sender chose so),
 * acting as the person who sent the link (or, if they have left or lost
 * the right, the company owner) with that person's own rights. Then each
 * module's SigningHook adds its steps: while CRM is on, the deal chosen
 * when sending is won and a done note is logged on the account. The sender
 * (and whoever a hook names, such as the deal's owner) gets a notice; the
 * signer and the company get the signed PDF and the signing certificate by
 * e-mail. A step that the actor may not take is skipped and audited, never
 * the signature itself, which is already committed. Idempotent:
 * followed_up_at is set under a row lock.
 *
 * Declined: the hooks' notes and a notice; the document stays open for the
 * seller to decide.
 */
class SigningFollowUp
{
    /** Skipped: the quote was changed between the signature and the follow-up. */
    public const string CHANGED_AFTER_SIGNING = 'CHANGED_AFTER_SIGNING';

    public function __construct(
        private readonly ProgressSalesDocument $progress,
        private readonly SigningHooks $hooks,
    ) {}

    /** Must run inside the company. */
    public function signed(int $companyId, int $requestId): void
    {
        $company = Company::query()->findOrFail($companyId);

        DB::transaction(function () use ($company, $requestId): void {
            $request = SigningRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->followed_up_at !== null || $request->signed_at === null) {
                return;
            }

            $actor = $this->actor($company, $request);
            $orderId = null;
            $invoiceId = null;
            $skipped = [];
            $title = SigningPdfs::title($request);

            if ($request->isQuote()) {
                [$orderId, $invoiceId, $skipped] = $this->quote($request, $actor);
            }

            foreach ($this->hooks->all() as $hook) {
                $skipped = [...$skipped, ...$hook->signed($company, $request, $actor, $orderId, $invoiceId)];
            }

            $request->forceFill(['order_id' => $orderId, 'invoice_id' => $invoiceId, 'followed_up_at' => now()])->save();

            Audit::record('crm.signing_followed_up', $request, array_filter(['signing_request_id' => $request->id, 'order_id' => $orderId, 'invoice_id' => $invoiceId, 'skipped' => $skipped === [] ? null : $skipped]), $company, $actor);

            $this->notify($company, $request, match (true) {
                in_array(self::CHANGED_AFTER_SIGNING, $skipped, true) => __(':title ändrades efter att den signerades. Ordern skapades inte; kontrollera offerten mot den signerade PDF:en.', ['title' => $title]),
                $skipped === [] => $request->isQuote() ? __(':title är signerad av kunden.', ['title' => $title]) : __('Avtalet ”:title” är signerat.', ['title' => $title]),
                default => __(':title är signerad, men allt kunde inte göras automatiskt. Öppna offerten och gör order eller faktura för hand.', ['title' => $title]),
            });

            // The signer's copy went from SignAtLink when the signature was saved; the company's goes here.
            $this->mailCompanyCopy($company, $request);
        });
    }

    /**
     * The follow-up failed for good (FollowUpSigning::failed): the signature
     * stands, so the sender is told to make the order by hand.
     */
    public function failed(int $companyId, int $requestId): void
    {
        $company = Company::query()->find($companyId);
        $request = SigningRequest::query()->find($requestId);

        if (! $company instanceof Company || ! $request instanceof SigningRequest) {
            return;
        }

        Audit::record('crm.signing_follow_up_failed', $request, ['signing_request_id' => $request->id], $company);
        $this->notify($company, $request, __(':title är signerad, men ordern kunde inte skapas automatiskt. Gör den för hand.', ['title' => SigningPdfs::title($request)]));
    }

    /**
     * The signer's copy: the signed PDF and the certificate, queued after
     * the signature commits, whatever happens to the order.
     */
    public static function mailSignerCopy(SigningRequest $request): void
    {
        $company = Company::query()->find($request->company_id);

        if (! $company instanceof Company || $company->isTestCompany()) {
            return;
        }

        Mail::to($request->email)->queue((new SignedDocumentMail($request->id, $company->id, true))->afterCommit());
    }

    /** Must run inside the company, in the decline's transaction. */
    public function declined(SigningRequest $request): void
    {
        $company = Company::query()->findOrFail($request->company_id);
        $title = SigningPdfs::title($request);

        foreach ($this->hooks->all() as $hook) {
            $hook->declined($company, $request);
        }

        $this->notify($company, $request, $request->isQuote() ? __(':title avböjdes av kunden.', ['title' => $title]) : __('Avtalet ”:title” avböjdes.', ['title' => $title]));
    }

    /**
     * The company's members (owner and team), by id.
     *
     * @return list<int>
     */
    public static function memberIds(Company $company): array
    {
        return array_values(array_map('intval', User::query()
            ->where(fn ($query) => $query
                ->whereKey($company->user_id)
                ->orWhereIn('id', fn ($members) => $members->select('user_id')->from('team_user')->where('team_id', $company->id)))
            ->pluck('id')
            ->all()));
    }

    /**
     * The order (and invoice draft) from a signed quote.
     *
     * @return array{?string, ?string, list<string>}
     */
    private function quote(SigningRequest $request, ?User $actor): array
    {
        // Locked, and compared with what was signed: SaveSalesDocument refuses a signed quote, but the order must be what the signer saw.
        $quote = SalesDocument::query()->whereKey($request->sales_document_id)->lockForUpdate()->first();

        if ($quote instanceof SalesDocument && ! hash_equals((string) $request->content_hash, QuoteFingerprint::for($quote))) {
            return [null, null, [self::CHANGED_AFTER_SIGNING]];
        }

        if (! $quote instanceof SalesDocument || ! $quote->isOpen() || ! $actor instanceof User) {
            return [null, null, ['ORDER']];
        }

        try {
            $order = $this->progress->toOrder($actor, $quote);
        } catch (AuthorizationException|ValidationException) {
            return [null, null, ['ORDER']];
        }

        if (! $request->create_invoice) {
            return [$order->id, null, []];
        }

        try {
            $invoice = $this->progress->toInvoice($actor, $order);
        } catch (AuthorizationException|ValidationException) {
            return [$order->id, null, ['INVOICE']];
        }

        return [$order->id, $invoice->id, []];
    }

    /**
     * Who the follow-up acts as: the sender while they are still in the
     * company, else the company's owner.
     */
    private function actor(Company $company, SigningRequest $request): ?User
    {
        $members = self::memberIds($company);

        foreach ([$request->sent_by, $company->user_id] as $candidate) {
            if ($candidate !== null && in_array((int) $candidate, $members, true)) {
                return User::query()->find($candidate);
            }
        }

        return null;
    }

    private function notify(Company $company, SigningRequest $request, string $sentence): void
    {
        $members = self::memberIds($company);
        $candidates = [$request->sent_by ?? $company->user_id];

        foreach ($this->hooks->all() as $hook) {
            $candidates = [...$candidates, ...$hook->recipients($company, $request)];
        }

        $recipients = array_unique(array_filter($candidates, fn (int $id): bool => in_array($id, $members, true)));
        $destination = $request->document()?->signingDestination() ?? ($request->isQuote() ? 'quotes' : 'crm-agreements');

        foreach (User::query()->whereKey($recipients)->get() as $user) {
            $user->notify(new SigningNotice($company->id, (string) ($company->legal_name ?: $company->name), $sentence, $destination));
        }
    }

    /** The signed PDF and the certificate to the company (its address, else the sender's), unless that is the signer's own. */
    private function mailCompanyCopy(Company $company, SigningRequest $request): void
    {
        if ($company->isTestCompany()) {
            return;
        }

        $address = $company->email ?: $this->copyRecipient($company, $request)?->email;

        if (! is_string($address) || $address === '' || mb_strtolower($address) === $request->email) {
            return;
        }

        Mail::to(mb_strtolower($address))->queue((new SignedDocumentMail($request->id, $company->id, false))->afterCommit());
    }

    /**
     * Without a company address, the copy (with prices) goes to the sender,
     * else to a member a module names (an ÄTA: the work order's creator),
     * never to a member a module refuses (SigningHook::mayReceiveCopy, such
     * as a field worker who presented an ÄTA on site, ADR-074 O4).
     */
    private function copyRecipient(Company $company, SigningRequest $request): ?User
    {
        $members = self::memberIds($company);
        $candidates = [];

        foreach ($this->hooks->all() as $hook) {
            $candidates = [...$candidates, ...$hook->recipients($company, $request)];
        }

        foreach ([$request->sent_by, ...$candidates] as $id) {
            $user = $id === null || ! in_array((int) $id, $members, true) ? null : User::query()->find((int) $id);

            if ($user instanceof User && collect($this->hooks->all())->every(fn (SigningHook $hook): bool => $hook->mayReceiveCopy($company, $user, $request))) {
                return $user;
            }
        }

        return null;
    }
}
