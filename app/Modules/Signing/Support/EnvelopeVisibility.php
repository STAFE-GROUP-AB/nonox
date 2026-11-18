<?php

declare(strict_types=1);

namespace App\Modules\Signing\Support;

use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which envelopes a member sees in Signering (ADR-046 amendment, gap 10,
 * package S7): a standalone document's with signing:view, except a
 * confidential one, which only its sender and signing:manage see; a
 * quote's with invoices:view, an agreement's with crm:view (CRM on), an
 * ÄTA's with work-orders:view (Arbetsorder on). With the module off the
 * list is an archive: only signed standalone documents.
 *
 * The rule lives here once, as a query for the list and as a check for one
 * envelope, so the two never disagree. An envelope the member may not see
 * answers 404, as another company's does.
 */
final class EnvelopeVisibility
{
    /** The type's label for a list. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            SigningRequest::QUOTE => __('Offert'),
            'AGREEMENT' => __('Avtal'),
            ChangeOrder::SIGNING_TYPE => __('ÄTA'),
            SigningDocument::SIGNING_TYPE => __('Dokument'),
            default => $type,
        };
    }

    /**
     * The envelopes the member may see, in the current company (the company
     * scope applies); the filters of the list are added on top.
     *
     * @return Builder<SigningEnvelope>
     */
    public static function query(User $user, Company $company): Builder
    {
        $modules = app(ModuleManager::class);
        $on = $modules->isEnabled(SigningModule::KEY, $company);
        $query = SigningEnvelope::query();

        if (! $user->hasTeamPermission($company, SigningModule::VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        $manage = $user->hasTeamPermission($company, SigningModule::MANAGE);

        return $query->where(function (Builder $any) use ($user, $company, $modules, $on, $manage): void {
            $any->where(function (Builder $own) use ($user, $on, $manage): void {
                $own->where('document_type', SigningDocument::SIGNING_TYPE)
                    ->when(! $on, fn (Builder $query) => $query->where('status', 'COMPLETED'))
                    ->when(! $manage, fn (Builder $query) => $query->where(fn (Builder $mine) => $mine->where('confidential', false)->orWhere('created_by', $user->id)));
            });

            // The other types' envelopes are listed while the module is on; off, the list is only the archive of standalone documents.
            if (! $on) {
                return;
            }

            if ($user->hasTeamPermission($company, 'invoices:view')) {
                $any->orWhere('document_type', SigningRequest::QUOTE);
            }

            if ($modules->isEnabled(CrmModule::KEY, $company) && $user->hasTeamPermission($company, 'crm:view')) {
                $any->orWhere('document_type', 'AGREEMENT');
            }

            if ($modules->isEnabled(WorkOrdersModule::KEY, $company) && $user->hasTeamPermission($company, WorkOrdersModule::VIEW)) {
                $any->orWhere('document_type', ChangeOrder::SIGNING_TYPE);
            }
        });
    }

    /** Whether the member may see this envelope (the same rule as query()). */
    public static function allows(User $user, Company $company, SigningEnvelope $envelope): bool
    {
        return $envelope->company_id === $company->id
            && self::query($user, $company)->whereKey($envelope->id)->exists();
    }

    /** Whether the signing module is on for the company. */
    public static function moduleOn(Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company);
    }
}
