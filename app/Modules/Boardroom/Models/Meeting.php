<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Models\FiscalYear;
use App\Modules\Boardroom\Domain\MeetingKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A general meeting or a board meeting: when and where, the chair, the
 * minute-taker and the adjusters (justerare), who attended, and the agenda.
 * It can be changed until its minutes are approved in NONOX.
 *
 * @property int $id
 * @property int $company_id
 * @property MeetingKind $kind
 * @property string $title
 * @property CarbonImmutable $meeting_date
 * @property ?string $start_time
 * @property ?string $place
 * @property bool $digital
 * @property ?int $fiscal_year_id
 * @property ?CarbonImmutable $notice_sent_on
 * @property ?string $chair_name
 * @property ?string $minute_taker_name
 * @property ?list<string> $adjusters
 * @property ?list<array{name: string, role: string, shareholder_id?: ?int}> $attendees
 * @property ?int $created_by
 */
class Meeting extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_meetings';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'kind', 'title', 'meeting_date', 'start_time', 'place', 'digital', 'fiscal_year_id', 'notice_sent_on',
        'chair_name', 'minute_taker_name', 'adjusters', 'attendees', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => MeetingKind::class,
            'meeting_date' => 'immutable_date',
            'notice_sent_on' => 'immutable_date',
            'digital' => 'boolean',
            'adjusters' => 'array',
            'attendees' => 'array',
        ];
    }

    public const array ROLES = ['shareholder', 'proxy', 'member', 'deputy', 'ceo', 'auditor', 'adviser', 'other'];

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'shareholder' => __('aktieägare'),
            'proxy' => __('ombud'),
            'member' => __('styrelseledamot'),
            'deputy' => __('suppleant'),
            'ceo' => __('verkställande direktör'),
            'auditor' => __('revisor'),
            'adviser' => __('biträde'),
            default => __('övrig'),
        };
    }

    /** @return HasMany<AgendaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AgendaItem::class)->orderBy('position');
    }

    /** @return HasMany<Minutes, $this> */
    public function minutes(): HasMany
    {
        return $this->hasMany(Minutes::class)->orderBy('id');
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
