<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\Workdays;
use App\Models\Company;
use App\Modules\Boardroom\Domain\BoardroomRules;
use App\Modules\Boardroom\Domain\ShareEventKind;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ShareEvent;
use Carbon\CarbonImmutable;

/**
 * The boardroom's dates in Mina datum, under Bolagsverket (ADR-070 §5):
 *
 * - a decision that must be reported for registration (a new board member,
 *   auditor or articles) until the user enters the day the notification
 *   reached Bolagsverket. The law says "genast" (ABL 8 kap. 44 §, 9 kap.
 *   48 §, 3 kap. 5 §); the date shown is an outer limit of 15 working days
 *   from the meeting, as advice. TODO(legal-verify): L6.
 * - a new issue not yet registered: reported within six months of the
 *   decision (13 kap. 27 §), or it lapses (13 kap. 30 §).
 *
 * The reminder links to Bolagsverket's own e-service; NONOX files nothing.
 * Two queries.
 */
final class BoardroomDeadlines implements DeadlineProvider
{
    /** TODO(legal-verify): L8 – the current e-service name and address; Bolagsverket's pages could not be read. */
    public const string E_SERVICE = 'https://bolagsverket.se/foretag/aktiebolag/forandraaktiebolag.html';

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $deadlines = [];
        $workdays = BoardroomRules::int('report_outer_limit_working_days', 'value');

        $items = AgendaItem::query()
            ->with('meeting:id,title,meeting_date')
            ->whereNotNull('reportable')
            ->whereNull('reported_on')
            ->get();

        foreach ($items as $item) {
            $meeting = $item->meeting;

            if ($meeting === null || $item->reportable === null) {
                continue;
            }

            $deadlines[] = new Deadline(
                'boardroom-report:'.$item->id,
                DeadlineType::CompanyRegistration,
                __('Anmäl till Bolagsverket: :what', ['what' => $item->reportable->label()]),
                self::addWorkdays($meeting->meeting_date, $workdays),
                $meeting->title,
                __('Beslutet på :meeting den :date ska anmälas genast. Datumet är en yttre gräns att kontrollera. :effect När anmälan har kommit in skriver du in datumet på mötet i Styrelserum.', [
                    'meeting' => $meeting->title,
                    'date' => $meeting->meeting_date->toDateString(),
                    'effect' => $item->reportable->effect(),
                ]),
                'boardroom.meetings',
                null,
                self::E_SERVICE,
                legalVerify: true,
            );
        }

        $months = BoardroomRules::int('new_issue_registration_months', 'value');
        $registered = ShareEvent::query()->whereIn('kind', [ShareEventKind::Registration->value, ShareEventKind::NewIssueLapsed->value, ShareEventKind::Correction->value])->pluck('refers_to_event_id')->all();

        foreach (ShareEvent::query()->where('kind', ShareEventKind::NewIssue->value)->whereNull('registered_on')->whereNotIn('id', $registered)->get() as $issue) {
            $decided = $issue->decided_on ?? $issue->effective_date;
            $deadlines[] = new Deadline(
                'boardroom-new-issue:'.$issue->id,
                DeadlineType::CompanyRegistration,
                __('Anmäl nyemissionen av aktie :range för registrering', ['range' => $issue->rangeLabel()]),
                $decided->addMonthsNoOverflow($months),
                __('Nyemission :date', ['date' => $decided->toDateString()]),
                __('Ett beslut om nyemission ska anmälas för registrering inom sex månader från beslutet, annars faller det och betalningarna ska betalas tillbaka (aktiebolagslagen 13 kap. 27 och 30 §§).'),
                'share-register.index',
                null,
                self::E_SERVICE,
                legalVerify: true,
            );
        }

        return $deadlines;
    }

    private static function addWorkdays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $day = $date;

        for ($added = 0; $added < $days;) {
            $day = $day->addDay();

            if (Workdays::isWorkday($day)) {
                $added++;
            }
        }

        return $day;
    }
}
