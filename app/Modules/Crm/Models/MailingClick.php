<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * That a recipient has clicked a link at least once (ADR-048), so a link's
 * unique clicks are counted once per recipient. Never shown per person;
 * goes with the recipient row when it is pruned.
 *
 * @property int $id
 * @property int $company_id
 * @property int $recipient_id
 * @property int $link_id
 */
class MailingClick extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_mailing_clicks';

    /** @var list<string> */
    protected $fillable = ['company_id', 'recipient_id', 'link_id'];
}
