<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One version of the articles of association as structured data (rule
 * sheet G.11): how notice is given, whether a private company may give
 * notice down to two weeks before, limits for capital, shares and board,
 * whether there is an auditor, and the transfer clauses. A version is
 * applied only from its registration (ABL 3 kap. 5 §); until then the
 * earlier one rules. A version is never changed, except that the
 * registration date may be entered once.
 *
 * @property int $id
 * @property int $company_id
 * @property int $version
 * @property CarbonImmutable $adopted_on
 * @property ?CarbonImmutable $registered_on
 * @property ?string $seat
 * @property string $notice_method
 * @property bool $short_notice_clause
 * @property ?int $share_capital_min_ore
 * @property ?int $share_capital_max_ore
 * @property ?int $shares_min
 * @property ?int $shares_max
 * @property ?int $board_min
 * @property ?int $board_max
 * @property bool $has_auditor
 * @property bool $pre_emption_clause
 * @property bool $consent_clause
 * @property bool $redemption_clause
 * @property bool $conversion_clause
 * @property ?string $other_provisions
 * @property ?int $created_by
 */
class ArticlesVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_articles';

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'version', 'adopted_on', 'registered_on', 'seat', 'notice_method', 'short_notice_clause',
        'share_capital_min_ore', 'share_capital_max_ore', 'shares_min', 'shares_max', 'board_min', 'board_max',
        'has_auditor', 'pre_emption_clause', 'consent_clause', 'redemption_clause', 'conversion_clause', 'other_provisions', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'adopted_on' => 'immutable_date',
            'registered_on' => 'immutable_date',
            'short_notice_clause' => 'boolean',
            'share_capital_min_ore' => 'integer',
            'share_capital_max_ore' => 'integer',
            'shares_min' => 'integer',
            'shares_max' => 'integer',
            'board_min' => 'integer',
            'board_max' => 'integer',
            'has_auditor' => 'boolean',
            'pre_emption_clause' => 'boolean',
            'consent_clause' => 'boolean',
            'redemption_clause' => 'boolean',
            'conversion_clause' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $articles): void {
            $dirty = array_keys($articles->getDirty());
            $onlyRegistration = array_diff($dirty, ['registered_on', 'updated_at']) === [] && $articles->getOriginal('registered_on') === null;

            if (! $onlyRegistration) {
                throw new LogicException('A version of the articles cannot be changed; adopt a new version.');
            }
        });
    }

    /**
     * The transfer and conversion clauses (förbehåll) that apply to every
     * share and must show in the register (ABL 5 kap. 5 § 5): förköp (4 kap.
     * 18 §), samtycke (4 kap. 8 §), hembud (4 kap. 27 §) and omvandling
     * (4 kap. 6 §).
     *
     * @return list<string>
     */
    public function clauses(): array
    {
        return array_values(array_filter([
            $this->pre_emption_clause ? (string) __('förköpsförbehåll (aktiebolagslagen 4 kap. 18 §)') : null,
            $this->consent_clause ? (string) __('samtyckesförbehåll (aktiebolagslagen 4 kap. 8 §)') : null,
            $this->redemption_clause ? (string) __('hembudsförbehåll (aktiebolagslagen 4 kap. 27 §)') : null,
            $this->conversion_clause ? (string) __('omvandlingsförbehåll (aktiebolagslagen 4 kap. 6 §)') : null,
        ]));
    }

    /**
     * The version that applies on a date: the latest registered on or before
     * it, or the first version while none is registered yet (a company's
     * first articles are registered with the company itself).
     */
    public static function applicable(CarbonImmutable $date): ?self
    {
        return self::query()
            ->whereNotNull('registered_on')
            ->whereDate('registered_on', '<=', $date->toDateString())
            ->orderByDesc('registered_on')
            ->orderByDesc('version')
            ->first()
            ?? self::query()->orderBy('version')->first();
    }
}
