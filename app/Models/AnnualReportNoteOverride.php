<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's own wording of a generated K3 note (ADR-059, A.2.7; spec S1
 * Behaviour 6). Only the text is replaced: the note's data, its number and
 * whether it is printed at all still come from the books. generated_hash is
 * the hash of the generated text when the override was written, so the
 * report can show that the books have changed since.
 *
 * @property int $id
 * @property int $company_id
 * @property int $annual_report_id
 * @property string $note_key
 * @property string $text
 * @property string $reason
 * @property string $generated_hash
 * @property int $set_by
 */
class AnnualReportNoteOverride extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'annual_report_id',
        'note_key',
        'text',
        'reason',
        'generated_hash',
        'set_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'annual_report_id' => 'integer',
            'set_by' => 'integer',
        ];
    }

    /** @return BelongsTo<AnnualReport, $this> */
    public function annualReport(): BelongsTo
    {
        return $this->belongsTo(AnnualReport::class);
    }
}
