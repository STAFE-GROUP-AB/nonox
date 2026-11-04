<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Actions\Invoicing\ProgressSalesDocument;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Mail\SignedDocumentMail;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Notifications\SigningNotice;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * What happens in NONOX after the signer has acted (ADR-046).
 *
 * Signed quote: it becomes an order through the invoicing core's own
 * ProgressSalesDocument (and a draft invoice when the sender chose so),
 * acting as the person who sent the link (or, if they have left or lost
 * the right, the company owner) with that person's own rights; the deal
 * chosen when sending is won through WinDeal with the quote, order and
 * invoice linked; a done CRM note "Offerten signerades" is logged on the
 * account; the sender and the deal's owner get a notice; the signer and
 * the company get the signed PDF and the signing certificate by e-mail.
 * A step that the actor may not take is skipped and audited, never the
 * signature itself, which is already committed. Idempotent:
 * followed_up_at is set under a row lock.
 *
 * Declined: a done note with the reason on the account and a notice; the
 * quote stays open for the seller to decide.
 */
final class SigningFollowUp
{
    public function __construct(
        private readonly ProgressSalesDocument $progress,
        private readonly WinDeal $win,
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

            $accountId = $this->accountId($request);

            if ($request->deal_id !== null) {
                $skipped = [...$skipped, ...$this->winDeal($company, $request, $actor, $orderId, $invoiceId)];
            }

            if ($accountId !== null) {
                $this->note($accountId, $request, $request->isQuote() ? __(':title signerades', ['title' => $title]) : __('Avtalet ”:title” signerades', ['title' => $title]), $this->signedBody($request, $orderId, $invoiceId));
            }

            $request->forceFill(['order_id' => $orderId, 'invoice_id' => $invoiceId, 'followed_up_at' => now()])->save();

            Audit::record('crm.signing_followed_up', $request, array_filter(['signing_request_id' => $request->id, 'order_id' => $orderId, 'invoice_id' => $invoiceId, 'skipped' => $skipped === [] ? null : $skipped]), $company, $actor);

            $this->notify($company, $request, $skipped === []
                ? ($request->isQuote() ? __(':title är signerad av kunden.', ['title' => $title]) : __('Avtalet ”:title” är signerat.', ['title' => $title]))
                : __(':title är signerad, men allt kunde inte göras automatiskt. Öppna offerten och gör order eller faktura för hand.', ['title' => $title]));

            $this->mailCopies($company, $request);
        });
    }

    /** Must run inside the company, in the decline's transaction. */
    public function declined(SigningRequest $request): void
    {
        $company = Company::query()->findOrFail($request->company_id);
        $title = SigningPdfs::title($request);
        $accountId = $this->accountId($request);

        if ($accountId !== null) {
            $this->note(
                $accountId,
                $request,
                $request->isQuote() ? __(':title avböjdes', ['title' => $title]) : __('Avtalet ”:title” avböjdes', ['title' => $title]),
                $request->decline_reason === null ? __('Mottagaren avböjde utan att ange skäl.') : __('Skäl: :reason', ['reason' => $request->decline_reason]),
            );
        }

        $this->notify($company, $request, $request->isQuote() ? __(':title avböjdes av kunden.', ['title' => $title]) : __('Avtalet ”:title” avböjdes.', ['title' => $title]));
    }

    /**
     * The order (and invoice draft) from a signed quote.
     *
     * @return array{?string, ?string, list<string>}
     */
    private function quote(SigningRequest $request, ?User $actor): array
    {
        $quote = SalesDocument::query()->find($request->sales_document_id);

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

    /** @return list<string> */
    private function winDeal(Company $company, SigningRequest $request, ?User $actor, ?string $orderId, ?string $invoiceId): array
    {
        $deal = Deal::query()->find($request->deal_id);

        if (! $deal instanceof Deal || $deal->status !== DealStatus::Open) {
            return [];
        }

        if (! $actor instanceof User) {
            return ['DEAL'];
        }

        try {
            ($this->win)($actor, $company, $deal, array_filter([
                'quote_id' => $request->sales_document_id,
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
            ]));
        } catch (AuthorizationException|ValidationException) {
            return ['DEAL'];
        }

        return [];
    }

    private function accountId(SigningRequest $request): ?int
    {
        if (! $request->isQuote()) {
            return Agreement::query()->whereKey($request->agreement_id)->value('account_id');
        }

        $customerId = SalesDocument::query()->whereKey($request->sales_document_id)->value('customer_id');

        return $customerId === null ? null : Account::query()->where('customer_id', $customerId)->value('id');
    }

    private function note(int $accountId, SigningRequest $request, string $subject, string $body): void
    {
        $now = CarbonImmutable::now();

        Activity::create([
            'account_id' => $accountId,
            'deal_id' => $request->deal_id,
            'type' => ActivityType::Note,
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'assigned_to' => $request->sent_by,
            'due_at' => null,
            'done_at' => $now,
            'created_by' => null,
        ]);

        // The signer acted: that is contact, so the pruning clock starts again.
        Account::query()->whereKey($accountId)->update(['last_activity_at' => $now]);
    }

    private function signedBody(SigningRequest $request, ?string $orderId, ?string $invoiceId): string
    {
        $lines = [__('Signerad elektroniskt av :name:title.', ['name' => (string) $request->signer_name, 'title' => $request->signer_title === null ? '' : ", {$request->signer_title}"])];

        if ($orderId !== null) {
            $order = SalesDocument::query()->find($orderId);
            $lines[] = __(':title skapades.', ['title' => $order?->title() ?? __('En order')]);
        }

        if ($invoiceId !== null) {
            $lines[] = __('Ett fakturautkast skapades.');
        }

        return implode(' ', $lines);
    }

    /**
     * Who the follow-up acts as: the sender while they are still in the
     * company, else the company's owner.
     */
    private function actor(Company $company, SigningRequest $request): ?User
    {
        $members = CompanyMembers::ids($company);

        foreach ([$request->sent_by, $company->user_id] as $candidate) {
            if ($candidate !== null && in_array((int) $candidate, $members, true)) {
                return User::query()->find($candidate);
            }
        }

        return null;
    }

    private function notify(Company $company, SigningRequest $request, string $sentence): void
    {
        $members = CompanyMembers::ids($company);
        $recipients = array_unique(array_filter([
            $request->sent_by,
            $request->deal_id === null ? null : Deal::query()->whereKey($request->deal_id)->value('owner_id'),
            $request->agreement_id === null ? null : Agreement::query()->whereKey($request->agreement_id)->value('owner_id'),
        ], fn (mixed $id): bool => $id !== null && in_array((int) $id, $members, true)));

        foreach (User::query()->whereKey($recipients)->get() as $user) {
            $user->notify(new SigningNotice($company->id, (string) ($company->legal_name ?: $company->name), $sentence, $request->isQuote() ? 'quotes' : 'crm-agreements'));
        }
    }

    /** The signed PDF and the certificate to the signer and to the company (its address, else the sender's). */
    private function mailCopies(Company $company, SigningRequest $request): void
    {
        if ($company->isTestCompany()) {
            return;
        }

        $companyAddress = $company->email ?: User::query()->whereKey($request->sent_by)->value('email');

        foreach (array_unique(array_filter([$request->email, is_string($companyAddress) ? mb_strtolower($companyAddress) : null])) as $address) {
            Mail::to($address)->queue((new SignedDocumentMail($request->id, $company->id, $address === $request->email))->afterCommit());
        }
    }
}
