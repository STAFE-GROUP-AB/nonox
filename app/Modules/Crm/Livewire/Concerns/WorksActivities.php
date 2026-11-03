<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\CallOutcome;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Logging, planning and completing activities on a CRM page (ADR-043): the
 * account card and the person page share it. Ring, Möte, Anteckning and
 * Uppgift open the activity form; Klar asks for the outcome and a note,
 * then the next step, and for the last open step of an open deal the next
 * step is required in the same form (DealNextStep). The business rules are
 * in LogActivity and CompleteActivity; this is only the screen.
 *
 * @mixin Component
 */
trait WorksActivities
{
    /** @var array<string, mixed> */
    public array $activity = [];

    #[Locked]
    public ?int $editingActivityId = null;

    /** @var array<string, string> */
    public array $completion = ['call_outcome' => '', 'note' => ''];

    /** The deal of the completed activity: its next step is linked to it. */
    #[Locked]
    public ?int $nextDealId = null;

    /** The completed activity is the last open step of an open deal: the next one is required. */
    public bool $dealStepRequired = false;

    /** The account an edited activity, or the next step after one, stays at: its own, whatever the page's default. */
    #[Locked]
    public ?int $lockedAccountId = null;

    /** Ring, Möte, Anteckning or Uppgift: the activity form, ready for that type. */
    public function startActivity(string $type, ?int $personId = null): void
    {
        $this->authorizeManage();
        $type = ActivityType::tryFrom($type) ?? ActivityType::Note;

        // A person marked Kontakta inte is only written about: calls, meetings and tasks are planned without them.
        if ($personId !== null && $type !== ActivityType::Note && Person::query()->whereKey($personId)->whereNotNull('do_not_contact_at')->exists()) {
            $personId = null;
        }

        $this->editingActivityId = null;
        $this->lockedAccountId = null;
        $this->activity = [
            'type' => $type->value,
            'subject' => '',
            'body' => '',
            'person_id' => $personId === null ? '' : (string) $personId,
            'call_outcome' => $type === ActivityType::Call ? CallOutcome::Reached->value : '',
            'due_at' => $type === ActivityType::Meeting || $type === ActivityType::Task ? CarbonImmutable::now(LogActivity::ZONE)->addDay()->setTime(9, 0)->format('Y-m-d\TH:i') : '',
            'assigned_to' => (string) $this->user()->id,
            // A call or an e-mail is usually logged afterwards; a meeting or a task is planned.
            'done' => $type === ActivityType::Call || $type === ActivityType::Email,
        ];
        $this->panel = 'activity';
        $this->resetErrorBag();
    }

    public function editActivity(int $activityId): void
    {
        $this->authorizeManage();
        $activity = $this->activities()->findOrFail($activityId);

        $this->editingActivityId = $activity->id;
        $this->lockedAccountId = $activity->account_id;
        $this->activity = [
            'account_id' => (string) $activity->account_id,
            'type' => $activity->type->value,
            'subject' => $activity->subject,
            'body' => (string) $activity->body,
            'person_id' => (string) $activity->person_id,
            'call_outcome' => (string) $activity->call_outcome?->value,
            'due_at' => (string) $activity->due_at?->setTimezone(LogActivity::ZONE)->format('Y-m-d\TH:i'),
            'assigned_to' => (string) $activity->assigned_to,
            'done' => ! $activity->isOpen(),
        ];
        $this->panel = 'activity';
        $this->resetErrorBag();
    }

    public function saveActivity(LogActivity $log): void
    {
        $existing = $this->editingActivityId === null ? null : $this->activities()->findOrFail($this->editingActivityId);
        $saved = $log($this->user(), $this->company(), [
            ...$this->activity,
            ...($this->panel === 'next' && $this->nextDealId !== null ? ['deal_id' => $this->nextDealId] : []),
            'account_id' => $this->activityAccountId(),
            'person_id' => ($this->activity['person_id'] ?? '') === '' ? null : (int) $this->activity['person_id'],
            'assigned_to' => ($this->activity['assigned_to'] ?? '') === '' ? null : (int) $this->activity['assigned_to'],
            'call_outcome' => ($this->activity['call_outcome'] ?? '') === '' ? null : $this->activity['call_outcome'],
        ], $existing);

        $this->closePanel();
        $this->toast(match (true) {
            $existing !== null => __('Aktiviteten är sparad.'),
            $saved->isOpen() => __(':type är planerad.', ['type' => $saved->type->label()]),
            default => __(':type är loggad.', ['type' => $saved->type->label()]),
        });
    }

    /** Klar: asks for the outcome and a short note, then the next step. */
    public function startCompletion(int $activityId): void
    {
        $this->authorizeManage();
        $activity = $this->activities()->whereNull('done_at')->findOrFail($activityId);

        $required = DealNextStep::isLastStep($activity);

        if ($required) {
            // The form's next-step fields: a task tomorrow at nine, for the same person.
            $this->startActivity(ActivityType::Task->value, $activity->person_id);
        }

        $this->editingActivityId = $activity->id;
        $this->lockedAccountId = $activity->account_id;
        $this->activity['account_id'] = (string) $activity->account_id;
        $this->nextDealId = $activity->deal_id;
        $this->dealStepRequired = $required;
        $this->completion = ['call_outcome' => $activity->type === ActivityType::Call ? CallOutcome::Reached->value : '', 'note' => ''];
        $this->panel = 'complete';
        $this->resetErrorBag();
    }

    public function complete(CompleteActivity $complete): void
    {
        $activity = $this->activities()->findOrFail((int) $this->editingActivityId);

        try {
            $complete($this->user(), $this->company(), $activity, [
                'call_outcome' => $this->completion['call_outcome'] === '' ? null : $this->completion['call_outcome'],
                'note' => $this->completion['note'],
                ...($this->dealStepRequired ? ['next' => [
                    'type' => $this->activity['type'] ?? ActivityType::Task->value,
                    'subject' => $this->activity['subject'] ?? '',
                    'due_at' => $this->activity['due_at'] ?? null,
                    'assigned_to' => ($this->activity['assigned_to'] ?? '') === '' ? null : (int) $this->activity['assigned_to'],
                    'person_id' => ($this->activity['person_id'] ?? '') === '' ? null : (int) $this->activity['person_id'],
                ]] : []),
            ]);
        } catch (ValidationException $exception) {
            // The next step's fields stay open with their errors; anything else closes the panel.
            if ($this->dealStepRequired) {
                throw $exception;
            }

            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
            $this->closePanel();

            return;
        }

        if ($this->dealStepRequired) {
            $this->closePanel();
            $this->toast(__('Klart, och nästa steg är planerat.'));

            return;
        }

        $dealId = $activity->deal_id;
        $this->toast(__('Klart. Vad är nästa steg?'));
        $this->startActivity(ActivityType::Task->value, $activity->person_id);
        // The next step is at the same account as the step it follows (and its deal), not the page's default.
        $this->lockedAccountId = $activity->account_id;
        $this->activity['account_id'] = (string) $activity->account_id;
        $this->nextDealId = $dealId;
        $this->panel = 'next';
    }

    public function skipNextStep(): void
    {
        $this->closePanel();
        $this->toast(__('Inget nästa steg planerat.'), 'info');
    }

    /** The activities this page may touch. @return Builder<Activity> */
    abstract protected function activities(): Builder;

    /** The account a new activity is logged at. */
    abstract protected function activityAccountId(): int;

    abstract protected function authorizeManage(): void;

    abstract protected function user(): User;

    abstract protected function company(): Company;
}
