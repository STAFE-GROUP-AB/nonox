<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Mail\PurchaseOrderMail;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Purchasing\PurchaseOrderDocument;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
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

            if ($method === PurchaseOrder::EMAIL) {
                MailQuota::take($company, 'documents');
            }

            return DB::transaction(function () use ($user, $company, $order, $method, $email): PurchaseOrderVersion {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $order->isDraft()) {
                    throw ValidationException::withMessages(['order' => __('Inköpsordern är redan skickad eller makulerad.')]);
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
                ]);

                $order->forceFill(['sent_at' => $now, 'sent_to' => $email, 'sent_method' => $method, 'sent_by' => $user->id])->save();
                PurchaseOrderStatuses::refresh($order, afterSending: true);

                Audit::record('inventory.purchase_order_sent', $order, ['number' => $order->number, 'version' => $order->version, 'method' => $method], $company, $user);

                return $version;
            });
        });
    }
}
