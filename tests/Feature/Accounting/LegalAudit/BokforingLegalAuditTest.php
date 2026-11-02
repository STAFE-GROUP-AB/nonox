<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\ExportSie;
use App\Actions\Accounting\LockPeriod;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\RemoveAttachment;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Domain\Accounting\Reports\ReportTable;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Support\Sie\Cp437;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * Legal audit of the bookkeeping core (AUD-BOK, 2026-11-02).
 *
 * Every expected value below is computed by hand from the scenario, not
 * printed by the code. Sources (read 2026-11-02):
 * - Bokföringslagen (1999:1078), BFL:
 *   https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
 * - BFN:s vägledning Bokföring (BFNAR 2013:2), uppdaterad 2024-09-16:
 *   https://bfn.se/wp-content/uploads/vl13-2-bokforing.pdf
 * - Årsredovisningslagen (1995:1554), ÅRL 2 kap. 4 § första stycket 7:
 *   https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arsredovisningslag-19951554_sfs-1995-1554/
 *
 * Tests that show a deviation are marked ->todo() with the finding id and
 * are not run; production code is not changed by the audit. AUD-BOK-1 is
 * fixed (#41) and its tests run.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @param  list<array<string, mixed>>  $rows */
function audBokPost(User $user, Company $company, string $date, array $rows, string $text = 'Händelse', string $series = 'A'): Voucher
{
    return app(PostVoucher::class)($user, audBokDraft($user, $company, $date, $rows, $text, $series));
}

/** @param  list<array<string, mixed>>  $rows */
function audBokDraft(User $user, Company $company, string $date, array $rows, string $text = 'Händelse', string $series = 'A'): Voucher
{
    return app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => $text, 'series' => $series, 'rows' => $rows]);
}

function audBokOpening(FiscalYear $year, int $number, int $ore): void
{
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', $number)->value('id'), 'amount_ore' => $ore]);
}

/**
 * A small trading company's year 2026, all amounts in öre:
 * IB 1930 Bank 100 000,00 kr, 2081 Aktiekapital -100 000,00 kr.
 * A1 2026-02-10 sale 12 500,00 incl. 25 % VAT: 1510 D 12 500,00 / 3001 K 10 000,00 / 2611 K 2 500,00
 * A2 2026-02-20 goods 5 000,00 + VAT 1 250,00: 4010 D 5 000,00 / 2640 D 1 250,00 / 2440 K 6 250,00
 * A3 2026-03-05 customer pays: 1930 D 12 500,00 / 1510 K 12 500,00
 * A4 2026-03-15 rent 3 000,00 paid: 5010 D 3 000,00 / 1930 K 3 000,00
 *
 * Result by hand: revenue 10 000,00 − goods 5 000,00 − rent 3 000,00 = 2 000,00 kr profit.
 * Bank UB: 100 000,00 + 12 500,00 − 3 000,00 = 109 500,00.
 */
function audBokYear(User $owner, Company $company, FiscalYear $year): array
{
    audBokOpening($year, 1930, 10_000_000);
    audBokOpening($year, 2081, -10_000_000);

    return [
        audBokPost($owner, $company, '2026-02-10', [
            ['account' => 1510, 'debit_ore' => 1_250_000],
            ['account' => 3001, 'credit_ore' => 1_000_000],
            ['account' => 2611, 'credit_ore' => 250_000],
        ], 'Faktura 1001 till Kund AB'),
        audBokPost($owner, $company, '2026-02-20', [
            ['account' => 4010, 'debit_ore' => 500_000],
            ['account' => 2640, 'debit_ore' => 125_000],
            ['account' => 2440, 'credit_ore' => 625_000],
        ], 'Leverantörsfaktura 77 från Grossist AB'),
        audBokPost($owner, $company, '2026-03-05', [
            ['account' => 1930, 'debit_ore' => 1_250_000],
            ['account' => 1510, 'credit_ore' => 1_250_000],
        ], 'Inbetalning faktura 1001'),
        audBokPost($owner, $company, '2026-03-15', [
            ['account' => 5010, 'debit_ore' => 300_000],
            ['account' => 1930, 'credit_ore' => 300_000],
        ], 'Hyra mars'),
    ];
}

