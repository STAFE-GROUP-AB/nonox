<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tag on a company document (at most 40 characters), used in the search.
 *
 * @property int $id
 * @property int $company_id
 * @property int $document_id
 * @property string $tag
 */
class DocumentTag extends Model
{
    use BelongsToCompany;

    public const int MAX_LENGTH = 40;

    public const int MAX_PER_DOCUMENT = 10;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'document_id',
        'tag',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
