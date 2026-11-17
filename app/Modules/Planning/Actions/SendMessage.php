<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Message;
use App\Modules\Planning\Models\MessageRecipient;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\MessageAudience;
use App\Modules\Planning\Support\PublishedShift;
use App\Modules\Planning\Support\ScheduleNotifier;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sends a message to staff about the schedule (ADR-037 gap 5). The
 * recipients are decided now and frozen (MessageAudience); those with a login
 * are told in Notiser and by an e-mail that holds no message text. The audit
 * log holds the scope, ids and counts, never the text. Planners only
 * (planning:manage).
 */
final class SendMessage
{
    use GuardsPlanning;

    public function __construct(private readonly ScheduleNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input  scope, shift_id, area_id, date_from, date_to, body, requires_ack
     * @return array{message: Message, recipients: int, unreachable: int}
     */
    public function __invoke(User $user, Company $company, array $input): array
    {
        $this->guard($user, $company);

        $input['body'] = is_string($input['body'] ?? null) ? trim($input['body']) : ($input['body'] ?? null);
        $scope = (string) ($input['scope'] ?? '');

        $validated = Validator::make($input, [
            'scope' => ['required', Rule::in(Message::SCOPES)],
            'shift_id' => [Rule::requiredIf($scope === Message::SHIFT), 'nullable', 'uuid', Rule::exists('planning_shifts', 'id')->where('company_id', $company->id)],
            'area_id' => [Rule::requiredIf($scope === Message::AREA), 'nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)],
            'date_from' => [Rule::requiredIf($scope !== Message::SHIFT), 'nullable', 'date_format:Y-m-d'],
            'date_to' => [Rule::requiredIf(in_array($scope, [Message::PERIOD, Message::AREA, Message::ALL], true)), 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'body' => ['required', 'string', 'max:'.Message::MAX_BODY],
            'requires_ack' => ['boolean'],
        ], [], [
            'scope' => __('Till'),
            'shift_id' => __('Pass'),
            'area_id' => __('Avdelning'),
            'date_from' => __('Från'),
            'date_to' => __('Till och med'),
            'body' => __('Meddelande'),
        ])->validate();

        [$from, $to] = $this->dates($scope, $validated);

        if ($from->diffInDays($to) + 1 > Message::MAX_DAYS) {
            throw ValidationException::withMessages(['date_to' => __('Ett meddelande kan gälla högst :days dagar.', ['days' => Message::MAX_DAYS])]);
        }

        $shiftId = $scope === Message::SHIFT ? (string) $validated['shift_id'] : null;
        $areaId = $scope === Message::AREA ? (int) $validated['area_id'] : null;

        return DB::transaction(function () use ($user, $company, $validated, $scope, $from, $to, $shiftId, $areaId): array {
            $employeeIds = MessageAudience::employeeIds($scope, $from, $to, $shiftId, $areaId);

            if ($employeeIds === []) {
                throw ValidationException::withMessages(['scope' => $scope === Message::ALL
                    ? __('Ingen är anställd under perioden, så ingen skulle få meddelandet.')
                    : __('Ingen har ett publicerat pass i det du valt, så ingen skulle få meddelandet. Publicera schemat först.')]);
            }

            $message = Message::query()->create([
                'author_id' => $user->id,
                'scope' => $scope,
                'shift_id' => $shiftId,
                'area_id' => $areaId,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'body' => (string) $validated['body'],
                'requires_ack' => (bool) ($validated['requires_ack'] ?? false),
            ]);

            $now = CarbonImmutable::now(LocalTimestamp::ZONE);
            MessageRecipient::query()->insert(array_map(fn (int $employeeId): array => [
                'company_id' => $company->id,
                'message_id' => $message->id,
                'employee_id' => $employeeId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $employeeIds));

            $told = $this->notifier->message($company, $employeeIds, $message->requires_ack);

            if ($told !== []) {
                MessageRecipient::query()->where('message_id', $message->id)->whereIn('employee_id', $told)
                    ->update(['notified_at' => $now->utc()->format('Y-m-d H:i:sP')]);
            }

            $unreachable = count($employeeIds) - count($told);

            Audit::record('planning.message_sent', $message, [
                'scope' => $scope,
                'shift_id' => $shiftId,
                'area_id' => $areaId,
                'requires_ack' => $message->requires_ack,
                'recipients' => count($employeeIds),
                'unreachable' => $unreachable,
            ], $company, $user);

            return ['message' => $message, 'recipients' => count($employeeIds), 'unreachable' => $unreachable];
        });
    }

    /**
     * Withdraws a message: it and its receipts are deleted, so recipients no
     * longer see it. Audited with the id only.
     */
    public function delete(User $user, Company $company, Message $message): void
    {
        $this->guard($user, $company);

        DB::transaction(function () use ($user, $company, $message): void {
            Audit::record('planning.message_deleted', $message, ['scope' => $message->scope], $company, $user);
            $message->delete();
        });
    }

    /**
     * The days the message concerns; they decide when it is pruned.
     *
     * @param  array<string, mixed>  $validated
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dates(string $scope, array $validated): array
    {
        if ($scope === Message::SHIFT) {
            $shift = Shift::query()->findOrFail((string) $validated['shift_id']);

            if ($shift->published_at === null || $shift->status === Shift::CANCELLED) {
                throw ValidationException::withMessages(['shift_id' => __('Passet är inte publicerat, så ingen har sett det än.')]);
            }

            $shown = PublishedShift::from($shift, CarbonImmutable::now());

            return [$shown->start->startOfDay(), $shown->end->startOfDay()];
        }

        $from = CarbonImmutable::parse((string) $validated['date_from'], LocalTimestamp::ZONE)->startOfDay();
        $to = $scope === Message::DAY ? $from : CarbonImmutable::parse((string) $validated['date_to'], LocalTimestamp::ZONE)->startOfDay();

        return [$from, $to];
    }
}
