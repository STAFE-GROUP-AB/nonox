<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A link in a mailing (ADR-048). The mail carries a redirect address that
 * is the same for every recipient; the redirect always goes to this stored
 * URL, so the endpoint can never send anyone elsewhere. Only the number of
 * clicks is kept, never who clicked.
 *
 * @property int $id
 * @property int $company_id
 * @property int $mailing_id
 * @property string $url
 * @property int $position
 * @property int $clicks
 */
class MailingLink extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_mailing_links';

    /** @var list<string> */
    protected $fillable = ['company_id', 'mailing_id', 'url', 'position'];
}
