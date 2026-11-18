<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentLinkType;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link from a company document to a record of the same company
 * (DocumentLinkType is the allow-list). Only ids are stored.
 *
 * @property int $id
 * @property int $company_id
 * @property int $document_id
 * @property DocumentLinkType $linkable_type
 * @property int $linkable_id
 * @property ?int $created_by
 */
class DocumentLink extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'document_id',
        'linkable_type',
        'linkable_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'linkable_type' => DocumentLinkType::class,
            'linkable_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
