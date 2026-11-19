<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\Fields\FieldType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A field in the PDF that one signer fills in (ADR-046 amendment
 * 2026-11-18, gap 2, package S6): its page, its rectangle and the page's
 * size in 1/100 PDF points from the lower left corner, a label, whether it
 * is required, and its rules (text: max_length; number: min, max,
 * decimals). A dropdown has its options; a radio field is one button whose
 * single option is its value, and the buttons with the same group_key are
 * one choice. Placed before the envelope goes out and frozen after
 * (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property ?string $envelope_id null on a template's field (ADR-046 S14)
 * @property ?int $signing_request_id
 * @property ?string $template_id
 * @property ?string $role_key
 * @property FieldType $type
 * @property int $page
 * @property int $x
 * @property int $y
 * @property int $width
 * @property int $height
 * @property int $page_width
 * @property int $page_height
 * @property string $label
 * @property bool $required
 * @property ?list<string> $options
 * @property ?string $group_key
 * @property ?array<string, mixed> $rules
 * @property int $position
 * @property CarbonImmutable $created_at
 */
class SigningField extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    /** Times are written with their offset, like the rest of the signing evidence (ADR-046 S2). */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'envelope_id',
        'signing_request_id',
        'template_id',
        'role_key',
        'type',
        'page',
        'x',
        'y',
        'width',
        'height',
        'page_width',
        'page_height',
        'label',
        'required',
        'options',
        'group_key',
        'rules',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'page' => 'integer',
            'x' => 'integer',
            'y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'page_width' => 'integer',
            'page_height' => 'integer',
            'required' => 'boolean',
            'options' => 'array',
            'rules' => 'array',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('A signing field is frozen once placed (ADR-046 S6).'));
    }

    /** A whole-number rule of the field (max_length, min, max, decimals), or null. */
    public function rule(string $key): ?int
    {
        $value = $this->rules[$key] ?? null;

        return is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null);
    }

    /** The radio button's value (its single option). */
    public function radioValue(): string
    {
        return (string) ($this->options[0] ?? '');
    }

    /**
     * The rectangle as percentages of the page, for a box over the page image.
     *
     * @return array{left: float, top: float, width: float, height: float}
     */
    public function box(): array
    {
        return [
            'left' => round($this->x / $this->page_width * 100, 3),
            'top' => round(($this->page_height - $this->y - $this->height) / $this->page_height * 100, 3),
            'width' => round($this->width / $this->page_width * 100, 3),
            'height' => round($this->height / $this->page_height * 100, 3),
        ];
    }

    /** @return BelongsTo<SigningEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }
}
