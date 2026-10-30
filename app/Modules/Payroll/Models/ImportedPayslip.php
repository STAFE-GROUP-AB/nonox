<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payslip from the earlier payroll system, kept as the PDF it was.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $period
 * @property string $file_path
 * @property string $original_name
 * @property string $sha256
 * @property int $uploaded_by
 */
class ImportedPayslip extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'period',
        'file_path',
        'original_name',
        'sha256',
        'uploaded_by',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
