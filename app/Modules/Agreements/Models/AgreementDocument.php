<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document NONOX AI may read to propose the agreement setup (ADR-033 §6):
 * one of the company's (a hängavtal, a membership confirmation, a payroll
 * report) or one employee's (an employment contract, a payslip). Kept on
 * the private disk.
 *
 * @property int $id
 * @property ?int $employee_id
 * @property string $document_type
 * @property string $original_name
 * @property string $file_path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int $uploaded_by
 * @property ?CarbonImmutable $analyzed_at
 * @property ?Employee $employee
 */
class AgreementDocument extends Model
{
    use BelongsToCompany;

    /** Company documents: what the company is bound by. */
    public const array COMPANY_TYPES = ['HANGAVTAL', 'MEMBERSHIP', 'PAYROLL_REPORT', 'OTHER'];

    /** An employee's documents: the terms of one employment. */
    public const array EMPLOYEE_TYPES = ['EMPLOYMENT_CONTRACT', 'PAYSLIP'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'document_type',
        'original_name',
        'file_path',
        'mime',
        'size',
        'sha256',
        'uploaded_by',
        'analyzed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'original_name' => 'encrypted',
            'size' => 'integer',
            'analyzed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<AgreementProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(AgreementProposal::class);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'HANGAVTAL' => __('Hängavtal'),
            'MEMBERSHIP' => __('Medlemsbekräftelse från arbetsgivarorganisation'),
            'PAYROLL_REPORT' => __('Lönebesked eller lönerapport'),
            'EMPLOYMENT_CONTRACT' => __('Anställningsavtal'),
            'PAYSLIP' => __('Lönebesked'),
            default => __('Annat dokument'),
        };
    }
}
