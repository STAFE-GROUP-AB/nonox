<?php

declare(strict_types=1);

use App\Modules\Boardroom\Actions\RecordShareEvent;
use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Boardroom\Domain\Holding;
use App\Modules\Boardroom\Domain\RegisterState;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The share register as an event log (ADR-070 §2), with the fixtures of the
 * rule sheet docs/specs/rules/aktiebok.md: formation, new issue, transfer,
 * split, reverse split, correction and the opening entry of an existing
 * company. Everyone here is synthetic.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    // The fixtures use the rule sheet's 2027 dates; registration and decision dates may not be in the future.
    $this->travelTo(CarbonImmutable::parse('2027-12-31 12:00:00'));
    $this->a = Boardroom::person($this->owner, $this->company, 'Anna Andersson', '19800101123');
    $this->b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
});

/** @return list<array{0: int, 1: int, 2: string}> from, to and holder name per row */
function registerRows(?string $date = null): array
{
    $state = ShareRegister::at($date === null ? null : CarbonImmutable::parse($date));

    return array_map(fn (Holding $holding): array => [$holding->from, $holding->to, (string) ($state->snapshot($holding->holderId)['name'] ?? '')], $state->rows());
}

function refused(callable $record, string $message): void
{
    try {
        $record();
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain($message);

        return;
    }

    test()->fail("The entry was not refused: {$message}");
}

it('starts with the formation dated by the memorandum, before registration (AB-R9)', function () {
    $event = Boardroom::formation($this->owner, $this->company, $this->a, 1000, 2_500_000, '2026-03-02');

    $state = ShareRegister::at(CarbonImmutable::parse('2026-03-02'));

    expect($event->sequence)->toBe(1)
        ->and($event->recorded_at)->not->toBeNull()
        ->and(registerRows('2026-03-02'))->toBe([[1, 1000, 'Anna Andersson']])
        ->and(registerRows('2026-03-01'))->toBe([])
        ->and($state->capitalOre)->toBe(2_500_000)
        ->and($state->quotaValueOre())->toBe(2_500)
        ->and(ShareClass::query()->count())->toBe(1);
});

it('moves shares on a transfer and refuses shares outside the issued ranges or not held (AB-R10)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 501, 'share_to' => 1000, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id, 'consideration_ore' => 1_000_000]);

    expect(registerRows('2027-01-14'))->toBe([[1, 1000, 'Anna Andersson']])
        ->and(registerRows('2027-01-15'))->toBe([[1, 500, 'Anna Andersson'], [501, 1000, 'Bertil Berg']]);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-02-01', 'share_from' => 900, 'share_to' => 1100, 'from_holder_id' => $this->b->id, 'to_holder_id' => $this->a->id]), 'Aktierna 900–1100 finns inte alla i aktieboken');
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-02-01', 'share_from' => 600, 'share_to' => 700, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]), 'ägs inte av den som anges lämna dem');

    // A backdated entry that would make a later one impossible is refused too.
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-10', 'share_from' => 501, 'share_to' => 600, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]), 'ägs inte av den som anges lämna dem');
});

it('never issues a share twice', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'effective_date' => '2027-02-20', 'share_from' => 990, 'share_to' => 1100, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 100]), 'Aktierna 990–1000 finns redan i aktieboken');
});

it('enters a new issue at allotment, flags it unregistered and counts its capital from registration (AB-R42)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $issue = Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'decided_on' => '2027-02-01', 'effective_date' => '2027-02-20', 'share_from' => 1001, 'share_to' => 1200, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 500_000]);

    $allotted = ShareRegister::at(CarbonImmutable::parse('2027-02-20'));

    expect(registerRows('2027-02-20'))->toBe([[1, 1000, 'Anna Andersson'], [1001, 1200, 'Bertil Berg']])
        ->and($allotted->rows()[1]->pendingIssueId)->toBe($issue->id)
        ->and($allotted->capitalOre)->toBe(2_500_000)
        ->and($allotted->pendingCapitalOre())->toBe(500_000)
        ->and($allotted->registeredShares())->toBe(1000);

    Boardroom::record($this->owner, $this->company, ['kind' => 'REGISTRATION', 'registered_on' => '2027-03-15', 'refers_to_event_id' => $issue->id]);

    $registered = ShareRegister::at(CarbonImmutable::parse('2027-03-15'));

    expect($registered->capitalOre)->toBe(3_000_000)
        ->and($registered->pendingCapitalOre())->toBe(0)
        ->and($registered->rows()[1]->pendingIssueId)->toBeNull()
        ->and(ShareRegister::at(CarbonImmutable::parse('2027-03-14'))->capitalOre)->toBe(2_500_000);
});

