<?php

declare(strict_types=1);

namespace App\Modules\Signing\Models;

use App\Models\Company;
use App\Models\SigningField;
use App\Models\User;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A template for signing (ADR-046 amendment 2026-11-18, gap 8, package S14):
 * a PDF (type DOCUMENT) or a document type (QUOTE, AGREEMENT), with roles
 * instead of names, each role's step, check and fields, and defaults for the
 * last day, reminders, copy recipients, language and confidentiality.
 *
 * The file and the type never change (PostgreSQL trigger); the roles, the
 * fields and the defaults do. An envelope copies what it needs when it goes
 * out, so earlier envelopes are unaffected. A used template is archived,
 * never deleted.
 *
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property string $document_type DOCUMENT, QUOTE or AGREEMENT
 * @property ?string $path
 * @property ?string $file_name
 * @property ?string $sha256
 * @property ?int $bytes
 * @property ?int $page_count
 * @property ?int $deadline_days
 * @property bool $auto_reminders
 * @property ?int $reminder_days
 * @property string $language
 * @property bool $confidential
 * @property ?list<array{email: string, name: ?string, language: string}> $copies
 * @property ?int $created_by
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 */
class SigningTemplate extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string DOCUMENT = 'DOCUMENT';

    public const string QUOTE = 'QUOTE';

    public const string AGREEMENT = 'AGREEMENT';

    public const array TYPES = [self::DOCUMENT, self::QUOTE, self::AGREEMENT];

    /** At most this many active (not archived) templates per company. */
    public const int MAX_PER_COMPANY = 200;

    /** Times are written with their offset, like the rest of signing. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'name', 'document_type', 'path', 'file_name', 'sha256', 'bytes', 'page_count', 'deadline_days',
        'auto_reminders', 'reminder_days', 'language', 'confidential', 'copies', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            if ($template->isDirty(['company_id', 'document_type', 'path', 'file_name', 'sha256', 'bytes', 'page_count'])) {
                throw new LogicException('A signing template\'s file cannot be changed; save a new template.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'page_count' => 'integer',
            'deadline_days' => 'integer',
            'reminder_days' => 'integer',
            'auto_reminders' => 'boolean',
            'confidential' => 'boolean',
            'copies' => 'array',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * The templates a member may see and use: a confidential template (ADR-046 S7 rule, S14 review) only to its
     * creator and to signing:manage; every other template is shared with everyone who may send, and so is its PDF.
     *
     * @param  Builder<SigningTemplate>  $query
     * @return Builder<SigningTemplate>
     */
    public function scopeVisibleTo(Builder $query, User $user, Company $company): Builder
    {
        if ($user->hasTeamPermission($company, SigningModule::MANAGE)) {
            return $query;
        }

        return $query->where(fn (Builder $visible) => $visible->where('confidential', false)->orWhere('created_by', $user->id));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function hasFile(): bool
    {
        return $this->document_type === self::DOCUMENT;
    }

    /** @return HasMany<SigningTemplateRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(SigningTemplateRole::class, 'template_id')->orderBy('position');
    }

    /** @return HasMany<SigningField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SigningField::class, 'template_id')->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
