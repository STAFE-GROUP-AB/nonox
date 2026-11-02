<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Models;

use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fact NONOX AI read from a document and proposes to record (ADR-033
 * §6), with the quote and page it read it from. Nothing changes until a
 * person with agreements:manage accepts (as proposed or edited) or rejects
 * it.
 *
 * @property int $id
 * @property int $agreement_document_id
 * @property ?int $employee_id
 * @property ?int $employment_id
 * @property string $agent
 * @property string $field
 * @property mixed $value
 * @property ?string $quote
 * @property ?int $page
 * @property bool $quote_found
 * @property string $status
 * @property mixed $decided_value
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?Employee $employee
 * @property ?AgreementDocument $document
 */
class AgreementProposal extends Model
{
    use BelongsToCompany;

    public const string PENDING = 'PENDING';

    public const string ACCEPTED = 'ACCEPTED';

    public const string REJECTED = 'REJECTED';

    public const string IDENTIFICATION = 'IDENTIFICATION';

    public const string EMPLOYMENT_TERMS = 'EMPLOYMENT_TERMS';

    public const string LOCAL_AGREEMENT = 'LOCAL_AGREEMENT';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'agreement_document_id',
        'employee_id',
        'employment_id',
        'agent',
        'field',
        'value',
        'quote',
        'page',
        'quote_found',
        'status',
        'decided_value',
        'decided_by',
        'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'decided_value' => 'json',
            'quote' => 'encrypted',
            'page' => 'integer',
            'quote_found' => 'boolean',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employment, $this> */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    /** @return BelongsTo<AgreementDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(AgreementDocument::class, 'agreement_document_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
