<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Takes a price out of a list without losing the history (ADR-082 §11): a
 * price that has not started is deleted; one that is running ends yesterday;
 * one that already ended or started today is archived. "What did it cost on
 * 3 March?" stays answerable.
 */
class RetirePriceListItem
{
    public const string DELETED = 'deleted';

    public const string ENDED = 'ended';

    public const string ARCHIVED = 'archived';

    /** @return string DELETED, ENDED or ARCHIVED */
    public function __invoke(User $user, Company $company, PriceListItem $item): string
    {
        if ((int) $item->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        return DB::transaction(fn (): string => CurrentCompany::run($company, function () use ($company, $user, $item): string {
            Gate::forUser($user)->authorize('update', PriceList::query()->findOrFail($item->price_list_id));

            $today = CarbonImmutable::today();
            $payload = ['price_list_id' => $item->price_list_id, 'article_id' => $item->article_id];

            if ($item->archived_at !== null) {
                return self::ARCHIVED;
            }

            if ($item->valid_from !== null && $item->valid_from->greaterThan($today)) {
                Audit::record('price_list_item.deleted', $item, $payload, $company, $user);
                $item->delete();

                return self::DELETED;
            }

            $running = $item->valid_to === null || $item->valid_to->greaterThanOrEqualTo($today);

            if ($running && ($item->valid_from === null || $item->valid_from->lessThan($today))) {
                $item->update(['valid_to' => $today->subDay()->toDateString()]);
                Audit::record('price_list_item.ended', $item, $payload, $company, $user);

                return self::ENDED;
            }

            $item->update(['archived_at' => CarbonImmutable::now()]);
            Audit::record('price_list_item.archived', $item, $payload, $company, $user);

            return self::ARCHIVED;
        }));
    }
}
