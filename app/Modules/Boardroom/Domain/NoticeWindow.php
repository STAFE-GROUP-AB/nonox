<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\Meeting;
use Carbon\CarbonImmutable;

/**
 * When the notice of a general meeting may be sent, from rules.json (rule
 * sheet AB-R20–AB-R22, AB-R26): an annual meeting and an extra meeting that
 * changes the articles six to four weeks before, down to two weeks when a
 * private company's articles allow it; another extra meeting six to two
 * weeks before. Counted in whole weeks back from the meeting day. Every
 * value is unverified, so the window is shown as advice with "kontrollera
 * mot aktiebolagslagen", never enforced.
 * TODO(legal-verify): L4 – how the weeks are counted and whether a weekend
 * moves the date.
 */
final readonly class NoticeWindow
{
    public function __construct(
        public CarbonImmutable $earliest,
        public CarbonImmutable $latest,
        public string $rule,
        public bool $verified,
        public string $source,
        public bool $postRequired,
        public ?string $method,
    ) {}

    /**
     * @param  iterable<AgendaItem>  $items
     */
    public static function for(Meeting $meeting, iterable $items, ?ArticlesVersion $articles): ?self
    {
        if (! $meeting->kind->isGeneralMeeting()) {
            return null;
        }

        $changesArticles = false;

        foreach ($items as $item) {
            if ($item->kind === AgendaItemKind::Articles) {
                $changesArticles = true;
            }
        }

        $rule = match (true) {
            $meeting->kind === MeetingKind::AnnualGeneralMeeting => 'notice_agm',
            $changesArticles => 'notice_extra_articles',
            default => 'notice_extra_other',
        };

        $values = BoardroomRules::rule($rule);
        $latestWeeks = BoardroomRules::int($rule, 'latest_weeks');

        if ($articles?->short_notice_clause === true && isset($values['articles_may_shorten_latest_to_weeks']) && is_int($values['articles_may_shorten_latest_to_weeks'])) {
            $latestWeeks = $values['articles_may_shorten_latest_to_weeks'];
        }

        return new self(
            $meeting->meeting_date->subWeeks(BoardroomRules::int($rule, 'earliest_weeks')),
            $meeting->meeting_date->subWeeks($latestWeeks),
            $rule,
            BoardroomRules::isVerified($rule),
            BoardroomRules::source($rule),
            // ABL 7 kap. 23 §: notice also by post for, among others, an articles change under 7 kap. 43–45 §§. TODO(legal-verify): which articles changes.
            $changesArticles,
            $articles?->notice_method,
        );
    }

    /** "för tidigt", "för sent" or null for a notice sent on the date. */
    public function judge(CarbonImmutable $sent): ?string
    {
        if ($sent->lessThan($this->earliest)) {
            return __('för tidigt');
        }

        if ($sent->greaterThan($this->latest)) {
            return __('för sent');
        }

        return null;
    }

    /** The last day a shareholder's request for an item must reach the board (ABL 7 kap. 16 §). */
    public function itemRequestDeadline(): CarbonImmutable
    {
        return $this->earliest->subWeeks(BoardroomRules::int('item_request_weeks_before_earliest_notice', 'value'));
    }
}
