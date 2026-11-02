<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Events\InvoicePaid;
use App\Events\InvoiceSent;
use App\Events\SupplierInvoiceApproved;
use App\Events\VoucherPosted;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\ModuleManager;
use App\Modules\Planning\Events\PeriodAttested;
use App\Modules\Planning\Events\PeriodPublished;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

/**
 * Turns domain events into webhook deliveries (ADR-032). The events are
 * dispatched after the transaction that caused them commits, so nothing
 * rolled back is ever announced. For every active webhook of the company
 * that subscribes to the event, and whose creator still holds connect:manage
 * and the event's permission, one delivery is stored and queued with the
 * company id given explicitly (AGENTS.md §6.0). The event is built once and
 * every subscription gets the same body and the same Nonox-Event-Id.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function voucherPosted(VoucherPosted $event): void
    {
        $this->dispatch($event->companyId, 'voucher.posted', fn (): ?array => WebhookEvents::voucherPosted($event->voucherId));
    }

    public function invoiceSent(InvoiceSent $event): void
    {
        $this->dispatch($event->companyId, 'invoice.sent', fn (): ?array => WebhookEvents::invoiceSent($event->invoiceId));
    }

    public function invoicePaid(InvoicePaid $event): void
    {
        $this->dispatch($event->companyId, 'invoice.paid', fn (): ?array => WebhookEvents::invoicePaid($event->invoiceId, $event->paymentId));
    }

    public function supplierInvoiceApproved(SupplierInvoiceApproved $event): void
    {
        $this->dispatch($event->companyId, 'supplier_invoice.approved', fn (): ?array => WebhookEvents::supplierInvoiceApproved($event->supplierInvoiceId));
    }

    public function planningPublished(PeriodPublished $event): void
    {
        $this->dispatch($event->companyId, 'planning.published', fn (): ?array => WebhookEvents::planningPublished($event->publicationId));
    }

    public function planningAttested(PeriodAttested $event): void
    {
        $this->dispatch($event->companyId, 'planning.attested', fn (): array => WebhookEvents::planningAttested($event));
    }

    /**
     * @param  Closure(): ?array<string, mixed>  $data
     */
    private function dispatch(int $companyId, string $type, Closure $data): void
    {
        $company = Company::query()->find($companyId);

        if (! $company instanceof Company || ! $this->modules->isEnabled(ConnectModule::KEY, $company)) {
            return;
        }

        $queued = CurrentCompany::run($company, function () use ($company, $type, $data): array {
            $webhooks = ConnectWebhook::query()->active()->whereJsonContains('events', $type)->with('creator')->get()
                ->filter(fn (ConnectWebhook $webhook): bool => $this->creatorMaySend($webhook->creator, $company, $type));

            if ($webhooks->isEmpty()) {
                return [];
            }

            $payload = $data();

            if ($payload === null) {
                return [];
            }

            $eventId = (string) Str::uuid();
            $body = (string) json_encode([
                'id' => $eventId,
                'type' => $type,
                'created_at' => CarbonImmutable::now()->toAtomString(),
                'company_id' => $company->id,
                'data' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return $webhooks->map(fn (ConnectWebhook $webhook): string => ConnectWebhookDelivery::create([
                'webhook_id' => $webhook->id,
                'event_id' => $eventId,
                'event_type' => $type,
                'body' => $body,
                'status' => ConnectWebhookDelivery::PENDING,
                'next_attempt_at' => now(),
            ])->id)->values()->all();
        });

        foreach ($queued as $deliveryId) {
            DeliverWebhook::dispatch($deliveryId, $company->id);
        }
    }

    /**
     * The creator's own rights, now: still a member with connect:manage and
     * one of the event's permissions. Checked without any API credential of
     * the current request, which would otherwise narrow it (an event caused
     * through the API is still sent to every subscriber entitled to it).
     */
    private function creatorMaySend(?User $creator, Company $company, string $type): bool
    {
        if (! $creator instanceof User) {
            return false;
        }

        return ConnectAccess::without(function () use ($creator, $company, $type): bool {
            if (! $creator->belongsToTeam($company) || ! $creator->hasTeamPermission($company, 'connect:manage')) {
                return false;
            }

            foreach (WebhookEvents::permissions($type) as $permission) {
                if ($creator->hasTeamPermission($company, $permission)) {
                    return true;
                }
            }

            return false;
        });
    }
}
