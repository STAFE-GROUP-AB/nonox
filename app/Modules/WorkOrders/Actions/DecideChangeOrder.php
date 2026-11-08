<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\ContactChannel;
use App\Modules\WorkOrders\Domain\PerformedReason;
use App\Modules\WorkOrders\Domain\PriceForm;
use App\Modules\WorkOrders\Mail\ChangeOrderMail;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\ContactAttempt;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The customer's answer to an ÄTA, recorded by the company (ADR-074 §4):
 *
 * - approved orally: who, when and how; weaker evidence, shown so (AO-R3);
 * - performed without the customer's answer: one of the three reasons in
 *   KtjL 8 §, at least one logged contact attempt and a justification
 *   (AO-R4); NONOX never judges whether the price is "obetydligt" (L3);
 * - declined, or withdrawn by the company.
 *
 * Once decided the ÄTA never changes (AO-R25). An approved or performed
 * ÄTA is always e-mailed to the customer as a PDF, a durable copy either
 * way (AO-R10, L5). Approval by signature at the link or on site goes
 * through the shared signing domain (SendChangeOrderForSigning).
 */
class DecideChangeOrder
{
    use GuardsWorkOrders;

    /** @param  array<string, mixed>  $input  name, title (optional), channel, at (Y-m-d H:i) */
    public function approveOrally(User $user, Company $company, ChangeOrder $changeOrder, array $input): ChangeOrder
    {
        $this->guard($user, $company, 'send');

        $name = trim((string) ($input['name'] ?? ''));
        $channel = ContactChannel::tryFrom((string) ($input['channel'] ?? ''))
            ?? throw ValidationException::withMessages(['channel' => __('Välj hur kunden godkände.')]);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Skriv vem som godkände.')]);
        }

        $at = $this->moment($input['at'] ?? null, 'at');

