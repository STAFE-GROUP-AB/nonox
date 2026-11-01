<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll file (löneunderlag) read into a payroll run. The original is
 * kept encrypted on the private disk; it holds personal numbers.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_run_id
 * @property int $payroll_import_source_id
 * @property string $period
 * @property string $format
 * @property ?string $format_version
 * @property string $original_name
 * @property string $file_path
 * @property string $sha256
 * @property int $size
 * @property array<string, mixed> $report
 * @property ?int $uploaded_by
 * @property ?CarbonInterface $created_at
 */
class PayrollFileImport extends Model
{
    use BelongsToCompany;

    public const string PAXML = 'PAXML';

    public const string CSV = 'CSV';

    protected $table = 'payroll_file_imports';

    /** @var list<string> */
    protected $fillable = ['company_id', 'payroll_run_id', 'payroll_import_source_id', 'period', 'format', 'format_version', 'original_name', 'file_path', 'sha256', 'size', 'report', 'uploaded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['report' => 'array', 'size' => 'integer'];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<PayrollImportSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PayrollImportSource::class, 'payroll_import_source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<PayrollFileImportLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollFileImportLine::class);
    }
}
