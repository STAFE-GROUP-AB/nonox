<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Modules\Boardroom\Models\ShareEvent;
use Carbon\CarbonImmutable;

/**
 * Turns the entries of the share register into the register at a date
 * (ADR-070 §2, rule sheet AB-R10). Entries are applied in order of effective
 * date and then of entry; an entry voided by a correction is skipped. Every
 * step is checked: no share may be held by two owners at once, no share may
 * lie outside the issued ranges, a transfer must come from the holder, and
 * a note must be ended by its own entry. A broken rule throws a
 * RegisterException with a Swedish message, so recording an entry folds the
 * whole history with the new entry added and refuses it if anything breaks.
 */
final class RegisterFold
{
    /** @var list<Holding> */
    private array $holdings = [];

    private int $capital = 0;

    /** @var array<int, int> */
    private array $pending = [];

    /** @var array<int, array<string, mixed>> */
    private array $snapshots = [];

    /** @var array<int, ShareEventKind> kinds of the entries applied so far, by id */
    private array $applied = [];

    private bool $started = false;

    /**
     * @param  iterable<ShareEvent>  $events  every entry of the company, voided and corrections included
     */
    public static function at(iterable $events, ?CarbonImmutable $date = null): RegisterState
    {
        return (new self)->run($events, $date);
    }

    /**
     * @param  iterable<ShareEvent>  $events
     */
    private function run(iterable $events, ?CarbonImmutable $date): RegisterState
    {
        $all = [];

        foreach ($events as $event) {
            $all[] = $event;
        }

        $voided = $this->voided($all);
        $active = array_values(array_filter($all, fn (ShareEvent $event): bool => $event->kind !== ShareEventKind::Correction
            && ! in_array($event->id, $voided, true)
            && ($date === null || $event->effective_date->lessThanOrEqualTo($date))));

        usort($active, fn (ShareEvent $a, ShareEvent $b): int => [$a->effective_date->toDateString(), $a->sequence] <=> [$b->effective_date->toDateString(), $b->sequence]);

        foreach ($active as $event) {
            $this->apply($event, $date);
            $this->applied[$event->id] = $event->kind;
        }

        return new RegisterState($date, $this->holdings, $this->capital, $this->pending, $this->snapshots, $voided, $this->started);
    }

    /**
     * Ids of the entries voided by a correction. A correction must name an
     * entry that exists, is not itself a correction and is not voided twice.
     *
     * @param  list<ShareEvent>  $events
     * @return list<int>
     */
    private function voided(array $events): array
    {
        $byId = [];

        foreach ($events as $event) {
            $byId[$event->id] = $event;
        }

        $voided = [];

        foreach ($events as $event) {
            if ($event->kind !== ShareEventKind::Correction) {
                continue;
            }

            $target = $event->refers_to_event_id === null ? null : ($byId[$event->refers_to_event_id] ?? null);

            if ($target === null || $target->sequence >= $event->sequence) {
                throw new RegisterException(__('Rättelsen måste gälla en tidigare händelse i aktieboken.'));
            }

            if ($target->kind === ShareEventKind::Correction) {
                throw new RegisterException(__('En rättelse kan inte rättas. Gör en ny händelse i stället.'));
            }

            if (in_array($target->id, $voided, true)) {
                throw new RegisterException(__('Händelse :number är redan rättad.', ['number' => $target->sequence]));
            }

            $voided[] = $target->id;
        }

        return $voided;
    }