        return $this->decide($user, $company, $changeOrder, ChangeOrderStatus::ApprovedOrally, [
            'approval_method' => 'ORAL',
            'approved_by_name' => mb_substr($name, 0, 255),
            'approved_by_title' => filled($input['title'] ?? null) ? mb_substr((string) $input['title'], 0, 255) : null,
            'oral_channel' => $channel->value,
            'decided_at' => $at,
        ], [ChangeOrderStatus::Draft, ChangeOrderStatus::Sent], sendCopy: true);
    }

    /** @param  array<string, mixed>  $input  reason, justification */
    public function performWithoutAnswer(User $user, Company $company, ChangeOrder $changeOrder, array $input): ChangeOrder
    {
        $this->guard($user, $company, 'send');

        $reason = PerformedReason::tryFrom((string) ($input['reason'] ?? ''))
            ?? throw ValidationException::withMessages(['reason' => __('Välj skälet enligt 8 § konsumenttjänstlagen.')]);
        $justification = trim((string) ($input['justification'] ?? ''));

        if ($justification === '') {
            throw ValidationException::withMessages(['justification' => __('Skriv varför arbetet utfördes utan kundens besked.')]);
        }

        $attempts = CurrentCompany::run($company, fn (): int => ContactAttempt::query()->where('change_order_id', $changeOrder->id)->count());

        if ($attempts === 0) {
            throw ValidationException::withMessages(['reason' => __('Registrera minst ett försök att nå kunden först, med tid och kanal.')]);
        }

        return $this->decide($user, $company, $changeOrder, ChangeOrderStatus::PerformedWithoutAnswer, [
            'performed_reason' => $reason->value,
            'justification' => mb_substr($justification, 0, 5000),
            'decided_at' => CarbonImmutable::now(),
        ], [ChangeOrderStatus::Draft, ChangeOrderStatus::Sent], sendCopy: true);
    }

    /** The customer said no, recorded by the company. */
    public function decline(User $user, Company $company, ChangeOrder $changeOrder, string $name = ''): ChangeOrder
    {
        $this->guard($user, $company, 'send');

        return $this->decide($user, $company, $changeOrder, ChangeOrderStatus::Declined, [
            'approval_method' => 'ORAL',
            'approved_by_name' => trim($name) === '' ? null : mb_substr(trim($name), 0, 255),
            'decided_at' => CarbonImmutable::now(),
        ], [ChangeOrderStatus::Draft, ChangeOrderStatus::Sent]);
    }

    /** The company takes the ÄTA back. */
    public function withdraw(User $user, Company $company, ChangeOrder $changeOrder): ChangeOrder
    {
        $this->guard($user, $company, 'send');

        return $this->decide($user, $company, $changeOrder, ChangeOrderStatus::Withdrawn, ['decided_at' => CarbonImmutable::now()], [ChangeOrderStatus::Draft, ChangeOrderStatus::Sent]);
    }

    /** @param  array<string, mixed>  $input  at (Y-m-d H:i), channel, note */
    public function recordAttempt(User $user, Company $company, ChangeOrder $changeOrder, array $input): ContactAttempt
    {
        $this->guard($user, $company, 'send');

        $channel = ContactChannel::tryFrom((string) ($input['channel'] ?? ''))
            ?? throw ValidationException::withMessages(['channel' => __('Välj hur du försökte nå kunden.')]);
        $at = $this->moment($input['at'] ?? null, 'at');

        if ($changeOrder->status->isDecided()) {
            throw ValidationException::withMessages(['change_order' => __('ÄTA:t är redan beslutat.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $changeOrder, $channel, $at, $input): ContactAttempt {
            $attempt = ContactAttempt::create([
                'change_order_id' => $changeOrder->id,
                'attempted_at' => $at,
                'channel' => $channel->value,
                'note' => filled($input['note'] ?? null) ? mb_substr((string) $input['note'], 0, 255) : null,
                'created_by' => $user->id,
            ]);

            Audit::record('work_orders.contact_attempt_recorded', $changeOrder, ['attempt_id' => $attempt->id, 'channel' => $channel->value], $company, $user);

            return $attempt;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<ChangeOrderStatus>  $from
     */
    private function decide(User $user, Company $company, ChangeOrder $changeOrder, ChangeOrderStatus $to, array $attributes, array $from, bool $sendCopy = false): ChangeOrder
    {
        $changeOrder = DB::transaction(fn (): ChangeOrder => CurrentCompany::run($company, function () use ($user, $company, $changeOrder, $to, $attributes, $from): ChangeOrder {
            $changeOrder = ChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($changeOrder->status, $from, true)) {
                throw ValidationException::withMessages(['change_order' => __('ÄTA:t är :status.', ['status' => mb_strtolower($changeOrder->status->label())])]);
            }

            // An approval without a price approves nothing (review P3-2): a fixed price needs amounts; löpande räkning uses the work order's rates.
            if (in_array($to, [ChangeOrderStatus::ApprovedOrally, ChangeOrderStatus::PerformedWithoutAnswer], true)
                && $changeOrder->price_form === PriceForm::Fixed && $changeOrder->netOre() <= 0) {
                throw ValidationException::withMessages(['change_order' => __('Sätt ett pris på ÄTA:t, eller välj löpande räkning, innan det registreras.')]);
            }

            if ($changeOrder->status === ChangeOrderStatus::Draft) {
                // The document the decision is about, fixed by its hash before the status changes.
                $changeOrder->forceFill(['document_sha256' => hash('sha256', ChangeOrderPdf::html($changeOrder))])->save();
            }

            $changeOrder->forceFill([...$attributes, 'status' => $to, 'decided_by' => $user->id]);

            // The PDF as it was decided is kept for an oral or performed-without-answer decision (review P2-2); a signed one keeps the signing request's.
            if (in_array($to, [ChangeOrderStatus::ApprovedOrally, ChangeOrderStatus::PerformedWithoutAnswer], true)) {
                $bytes = ChangeOrderPdf::render($changeOrder);
                $path = "arbetsorder/{$company->id}/{$changeOrder->work_order_id}/ata-{$changeOrder->number}-".bin2hex(random_bytes(6)).'.pdf';
                Storage::disk(WorkOrderPhoto::DISK)->put($path, $bytes);
                $changeOrder->forceFill(['decided_document_path' => $path, 'decided_document_sha256' => hash('sha256', $bytes)]);
            }

            $changeOrder->save();

            Audit::record('work_orders.change_order_decided', $changeOrder, [
                'work_order_id' => $changeOrder->work_order_id,
                'status' => $to->value,
                'method' => $changeOrder->approval_method,
                'reason' => $changeOrder->performed_reason?->value,
                'document_sha256' => $changeOrder->document_sha256,
            ], $company, $user);

            return $changeOrder;
        }));

        if ($sendCopy) {
            $this->sendCopy($company, $changeOrder);
        }

        return $changeOrder;
    }

    private function sendCopy(Company $company, ChangeOrder $changeOrder): void
    {
        $customer = CurrentCompany::run($company, fn (): ?Customer => Customer::query()->find((int) WorkOrder::query()->whereKey($changeOrder->work_order_id)->value('customer_id')));

        if ($customer instanceof Customer && filled($customer->email)) {
            Mail::to($customer->email)->send(new ChangeOrderMail($company, $changeOrder));
        }
    }

    private function moment(mixed $value, string $field): CarbonImmutable
    {
        if (blank($value)) {
            return CarbonImmutable::now();
        }

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $value) ?: null;

        if ($at === null || $at->isFuture()) {
            throw ValidationException::withMessages([$field => __('Skriv en tidpunkt som har varit, till exempel 2026-03-02 09:12.')]);
        }

        return $at;
    }
}
