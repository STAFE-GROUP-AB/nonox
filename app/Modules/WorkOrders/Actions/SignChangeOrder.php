<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Verification\SignerChecks;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SigningRequest;
use App\Models\SigningVerificationCode;
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
 * The code itself lives on the shared signer check service (ADR-046
 * amendment, gap 3, S3): a row in signing_verification_codes with the same
 * HMAC, its CODE_SENT, VERIFIED and VERIFY_FAILED events in the envelope's
 * hash chain. The on-site row stays the record of who presented the page on
 * which device and mirrors the attempts; rows from before S3 are checked
 * against their own code_hash, as before.
 *
 * The signing page records name, signature, IP and device, and the
 * document's SHA-256 (ADR-046); ChangeOrder::markSigned makes it APPROVED.
 */
class SignChangeOrder
{
    public function __construct(
        private readonly SendForSigning $send,
        private readonly SignerChecks $checks,
    ) {}

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
            $issued = $this->checks->issueOnSite($result['request'], OnSiteCode::MINUTES, OnSiteCode::MAX_ATTEMPTS);
            $code = $issued['code'];

            $onSite = OnSiteCode::create([
                'change_order_id' => $changeOrder->id,
                'signing_request_id' => $result['request']->id,
                'verification_code_id' => $issued['row']->id,
                'code_hash' => $issued['row']->code_mac,
                'url' => $result['url'],
                'expires_at' => $issued['row']->expires_at,
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
            // The document first, as every signing path (the chain's events come last, under the envelope's lock).
            $request = SigningRequest::query()->whereKey($onSite->signing_request_id)->first();
            $request?->document(lock: true);
            $locked = OnSiteCode::query()->whereKey($onSite->id)->lockForUpdate()->firstOrFail();

            if ($locked->presented_by !== $user->id) {
                abort(404);
            }

            if ($locked->verification_code_id !== null) {
                return $this->verifyThroughService($user, $company, $locked, $request, $code);
            }

            if ($locked->verified_at !== null || $locked->url === null || $locked->expires_at->isPast() || $locked->attempts >= OnSiteCode::MAX_ATTEMPTS) {
                // A link that can no longer be opened is not kept (review P3-1).
                $locked->forceFill(['url' => null])->save();

                return 'expired';
            }

            if (! hash_equals($locked->code_hash, self::codeHash($locked->signing_request_id, trim($code)))) {
                $attempts = $locked->attempts + 1;
                $locked->forceFill(['attempts' => $attempts, 'url' => $attempts >= OnSiteCode::MAX_ATTEMPTS ? null : $locked->url])->save();

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

    /** A code kept by the signer check service (ADR-046 S3): same answers, same limits, its events in the chain. */
    private function verifyThroughService(User $user, Company $company, OnSiteCode $locked, ?SigningRequest $request, string $code): string
    {
        $row = SigningVerificationCode::query()->whereKey($locked->verification_code_id)->lockForUpdate()->first();

        if ($locked->verified_at !== null || $locked->url === null || ! $row instanceof SigningVerificationCode || ! $request instanceof SigningRequest) {
            $locked->forceFill(['url' => null])->save();

            return 'expired';
        }

        $outcome = $this->checks->checkOnSite($row, $request, trim($code));

        if ($outcome === 'expired') {
            $locked->forceFill(['url' => null])->save();

            return 'expired';
        }

        if ($outcome === 'wrong') {
            $locked->forceFill(['attempts' => $row->attempts, 'url' => $row->attempts >= $row->max_attempts ? null : $locked->url])->save();

            return 'wrong';
        }

        $url = (string) $locked->url;
        $locked->forceFill(['verified_at' => $row->verified_at, 'url' => null])->save();
        Audit::record('work_orders.change_order_on_site_verified', $locked, ['change_order_id' => $locked->change_order_id, 'signing_request_id' => $locked->signing_request_id], $company, $user);

        return $url;
    }

    /** An HMAC keyed with the application key (review P3-1): a stolen table alone cannot be tried against all codes. The signer check service's MAC. */
    public static function codeHash(int $signingRequestId, string $code): string
    {
        return SignerChecks::mac($signingRequestId, $code);
    }
}
