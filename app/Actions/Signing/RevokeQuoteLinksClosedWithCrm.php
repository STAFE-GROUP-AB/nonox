<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Models\CompanyModule;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Modules\Crm\CrmModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Once, when signing moved from CRM to core (ADR-075 §7): under ADR-046 a
 * quote's signing link stopped working while CRM was off. Moving signing to
 * core would make those links work again, so every pending, unexpired
 * quote link in a company where CRM is not on is revoked, as a revoke does
 * (status REVOKED, revoked_at, an event; nothing deleted), with an audit
 * entry of ids only. Idempotent: a revoked link is not pending.
 */
final class RevokeQuoteLinksClosedWithCrm
{
    /** The number of links revoked. */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        // Looks across every company on purpose: a one-off fix of links in companies without CRM; each link is then handled in its own company.
        $withCrm = CompanyScope::bypass(fn (): array => CompanyModule::query()->where('module_key', CrmModule::KEY)->where('enabled', true)->pluck('company_id')->map(fn (mixed $id): int => (int) $id)->all());
        $requests = CompanyScope::bypass(fn () => SigningRequest::query()
            ->where('document_type', SalesDocument::QUOTE)
            ->where('status', SigningStatus::Pending->value)
            ->where('expires_at', '>', $now)
            ->whereNotIn('company_id', $withCrm === [] ? [0] : $withCrm)
            ->get(['id', 'company_id']));

        foreach ($requests as $request) {
            CurrentCompany::run((int) $request->company_id, fn () => DB::transaction(function () use ($request, $now): void {
                $locked = SigningRequest::query()->whereKey($request->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== SigningStatus::Pending) {
                    return;
                }

                $locked->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => $now])->save();
                SigningEvent::create(['signing_request_id' => $locked->id, 'event' => SigningEventType::Revoked]);
                Audit::record('crm.signing_revoked', $locked, ['signing_request_id' => $locked->id, 'reason' => 'closed_without_crm'], $locked->company_id);
            }));
        }

        return $requests->count();
    }
}