it('strikes the shares of a new issue that lapses (13 kap. 30 §)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $issue = Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'effective_date' => '2027-02-20', 'share_from' => 1001, 'share_to' => 1200, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 500_000]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE_LAPSED', 'effective_date' => '2027-08-02', 'refers_to_event_id' => $issue->id]);

    expect(registerRows('2027-08-02'))->toBe([[1, 1000, 'Anna Andersson']])
        ->and(ShareRegister::at()->pendingCapitalOre())->toBe(0);
});

it('changes the number of shares in a split only from registration (AB-R42)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 501, 'share_to' => 1000, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'SPLIT', 'decided_on' => '2027-03-10', 'registered_on' => '2027-03-24', 'ratio' => 2]);

    expect(registerRows('2027-03-23'))->toBe([[1, 500, 'Anna Andersson'], [501, 1000, 'Bertil Berg']])
        ->and(registerRows('2027-03-24'))->toBe([[1, 1000, 'Anna Andersson'], [1001, 2000, 'Bertil Berg']])
        ->and(ShareRegister::at()->capitalOre)->toBe(2_500_000)
        ->and(ShareRegister::at()->quotaValueOre())->toBe(1_250);
});

it('passes surplus shares to the company in a reverse split (ABL 4 kap. 50 §, AB-R11)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a, 999);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 335, 'share_to' => 999, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    $event = Boardroom::record($this->owner, $this->company, ['kind' => 'REVERSE_SPLIT', 'registered_on' => '2027-04-01', 'ratio' => 3]);

    $state = ShareRegister::at();
    $company = $state->snapshot((int) $event->to_holder_id);

    // 334 → 111 + 1 surplus; 665 → 221 + 2 surplus; the company's 3 surplus old shares make 1 new.
    expect(registerRows())->toBe([[1, 111, 'Anna Andersson'], [112, 332, 'Bertil Berg'], [333, 333, $company['name']]])
        ->and($company['kind'])->toBe(HolderKind::CompanyItself->value)
        ->and($state->totalShares())->toBe(333);
});

it('records a reverse split with a remainder only with a note on how the remainder is handled (rule sheet 1000, 3:1)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a, 1000);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'REVERSE_SPLIT', 'registered_on' => '2027-04-01', 'ratio' => 3]), 'Beskriv i anteckningen hur resten hanteras');

    Boardroom::record($this->owner, $this->company, ['kind' => 'REVERSE_SPLIT', 'registered_on' => '2027-04-01', 'ratio' => 3, 'note' => 'Den överskjutande aktien löses in enligt stämmans beslut.']);

    // 1000 → 333 new shares; the one surplus old share makes no new share, so the company gets none and is not added as holder.
    expect(registerRows())->toBe([[1, 333, 'Anna Andersson']])
        ->and(Shareholder::query()->where('kind', HolderKind::CompanyItself->value)->exists())->toBeFalse();
});

it('refuses a split while a new issue is not yet registered on the split\'s date (review P2-1)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-01 12:00:00'));
    Boardroom::formation($this->owner, $this->company, $this->a, 1000, 2_500_000, '2026-03-02');
    Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'effective_date' => '2026-04-01', 'registered_on' => '2026-06-01', 'share_from' => 1001, 'share_to' => 1500, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 1_250_000]);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'SPLIT', 'registered_on' => '2026-05-01', 'ratio' => 2]), 'Det finns en nyemission som inte är registrerad');
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'SPLIT', 'registered_on' => '2026-07-02', 'ratio' => 2]), 'Registrerad hos Bolagsverket');
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'effective_date' => '2026-06-10', 'registered_on' => '2026-06-05', 'share_from' => 1501, 'share_to' => 1600, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 100]), 'före tilldelningen');
});

