<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Company;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A web form that creates leads (ADR-048), at /formular/{token} and
 * embeddable in an iframe. Name and e-mail are always there, with a line
 * saying how the data is used and a link to the company's privacy policy;
 * phone, company, organisation number and message are optional, and so is
 * an unticked box for newsletters (consent_text is its text). Changing
 * that text gives it a new version, and each submission keeps the text it
 * was given and whether the box was ticked.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $token
 * @property array<string, string> $fields field => off, optional or required
 * @property string $consent_text
 * @property int $consent_version
 * @property string $privacy_url
 * @property ?string $thank_you
 * @property ?list<string> $embed_origins the https origins that may frame the form
 * @property ?int $owner_id
 * @property ?string $tags
 * @property bool $is_active
 * @property int $submission_count
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class Form extends Model
{
    use BelongsToCompany;

    /** The optional fields; name, e-mail and consent are always on. */
    public const array OPTIONAL_FIELDS = ['phone', 'company', 'org_number', 'message'];

    public const array FIELD_STATES = ['off', 'optional', 'required'];

    protected $table = 'crm_forms';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'token', 'fields', 'consent_text', 'consent_version', 'privacy_url', 'thank_you', 'embed_origins', 'owner_id', 'tags', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'embed_origins' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The default text beside the optional newsletter box (second review round, ADR-048). */
    public static function defaultNewsletterText(Company $company): string
    {
        return __('Ja, jag vill få nyhetsbrev och erbjudanden från :company via e-post. Jag kan avregistrera mig när som helst via länken i varje utskick.', ['company' => $company->legal_name ?: $company->name]);
    }

    public function fieldState(string $field): string
    {
        $state = $this->fields[$field] ?? 'off';

        return in_array($state, self::FIELD_STATES, true) ? $state : 'off';
    }
}
