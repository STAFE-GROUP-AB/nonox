<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Modules\Boardroom\Actions\MinutesWorkflow;
use App\Modules\Boardroom\Actions\RecordShareEvent;
use App\Modules\Boardroom\Actions\SaveArticles;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Actions\SaveShareholder;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\Boardroom\Domain\AgendaItemKind;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\ModuleManager;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;

/**
 * Aktiebok och styrelserum (ADR-070) in the consultancy, for the manual's
 * aktiebok pages: two made-up shareholders, the formation in 2019 with
 * 20 000 kr share capital, a new issue in 2023 that brings it to the
 * 25 000 kr on account 2081, a transfer this summer, the articles of
 * association, an extra general meeting and a board meeting with approved
 * minutes, and next year's annual general meeting planned with its agenda.
 *
 * Only where the installation has a key for the register's hash chain
 * (NONOX_REGISTER_HASH_KEY), and never in a visitor's demo.
 */
final class BoardroomExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase()) {
            return;
        }

        if (! is_string(config('nonox.register_hash_key')) || config('nonox.register_hash_key') === '') {
            $c->warn('Aktieboken hoppas över: NONOX_REGISTER_HASH_KEY saknas.');

            return;
        }

        $this->atomically($c, 'Aktieboken', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            app(ModuleManager::class)->enable(BoardroomModule::KEY, $c->company, $c->user);
            $this->articles($c);
            [$anna, $johan] = $this->holders($c);
            $this->register($c, $anna, $johan);
            $this->meetings($c, $anna, $johan);
        }));
    }

    private function articles(DemoContext $c): void
    {
        app(SaveArticles::class)->adopt($c->user, $c->company, [
            'adopted_on' => '2019-03-20',
            'registered_on' => '2019-04-02',
            'seat' => 'Stockholm',
            'notice_method' => 'Kallelse till bolagsstämma sker genom e-post till aktieägarna.',
            'short_notice_clause' => true,
            'share_capital_min' => '20 000',
            'share_capital_max' => '80 000',
            'shares_min' => 800,
            'shares_max' => 3200,
            'board_min' => 1,
            'board_max' => 3,
            'has_auditor' => false,
            'pre_emption_clause' => true,
            'consent_clause' => false,
        ]);
    }

    /** @return array{0: Shareholder, 1: Shareholder} */
    private function holders(DemoContext $c): array
    {
        $save = app(SaveShareholder::class);
        $anna = $save($c->user, $c->company, [
            'kind' => 'PERSON',
            'name' => 'Anna Andersson',
            'personal_number' => DemoIndustries::personalNumber('19840229', '910'),
            'address_line1' => 'Exempelvägen 4',
            'postal_code' => '111 22',
            'city' => 'Stockholm',
        ]);
        $johan = $save($c->user, $c->company, [
            'kind' => 'PERSON',
            'name' => 'Johan Exempelsson',
            'personal_number' => DemoIndustries::personalNumber('19790612', '931'),
            'address_line1' => 'Provgatan 12',
            'postal_code' => '113 30',
            'city' => 'Stockholm',
        ]);

        return [$anna, $johan];
    }

    /** Formation, a new issue that is registered, and a transfer: 25 000 kr in 1 000 shares, as on account 2081. */
    private function register(DemoContext $c, Shareholder $anna, Shareholder $johan): void
    {
        $record = app(RecordShareEvent::class);

        $record($c->user, $c->company, [
            'kind' => 'FORMATION', 'effective_date' => '2019-03-20', 'share_from' => 1, 'share_to' => 800,
            'to_holder_id' => $anna->id, 'capital_change_ore' => 2_000_000, 'document_reference' => 'Stiftelseurkund 2019-03-20',
        ]);
        $record($c->user, $c->company, [
            'kind' => 'NEW_ISSUE', 'decided_on' => '2023-03-15', 'effective_date' => '2023-04-03', 'registered_on' => '2023-04-20',
            'share_from' => 801, 'share_to' => 1000, 'to_holder_id' => $johan->id, 'capital_change_ore' => 500_000,
            'consideration_ore' => 6_000_000, 'document_reference' => 'Stämmoprotokoll 2023-03-15',
        ]);
        $record($c->user, $c->company, [
            'kind' => 'TRANSFER', 'effective_date' => '2026-06-15', 'share_from' => 701, 'share_to' => 800,
            'from_holder_id' => $anna->id, 'to_holder_id' => $johan->id, 'consideration_ore' => 4_000_000,
            'document_reference' => 'Köpeavtal 2026-06-15',
        ]);
    }

    private function meetings(DemoContext $c, Shareholder $anna, Shareholder $johan): void
    {
        $save = app(SaveMeeting::class);
        $minutes = app(MinutesWorkflow::class);

        // An extra general meeting in June that elected Johan to the board; Anna and Johan hold every share, so no adjuster.
        $extra = $save($c->user, $c->company, [
            'kind' => 'EXTRA_GM', 'title' => 'Extra bolagsstämma', 'meeting_date' => '2026-06-10', 'start_time' => '09:00',
            'place' => 'Bolagets kontor, Stockholm', 'notice_sent_on' => '2026-05-25', 'chair_name' => 'Anna Andersson', 'minute_taker_name' => 'Johan Exempelsson',
            'attendees' => [
                ['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $anna->id],
                ['name' => 'Johan Exempelsson', 'role' => 'shareholder', 'shareholder_id' => $johan->id],
            ],
        ]);
        $election = $save->saveItem($c->user, $c->company, $extra, ['kind' => 'ELECTION_BOARD', 'title' => 'Val av styrelseledamot', 'proposal' => 'Johan Exempelsson väljs till styrelseledamot för tiden intill slutet av nästa årsstämma.']);
        $draft = $minutes->start($c->user, $c->company, $extra);
        $minutes->update($c->user, $c->company, $draft, [
            'decisions' => [$election->position => 'Stämman valde Johan Exempelsson till styrelseledamot för tiden intill slutet av nästa årsstämma.'],
            'notes' => 'Stämman förklarades avslutad klockan 09.40.',
        ]);
        $minutes->check($c->user, $c->company, $draft->fresh() ?? $draft, 'Anna Andersson', 'Johan Exempelsson', []);
        $minutes->approve($c->user, $c->company, $draft->fresh() ?? $draft);
        $save->recordReported($c->user, $c->company, $election->fresh() ?? $election, '2026-06-18');

        // A board meeting in October: two members, so Johan adjusts Anna's minutes.
        $board = $save($c->user, $c->company, [
            'kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => $c->today->subWeeks(3)->toDateString(), 'start_time' => '16:00',
            'place' => 'Digitalt', 'digital' => true, 'chair_name' => 'Anna Andersson', 'minute_taker_name' => 'Anna Andersson', 'adjusters' => ['Johan Exempelsson'],
            'attendees' => [
                ['name' => 'Anna Andersson', 'role' => 'member'],
                ['name' => 'Johan Exempelsson', 'role' => 'member'],
            ],
        ]);
        $report = $save->saveItem($c->user, $c->company, $board, ['kind' => 'OTHER', 'title' => 'Rapport för tredje kvartalet', 'proposal' => 'VD redogör för omsättning och likviditet.']);
        $budget = $save->saveItem($c->user, $c->company, $board, ['kind' => 'OTHER', 'title' => 'Budget för nästa år', 'proposal' => 'Förslag till budget med två nya kunduppdrag.']);
        $draft = $minutes->start($c->user, $c->company, $board);
        $minutes->update($c->user, $c->company, $draft, [
            'decisions' => [
                $report->position => 'Styrelsen lade rapporten till handlingarna.',
                $budget->position => 'Styrelsen fastställde budgeten enligt förslaget.',
            ],
        ]);
        $minutes->check($c->user, $c->company, $draft->fresh() ?? $draft, 'Anna Andersson', 'Anna Andersson', ['Johan Exempelsson']);
        $minutes->approve($c->user, $c->company, $draft->fresh() ?? $draft);

        // Next spring's annual general meeting for this year, planned with the agenda from the closing.
        $agm = $save($c->user, $c->company, [
            'kind' => 'AGM', 'title' => 'Årsstämma', 'meeting_date' => CarbonImmutable::parse($c->fiscalYear->end_date->toDateString())->addMonthsNoOverflow(5)->day(12)->toDateString(), 'start_time' => '10:00',
            'place' => 'Bolagets kontor, Stockholm', 'fiscal_year_id' => $c->fiscalYear->id, 'chair_name' => 'Anna Andersson', 'minute_taker_name' => 'Johan Exempelsson',
            'attendees' => [
                ['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $anna->id],
                ['name' => 'Johan Exempelsson', 'role' => 'shareholder', 'shareholder_id' => $johan->id],
            ],
        ]);
        $save->saveItem($c->user, $c->company, $agm, ['kind' => AgendaItemKind::Fees->value, 'title' => 'Arvode till styrelsen', 'proposal' => 'Inget arvode utgår.']);
        $save->saveItem($c->user, $c->company, $agm, ['kind' => AgendaItemKind::ElectionBoard->value, 'title' => 'Val av styrelse', 'proposal' => 'Omval av Anna Andersson och Johan Exempelsson.']);
    }
}
