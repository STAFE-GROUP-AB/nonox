<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Modules\Boardroom\Domain\AgendaItemKind;
use App\Modules\Boardroom\Domain\ReportableKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A numbered item on a meeting's agenda (ABL 7 kap. 24 §), with the
 * proposal and the decision. A decision that must be reported to
 * Bolagsverket carries `reportable`, and `reported_on` is the day the
 * notification came in there, entered by the user; until then Mina datum
 * reminds.
 *
 * @property int $id
 * @property int $company_id
 * @property int $meeting_id
 * @property int $position
 * @property AgendaItemKind $kind
 * @property string $title
 * @property ?string $proposal
 * @property ?string $decision
 * @property bool $mandatory
 * @property ?ReportableKind $reportable
 * @property ?CarbonImmutable $reported_on
 */
class AgendaItem extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_agenda_items';

    /** @var list<string> */
    protected $fillable = ['company_id', 'meeting_id', 'position', 'kind', 'title', 'proposal', 'decision', 'mandatory', 'reportable', 'reported_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => AgendaItemKind::class,
            'mandatory' => 'boolean',
            'reportable' => ReportableKind::class,
            'reported_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
