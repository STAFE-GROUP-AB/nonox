<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An absence. Sensitive: only payroll:view-sensitive may read the type, and every read is audit-logged (B.15).
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
        ];
    }
}
