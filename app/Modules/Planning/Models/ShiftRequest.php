<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's request about a published shift: take an open shift
 * (CLAIM), swap one of their shifts with a colleague's (SWAP) or give one
 * up (DROP). A swap first asks the colleague (AWAITING_COUNTERPART); only
 * when they accept does it wait for a planner (PENDING), and a colleague's
 * no closes it (DECLINED). Nothing changes in the schedule until a planner
 * approves it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $kind
 * @property string $shift_id
 * @property int $requested_by
 * @property ?int $counterpart_employee_id
 * @property ?string $counterpart_shift_id
 * @property string $status
 * @property ?string $note
 * @property ?string $decision_note
 * @property ?int $created_by
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $counterpart_answered_at
 * @property ?int $counterpart_answered_by
 * @property CarbonImmutable $created_at
 * @property-read Shift $shift
 * @property-read ?Shift $counterpartShift
 * @property-read Employee $requester
 * @property-read ?Employee $counterpart
 */
class ShiftRequest extends Model
{
    use BelongsToCompany;

    public const string CLAIM = 'CLAIM';

    public const string SWAP = 'SWAP';

    public const string DROP = 'DROP';

    public const array KINDS = [self::CLAIM, self::SWAP, self::DROP];

    public const string AWAITING_COUNTERPART = 'AWAITING_COUNTERPART';

    public const string PENDING = 'PENDING';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    public const string DECLINED = 'DECLINED';

    public const string WITHDRAWN = 'WITHDRAWN';

    /** Still waiting for someone: the colleague or the planner. */
    public const array OPEN = [self::AWAITING_COUNTERPART, self::PENDING];

    protected $table = 'planning_requests';

    /** @var list<string> */
    protected $fillable = ['company_id', 'kind', 'shift_id', 'requested_by', 'counterpart_employee_id', 'counterpart_shift_id', 'status', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'counterpart_employee_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'immutable_datetime',
            'counterpart_answered_at' => 'immutable_datetime',
            'counterpart_answered_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function counterpartShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'counterpart_shift_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    /** @return BelongsTo<Employee, $this> */
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counterpart_employee_id');
    }

    /** Waiting for a planner's decision. */
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isAwaitingCounterpart(): bool
    {
        return $this->status === self::AWAITING_COUNTERPART;
    }

    /** Not answered yet, by the colleague or the planner; the requester can still withdraw it. */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::CLAIM => __('Vill ta passet'),
            self::SWAP => __('Vill byta pass'),
            default => __('Vill lämna passet'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::AWAITING_COUNTERPART => __('Väntar på kollegan'),
            self::PENDING => __('Väntar på svar'),
            self::APPROVED => __('Godkänd'),
            self::REJECTED => __('Inte godkänd'),
            self::DECLINED => __('Kollegan tackade nej'),
            default => __('Återkallad'),
        };
    }
}
