<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable quote (ADR-046): rows from the article register or free rows,
 * an introduction, terms and how many days a quote made from it is valid.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property ?string $intro_text
 * @property ?string $terms_text
 * @property int $validity_days
 * @property ?CarbonImmutable $archived_at
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class QuoteTemplate extends Model
{
    use BelongsToCompany;

    public const int MAX_ROWS = 100;

    protected $table = 'crm_quote_templates';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'intro_text',
        'terms_text',
        'validity_days',
        'archived_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'validity_days' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return HasMany<QuoteTemplateRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(QuoteTemplateRow::class, 'template_id')->orderBy('position')->orderBy('id');
    }
}