function audBokPdf(string $name = 'kvitto.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
}

describe('verifikationsnummer (BFL 5 kap. 6–7 §§, BFNAR 2013:2 punkt 5.9)', function () {
    it('numbers each series without gaps in posting order, not date order, from 1 per fiscal year', function () {
        // Drafts take no number; a draft that is deleted and a posting that fails leave no gap.
        $late = audBokDraft($this->owner, $this->company, '2026-05-01', [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]);
        $early = audBokDraft($this->owner, $this->company, '2026-01-02', [['account' => 1930, 'debit_ore' => 200], ['account' => 3001, 'credit_ore' => 200]]);
        $deleted = audBokDraft($this->owner, $this->company, '2026-02-01', [['account' => 1930, 'debit_ore' => 300], ['account' => 3001, 'credit_ore' => 300]]);
        $unbalanced = audBokDraft($this->owner, $this->company, '2026-02-01', [['account' => 1930, 'debit_ore' => 300], ['account' => 3001, 'credit_ore' => 299]]);

        app(PostVoucher::class)($this->owner, $late);
        app(DeleteDraftVoucher::class)($this->owner, $deleted);
        expect(fn () => app(PostVoucher::class)($this->owner, $unbalanced))->toThrow(ValidationException::class);
        app(PostVoucher::class)($this->owner, $early);
        $supplier = audBokPost($this->owner, $this->company, '2026-03-01', [['account' => 4010, 'debit_ore' => 100], ['account' => 2440, 'credit_ore' => 100]], 'Inköp', 'D');

        expect($late->fresh()->reference())->toBe('A1')
            ->and($early->fresh()->reference())->toBe('A2')
            ->and($supplier->reference())->toBe('D1');

        // A new fiscal year starts the series again at 1 (the series is unbroken per year, punkt 5.9).
        $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $first2027 = audBokPost($this->owner, $this->company, '2027-01-05', [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]);

        expect($first2027->reference())->toBe('A1')
            ->and($first2027->fiscal_year_id)->toBe($next->id)
            ->and(app(LedgerVerifier::class)->verify($this->company))->toMatchArray(['ok' => true, 'vouchers' => 4, 'problems' => []]);
    });

    it('records when the voucher was registered and by whom (BFL 5 kap. 7 §, BFNAR punkt 9.16)', function () {
        [$sale] = audBokYear($this->owner, $this->company, $this->year);

        // The voucher date is the business event; the registration date is when it was posted.
        expect($sale->voucher_date->toDateString())->toBe('2026-02-10')
            ->and($sale->posted_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-10-29 10:00')
            ->and($sale->posted_by)->toBe($this->owner->id)
            ->and($sale->created_at)->not->toBeNull();

        $entry = AuditLog::query()->where('action', 'voucher.posted')->where('subject_id', $sale->id)->sole();

        expect($entry->user_id)->toBe($this->owner->id)
            ->and($entry->payload['reference'])->toBe('A1')
            ->and($entry->payload['voucher_date'])->toBe('2026-02-10')
            ->and($entry->payload['amount_ore'])->toBe(1_250_000);
    });

    it('lets a voucher carry the counterparty (motpart) required by BFL 5 kap. 7 § and BFNAR punkt 5.6', function () {
        $voucher = audBokPost($this->owner, $this->company, '2026-02-10', [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]], 'Kontantförsäljning');

        // A manual voucher with no underlag has no field for the counterparty and no warning is given.
        expect($voucher->attachments()->count())->toBe(0)
            ->and(Schema::hasColumn('vouchers', 'counterparty'))->toBeTrue();
    })->todo('AUD-BOK-6: en manuell verifikation kan bokföras utan motpart och utan underlag, utan varning');
});

