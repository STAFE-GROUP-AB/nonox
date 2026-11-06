<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One of Skatteverket's customer events about the company (kundhändelse,
 * ADR-054 stage 4), as Skatteverket gave it to the person who fetched it:
 * heading, description and extended fields in full, whether the company
 * "has the ball" (requires_action) and whether Skatteverket considers the
 * matter closed. Only that person sees it (Allmänna villkor Kundhändelser 2,
 * 5.1 and 7.3); an event two people fetched is two rows. Links to another
 * host than skatteverket.se are kept as text and never made clickable.
 * Pruned two years after the event (privacy:prune).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $fetched_by
 * @property string $event_id
 * @property string $type
 * @property string $producer
 * @property ?CarbonImmutable $occurred_at
 * @property string $title
 * @property string $description
 * @property string $language
 * @property bool $requires_action
 * @property bool $case_closed
 * @property ?CarbonImmutable $due_date
 * @property ?list<array{url: string, text: ?string, skatteverket: bool}> $links
 * @property ?string $kopplings_id
 * @property ?string $skatteslag
 * @property ?list<string> $kundamne
 * @property ?string $korrespondens_referens
 * @property ?string $notice
 * @property CarbonImmutable $fetched_at
 * @property ?CarbonImmutable $handled_at
 * @property ?int $handled_by
 */
class SkatteverketEvent extends Model
{
    use BelongsToCompany;

    /** Skatteverket removes an event two years after it (Tjänstebeskrivning Kundhändelser 2.2.3); NONOX does the same. */
    public const int RETENTION_DAYS = 730;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fetched_by',
        'event_id',
        'type',
        'producer',
        'occurred_at',
        'title',
        'description',
        'language',
        'requires_action',
        'case_closed',
        'due_date',
        'links',
        'kopplings_id',
        'skatteslag',
        'kundamne',
        'korrespondens_referens',
        'notice',
        'fetched_at',
        'handled_at',
        'handled_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'requires_action' => 'boolean',
            'case_closed' => 'boolean',
            'due_date' => 'immutable_date',
            'links' => 'array',
            'kundamne' => 'array',
            'fetched_at' => 'immutable_datetime',
            'handled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function fetcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fetched_by');
    }

    /**
     * The events the person fetched; nobody else's.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFetchedBy(Builder $query, User $user): Builder
    {
        return $query->where('fetched_by', $user->id);
    }

    /**
     * Events where Skatteverket waits for the company: the event asks for
     * action, its matter is not closed, nobody has marked it handled, and no
     * later event of the person's has the same kopplingsId (the chain has
     * moved on, Tjänstebeskrivning 4.1.2.5).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingCompany(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.requires_action", true)
            ->where("{$table}.case_closed", false)
            ->whereNull("{$table}.handled_at")
            ->where(fn (Builder $query) => $query->whereNull("{$table}.kopplings_id")->orWhereNotExists(
                fn (QueryBuilder $later) => $later->selectRaw('1')->from("{$table} as later")
                    ->whereColumn('later.company_id', "{$table}.company_id")
                    ->whereColumn('later.fetched_by', "{$table}.fetched_by")
                    ->whereColumn('later.kopplings_id', "{$table}.kopplings_id")
                    ->where(fn (QueryBuilder $after) => $after->whereColumn('later.occurred_at', '>', "{$table}.occurred_at")
                        ->orWhere(fn (QueryBuilder $same) => $same->whereColumn('later.occurred_at', "{$table}.occurred_at")->whereColumn('later.id', '>', "{$table}.id"))),
            ));
    }
}
