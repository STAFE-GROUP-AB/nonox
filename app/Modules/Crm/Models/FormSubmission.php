<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof of a consent given through a web form (GDPR art. 7.1, ADR-048):
 * the text and version shown, the privacy policy link and when. The person
 * and the account it created are linked while they exist.
 *
 * A submission that came in while the form's or the company's hourly cap
 * was reached is held (quarantined_at): it has no person or account yet, and
 * what was typed is kept encrypted in held_payload until someone accepts or
 * deletes it, or for at most 14 days (crm:prune-mail).
 *
 * @property int $id
 * @property int $company_id
 * @property int $form_id
 * @property ?int $account_id
 * @property ?int $person_id
 * @property string $consent_text
 * @property int $consent_version
 * @property string $privacy_url
 * @property CarbonImmutable $consented_at
 * @property bool $marketing_consent the newsletter box was ticked
 * @property ?CarbonImmutable $quarantined_at
 * @property ?array{data: array<string, ?string>, org_number: ?string, newsletter: bool} $held_payload
 * @property-read ?Form $form
 */
class FormSubmission extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_form_submissions';

    /** @var list<string> */
    protected $fillable = ['company_id', 'form_id', 'account_id', 'person_id', 'consent_text', 'consent_version', 'privacy_url', 'consented_at', 'marketing_consent', 'quarantined_at', 'held_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consented_at' => 'immutable_datetime',
            'marketing_consent' => 'boolean',
            'quarantined_at' => 'immutable_datetime',
            'held_payload' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->whereNotNull('quarantined_at');
    }
}