describe('varaktighet och rättelse (BFL 5 kap. 5 §, BFNAR punkterna 2.1, 2.17, 2.18, 5.4)', function () {
    it('corrects a posted voucher only with a new correcting voucher that shows when and by whom, keeping the original readable', function () {
        [, $purchase] = audBokYear($this->owner, $this->company, $this->year);
        $bookkeeper = Bookkeeping::member($this->company, 'accountant');

        // The goods were really 4 000,00 + VAT 1 000,00: reverse and re-book.
        $correction = app(CorrectVoucher::class)($bookkeeper, $purchase, 'Fel belopp på fakturan', '2026-03-31', [
            ['account' => 4010, 'debit_ore' => 400_000],
            ['account' => 2640, 'debit_ore' => 100_000],
            ['account' => 2440, 'credit_ore' => 500_000],
        ]);

        $original = $purchase->fresh(['rows.account']);
        $rows = $correction->rows()->with('account')->get()->map(fn ($row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all();

        expect($correction->reference())->toBe('A5')
            ->and($correction->source)->toBe(VoucherSource::Correction)
            ->and($correction->posted_by)->toBe($bookkeeper->id)
            ->and($correction->posted_at)->not->toBeNull()
            ->and($correction->corrects_voucher_id)->toBe($purchase->id)
            ->and($original->corrected_by_voucher_id)->toBe($correction->id)
            ->and($original->status)->toBe(VoucherStatus::Posted)
            ->and($original->rows->map(fn ($row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())->toBe([
                [4010, 500_000, 0], [2640, 125_000, 0], [2440, 0, 625_000],
            ])
            ->and($rows)->toBe([
                [4010, 0, 500_000], [2640, 0, 125_000], [2440, 625_000, 0],
                [4010, 400_000, 0], [2640, 100_000, 0], [2440, 0, 500_000],
            ]);

        // Net effect by hand: 4010 = 5 000,00 − 5 000,00 + 4 000,00 = 4 000,00; 2440 = −5 000,00.
        $balance = collect(app(LedgerReports::class)->trialBalance(LedgerQuery::forYear($this->year))['rows'])->keyBy('number');

        expect($balance[4010]['closing'])->toBe(400_000)
            ->and($balance[2640]['closing'])->toBe(100_000)
            ->and($balance[2440]['closing'])->toBe(-500_000)
            ->and(AuditLog::query()->where('action', 'voucher.corrected')->sole()->payload)->toMatchArray(['reference' => 'A2', 'corrected_by' => 'A5', 'reason' => 'Fel belopp på fakturan']);
    });

    it('keeps a closed period closed: no posting and no correction dated in a locked period (BFNAR punkt 2.18 kommentar)', function () {
        [$sale] = audBokYear($this->owner, $this->company, $this->year);
        app(LockPeriod::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-31'));

        expect(fn () => audBokPost($this->owner, $this->company, '2026-03-31', [['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 100]]))
            ->toThrow(ValidationException::class, 'Perioden till och med 2026-03-31 är låst')
            ->and(fn () => app(CorrectVoucher::class)($this->owner, $sale, 'Fel konto', '2026-03-31'))
            ->toThrow(ValidationException::class, 'Perioden till och med 2026-03-31 är låst')
            ->and(fn () => app(LockPeriod::class)($this->owner, $this->company->fresh(), CarbonImmutable::parse('2026-02-28')))
            ->toThrow(ValidationException::class);

        // The correction goes into the open period where the error was found.
        $correction = app(CorrectVoucher::class)($this->owner, $sale, 'Fel konto', '2026-04-01');

        expect($correction->voucher_date->toDateString())->toBe('2026-04-01');
    });

    it('never removes underlag from a posted voucher, but allows adding a late receipt (BFNAR punkt 5.4)', function () {
        $voucher = audBokPost($this->owner, $this->company, '2026-02-10', [['account' => 5010, 'debit_ore' => 100], ['account' => 1930, 'credit_ore' => 100]], 'Hyra');
        $attachment = app(AttachToVoucher::class)($this->owner, $voucher, audBokPdf());

        expect(fn () => app(RemoveAttachment::class)($this->owner, $attachment))->toThrow(Exception::class)
            ->and(VoucherAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue()
            ->and(Storage::disk('local')->exists($attachment->file_path))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'voucher.attachment_added')->sole()->payload['voucher_posted'])->toBeTrue();
    });

    it('keeps the inbox underlag of a posted voucher when a sibling draft from the same mail is deleted (BFL 7 kap. 2 §)', function () {
        app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
        Inbox::allow($this->company, 'export@kassaexport.example');
        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));

        // Two drafts from one mail; both link the same stored .eml.
        $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT);
        [$first, $second] = Voucher::query()->whereIn('id', $approved->voucher_ids)->orderBy('created_at')->get()->all();

        app(PostVoucher::class)($this->owner, $first);
        app(DeleteDraftVoucher::class)($this->owner, $second);

        $eml = VoucherAttachment::query()->where('voucher_id', $first->id)->where('mime', 'message/rfc822')->sole();

        expect(Storage::disk('local')->exists($eml->file_path))->toBeTrue()
            ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([]);
    });

    it('keeps the inbox original when a draft made from it is deleted (BFL 5 kap. 6 §, 7 kap. 2 §)', function () {
        app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
        Inbox::allow($this->company, 'export@kassaexport.example');
        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));

        $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT);

        foreach (Voucher::query()->whereIn('id', $approved->voucher_ids)->get() as $draft) {
            app(DeleteDraftVoucher::class)($this->owner, $draft);
        }

        expect(Storage::disk('local')->exists($approved->fresh()->eml_path))->toBeTrue();
    });

    it('keeps the inbox original when its attachment is removed from a draft (BFL 7 kap. 1 § tredje stycket)', function () {
        app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
        Inbox::allow($this->company, 'export@kassaexport.example');
        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('sie.eml')));

        $approved = app(ApproveInboxMessage::class)($this->owner, $message, $message->proposal, ApproveInboxMessage::DRAFT);
        $draft = Voucher::query()->whereIn('id', $approved->voucher_ids)->orderBy('created_at')->firstOrFail();
        $eml = VoucherAttachment::query()->where('voucher_id', $draft->id)->where('mime', 'message/rfc822')->sole();

        app(RemoveAttachment::class)($this->owner, $eml);

        expect(VoucherAttachment::query()->whereKey($eml->id)->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($approved->eml_path))->toBeTrue();
    });
});