    private function apply(ShareEvent $event, ?CarbonImmutable $date): void
    {
        if ($event->to_holder_id !== null && is_array($event->holder_snapshot)) {
            $this->snapshots[$event->to_holder_id] = $event->holder_snapshot;
        }

        if ($event->kind === ShareEventKind::AddressChange) {
            return;
        }

        if ($event->kind->startsRegister()) {
            if ($this->hasAppliedOtherThanStart()) {
                throw new RegisterException(__('Bildande och öppningsläge måste komma före alla andra händelser i aktieboken.'));
            }

            $this->started = true;
        } elseif (! $this->started) {
            throw new RegisterException(__('Aktieboken har inte startats. Registrera först bildandet eller aktieboken som den ser ut i dag.'));
        }

        if ($event->kind->needsRange()) {
            $this->ensureRange($event);
        }

        match ($event->kind) {
            ShareEventKind::Opening, ShareEventKind::Formation => $this->issue($event, registered: true),
            ShareEventKind::NewIssue => $this->issue($event, registered: $event->registered_on !== null && ($date === null || $event->registered_on->lessThanOrEqualTo($date))),
            ShareEventKind::BonusIssue => $this->bonusIssue($event),
            ShareEventKind::Registration => $this->register($event),
            ShareEventKind::NewIssueLapsed => $this->lapse($event),
            ShareEventKind::Transfer, ShareEventKind::InheritanceGift => $this->transfer($event),
            ShareEventKind::Split => $this->split($event),
            ShareEventKind::ReverseSplit => $this->reverseSplit($event),
            ShareEventKind::Redemption => $this->redeem($event),
            ShareEventKind::Conversion => $this->change($event, fn (Holding $holding): Holding => $holding->with(['classId' => $event->share_class_id])),
            ShareEventKind::Certificate => $this->change($event, fn (Holding $holding): Holding => $holding->with(['certificate' => (bool) $event->certificate_issued])),
            ShareEventKind::RightsHolderNote, ShareEventKind::PreEmptionNotice, ShareEventKind::PreEmptionClaim, ShareEventKind::PreEmptionAction => $this->note($event),
            ShareEventKind::NoteEnded => $this->endNote($event),
            ShareEventKind::AddressChange, ShareEventKind::Correction => null,
        };
    }

    private function hasAppliedOtherThanStart(): bool
    {
        foreach ($this->applied as $kind) {
            if (! $kind->startsRegister() && $kind !== ShareEventKind::AddressChange) {
                return true;
            }
        }

        return false;
    }

    private function ensureRange(ShareEvent $event): void
    {
        if ($event->share_from === null || $event->share_to === null || $event->share_from < 1 || $event->share_to < $event->share_from) {
            throw new RegisterException(__('Ange aktienumren som ett intervall, till exempel 1–500.'));
        }
    }

    private function issue(ShareEvent $event, bool $registered): void
    {
        $from = (int) $event->share_from;
        $to = (int) $event->share_to;

        if ($event->to_holder_id === null) {
            throw new RegisterException(__('Ange vem som får aktierna.'));
        }

        foreach ($this->holdings as $holding) {
            if ($holding->from <= $to && $from <= $holding->to) {
                throw new RegisterException(__('Aktierna :from–:to finns redan i aktieboken. En aktie kan inte ges ut två gånger.', ['from' => max($from, $holding->from), 'to' => min($to, $holding->to)]));
            }
        }

        $capital = (int) $event->capital_change_ore;
        $this->holdings[] = new Holding($from, $to, $event->to_holder_id, $event->share_class_id, (bool) $event->certificate_issued, $registered ? null : $event->id);
        $this->sort();

        if ($registered) {
            $this->capital += $capital;

            return;
        }

        $this->pending[$event->id] = $capital;
    }

    private function bonusIssue(ShareEvent $event): void
    {
        $this->ensureNoPending(__('fondemissionen'));
        $this->capital += (int) $event->capital_change_ore;

        if ($event->share_from === null) {
            return;
        }

        $this->ensureRange($event);
        $this->issue($event->replicate()->forceFill(['capital_change_ore' => 0]), registered: true);
    }

    private function register(ShareEvent $event): void
    {
        $issueId = (int) $event->refers_to_event_id;

        if (($this->applied[$issueId] ?? null) !== ShareEventKind::NewIssue || ! array_key_exists($issueId, $this->pending)) {
            throw new RegisterException(__('Registreringen måste gälla en nyemission som ännu inte är registrerad.'));
        }

        $this->capital += $this->pending[$issueId];
        unset($this->pending[$issueId]);
        $this->holdings = array_map(fn (Holding $holding): Holding => $holding->pendingIssueId === $issueId ? $holding->with(['pendingIssueId' => null]) : $holding, $this->holdings);
    }

    private function lapse(ShareEvent $event): void
    {
        $issueId = (int) $event->refers_to_event_id;

        if (($this->applied[$issueId] ?? null) !== ShareEventKind::NewIssue || ! array_key_exists($issueId, $this->pending)) {
            throw new RegisterException(__('Bara en nyemission som inte är registrerad kan falla.'));
        }

        unset($this->pending[$issueId]);
        $this->holdings = array_values(array_filter($this->holdings, fn (Holding $holding): bool => $holding->pendingIssueId !== $issueId));
    }

