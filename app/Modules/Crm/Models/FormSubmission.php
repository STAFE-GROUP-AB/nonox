<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Proof of a consent given through a web form (GDPR art. 7.1, ADR-048):
 * the text and version shown, the privacy policy link and when. The person
 * and the account it created are linked while they exist.
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
 */
class FormSubmission extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_form_submissions';

    /** @var list<string> */
    protected $fillable = ['company_id', 'form_id', 'account_id', 'person_id', 'consent_text', 'consent_version', 'privacy_url', 'consented_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consented_at' => 'immutable_datetime',
        ];
    }
}