it('corrects a wrong entry with a correction that names it, keeping the original (ADR-070 §2)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $wrong = Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 1, 'share_to' => 100, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    $correction = Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2027-01-20', 'refers_to_event_id' => $wrong->id, 'reason' => 'Fel aktienummer, ska vara 901–1000.']);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 901, 'share_to' => 1000, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);

    expect(registerRows())->toBe([[1, 900, 'Anna Andersson'], [901, 1000, 'Bertil Berg']])
        ->and(ShareRegister::at()->isVoided($wrong->id))->toBeTrue()
        ->and(count(ShareRegister::events()))->toBe(4);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2027-01-21', 'refers_to_event_id' => $correction->id, 'reason' => 'Ångrar rättelsen']), 'En rättelse kan inte rättas');
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2027-01-21', 'refers_to_event_id' => $wrong->id, 'reason' => 'Igen']), 'är redan rättad');
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2027-01-21', 'refers_to_event_id' => $wrong->id]), 'Skäl');
});

it('refuses a correction that would make a later entry impossible', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $first = Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 1, 'share_to' => 100, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-02-15', 'share_from' => 1, 'share_to' => 100, 'from_holder_id' => $this->b->id, 'to_holder_id' => $this->a->id]);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2027-03-01', 'refers_to_event_id' => $first->id, 'reason' => 'Fel']), 'ägs inte av den som anges lämna dem');
});

it('records an existing company\'s register as an opening entry', function () {
    $holding = Boardroom::entity($this->owner, $this->company);
    Boardroom::record($this->owner, $this->company, ['kind' => 'OPENING', 'effective_date' => '2026-11-08', 'share_from' => 1, 'share_to' => 500, 'to_holder_id' => $holding->id, 'capital_change_ore' => 5_000_000, 'certificate_issued' => true]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'OPENING', 'effective_date' => '2026-11-08', 'share_from' => 501, 'share_to' => 1000, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 5_000_000]);

    $state = ShareRegister::at();

    expect(registerRows())->toBe([[1, 500, 'Testholding AB'], [501, 1000, 'Anna Andersson']])
        ->and($state->rows()[0]->certificate)->toBeTrue()
        ->and($state->capitalOre)->toBe(10_000_000);

    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2026-12-01', 'share_from' => 1, 'share_to' => 10, 'from_holder_id' => $holding->id, 'to_holder_id' => $this->b->id]);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'OPENING', 'effective_date' => '2026-12-02', 'share_from' => 1001, 'share_to' => 1100, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 0]), 'måste komma före alla andra händelser');
});

it('refuses an entry before the register has started', function () {
    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 1, 'share_to' => 10, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]), 'Aktieboken har inte startats');
});

it('keeps a rights holder until its own entry ends it (AB-R4), and shows förköp notes (AB-R7)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $note = Boardroom::record($this->owner, $this->company, ['kind' => 'RIGHTS_HOLDER_NOTE', 'effective_date' => '2027-01-02', 'share_from' => 1, 'share_to' => 100, 'note' => 'Nyttjanderätt enligt testamente för Cecilia Carlsson']);

    refused(fn () => Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 50, 'share_to' => 150, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]), 'anteckning om rättighetshavare');

    Boardroom::record($this->owner, $this->company, ['kind' => 'NOTE_ENDED', 'effective_date' => '2027-01-10', 'refers_to_event_id' => $note->id]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 50, 'share_to' => 150, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'PRE_EMPTION_NOTICE', 'effective_date' => '2027-02-10', 'share_from' => 50, 'share_to' => 150, 'note' => 'Anmälan om förköp mottagen']);

    $notes = array_values(array_filter(ShareRegister::at()->rows(), fn (Holding $holding): bool => $holding->notes !== []));

    expect($notes)->toHaveCount(1)
        ->and([$notes[0]->from, $notes[0]->to])->toBe([50, 150])
        ->and($notes[0]->notes[0]['date'])->toBe('2027-02-10')
        ->and(ShareRegister::at(CarbonImmutable::parse('2027-02-09'))->rows()[1]->notes)->toBe([]);
});

