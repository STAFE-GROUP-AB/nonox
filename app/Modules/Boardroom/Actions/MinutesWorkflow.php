<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Domain\MeetingKind;
use App\Modules\Boardroom\Domain\MinutesChecking;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\Boardroom\Support\BoardroomPdf;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Minutes from draft to "Godkänt i NONOX" (ADR-070 §4 and addendum):
 *
 * 1. A draft copies the meeting and its agenda; the decisions are written in it.
 * 2. Justerat: the chair and the adjusters the law asks for are named
 *    (MinutesChecking). NONOX records who marked it and when.
 * 3. Godkänt i NONOX: who approved and when. The body is frozen, a PDF is
 *    written and its SHA-256 kept, board minutes get the next number
 *    (8 kap. 26 §), and the row is locked. It is not a signature, and the
 *    PDF says so. TODO(legal-verify): L2 – an advanced electronic signature
 *    (ABL 1 kap. 13 §) is a later step.
 *
 * A mistake in approved minutes is corrected with new minutes that name
 * them; the original stays.
 */
final class MinutesWorkflow
{
    public function start(User $user, Company $company, Meeting $meeting, ?Minutes $corrects = null): Minutes
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $meeting, $corrects): Minutes {
            if (Minutes::query()->where('meeting_id', $meeting->id)->where('status', '!=', MinutesStatus::Approved->value)->exists()) {
                throw ValidationException::withMessages(['minutes' => __('Mötet har redan ett protokoll som inte är godkänt.')]);
            }

            if ($corrects === null && SaveMeeting::isLocked($meeting)) {
                throw ValidationException::withMessages(['minutes' => __('Protokollet är redan godkänt. Rätta det med ett nytt protokoll.')]);
            }

            if ($corrects !== null && $corrects->meeting_id !== $meeting->id) {
                throw ValidationException::withMessages(['minutes' => __('Rättelsen måste gälla ett protokoll från samma möte.')]);
            }

            if ($corrects !== null && ! $corrects->isApproved()) {
                throw ValidationException::withMessages(['minutes' => __('Bara ett godkänt protokoll rättas med ett nytt.')]);
            }

            $minutes = Minutes::query()->create([
                'meeting_id' => $meeting->id,
                'status' => MinutesStatus::Draft->value,
                'body' => $corrects->body ?? self::body($meeting),
                'corrects_minutes_id' => $corrects?->id,
                'created_by' => $user->id,
            ]);

            Audit::record($corrects === null ? 'boardroom.minutes_started' : 'boardroom.minutes_correction_started', $minutes, ['meeting' => $meeting->id, 'corrects' => $corrects?->id], $company, $user);

            return $minutes;
        });
    }

    /**
     * Writes the decisions, dissents and notes into a draft.
     *
     * @param  array{decisions?: array<int|string, mixed>, dissents?: array<int|string, mixed>, notes?: ?string}  $input
     */
    public function update(User $user, Company $company, Minutes $minutes, array $input): Minutes
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $minutes, $input): Minutes {
            $this->ensureDraft($minutes);

            Validator::make($input, [
                'decisions' => ['nullable', 'array'],
                'decisions.*' => ['nullable', 'string', 'max:5000'],
                'dissents' => ['nullable', 'array'],
                'dissents.*' => ['nullable', 'string', 'max:2000'],
                'notes' => ['nullable', 'string', 'max:10000'],
            ])->validate();

            $body = $minutes->body;
            $decisions = (array) ($input['decisions'] ?? []);
            $dissents = (array) ($input['dissents'] ?? []);

            foreach ($body['items'] as $index => $item) {
                $position = $item['position'];
                $body['items'][$index]['decision'] = self::text($decisions[$position] ?? $item['decision'] ?? null);
                $body['items'][$index]['dissent'] = self::text($dissents[$position] ?? $item['dissent'] ?? null);
            }

            $body['notes'] = self::text($input['notes'] ?? $body['notes'] ?? null);

            // A draft that was checked must be checked again after a change.
            $minutes->forceFill(['body' => $body, 'status' => MinutesStatus::Draft->value, 'checked_names' => null, 'checked_by' => null, 'checked_at' => null])->save();
            Audit::record('boardroom.minutes_updated', $minutes, ['meeting' => $minutes->meeting_id], $company, $user);

            return $minutes;
        });
    }

    /**
     * Marks the minutes justerat with the names of the chair and the adjusters.
     *
     * @param  list<string>  $adjusters
     */
    public function check(User $user, Company $company, Minutes $minutes, string $chair, string $minuteTaker, array $adjusters): Minutes
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $minutes, $chair, $minuteTaker, $adjusters): Minutes {
            $this->ensureDraft($minutes);
            /** @var Meeting $meeting */
            $meeting = Meeting::query()->findOrFail($minutes->meeting_id);
            $chair = trim($chair);
            $minuteTaker = trim($minuteTaker);
            $adjusters = array_values(array_filter(array_map('trim', $adjusters), fn (string $name): bool => $name !== ''));

            if ($chair === '' || $minuteTaker === '') {
                throw ValidationException::withMessages(['chair' => __('Ange ordförande och protokollförare.')]);
            }

            $meeting->forceFill(['chair_name' => $chair, 'minute_taker_name' => $minuteTaker, 'adjusters' => $adjusters]);

            try {
                $shares = $meeting->kind->isGeneralMeeting() ? ShareRegister::at($meeting->meeting_date)->sharesByHolder() : [];
            } catch (RegisterException) {
                $shares = [];
            }

            $problem = MinutesChecking::problem($meeting, $shares, $chair, $adjusters);

            if ($problem !== null) {
                throw ValidationException::withMessages(['adjusters' => $problem]);
            }

            $meeting->save();
            $body = $minutes->body;
            $body['chair'] = $chair;
            $body['minute_taker'] = $minuteTaker;
            $body['adjusters'] = $adjusters;

            $minutes->forceFill([
                'body' => $body,
                'status' => MinutesStatus::Checked->value,
                'checked_names' => array_values(array_unique([$chair, ...$adjusters])),
                'checked_by' => $user->id,
                'checked_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record('boardroom.minutes_checked', $minutes, ['meeting' => $meeting->id, 'adjusters' => count($adjusters)], $company, $user);

            return $minutes;
        });
    }

    public function approve(User $user, Company $company, Minutes $minutes): Minutes
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): Minutes => DB::transaction(function () use ($user, $company, $minutes): Minutes {
            /** @var Minutes $locked */
            $locked = Minutes::query()->whereKey($minutes->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MinutesStatus::Checked) {
                throw ValidationException::withMessages(['minutes' => __('Protokollet ska justeras innan det godkänns.')]);
            }

            /** @var Meeting $meeting */
            $meeting = Meeting::query()->findOrFail($locked->meeting_id);
            $number = null;

            if ($meeting->kind === MeetingKind::BoardMeeting) {
                // Board minutes in number order (8 kap. 26 §): numbered on approval, so a deleted draft uses no number.
                $register = Register::locked();
                $number = $register->last_board_minutes_number + 1;
                $register->forceFill(['last_board_minutes_number' => $number])->save();
            }

            $approvedAt = CarbonImmutable::now();
            $body = $locked->body;

            if ($meeting->kind->isGeneralMeeting()) {
                // The voting list as it stood on the meeting day is part of the minutes (ABL 7 kap. 48 §).
                $body['voting_list'] = self::votingList($meeting);
            }

            $locked->forceFill(['number' => $number, 'approved_by' => $user->id, 'approved_at' => $approvedAt, 'body' => $body]);
            $pdf = BoardroomPdf::minutes($company, $meeting, $locked, $user);
            $path = "boardroom/{$company->id}/protokoll-{$locked->id}-".$approvedAt->format('YmdHis').'.pdf';
            Storage::disk(AttachToVoucher::DISK)->put($path, $pdf);

            $locked->forceFill([
                'status' => MinutesStatus::Approved->value,
                'pdf_path' => $path,
                'sha256' => hash('sha256', $pdf),
            ])->save();

            Audit::record('boardroom.minutes_approved', $locked, ['meeting' => $meeting->id, 'number' => $number, 'sha256' => $locked->sha256, 'corrects' => $locked->corrects_minutes_id], $company, $user);

            return $locked;
        }));
    }

    public function discard(User $user, Company $company, Minutes $minutes): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $minutes): void {
            $this->ensureDraft($minutes);
            $minutes->delete();
            Audit::record('boardroom.minutes_discarded', $minutes, ['meeting' => $minutes->meeting_id], $company, $user);
        });
    }

    /**
     * The minutes as they start: the meeting and its agenda.
     *
     * @return array<string, mixed>
     */
    public static function body(Meeting $meeting): array
    {
        $items = AgendaItem::query()->where('meeting_id', $meeting->id)->orderBy('position')->get();

        return [
            'meeting' => [
                'kind' => $meeting->kind->value,
                'title' => $meeting->title,
                'date' => $meeting->meeting_date->toDateString(),
                'time' => $meeting->start_time,
                'place' => $meeting->place,
                'digital' => $meeting->digital,
            ],
            'chair' => $meeting->chair_name,
            'minute_taker' => $meeting->minute_taker_name,
            'adjusters' => (array) $meeting->adjusters,
            'attendees' => array_map(fn (array $attendee): array => ['name' => (string) $attendee['name'], 'role' => (string) $attendee['role']], (array) $meeting->attendees),
            'items' => array_values($items->map(fn (AgendaItem $item): array => [
                'position' => $item->position,
                'title' => $item->title,
                'proposal' => $item->proposal,
                'decision' => $item->decision,
                'dissent' => null,
            ])->all()),
            'notes' => null,
        ];
    }

    /**
     * The voting list from the register on the meeting day and the attendees
     * who represent a holder (ABL 7 kap. 29 §).
     *
     * @return list<array{name: string, role: string, shares: int, votes_tenths: int}>
     */
    public static function votingList(Meeting $meeting): array
    {
        try {
            $state = ShareRegister::at($meeting->meeting_date);
        } catch (RegisterException) {
            return [];
        }

        $shares = $state->sharesByHolder();
        $votes = $state->votesByHolder(ShareClass::query()->pluck('votes_tenths', 'id')->map(fn (mixed $tenths): int => (int) $tenths)->all());
        $list = [];

        foreach ((array) $meeting->attendees as $attendee) {
            $holder = $attendee['shareholder_id'] ?? null;
            $list[] = [
                'name' => (string) $attendee['name'],
                'role' => (string) $attendee['role'],
                'shares' => is_int($holder) ? ($shares[$holder] ?? 0) : 0,
                'votes_tenths' => is_int($holder) ? ($votes[$holder] ?? 0) : 0,
            ];
        }

        return $list;
    }

    private function ensureDraft(Minutes $minutes): void
    {
        if ($minutes->isApproved()) {
            throw ValidationException::withMessages(['minutes' => __('Protokollet är godkänt och låst. Rätta det med ett nytt protokoll.')]);
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
