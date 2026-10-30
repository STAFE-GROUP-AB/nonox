<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An absence. Sensitive: only payroll:view-sensitive may read the type, and every read is audit-logged (B.15).
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $type
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property ?string $hours
 * @property string $status
 * @property ?string $note
 * @property ?int $requested_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $rejection_reason
 */
class Absence extends Model
{
    use BelongsToCompany;

    protected $table = 'absences';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'date_from',
        'date_to',
        'extent_bp',
        'hours',
        'doctor_certificate_received',
        'related_absence_id',
        'note',
        'status',
        'approved_by',
        'requested_by',
        'decided_at',
        'rejection_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'extent_bp' => 'integer',
            'doctor_certificate_received' => 'boolean',
            'note' => 'encrypted',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
