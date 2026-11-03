<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * "Nästa steg är obligatoriskt" (ADR-043 stage 2): an open deal always has
 * an open activity linked to it, so no deal is forgotten. A deal is created
 * with its next step (SaveDeal); the last open step can only be completed
 * together with a new one (CompleteActivity), or after the deal is won or
 * lost. A deal that ends up without one anyway (data from elsewhere, a
 * person's processing restricted) is listed in "Att göra" and flagged on
 * the board. Queries for the current company.
 */
final class DealNextStep
{
    public static function message(): string
    {
        return __('En öppen affär behöver ett nästa steg. Planera det, eller markera affären som vunnen eller förlorad.');
    }

    /** Whether $activity is the only open step of an open deal: completing it or moving it would leave the deal without one. */
    public static function isLastStep(Activity $activity): bool
    {
        if ($activity->deal_id === null || ! $activity->isOpen()) {
            return false;
        }

        $deal = Deal::query()->find($activity->deal_id);

        if (! $deal instanceof Deal || ! $deal->isOpen()) {
            return false;
        }

        return ! Activity::query()
            ->where('deal_id', $deal->id)
            ->whereNull('done_at')
            ->whereKeyNot($activity->id)
            ->exists();
    }

    /** @throws ValidationException when $activity is the last open step of an open deal */
    public static function guardLeaving(Activity $activity, string $key = 'next'): void
    {
        if (self::isLastStep($activity)) {
            throw ValidationException::withMessages([$key => self::message()]);
        }
    }

    /**
     * Open deals without any open activity.
     *
     * @return Builder<Deal>
     */
    public static function missing(): Builder
    {
        return Deal::query()
            ->where('status', DealStatus::Open->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('crm_activities')
                ->whereColumn('crm_activities.deal_id', 'crm_deals.id')
                ->whereNull('crm_activities.done_at'));
    }
}
