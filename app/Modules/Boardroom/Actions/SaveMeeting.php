<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Domain\AgendaItemKind;
use App\Modules\Boardroom\Domain\AgmAgenda;
use App\Modules\Boardroom\Domain\MeetingKind;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Domain\ReportableKind;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans a meeting and keeps its agenda: a new annual general meeting gets
 * the agenda from AgmAgenda, pre-filled from the closing. The three items
 * an annual meeting must decide cannot be removed (ABL 7 kap. 11 §). Items
 * are numbered in order (7 kap. 24 §). Once a set of minutes is approved in
 * NONOX the meeting is locked; only the day a notification reached
 * Bolagsverket can still be entered.
 */
final class SaveMeeting
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Meeting $meeting = null): Meeting
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $input, $meeting): Meeting {
            if ($meeting !== null) {
                $this->ensureOpen($meeting);
            }

            $data = Validator::make($input, [
                'kind' => [$meeting === null ? 'required' : 'prohibited', Rule::enum(MeetingKind::class)],
                'title' => ['required', 'string', 'max:255'],
                'meeting_date' => ['required', 'date'],
                'start_time' => ['nullable', 'date_format:H:i'],
                'place' => ['nullable', 'string', 'max:255'],
                'digital' => ['nullable', 'boolean'],
                'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')->where('company_id', $company->id)],
                'notice_sent_on' => ['nullable', 'date'],
                'chair_name' => ['nullable', 'string', 'max:255'],
                'minute_taker_name' => ['nullable', 'string', 'max:255'],
                'adjusters' => ['nullable', 'array', 'max:5'],
                'adjusters.*' => ['nullable', 'string', 'max:255'],
                'attendees' => ['nullable', 'array', 'max:200'],
                'attendees.*.name' => ['required', 'string', 'max:255'],
                'attendees.*.role' => ['required', Rule::in(['shareholder', 'proxy', 'member', 'deputy', 'ceo', 'auditor', 'adviser', 'other'])],
                'attendees.*.shareholder_id' => ['nullable', 'integer', Rule::exists('boardroom_shareholders', 'id')->where('company_id', $company->id)],
            ], [], [
                'title' => __('Rubrik'),
                'meeting_date' => __('Datum'),
                'start_time' => __('Klockan'),
            ])->validate();

            return DB::transaction(function () use ($user, $company, $data, $meeting): Meeting {
                $created = $meeting === null;
                $kind = $created ? MeetingKind::from((string) $data['kind']) : $meeting->kind;
                $year = isset($data['fiscal_year_id']) ? FiscalYear::query()->find((int) $data['fiscal_year_id']) : null;
                $meeting ??= new Meeting(['kind' => $kind->value, 'created_by' => $user->id]);

                $meeting->fill([
                    'title' => trim((string) $data['title']),
                    'meeting_date' => CarbonImmutable::parse((string) $data['meeting_date'])->toDateString(),
                    'start_time' => $data['start_time'] ?? null,
                    'place' => isset($data['place']) ? trim((string) $data['place']) : null,
                    'digital' => (bool) ($data['digital'] ?? false),
                    'fiscal_year_id' => $year?->id,
                    'notice_sent_on' => isset($data['notice_sent_on']) && $data['notice_sent_on'] !== '' ? CarbonImmutable::parse((string) $data['notice_sent_on'])->toDateString() : null,
                    'chair_name' => self::text($data['chair_name'] ?? null),
                    'minute_taker_name' => self::text($data['minute_taker_name'] ?? null),
                    'adjusters' => array_values(array_filter(array_map(fn (mixed $name): ?string => self::text($name), (array) ($data['adjusters'] ?? [])))),
                    'attendees' => array_values(array_map(fn (array $attendee): array => [
                        'name' => trim((string) $attendee['name']),
                        'role' => (string) $attendee['role'],
                        'shareholder_id' => isset($attendee['shareholder_id']) ? (int) $attendee['shareholder_id'] : null,
                    ], (array) ($data['attendees'] ?? []))),
                ])->save();

                if ($created) {
                    foreach (AgmAgenda::for($kind, $year) as $position => $item) {
                        AgendaItem::query()->create([
                            'meeting_id' => $meeting->id,
                            'position' => $position + 1,
                            'kind' => $item['kind']->value,
                            'title' => $item['title'],
                            'proposal' => $item['proposal'],
                            'mandatory' => $item['mandatory'],
                        ]);
                    }
                }

                Audit::record($created ? 'boardroom.meeting_created' : 'boardroom.meeting_updated', $meeting, ['kind' => $kind->value, 'meeting_date' => $meeting->meeting_date->toDateString()], $company, $user);

                return $meeting;
            });
        });
    }

    /**
     * Adds an item or changes one; new items go before the closing item.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveItem(User $user, Company $company, Meeting $meeting, array $input, ?AgendaItem $item = null): AgendaItem
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $meeting, $input, $item): AgendaItem {
            $this->ensureOpen($meeting);

            $data = Validator::make($input, [
                'kind' => ['required', Rule::enum(AgendaItemKind::class)],
                'title' => ['required', 'string', 'max:255'],
                'proposal' => ['nullable', 'string', 'max:5000'],
                'decision' => ['nullable', 'string', 'max:5000'],
                'reportable' => ['nullable', Rule::enum(ReportableKind::class)],
            ], [], ['title' => __('Ärende')])->validate();

            $kind = AgendaItemKind::from((string) $data['kind']);

            if ($item !== null && $item->mandatory && $kind !== $item->kind) {
                throw ValidationException::withMessages(['kind' => __('Ärendet måste finnas på årsstämman och kan inte bytas.')]);
            }

            return DB::transaction(function () use ($user, $company, $meeting, $data, $kind, $item): AgendaItem {
                if ($item === null) {
                    $last = AgendaItem::query()->where('meeting_id', $meeting->id)->orderByDesc('position')->first();
                    $closing = $last !== null && $last->kind === AgendaItemKind::Procedure && str_contains(mb_strtolower($last->title), 'avslutande');
                    $position = $closing ? $last->position : (int) ($last->position ?? 0) + 1;

                    if ($closing) {
                        $last->forceFill(['position' => $last->position + 1])->save();
                    }

                    $item = new AgendaItem(['meeting_id' => $meeting->id, 'position' => $position, 'mandatory' => false]);
                }

                $item->fill([
                    'kind' => $kind->value,
                    'title' => trim((string) $data['title']),
                    'proposal' => self::text($data['proposal'] ?? null),
                    'decision' => self::text($data['decision'] ?? null),
                    'reportable' => isset($data['reportable']) && $data['reportable'] !== '' ? (string) $data['reportable'] : $kind->suggestedReport()?->value,
                ])->save();

                Audit::record('boardroom.agenda_saved', $meeting, ['item' => $item->id, 'kind' => $kind->value], $company, $user);

                return $item;
            });
        });
    }

    public function removeItem(User $user, Company $company, AgendaItem $item): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $item): void {
            /** @var Meeting $meeting */
            $meeting = Meeting::query()->findOrFail($item->meeting_id);
            $this->ensureOpen($meeting);

            if ($item->mandatory) {
                throw ValidationException::withMessages(['item' => __('Årsstämman måste besluta i det här ärendet (aktiebolagslagen 7 kap. 11 §). Det kan inte tas bort.')]);
            }

            DB::transaction(function () use ($item, $meeting): void {
                $item->delete();
                AgendaItem::query()->where('meeting_id', $meeting->id)->where('position', '>', $item->position)->decrement('position');
            });

            Audit::record('boardroom.agenda_removed', $meeting, ['item' => $item->id], $company, $user);
        });
    }

    /**
     * The day the notification of a decision came in to Bolagsverket, entered
     * by the user; also allowed after the minutes are approved. A board change
     * takes effect from that day (ABL 8 kap. 13 §).
     */
    public function recordReported(User $user, Company $company, AgendaItem $item, string $date): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $item, $date): void {
            $valid = Validator::make(['reported_on' => $date], ['reported_on' => ['required', 'date', 'before_or_equal:today']], [], ['reported_on' => __('Anmälan inkom till Bolagsverket')])->validate();

            if ($item->reportable === null) {
                throw ValidationException::withMessages(['reported_on' => __('Beslutet är inte markerat som anmälningspliktigt.')]);
            }

            $item->forceFill(['reported_on' => CarbonImmutable::parse((string) $valid['reported_on'])->toDateString()])->save();
            Audit::record('boardroom.reported', $item, ['reportable' => $item->reportable?->value, 'reported_on' => $item->reported_on?->toDateString()], $company, $user);
        });
    }

    public function delete(User $user, Company $company, Meeting $meeting): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $meeting): void {
            if (Minutes::query()->where('meeting_id', $meeting->id)->exists()) {
                throw ValidationException::withMessages(['meeting' => __('Mötet har ett protokoll och kan inte tas bort.')]);
            }

            DB::transaction(fn () => $meeting->delete());
            Audit::record('boardroom.meeting_deleted', $meeting, ['kind' => $meeting->kind->value], $company, $user);
        });
    }

    public static function isLocked(Meeting $meeting): bool
    {
        return Minutes::query()->where('meeting_id', $meeting->id)->where('status', MinutesStatus::Approved->value)->exists();
    }

    private function ensureOpen(Meeting $meeting): void
    {
        if (self::isLocked($meeting)) {
            throw ValidationException::withMessages(['meeting' => __('Protokollet är godkänt och mötet är låst. Rätta med ett nytt protokoll.')]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(BoardroomModule::KEY, $company);
        BoardroomAccess::authorize($user, $company, 'meetings:manage');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