    private function transfer(ShareEvent $event): void
    {
        if ($event->to_holder_id === null || $event->from_holder_id === null) {
            throw new RegisterException(__('Ange både vem som lämnar och vem som får aktierna.'));
        }

        if ($event->to_holder_id === $event->from_holder_id) {
            throw new RegisterException(__('Aktierna kan inte överlåtas till samma ägare.'));
        }

        $this->change($event, function (Holding $holding) use ($event): Holding {
            if ($holding->holderId !== $event->from_holder_id) {
                throw new RegisterException(__('Aktierna :from–:to ägs inte av den som anges lämna dem.', ['from' => $holding->from, 'to' => $holding->to]));
            }

            // Rule sheet AB-R4: a rights holder is not dropped silently; the note is ended by its own entry.
            if ($holding->hasNote(ShareEventKind::RightsHolderNote->value)) {
                throw new RegisterException(__('Aktierna :from–:to har en anteckning om rättighetshavare. Avsluta anteckningen först.', ['from' => $holding->from, 'to' => $holding->to]));
            }

            return $holding->with(['holderId' => $event->to_holder_id]);
        });
    }

    private function split(ShareEvent $event): void
    {
        $ratio = $this->ratio($event);
        $this->ensureNoPending(__('splitten'));

        // Every old share becomes $ratio new shares, numbered in the same order; old certificates no longer match.
        $this->holdings = array_map(fn (Holding $holding): Holding => $holding->with([
            'from' => ($holding->from - 1) * $ratio + 1,
            'to' => $holding->to * $ratio,
            'certificate' => false,
        ]), $this->holdings);
    }

    /**
     * A reverse split: every $ratio old shares become one new share, numbered
     * from 1 in the order of the holders' first shares. A holder's surplus
     * shares pass to the company (ABL 4 kap. 50 §), which gets the new
     * shares they add up to.
     * TODO(legal-verify): how surplus shares that do not add up to a whole
     * new share are handled; NONOX refuses the entry until that is answered.
     */
    private function reverseSplit(ShareEvent $event): void
    {
        $ratio = $this->ratio($event);
        $this->ensureNoPending(__('sammanläggningen'));

        foreach ($this->holdings as $holding) {
            if ($holding->notes !== []) {
                throw new RegisterException(__('Avsluta anteckningarna på aktierna :from–:to före sammanläggningen.', ['from' => $holding->from, 'to' => $holding->to]));
            }
        }

        /** @var array<string, array{holder: int, class: ?int, count: int}> $groups */
        $groups = [];

        foreach ($this->holdings as $holding) {
            $key = $holding->holderId.':'.($holding->classId ?? '-');
            $groups[$key] ??= ['holder' => $holding->holderId, 'class' => $holding->classId, 'count' => 0];
            $groups[$key]['count'] += $holding->count();
        }

        /** @var array<string, int> $surplus surplus old shares per class */
        $surplus = [];
        $next = 1;
        $holdings = [];

        foreach ($groups as $group) {
            $classKey = (string) ($group['class'] ?? '-');
            $surplus[$classKey] = ($surplus[$classKey] ?? 0) + $group['count'] % $ratio;
            $new = intdiv($group['count'], $ratio);

            if ($new > 0) {
                $holdings[] = new Holding($next, $next + $new - 1, $group['holder'], $group['class']);
                $next += $new;
            }
        }

        foreach ($surplus as $classKey => $count) {
            if ($count === 0) {
                continue;
            }

            if ($count % $ratio !== 0) {
                throw new RegisterException(__('Överskjutande aktier går inte jämnt upp i nya aktier. Kontrollera beslutet; NONOX kan inte registrera sammanläggningen så här.'));
            }

            if ($event->to_holder_id === null) {
                throw new RegisterException(__('Överskjutande aktier övergår till bolaget. Bolaget saknas som ägare i aktieboken.'));
            }

            $new = intdiv($count, $ratio);
            $holdings[] = new Holding($next, $next + $new - 1, $event->to_holder_id, $classKey === '-' ? null : (int) $classKey);
            $next += $new;
        }

        $this->holdings = $holdings;
    }

