<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Actions\Signing\SendForSigning;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Mail\OnSiteCodeMail;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\OnSiteCode;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Approval of an ÄTA by the customer's signature (ADR-074 §4, O4), through
 * the shared signing domain (ADR-046, ADR-075 §7):
 *
 * - send(): the office sends the priced ÄTA; it becomes SENT (content then
 *   fixed by trigger) and the customer gets the signing link by e-mail.
 * - onSite(): on the field worker's phone. A new link is made for the same
 *   ÄTA and a six-digit code goes to the customer's own e-mail; the worker
 *   never approves on the customer's behalf.
 * - verifyOnSite(): the customer types the code on the phone; only then is
 *   the signing page opened there. Five tries, fifteen minutes.
 *
 * The signing page records name, signature, IP and device, and the
 * document's SHA-256 (ADR-046); ChangeOrder::markSigned makes it APPROVED.
 */
class SignChangeOrder
{
    public function __construct(private readonly SendForSigning $send) {}

    /**
     * @param  array<string, mixed>  $input  email (default: the customer's), expires_on
     * @return array{request: SigningRequest, url: string}
     */
    public function send(User $user, Company $company, ChangeOrder $changeOrder, array $input = []): array
    {
        app(ModuleManager::class)->ensureEnabled(WorkOrdersModule::KEY, $company);
        Gate::forUser($user)->authorize('send', [WorkOrder::class, $company]);

        return DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $changeOrder, $input): array {
            $changeOrder = ChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->firstOrFail();
            $workOrder = WorkOrder::query()->findOrFail($changeOrder->work_order_id);

            if (! $workOrder->status->isOpen()) {
                throw ValidationException::withMessages(['change_order' => __('Arbetsordern är avslutad.')]);
            }

            if ($changeOrder->status === ChangeOrderStatus::Draft) {
                if ($changeOrder->netOre() <= 0) {
                    throw ValidationException::withMessages(['change_order' => __('Sätt ett pris på ÄTA:t innan det skickas till kunden.')]);
                }

                $changeOrder->forceFill([
                    'status' => ChangeOrderStatus::Sent,
                    'sent_at' => CarbonImmutable::now(),
                    'expires_on' => $input['expires_on'] ?? $changeOrder->expires_on,
                    'document_sha256' => $changeOrder->signingFingerprint(),
                ])->save();
            } elseif ($changeOrder->status !== ChangeOrderStatus::Sent) {
                throw ValidationException::withMessages(['change_order' => __('ÄTA:t är :status.', ['status' => mb_strtolower($changeOrder->status->label())])]);
            }

            $email = trim((string) ($input['email'] ?? '')) ?: (string) Customer::query()->whereKey($workOrder->customer_id)->value('email');

            $result = ($this->send)($user, $company, $changeOrder, ['email' => $email, 'expires_on' => $changeOrder->expires_on?->toDateString()]);

            Audit::record('work_orders.change_order_sent', $changeOrder, ['work_order_id' => $workOrder->id, 'signing_request_id' => $result['request']->id], $company, $user);

            return $result;
        }));
    }

    /** A new link for the same ÄTA and a code to the customer's own e-mail; the page opens on the phone after the code. */
    public function onSite(User $user, Company $company, ChangeOrder $changeOrder, ?string $userAgent = null): OnSiteCode
    {
        app(ModuleManager::class)->ensureEnabled(WorkOrdersModule::KEY, $company);

        return DB::transaction(fn (): OnSiteCode => CurrentCompany::run($company, function () use ($user, $company, $changeOrder, $userAgent): OnSiteCode {
            $changeOrder = ChangeOrder::query()->whereKey($changeOrder->id)->firstOrFail();

            if ($changeOrder->status !== ChangeOrderStatus::Sent) {
                throw ValidationException::withMessages(['change_order' => __('Kontoret behöver prissätta och skicka ÄTA:t innan kunden kan signera på plats.')]);
            }

            $workOrder = WorkOrder::query()->findOrFail($changeOrder->work_order_id);
            $email = (string) Customer::query()->whereKey($workOrder->customer_id)->value('email');

            if ($email === '') {
                throw ValidationException::withMessages(['change_order' => __('Kunden har ingen e-postadress. Koden skickas till kundens egen e-post, så lägg till den i kundregistret först.')]);
            }

            $result = ($this->send)($user, $company, $changeOrder, ['email' => $email, 'expires_on' => $changeOrder->expires_on?->toDateString()]);
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            $onSite = OnSiteCode::create([
                'change_order_id' => $changeOrder->id,
                'signing_request_id' => $result['request']->id,
                'code_hash' => hash('sha256', "{$result['request']->id}|{$code}"),
                'url' => $result['url'],
                'expires_at' => CarbonImmutable::now()->addMinutes(OnSiteCode::MINUTES),
                'presented_by' => $user->id,
                'presented_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ]);

            Mail::to($email)->send(new OnSiteCodeMail($company, $changeOrder, $code));
            Audit::record('work_orders.change_order_on_site_started', $changeOrder, ['work_order_id' => $workOrder->id, 'signing_request_id' => $result['request']->id, 'on_site_code_id' => $onSite->id], $company, $user);

            return $onSite;
        }));
    }

    /** The signing link, once the customer has typed the right code; then it is forgotten. */
    public function verifyOnSite(User $user, Company $company, OnSiteCode $onSite, string $code): string
    {
        // A wrong code is counted in its own committed transaction, so the limit holds; the error is thrown after.
        $result = DB::transaction(fn (): string => CurrentCompany::run($company, function () use ($user, $company, $onSite, $code): string {
            $locked = OnSiteCode::query()->whereKey($onSite->id)->lockForUpdate()->firstOrFail();

            if ($locked->presented_by !== $user->id) {
                abort(404);
            }

            if ($locked->verified_at !== null || $locked->url === null || $locked->expires_at->isPast() || $locked->attempts >= OnSiteCode::MAX_ATTEMPTS) {
                return 'expired';
            }

            if (! hash_equals($locked->code_hash, hash('sha256', $locked->signing_request_id.'|'.trim($code)))) {
                $locked->forceFill(['attempts' => $locked->attempts + 1])->save();

                return 'wrong';
            }

            $url = (string) $locked->url;
            $locked->forceFill(['verified_at' => CarbonImmutable::now(), 'url' => null])->save();
            Audit::record('work_orders.change_order_on_site_verified', $locked, ['change_order_id' => $locked->change_order_id, 'signing_request_id' => $locked->signing_request_id], $company, $user);

            return $url;
        }));

        return match ($result) {
            'expired' => throw ValidationException::withMessages(['code' => __('Koden gäller inte längre. Skicka en ny kod till kunden.')]),
            'wrong' => throw ValidationException::withMessages(['code' => __('Fel kod. Be kunden kontrollera koden i sin e-post.')]),
            default => $result,
        };
    }
}
