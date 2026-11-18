<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\DocumentRetention;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Documents\RetentionClass;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * A company document in the document archive (ADR-081 gap 9, E19): a file
 * that belongs to no feature, such as a contract, an insurance policy or a
 * permit, with its versions, tags and links. The retention class decides how
 * long it is kept; retain_until is computed and never moves earlier while it
 * is in force (also guarded by the documents trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property string $category a DocumentCategory key or the company's own
 * @property RetentionClass $retention_class
 * @property ?int $fiscal_year_id
 * @property ?CarbonImmutable $contract_ends_on
 * @property ?int $contract_retention_years
 * @property ?CarbonImmutable $keep_until
 * @property ?CarbonImmutable $retain_until
 * @property bool $legal_hold
 * @property ?LegalHoldReason $legal_hold_reason
 * @property bool $is_accounting_record contract that is räkenskapsinformation
 * @property ?string $title_encrypted
 * @property ?CarbonImmutable $legal_hold_at
 * @property ?int $legal_hold_by
 * @property bool $contains_personal_data
 * @property ?int $current_version_id
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Document extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        // The class comes before the title: a personnel document's title is stored encrypted.
        'retention_class',
        'title',
        'category',
        'is_accounting_record',
        'legal_hold_reason',
        'fiscal_year_id',
        'contract_ends_on',
        'contract_retention_years',
        'keep_until',
        'retain_until',
        'legal_hold',
        'legal_hold_at',
        'legal_hold_by',
        'contains_personal_data',
        'current_version_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retention_class' => RetentionClass::class,
            'contract_ends_on' => 'immutable_date',
            'contract_retention_years' => 'integer',
            'keep_until' => 'immutable_date',
            'retain_until' => 'immutable_date',
            'legal_hold' => 'boolean',
            'legal_hold_reason' => LegalHoldReason::class,
            'is_accounting_record' => 'boolean',
            'legal_hold_at' => 'immutable_datetime',
            'contains_personal_data' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Document $document): void {
            $floor = DocumentRetention::floor($document);

            if ($floor !== null && ($document->retain_until === null || $document->retain_until->lessThan($floor))) {
                throw new LogicException('The retention date of a document never goes below its rule.');
            }

            $originalClass = $document->getOriginal('retention_class');
            $oldFloor = DocumentRetention::accountingFloor(
                $originalClass instanceof RetentionClass ? $originalClass : RetentionClass::from((string) $originalClass),
                (bool) $document->getOriginal('is_accounting_record'),
                $document->getOriginal('fiscal_year_id') === null ? null : (int) $document->getOriginal('fiscal_year_id'),
            );
            $newFloor = DocumentRetention::accountingFloor($document->retention_class, $document->is_accounting_record, $document->fiscal_year_id);

            if (! DocumentRetention::keepsFloor($oldFloor, $newFloor, $document->retain_until)) {
                throw new LogicException('Räkenskapsinformation keeps its floor: no leaving the class, the mark or the fiscal year while it applies.');
            }
        });

        static::deleting(function (Document $document): void {
            if (DocumentRetention::blocker($document) !== null) {
                throw new LogicException('The document is kept: legal hold, retention period or a running contract.');
            }
        });
    }

    /**
     * A personnel document's title is stored encrypted (title_encrypted) and
     * the plain column is left empty, so a name or a health detail in the
     * title is not readable in the database. The class is set first.
     *
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): string => ($attributes['retention_class'] ?? null) === RetentionClass::Personnel->value && is_string($attributes['title_encrypted'] ?? null)
                ? Crypt::decryptString($attributes['title_encrypted'])
                : (string) $value,
            set: fn (string $value, array $attributes): array => ($attributes['retention_class'] ?? null) === RetentionClass::Personnel->value
                ? ['title' => '', 'title_encrypted' => Crypt::encryptString($value)]
                : ['title' => $value, 'title_encrypted' => null],
        );
    }

    /**
     * Only the documents the user may read: personnel documents need
     * payroll:view-sensitive (owner decision 21, DocumentAccess).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisibleTo(Builder $query, User $user, Company $company): Builder
    {
        $classes = DocumentAccess::classes($user, $company, 'documents:view');

        if (count($classes) === count(RetentionClass::cases())) {
            return $query;
        }

        return $query->whereIn('retention_class', array_map(fn (RetentionClass $class): string => $class->value, $classes));
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** @return HasMany<DocumentTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(DocumentTag::class);
    }

    /** @return HasMany<DocumentLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
