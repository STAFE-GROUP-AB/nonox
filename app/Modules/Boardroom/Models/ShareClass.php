<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A share class from the articles of association: its code, the votes per
 * share (in tenths, so a B share with a tenth of a vote is 1) and any
 * restriction text (ABL 4 kap. 1–2 §§).
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property int $votes_tenths
 * @property ?string $restriction_text
 */
class ShareClass extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_share_classes';

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'name', 'votes_tenths', 'restriction_text'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['votes_tenths' => 'integer'];
    }

    /** "1" or "0,1". */
    public function votesLabel(): string
    {
        return $this->votes_tenths % 10 === 0
            ? (string) intdiv($this->votes_tenths, 10)
            : number_format($this->votes_tenths / 10, 1, ',', ' ');
    }
}
