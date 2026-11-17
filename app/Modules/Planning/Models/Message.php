<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message to staff about the schedule (schemameddelande, ADR-037 gap 5):
 * written by a planner, addressed by a scope, read by the recipients frozen
 * when it was sent. Not the encrypted chat (ADR-045): it is kept, belongs to
 * one company and has no reply thread. The body is encrypted at rest, is
 * never written to the audit log and never leaves NONOX by e-mail. Pruned 12
 * months after date_to (planning:prune-messages).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $author_id
 * @property string $scope
 * @property ?string $shift_id
 * @property ?int $area_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property string $body
 * @property bool $requires_ack
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class Message extends Model
{
    use BelongsToCompany;

    /** One shift: the employee the published shift was shown to. */
    public const string SHIFT = 'SHIFT';

    /** One day: everyone with a published shift that day. */
    public const string DAY = 'DAY';

    /** A period, such as a week: everyone with a published shift in it. */
    public const string PERIOD = 'PERIOD';

    /** An area in a period: everyone with a published shift in the area. */
    public const string AREA = 'AREA';

    /** Everyone employed in the period, with or without shifts. */
    public const string ALL = 'ALL';

    public const array SCOPES = [self::SHIFT, self::DAY, self::PERIOD, self::AREA, self::ALL];

    public const int MAX_BODY = 2000;

    /** The longest period one message may concern. */
    public const int MAX_DAYS = 31;

    protected $table = 'planning_messages';

    /** @var list<string> */
    protected $fillable = ['company_id', 'author_id', 'scope', 'shift_id', 'area_id', 'date_from', 'date_to', 'body', 'requires_ack'];

    /** @var list<string> */
    protected $hidden = ['body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'area_id' => 'integer',
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'body' => 'encrypted',
            'requires_ack' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return HasMany<MessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            self::SHIFT => __('Ett pass'),
            self::DAY => __('En dag'),
            self::PERIOD => __('En period'),
            self::AREA => __('En avdelning'),
            default => __('Alla anställda'),
        };
    }
}
