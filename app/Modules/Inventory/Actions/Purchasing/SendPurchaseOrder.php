<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\DraftEditors;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseOrderApprovalMode;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Mail\PurchaseOrderMail;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\Inventory\Purchasing\PurchaseOrderDocument;
use App\Modules\Inventory\Purchasing\PurchaseOrderReceipts;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft purchase order to the supplier (ADR-071 §2): by e-mail
 * through the app's mail, with the company as sender and reply-to, or
 * printed and marked as sent. The screen has shown the PDF and the
 * recipient before this runs. The version that went is kept as it was; the
 * order then waits for goods. Nothing is booked.
 *
 * Approval (ADR-081 E10): an order a policy of the company's own applies to
 * must have been approved through it for its current round; an order above the
 * company's amount (the default policy) is sent by someone with
 * purchase-orders:send, and not by its creator while the company requires
 * separate approval. The version that goes keeps who approved it.
 */
class SendPurchaseOrder
{
    public function email(User $user, Company $company, PurchaseOrder $order, string $email): PurchaseOrderVersion
    {
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();

        $version = $this->send($user, $company, $order, PurchaseOrder::EMAIL, $email);

        // A test company's documents never leave NONOX (ADR-032).
        if (! $company->isTestCompany()) {
            Mail::to($email)->send(new PurchaseOrderMail($company, $version));
        }

        return $version;
    }

    /** The order was printed (or sent some other way): kept as sent without a mail. */
    public function printed(User $user, Company $company, PurchaseOrder $order): PurchaseOrderVersion
    {
        return $this->send($user, $company, $order, PurchaseOrder::PRINT, null);
    }

    private function send(User $user, Company $company, PurchaseOrder $order, string $method, ?string $email): PurchaseOrderVersion
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $order, $method, $email): PurchaseOrderVersion {
            Gate::forUser($user)->authorize('send', [$order, $company]);

            return DB::transaction(function () use ($user, $company, $order, $method, $email): PurchaseOrderVersion {
                // Receipts first, then the order (PurchaseOrderReceipts).
                PurchaseOrderReceipts::lockOpen($order->id);
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $order->isDraft()) {
                    throw ValidationException::withMessages(['order' => __('Inköpsordern är redan skickad eller makulerad.')]);
                }

                [$requestId, $approvedBy, $approvedAt] = $this->approval($user, $company, $order);

                // Only an order that will really be sent uses the mail quota.
                if ($method === PurchaseOrder::EMAIL) {
                    MailQuota::take($company, 'documents');
                }

                $order->load('rows');

                if ($order->rows->isEmpty()) {
                    throw ValidationException::withMessages(['order' => __('Lägg till minst en rad.')]);
                }

                $order->supplier?->ensureNotRestricted('order');

                $now = CarbonImmutable::now();
                $snapshot = PurchaseOrderDocument::snapshot($order);

                $version = PurchaseOrderVersion::create([
                    'purchase_order_id' => $order->id,
                    'version' => $order->version,
                    'snapshot' => $snapshot,
                    'total_ore' => (int) $snapshot['total_ore'],
                    'method' => $method,
                    'sent_to' => $email,
                    'sent_by' => $user->id,
                    'sent_at' => $now,
                    'approval_request_id' => $requestId,
                    'approved_by' => $approvedBy,
                    'approved_at' => $approvedAt,
                ]);

                $order->forceFill(['sent_at' => $now, 'sent_to' => $email, 'sent_method' => $method, 'sent_by' => $user->id])->save();
                PurchaseOrderReceipts::sync($order);
                PurchaseOrderStatuses::refresh($order, afterSending: true);

                Audit::record('inventory.purchase_order_sent', $order, ['number' => $order->number, 'version' => $order->version, 'method' => $method], $company, $user);

                return $version;
            });
        });
    }

    /**
     * Who approved the order for the version that goes: a policy's last
     * decision, the sender of an order above the threshold, or nobody.
     *
     * @return array{0: ?int, 1: ?int, 2: ?CarbonImmutable}
     */
    private function approval(User $user, Company $company, PurchaseOrder $order): array
    {
        switch (PurchaseOrderApprovals::mode($order, $company)) {
            case PurchaseOrderApprovalMode::Policy:
                if (! $order->isApproved()) {
                    throw ValidationException::withMessages(['order' => __('Inköpsordern väntar på attest innan den kan skickas till leverantören.')]);
                }

                return [$order->approval_request_id, $order->approved_by, $order->approved_at === null ? null : CarbonImmutable::instance($order->approved_at)];
            case PurchaseOrderApprovalMode::Threshold:
                if (! $user->hasTeamPermission($company, InventoryModule::PURCHASE_SEND)) {
                    throw new AuthorizationException(__('Ordern är över företagets attestgräns. Någon med rätt att skicka inköpsorder behöver skicka den.'));
                }

                if ($company->separate_supplier_approval && in_array($user->id, [$order->created_by, $order->prepared_by, ...(DraftEditors::of($order->company_id, $order->getMorphClass(), [(string) $order->id], DraftEditors::PURCHASE_ORDER_ACTIONS)[(string) $order->id] ?? [])], true)) {
                    throw ValidationException::withMessages(['order' => __('Du skapade eller ändrade ordern och kan därför inte skicka den när den är över företagets attestgräns. Någon annan med rätt att skicka ordern behöver göra det. Är ni bara en person i företaget kan en ägare stänga av kravet under Leverantörsfakturor.')]);
                }

                return [null, $user->id, CarbonImmutable::now()];
            default:
                return [null, null, null];
        }
    }
}
