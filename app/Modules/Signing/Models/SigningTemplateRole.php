<?php

declare(strict_types=1);

namespace App\Modules\Signing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role of a signing template (ADR-046 S14): "Kund", "Säljare", "Vittne".
 * Its step and its check are copied to the signer who takes the role; the
 * company's own role (internal) is suggested a member when the template is
 * sent. The key ties the role's fields to it and stays when the role is
 * renamed.
 *
 * @property int $id
 * @property int $company_id
 * @property string $template_id
 * @property string $key
 * @property string $label
 * @property int $step
 * @property string $verification
 * @property bool $internal
 * @property int $position
 */
class SigningTemplateRole extends Model
{
    use BelongsToCompany;

    public const null CREATED_AT = null;

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'template_id', 'key', 'label', 'step', 'verification', 'internal', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['step' => 'integer', 'internal' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<SigningTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SigningTemplate::class, 'template_id');
    }
}
