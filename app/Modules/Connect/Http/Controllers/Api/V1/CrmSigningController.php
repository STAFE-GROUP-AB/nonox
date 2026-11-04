<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Models\SalesDocument;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Actions\SendForSigning;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningEvent;
use App\Modules\Crm\Models\SigningRequest;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CRM stage 3 over the API (ADR-046, ADR-032): sending a quote or an
 * agreement for signing and reading the status of its links, and the
 * agreement register (read with crm:view; created and changed with
 * crm:manage). The same Actions as the screens. The link itself is never
 * returned: it goes only by e-mail to the address given, which is part of
 * the evidence. The signer's IP address, browser and drawn signature are
 * not exposed; the signing certificate holds them.
 */
final class CrmSigningController
{
    use ActsAsCredential;

    private const array AGREEMENT_FIELDS = ['account_id', 'title', 'kind', 'start_date', 'end_date', 'notice_months', 'auto_renew', 'renewal_months', 'value_ore', 'value_period', 'recurring_invoice_id', 'owner_id', 'body_text', 'notes'];

    public function quoteSignings(string $quote): JsonResponse
    {
        $document = $this->quote($quote);
        Gate::forUser($this->user())->authorize('view', $document);

        return $this->list(SigningRequest::query()->where('sales_document_id', $document->id));
    }

    public function sendQuote(Request $request, string $quote, SendForSigning $send): JsonResponse
    {
        $sent = $send($this->user(), $this->company(), $this->quote($quote), $this->signingInput($request));

        return CursorList::one(self::presentSigning($sent['request']->load('events')), 201);
    }

    public function agreements(Request $request): JsonResponse
    {
        $query = Agreement::query();

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('ending_within_days')) {
            $today = CarbonImmutable::now(LogActivity::ZONE)->startOfDay();
            $query->whereNotNull('end_date')
                ->where('end_date', '>=', $today->toDateString())
                ->where('end_date', '<=', $today->addDays(max(0, min(3650, $request->integer('ending_within_days'))))->toDateString());
        }

        return CursorList::respond($request, $query, fn (Agreement $agreement): array => $this->presentAgreement($agreement));
    }

    public function agreement(int $agreement): JsonResponse
    {
        return CursorList::one($this->presentAgreement(Agreement::query()->findOrFail($agreement)));
    }

    public function storeAgreement(Request $request, SaveAgreement $save): JsonResponse
    {
        $agreement = $save($this->user(), $this->company(), array_intersect_key($request->json()->all(), array_flip(self::AGREEMENT_FIELDS)));

        return CursorList::one($this->presentAgreement($agreement->refresh()), 201)
            ->header('Location', route('connect.api.crm.agreements.show', $agreement->id));
    }

    /**
     * Changes the given fields (the rest are kept); terminated_on (a date,
     * or null) terminates the agreement or withdraws the termination.
     */
    public function updateAgreement(Request $request, int $agreement, SaveAgreement $save): JsonResponse
    {
        $model = Agreement::query()->findOrFail($agreement);
        $body = $request->json()->all();
        $changes = array_intersect_key($body, array_flip(self::AGREEMENT_FIELDS));

        if ($changes !== []) {
            $current = [
                ...$model->only(['account_id', 'title', 'notice_months', 'auto_renew', 'renewal_months', 'value_ore', 'recurring_invoice_id', 'owner_id', 'body_text', 'notes']),
                'kind' => $model->kind->value,
                'value_period' => $model->value_period->value,
                'start_date' => $model->start_date->toDateString(),
                'end_date' => $model->end_date?->toDateString(),
            ];
            $model = $save($this->user(), $this->company(), [...$current, ...$changes], $model);
        }

        if (array_key_exists('terminated_on', $body)) {
            $model = $save->terminate($this->user(), $this->company(), $model, $body['terminated_on'] === null ? null : (string) $body['terminated_on']);
        }

        return CursorList::one($this->presentAgreement($model->refresh()));
    }

    public function agreementSignings(int $agreement): JsonResponse
    {
        $model = Agreement::query()->findOrFail($agreement);

        return $this->list(SigningRequest::query()->where('agreement_id', $model->id));
    }

    public function sendAgreement(Request $request, int $agreement, SendForSigning $send): JsonResponse
    {
        $sent = $send($this->user(), $this->company(), Agreement::query()->findOrFail($agreement), $this->signingInput($request));

        return CursorList::one(self::presentSigning($sent['request']->load('events')), 201);
    }

    /** @return array<string, mixed> */
    public static function presentSigning(SigningRequest $request): array
    {
        return [
            'id' => $request->id,
            'document_type' => $request->document_type,
            'quote_id' => $request->sales_document_id,
            'agreement_id' => $request->agreement_id,
            'deal_id' => $request->deal_id,
            'status' => $request->effectiveStatus()->value,
            'email' => $request->email,
            'expires_at' => self::time($request->expires_at),
            'document_name' => $request->document_name,
            'document_sha256' => $request->document_sha256,
            'create_invoice' => $request->create_invoice,
            'opened_at' => self::time($request->opened_at),
            'signed_at' => self::time($request->signed_at),
            'signer_name' => $request->signer_name,
            'signer_title' => $request->signer_title,
            'signature_method' => $request->signature_method,
            'signed_document_sha256' => $request->signed_document_sha256,
            'declined_at' => self::time($request->declined_at),
            'decline_reason' => $request->decline_reason,
            'revoked_at' => self::time($request->revoked_at),
            'order_id' => $request->order_id,
            'invoice_id' => $request->invoice_id,
            'sent_by' => $request->sent_by,
            'events' => $request->events->map(fn (SigningEvent $event): array => ['event' => $event->event->value, 'at' => self::time($event->created_at)])->values()->all(),
            'created_at' => self::time($request->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAgreement(Agreement $agreement): array
    {
        $canSeeInvoices = $this->user()->hasTeamPermission($this->company(), 'invoices:view');

        return [
            'id' => $agreement->id,
            'account_id' => $agreement->account_id,
            'title' => $agreement->title,
            'kind' => $agreement->kind->value,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => $agreement->end_date?->toDateString(),
            'notice_months' => $agreement->notice_months,
            'notice_deadline' => $agreement->noticeDeadline()?->toDateString(),
            'auto_renew' => $agreement->auto_renew,
            'renewal_months' => $agreement->renewal_months,
            'terminated_on' => $agreement->terminated_on?->toDateString(),
            'status' => $agreement->status(CarbonImmutable::now(LogActivity::ZONE)->startOfDay()),
            'value_ore' => $agreement->value_ore,
            'value_period' => $agreement->value_period->value,
            'recurring_invoice_id' => $canSeeInvoices ? $agreement->recurring_invoice_id : null,
            'owner_id' => $agreement->owner_id,
            'body_text' => $agreement->body_text,
            'notes' => $agreement->notes,
            'document_sha256' => $agreement->document_sha256,
            'signed_at' => self::time($agreement->signed_at),
            'created_at' => self::time($agreement->created_at),
            'updated_at' => self::time($agreement->updated_at),
        ];
    }

    /** @param  Builder<SigningRequest>  $query */
    private function list(Builder $query): JsonResponse
    {
        return CursorList::respond(request(), $query->with('events'), fn (SigningRequest $request): array => self::presentSigning($request));
    }

    private function quote(string $id): SalesDocument
    {
        return SalesDocument::query()->where('kind', SalesDocument::QUOTE)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function signingInput(Request $request): array
    {
        return array_intersect_key($request->json()->all(), array_flip(['email', 'expires_on', 'deal_id', 'create_invoice']));
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }
}