    private function redeem(ShareEvent $event): void
    {
        if ((int) $event->capital_change_ore > 0) {
            throw new RegisterException(__('En indragning minskar aktiekapitalet. Ange minskningen som ett negativt belopp eller noll.'));
        }

        $taken = $this->segment((int) $event->share_from, (int) $event->share_to);

        foreach ($taken as $index) {
            $holding = $this->holdings[$index];

            if ($event->from_holder_id !== null && $holding->holderId !== $event->from_holder_id) {
                throw new RegisterException(__('Aktierna :from–:to ägs inte av den som anges lämna dem.', ['from' => $holding->from, 'to' => $holding->to]));
            }

            if ($holding->pendingIssueId !== null) {
                throw new RegisterException(__('Aktierna :from–:to kommer från en nyemission som inte är registrerad.', ['from' => $holding->from, 'to' => $holding->to]));
            }
        }

        $this->holdings = array_values(array_filter($this->holdings, fn (Holding $holding, int $index): bool => ! in_array($index, $taken, true), ARRAY_FILTER_USE_BOTH));
        $this->capital += (int) $event->capital_change_ore;
    }

    private function note(ShareEvent $event): void
    {
        $text = trim((string) $event->note);

        if ($text === '') {
            throw new RegisterException(__('Skriv vad anteckningen gäller.'));
        }

        $note = ['event_id' => $event->id, 'kind' => $event->kind->value, 'text' => $text, 'date' => $event->effective_date->toDateString()];
        $this->change($event, fn (Holding $holding): Holding => $holding->with(['notes' => [...$holding->notes, $note]]));
    }

    private function endNote(ShareEvent $event): void
    {
        $target = (int) $event->refers_to_event_id;
        $found = false;

        $this->holdings = array_map(function (Holding $holding) use ($target, &$found): Holding {
            $notes = array_values(array_filter($holding->notes, fn (array $note): bool => $note['event_id'] !== $target));

            if (count($notes) === count($holding->notes)) {
                return $holding;
            }

            $found = true;

            return $holding->with(['notes' => $notes]);
        }, $this->holdings);

        if (! $found) {
            throw new RegisterException(__('Anteckningen som ska avslutas finns inte i aktieboken.'));
        }
    }

    /**
     * Applies a change to every share of the entry's range, which must all be
     * in the register.
     *
     * @param  callable(Holding): Holding  $change
     */
    private function change(ShareEvent $event, callable $change): void
    {
        $inside = $this->segment((int) $event->share_from, (int) $event->share_to);
        $holdings = [];

        foreach ($this->holdings as $index => $holding) {
            $holdings[] = in_array($index, $inside, true) ? $change($holding) : $holding;
        }

        $this->holdings = $holdings;
    }

    /**
     * Splits the holdings at the range's ends and returns the indexes of the
     * holdings inside it, which must cover every share of it.
     *
     * @return list<int>
     */
    private function segment(int $from, int $to): array
    {
        $this->splitAt($from);
        $this->splitAt($to + 1);
        $inside = [];
        $covered = 0;

        foreach ($this->holdings as $index => $holding) {
            if ($holding->from >= $from && $holding->to <= $to) {
                $inside[] = $index;
                $covered += $holding->count();
            }
        }

        if ($covered !== $to - $from + 1) {
            throw new RegisterException(__('Aktierna :from–:to finns inte alla i aktieboken. Ingen aktie kan finnas utanför de utgivna.', ['from' => $from, 'to' => $to]));
        }

        return $inside;
    }

    private function splitAt(int $at): void
    {
        $holdings = [];

        foreach ($this->holdings as $holding) {
            if ($holding->from < $at && $at <= $holding->to) {
                $holdings[] = $holding->with(['to' => $at - 1]);
                $holdings[] = $holding->with(['from' => $at]);

                continue;
            }

            $holdings[] = $holding;
        }

        $this->holdings = $holdings;
    }

    private function ratio(ShareEvent $event): int
    {
        if ($event->ratio === null || $event->ratio < 2) {
            throw new RegisterException(__('Ange hur många aktier som blir en (sammanläggning) eller hur många en aktie blir (split), minst 2.'));
        }

        return $event->ratio;
    }

    private function ensureNoPending(string $what): void
    {
        if ($this->pending !== []) {
            throw new RegisterException(__('Det finns en nyemission som inte är registrerad. Registrera den eller låt den falla före :what.', ['what' => $what]));
        }
    }

    private function sort(): void
    {
        usort($this->holdings, fn (Holding $a, Holding $b): int => $a->from <=> $b->from);
    }
}