describe('rapporter stämmer mot huvudboken (BFL 5 kap. 1 §, BFNAR punkterna 2.2–2.3)', function () {
    it('makes a trial balance, income statement and balance sheet that reconcile, by hand', function () {
        audBokYear($this->owner, $this->company, $this->year);
        $query = LedgerQuery::forYear($this->year);
        $reports = app(LedgerReports::class);

        $trial = $reports->trialBalance($query);
        $byNumber = collect($trial['rows'])->keyBy('number');

        // Movements: 12 500 + 5 000 + 1 250 + 12 500 + 3 000 = 34 250,00 kr each side.
        expect($trial['totals'])->toBe(['opening' => 0, 'debit' => 3_425_000, 'credit' => 3_425_000, 'closing' => 0])
            ->and($byNumber[1930]['closing'])->toBe(10_950_000)
            ->and($byNumber[1510]['closing'])->toBe(0)
            ->and($byNumber[2611]['closing'])->toBe(-250_000)
            ->and($byNumber[2640]['closing'])->toBe(125_000)
            ->and($byNumber[2440]['closing'])->toBe(-625_000);

        $income = $reports->incomeStatement($query);
        $groups = collect($income['groups'])->keyBy('key');

        expect($income['result'])->toBe(200_000)
            ->and($groups['revenue']['total'])->toBe(1_000_000)
            ->and($groups['goods']['total'])->toBe(-500_000)
            ->and($groups['external']['total'])->toBe(-300_000)
            ->and($groups['other_operating']['subtotal']['amount'])->toBe(200_000);

        // Assets (class 1): bank 109 500,00. Input VAT 2640 is a class 2 account (BAS) and nets
        // against the liabilities: 100 000,00 + 6 250,00 + 2 500,00 − 1 250,00 = 107 500,00,
        // plus the unbooked result 2 000,00 = 109 500,00.
        $balance = $reports->balanceSheet($query);

        expect($balance['sides']['assets']['closing'])->toBe(10_950_000)
            ->and($balance['unbooked_result']['closing'])->toBe(200_000)
            ->and($balance['sides']['equity']['closing'])->toBe(10_950_000);

        // Every account's general ledger ends at its trial balance closing; the voucher list sums the movements.
        foreach ($reports->generalLedger($query) as $account) {
            expect($account['closing'])->toBe($byNumber[$account['number']]['closing']);
        }

        $list = $reports->voucherList($query);

        expect($list->map->reference()->all())->toBe(['A1', 'A2', 'A3', 'A4'])
            ->and($list->sum(fn (Voucher $voucher): int => $voucher->rows->sum(fn ($row): int => $row->debit_ore->ore)))->toBe(3_425_000)
            ->and($list->sum(fn (Voucher $voucher): int => $voucher->rows->sum(fn ($row): int => $row->credit_ore->ore)))->toBe(3_425_000);
    });

    it('opens a period with everything posted before it and keeps drafts out of every report', function () {
        audBokYear($this->owner, $this->company, $this->year);
        audBokDraft($this->owner, $this->company, '2026-03-20', [['account' => 1930, 'debit_ore' => 999_999], ['account' => 3001, 'credit_ore' => 999_999]], 'Utkast');

        $march = new LedgerQuery($this->year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
        $trial = collect(app(LedgerReports::class)->trialBalance($march)['rows'])->keyBy('number');

        // 1930 at 1 March: IB 100 000,00 and nothing in Jan–Feb. March: +12 500,00 −3 000,00.
        expect($trial[1930])->toMatchArray(['opening' => 10_000_000, 'debit' => 1_250_000, 'credit' => 300_000, 'closing' => 10_950_000])
            ->and($trial[1510])->toMatchArray(['opening' => 1_250_000, 'credit' => 1_250_000, 'closing' => 0])
            ->and($trial[3001]['opening'])->toBe(-1_000_000)
            ->and($trial[3001]['debit'] + $trial[3001]['credit'])->toBe(0);
    });

    it('shows the voucher number on every general ledger line, so registration order shows per account (BFNAR punkt 2.2)', function () {
        audBokYear($this->owner, $this->company, $this->year);

        $bank = app(LedgerReports::class)->generalLedger(LedgerQuery::forYear($this->year), 1930)[0];

        expect($bank['opening'])->toBe(10_000_000)
            ->and(array_column($bank['rows'], 'reference'))->toBe(['A3', 'A4'])
            ->and(array_column($bank['rows'], 'balance'))->toBe([11_250_000, 10_950_000]);
    });

    it('shows on the corrected voucher in the archived verifikationslista that it was corrected (BFL 5 kap. 5 §)', function () {
        [, $purchase] = audBokYear($this->owner, $this->company, $this->year);
        app(CorrectVoucher::class)($this->owner, $purchase, 'Fel belopp', '2026-03-31');

        $rows = collect(app(ReportTable::class)->rows('verifikationer', LedgerQuery::forYear($this->year)));
        $original = $rows->filter(fn (array $row): bool => $row[0] === 'A2');

        // The archive package must be readable without NONOX; the original's lines must point to A5.
        expect($original->flatten()->implode(' '))->toContain('A5');
    });

    it('shows the year\'s result in the income statement also after the result is booked to equity (8999/2099)', function () {
        audBokYear($this->owner, $this->company, $this->year);
        audBokPost($this->owner, $this->company, '2026-12-31', [['account' => 8999, 'debit_ore' => 200_000], ['account' => 2099, 'credit_ore' => 200_000]], 'Årets resultat');

        $income = app(LedgerReports::class)->incomeStatement(LedgerQuery::forYear($this->year));

        expect($income['result'])->toBe(200_000);
    })->todo('AUD-BOK-7: efter bokningen 8999/2099 visar resultatrapporten 0 kr som beräknat resultat');
});

describe('årsskifte och ingående balanser (ÅRL 2 kap. 4 § första stycket 7)', function () {
    it('carries the closing balances of balance-sheet accounts into the next year and an unbooked result to 2099', function () {
        audBokYear($this->owner, $this->company, $this->year);

        $next = app(CarryForwardBalances::class)($this->owner, $this->year);
        $ib = OpeningBalance::query()->where('fiscal_year_id', $next->id)->with('account')->get()
            ->mapWithKeys(fn (OpeningBalance $balance): array => [$balance->account->number => $balance->amount_ore->ore])->sortKeys()->all();

        // By hand: 1930 109 500,00; 2640 1 250,00; 2081 −100 000,00; 2440 −6 250,00; 2611 −2 500,00; 2099 −2 000,00.
        expect($next->start_date->toDateString())->toBe('2027-01-01')
            ->and($next->end_date->toDateString())->toBe('2027-12-31')
            ->and($ib)->toBe([1930 => 10_950_000, 2081 => -10_000_000, 2099 => -200_000, 2440 => -625_000, 2611 => -250_000, 2640 => 125_000])
            ->and(array_sum($ib))->toBe(0);
    });

    it('carries an unbooked result to 2019 for a sole trader', function () {
        $this->company->forceFill(['legal_form' => 'EF'])->save();
        audBokYear($this->owner, $this->company, $this->year);

        $next = app(CarryForwardBalances::class)($this->owner, $this->year);
        $resultAccount = OpeningBalance::query()->where('fiscal_year_id', $next->id)->whereHas('account', fn ($query) => $query->where('number', 2019))->sole();

        expect($resultAccount->amount_ore->ore)->toBe(-200_000);
    });

    it('keeps the next year\'s opening balances equal to this year\'s closing balances after a late voucher', function () {
        audBokYear($this->owner, $this->company, $this->year);
        $next = app(CarryForwardBalances::class)($this->owner, $this->year);

        // A late bank fee in the old year: 1930 UB becomes 109 400,00.
        audBokPost($this->owner, $this->company, '2026-12-31', [['account' => 6570, 'debit_ore' => 10_000], ['account' => 1930, 'credit_ore' => 10_000]], 'Bankavgift december');

        $closing2026 = collect(app(LedgerReports::class)->trialBalance(LedgerQuery::forYear($this->year))['rows'])->keyBy('number')[1930]['closing'];
        $opening2027 = (int) OpeningBalance::query()->where('fiscal_year_id', $next->id)->whereHas('account', fn ($query) => $query->where('number', 1930))->sole()->amount_ore->ore;

        expect($closing2026)->toBe(10_940_000)
            ->and($opening2027)->toBe($closing2026);
    })->todo('AUD-BOK-4: ingående balanser blir inaktuella när det gamla året ändras efter överföringen, utan varning');
});

describe('SIE 4 (SIE-gruppens filformat 4C, BFL 7 kap. 1 §)', function () {
    it('exports balances, results and vouchers with the hand-computed amounts and the registration date', function () {
        audBokYear($this->owner, $this->company, $this->year);

        $stream = fopen('php://memory', 'w+b');
        app(ExportSie::class)($this->owner, $this->year, $stream);
        rewind($stream);
        $text = Cp437::decode((string) stream_get_contents($stream));
        fclose($stream);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n/', $text) ?: [])));

        expect($lines)->toContain('#IB 0 1930 100000.00')
            ->toContain('#IB 0 2081 -100000.00')
            ->toContain('#UB 0 1930 109500.00')
            ->toContain('#UB 0 2440 -6250.00')
            ->toContain('#RES 0 3001 -10000.00')
            ->toContain('#RES 0 4010 5000.00')
            ->toContain('#RES 0 5010 3000.00')
            ->toContain('#TRANS 2611 {} -2500.00')
            ->and(collect($lines)->first(fn (string $line): bool => str_starts_with($line, '#VER A 1 ')))->toStartWith('#VER A 1 20260210 "Faktura 1001 till Kund AB" 20261029')
            ->and(collect($lines)->last())->toStartWith('#KSUMMA ');
    });
});

describe('bevarande i sju år (BFL 7 kap. 2 §) och behandlingshistorik (BFNAR punkt 9.16)', function () {
    it('cannot delete a company, a voucher or the audit trail while bookkeeping exists', function () {
        audBokYear($this->owner, $this->company, $this->year);
        $refused = function (Closure $statement): bool {
            try {
                DB::transaction($statement);
            } catch (Throwable) {
                return true;
            }

            return false;
        };

        expect($refused(fn () => DB::delete('delete from teams where id = ?', [$this->company->id])))->toBeTrue()
            ->and($refused(fn () => DB::delete('delete from audit_logs where company_id = ?', [$this->company->id])))->toBeTrue()
            ->and($refused(fn () => DB::update('update audit_logs set action = ? where company_id = ?', ['x', $this->company->id])))->toBeTrue()
            ->and(Voucher::query()->where('status', VoucherStatus::Posted->value)->count())->toBe(4);
    });

    it('shows per fiscal year the date from which its räkenskapsinformation may be destroyed', function () {
        // A broken fiscal year 2025-07-01–2026-06-30 ends in calendar year 2026: keep through 2033-12-31.
        $year = FiscalYear::create(['start_date' => '2025-07-01', 'end_date' => '2026-06-30']);

        expect($year->retainedUntil()->toDateString())->toBe('2033-12-31');
    })->todo('AUD-BOK-5: inget "får förstöras från"-datum per räkenskapsår (AGENTS.md §10); inget raderas, så ingen laglig risk i dag');

    it('logs in the audit trail when a new program version is taken into use (BFNAR punkt 9.16 andra stycket)', function () {
        expect(AuditLog::query()->where('action', 'like', 'system.%')->exists())->toBeTrue();
    })->todo('AUD-BOK-8: nya programversioner skrivs inte i behandlingshistoriken med datum');

    it('logs a new account in the audit trail (BFNAR punkt 9.16 andra stycket)', function () {
        app(CreateAccount::class)($this->owner, $this->company, ['number' => 6993, 'name' => 'Lämnade bidrag och gåvor']);

        expect(AuditLog::query()->where('action', 'account.created')->latest('id')->first()?->payload)->toMatchArray(['number' => 6993]);
    });

    it('keeps the systemdokumentation header at the latest version in its own change table', function () {
        $doc = (string) file_get_contents(base_path('docs/systemdokumentation/README.md'));
        preg_match('/^- Version: ([0-9.]+)$/m', $doc, $header);
        preg_match_all('/^\| (0\.\d+) \| (\d{4}-\d{2}-\d{2}) \|/m', $doc, $table);
        $latest = collect($table[1])->sortBy(fn (string $version): int => (int) explode('.', $version)[1])->last();

        expect($header[1] ?? null)->toBe($latest);
    })->todo('AUD-BOK-9: systemdokumentationen säger "Version: 0.15" men tabellen går till 0.43 och raderna ligger i fel ordning');
});
