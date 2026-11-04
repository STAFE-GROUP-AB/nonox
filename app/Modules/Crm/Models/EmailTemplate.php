<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An e-mail template (ADR-048): a subject and a plain-text body with merge
 * fields such as {förnamn} and {säljare} (MergeFields).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $subject
 * @property string $body
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class EmailTemplate extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_email_templates';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'subject', 'body', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
