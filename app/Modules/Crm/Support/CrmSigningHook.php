<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Domain\Signing\ResolvesSigningParty;
use App\Domain\Signing\SigningHook;
use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * CRM's part of the signing follow-up (ADR-046, ADR-075 §7), only while
 * CRM is on in the company: the deal chosen when the link was sent is won
 * with the quote, order and invoice linked, a done note "Offerten
 * signerades" (or "avböjdes") is logged on the account, and the deal's and
 * the agreement's owners are told too.
 */
final class CrmSigningHook implements ResolvesSigningParty, SigningHook
{
    public function __construct(
        private readonly WinDeal $win,
        private readonly ModuleManager $modules,
    ) {}

    public function signed(Company $company, SigningRequest $request, ?User $actor, ?string $orderId, ?string $invoiceId): array
    {
        if (! $this->modules->isEnabled(CrmModule::KEY, $company)) {
            return [];
        }

        $skipped = $request->deal_id !== null ? $this->winDeal($company, $request, $actor, $orderId, $invoiceId) : [];
        $accountId = $this->accountId($request);

        if ($accountId !== null) {
            $title = SigningPdfs::title($request);
            $this->note($accountId, $request, $request->isQuote() ? __(':title signerades', ['title' => $title]) : __('Avtalet ”:title” signerades', ['title' => $title]), $this->signedBody($request, $orderId, $invoiceId));
        }

        return $skipped;
    }

    /**
     * The customer's CRM account, when it has one: its kind and name decide
     * the acceptance text. The table exists whether or not CRM is on, so a
     * quote reads the same as before CRM was turned off.
     */
    public function signingParty(Customer $customer): ?array
    {
        $account = Account::query()->where('customer_id', $customer->id)->first();

        return $account instanceof Account ? ['name' => $account->name, 'legal_person' => $account->kind === AccountKind::Company] : null;
    }

    public function declined(Company $company, SigningRequest $request): void
    {
        $accountId = $this->modules->isEnabled(CrmModule::KEY, $company) ? $this->accountId($request) : null;

        if ($accountId === null) {
            return;
        }

        $title = SigningPdfs::title($request);
        $this->note(
            $accountId,
            $request,
            $request->isQuote() ? __(':title avböjdes', ['title' => $title]) : __('Avtalet ”:title” avböjdes', ['title' => $title]),
            // The reason stays only on the signing request, which is pruned after a year.
            $request->decline_reason === null ? __('Mottagaren avböjde utan att ange skäl.') : __('Mottagaren avböjde (skälet finns vid signeringen)'),
        );
    }

    /** CRM has no role that may not see the signed copy. */
    public function mayReceiveCopy(Company $company, User $user, SigningRequest $request): bool
    {
        return true;
    }

    public function recipients(Company $company, SigningRequest $request): array
    {
        return array_values(array_map('intval', array_filter([
            $request->deal_id === null ? null : Deal::query()->whereKey($request->deal_id)->value('owner_id'),
            $request->agreement_id === null ? null : Agreement::query()->whereKey($request->agreement_id)->value('owner_id'),
        ], fn (mixed $id): bool => $id !== null)));
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
        if ($request->document_type === SigningRequest::AGREEMENT) {
            return Agreement::query()->whereKey($request->agreement_id)->value('account_id');
        }

        if (! $request->isQuote()) {
            return null;
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
}
