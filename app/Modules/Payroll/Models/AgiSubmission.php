<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated arbetsgivardeklaration for one payment month (PAYROLL_MODULE.md
 * B.13). `individuals` holds the reported amounts per employee id in kronor.
 *
 * @property int $id
 * @property int $company_id
 * @property string $period
 * @property int $sequence
 * @property string $status
 * @property string $xml_path
 * @property string $sha256
 * @property int $tax_ore
 * @property int $contributions_ore
 * @property array<int|string, array<string, int|bool|string>> $individuals
 * @property int $removals
 * @property ?string $receipt_reference
 * @property int $generated_by
 * @property ?int $submitted_by
 * @property ?CarbonImmutable $submitted_at
 * @property ?CarbonImmutable $decided_at
 * @property CarbonImmutable $created_at
 */
class AgiSubmission extends Model
{
    use BelongsToCompany;

    public const string GENERATED = 'GENERATED';

    public const string SUBMITTED = 'SUBMITTED';

    public const string ACCEPTED = 'ACCEPTED';

    public const string REJECTED = 'REJECTED';

    public const string CORRECTED = 'CORRECTED';

    /** Statuses whose amounts Skatteverket has, so a later file corrects them. */
    public const array REPORTED = [self::SUBMITTED, self::ACCEPTED];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period',
        'sequence',
        'xml_path',
        'sha256',
        'tax_ore',
        'contributions_ore',
        'individuals',
        'removals',
        'generated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::GENERATED,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'tax_ore' => 'integer',
            'contributions_ore' => 'integer',
            'individuals' => 'array',
            'removals' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function filename(): string
    {
        return 'Arbetsgivardeklaration-'.$this->period.($this->sequence > 1 ? "-rattelse-{$this->sequence}" : '').'.xml';
    }
}
