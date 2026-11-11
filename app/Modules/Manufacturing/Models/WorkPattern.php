<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday of a person's working week in Tillverkning (ADR-071 §13.2),
 * used when Planering is off: start, end and a break, local time.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 * @property ?string $break_start
 * @property int $break_minutes
 */
class WorkPattern extends Model
{
    use BelongsToCompany;

    protected $table = 'production_work_patterns';

    /** @var list<string> */
    protected $fillable = ['company_id', 'employee_id', 'weekday', 'start_time', 'end_time', 'break_start', 'break_minutes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'break_minutes' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
