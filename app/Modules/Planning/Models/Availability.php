<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When an employee can, cannot or would rather work: every week on a
 * weekday, or on one date, the whole day or between two wall-clock times.
 * The employee sets it in Min sida; the schedule shows it to the planner and
 * never blocks a plan with it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property ?CarbonImmutable $date
 * @property ?int $weekday
 * @property ?string $from
 * @property ?string $to
 * @property string $kind
 * @property ?string $note
 * @property ?int $created_by
 */
class Availability extends Model
{
    use BelongsToCompany;

    public const string AVAILABLE = 'AVAILABLE';

    public const string UNAVAILABLE = 'UNAVAILABLE';

    public const string PREFERRED = 'PREFERRED';

    public const array KINDS = [self::AVAILABLE, self::UNAVAILABLE, self::PREFERRED];

    protected $table = 'planning_availability';

    /** @var list<string> */
    protected $fillable = ['company_id', 'employee_id', 'date', 'weekday', 'from', 'to', 'kind', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'date' => 'immutable_date',
            'weekday' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isRecurring(): bool
    {
        return $this->weekday !== null;
    }

    public function isWholeDay(): bool
    {
        return $this->from === null;
    }

    /** Whether the row applies to the day: its date, or its weekday every week. */
    public function appliesTo(CarbonImmutable $day): bool
    {
        return $this->date !== null
            ? $this->date->toDateString() === $day->toDateString()
            : $this->weekday === $day->isoWeekday();
    }

    /** "Hela dagen" or "08:00–12:00". */
    public function timeLabel(): string
    {
        return $this->isWholeDay() ? __('Hela dagen') : "{$this->from}–{$this->to}";
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::AVAILABLE => __('Kan jobba'),
            self::UNAVAILABLE => __('Kan inte jobba'),
            default => __('Jobbar gärna'),
        };
    }
}