it('keeps a holder\'s earlier address in the history when it changes (5 kap. 9 §)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    app(RecordShareEvent::class)->changeDetails($this->owner, $this->company, $this->a, [
        'kind' => 'PERSON', 'name' => 'Anna Andersson', 'personal_number' => Boardroom::personalNumber('19800101123'),
        'address_line1' => 'Nya gatan 5', 'postal_code' => '333 44', 'city' => 'Nystad',
    ], '2027-05-01');

    $before = ShareRegister::at(CarbonImmutable::parse('2027-04-30'))->snapshot($this->a->id);
    $after = ShareRegister::at(CarbonImmutable::parse('2027-05-01'))->snapshot($this->a->id);

    expect($before['address_line1'])->toBe('Testvägen 1')
        ->and($after['address_line1'])->toBe('Nya gatan 5')
        ->and($this->a->fresh()->city)->toBe('Nystad');
});

it('lists the register in share number order, not by name (AB-R3)', function () {
    $zeta = Boardroom::person($this->owner, $this->company, 'Zeta Zetterberg', '19700303123');
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 1, 'share_to' => 250, 'to_holder_id' => $zeta->id, 'capital_change_ore' => 1_250_000]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 251, 'share_to' => 500, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 1_250_000]);

    expect(array_column(registerRows(), 2))->toBe(['Zeta Zetterberg', 'Anna Andersson']);
});

it('requires a class when the company has several, and counts votes per class (AB-R3, AB-R30)', function () {
    $a = ShareClass::query()->create(['code' => 'A', 'name' => 'A-aktier', 'votes_tenths' => 100]);
    $b = ShareClass::query()->create(['code' => 'B', 'name' => 'B-aktier', 'votes_tenths' => 10]);

    refused(fn () => Boardroom::formation($this->owner, $this->company, $this->a, 100), 'flera aktieslag');

    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 1, 'share_to' => 100, 'share_class_id' => $a->id, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 1_000_000]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 101, 'share_to' => 150, 'share_class_id' => $b->id, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 500_000]);

    expect(ShareRegister::at()->votesByHolder([$a->id => 100, $b->id => 10]))->toBe([$this->a->id => 10_500]);
});

it('converts shares to another class only from registration', function () {
    $a = ShareClass::query()->create(['code' => 'A', 'name' => 'A-aktier', 'votes_tenths' => 100]);
    $b = ShareClass::query()->create(['code' => 'B', 'name' => 'B-aktier', 'votes_tenths' => 10]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 1, 'share_to' => 100, 'share_class_id' => $a->id, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 1_000_000]);
    Boardroom::record($this->owner, $this->company, ['kind' => 'CONVERSION', 'registered_on' => '2027-06-01', 'share_from' => 51, 'share_to' => 100, 'share_class_id' => $b->id]);

    expect(array_map(fn (Holding $holding): ?int => $holding->classId, ShareRegister::at(CarbonImmutable::parse('2027-05-31'))->rows()))->toBe([$a->id])
        ->and(array_map(fn (Holding $holding): ?int => $holding->classId, ShareRegister::at(CarbonImmutable::parse('2027-06-01'))->rows()))->toBe([$a->id, $b->id]);
});

it('strikes redeemed shares and lowers the capital (20 kap. 21 §)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    Boardroom::record($this->owner, $this->company, ['kind' => 'REDEMPTION', 'effective_date' => '2027-09-01', 'share_from' => 901, 'share_to' => 1000, 'from_holder_id' => $this->a->id, 'capital_change_ore' => -250_000]);

    expect(registerRows())->toBe([[1, 900, 'Anna Andersson']])
        ->and(ShareRegister::at()->capitalOre)->toBe(2_250_000);
});

it('is a RegisterState with the date it was folded for', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);

    expect(ShareRegister::at(CarbonImmutable::parse('2027-01-01')))->toBeInstanceOf(RegisterState::class)
        ->and(ShareRegister::at(CarbonImmutable::parse('2027-01-01'))->date?->toDateString())->toBe('2027-01-01');
});
