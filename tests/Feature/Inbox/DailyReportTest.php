<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\ManageInbox;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\Interpreter;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\DailyReportVoucher;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\Inbox;

/*
 * Acceptance (spec "Nonox Inbox"): Z-reports from five cash registers become
 * balanced drafts with the right VAT per rate, NONOX AI is asked the first
 * time, and the second report from the same register is read with the
 * template without any AI call.
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Inbox::company();
    Inbox::enableAi($this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

/** The voucher every one of the five reports describes, by account: [debit öre, credit öre]. */
const Z_EXPECTED = [
    1680 => [1_240_000, 0], 1930 => [300_000, 0], 1910 => [190_000, 0],
    2890 => [6_000, 20_000], 3001 => [0, 800_000], 2611 => [0, 200_000], 3002 => [0, 500_000], 2621 => [0, 60_000],
    3003 => [0, 100_000], 2631 => [0, 6_000], 3004 => [0, 49_960], 3740 => [0, 40],
];

const Z_VAT = [0 => ['net' => 49_960, 'vat' => 0], 6 => ['net' => 100_000, 'vat' => 6_000], 12 => ['net' => 500_000, 'vat' => 60_000], 25 => ['net' => 800_000, 'vat' => 200_000]];

/**
 * What NONOX AI answers for a report: one entry per key.
 *
 * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: string}>  $rows  key, role, VAT rate (-1 none), account, side
 */
function zAnswer(array $rows): array
{
    return [
        'is_daily_report' => true,
        'date' => '2026-10-30',
        'notes' => '',
        'fields' => array_map(fn (array $row): array => [
            'key' => $row[0], 'value' => 0, 'role' => $row[1], 'vat_rate' => $row[2], 'account' => $row[3], 'side' => $row[4],
            'reason' => 'Enligt rubriken på rapporten.', 'confidence' => 0.9,
        ], $rows),
    ];
}

/** @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}> */
function zPayments(string $prefix, string $suffix, array $names): array
{
    return [
        ["{$prefix}{$names[0]}{$suffix}", 'PAYMENT_CARD', -1, 1680, 'D'],
        ["{$prefix}{$names[1]}{$suffix}", 'PAYMENT_SWISH', -1, 1930, 'D'],
        ["{$prefix}{$names[2]}{$suffix}", 'PAYMENT_CASH', -1, 1910, 'D'],
        ["{$prefix}{$names[3]}{$suffix}", 'PAYMENT_GIFT_CARD', -1, 2890, 'D'],
        ["{$prefix}{$names[4]}{$suffix}", 'TIPS', -1, 2890, 'K'],
        ["{$prefix}{$names[5]}{$suffix}", 'ROUNDING', -1, 3740, 'K'],
    ];
}

/**
 * The five cash register formats: the .eml fixture, the sender, the AI's
 * answer for it, and how to make the next day's report from it.
 */
function zFormats(): array
{
    $sv = ['kort', 'swish', 'kontant', 'presentkort', 'dricks'];

    return [
        'semicolon CSV, label;amount' => ['z-kassanova.eml', 'rapport@kassanova.example', zAnswer([
            ['försäljning 25% netto | belopp', 'SALES', 25, 3001, 'K'], ['moms 25% | belopp', 'OUTPUT_VAT', 25, 2611, 'K'],
            ['försäljning 12% netto | belopp', 'SALES', 12, 3002, 'K'], ['moms 12% | belopp', 'OUTPUT_VAT', 12, 2621, 'K'],
            ['försäljning 6% netto | belopp', 'SALES', 6, 3003, 'K'], ['moms 6% | belopp', 'OUTPUT_VAT', 6, 2631, 'K'],
            ['momsfri försäljning | belopp', 'SALES', 0, 3004, 'K'], ['summa brutto | belopp', 'IGNORE', -1, 0, 'K'], ['antal kvitton | belopp', 'IGNORE', -1, 0, 'K'],
            ...zPayments('', ' | belopp', [...$sv, 'öresavrundning']),
        ]), 'kassanova-z.csv', 'text/csv'],
        'comma CSV, one row with a column per amount' => ['z-bongia.eml', 'export@bongia.example', zAnswer([
            ['k1 | net_25', 'SALES', 25, 3001, 'K'], ['k1 | vat_25', 'OUTPUT_VAT', 25, 2611, 'K'],
            ['k1 | net_12', 'SALES', 12, 3002, 'K'], ['k1 | vat_12', 'OUTPUT_VAT', 12, 2621, 'K'],
            ['k1 | net_6', 'SALES', 6, 3003, 'K'], ['k1 | vat_6', 'OUTPUT_VAT', 6, 2631, 'K'],
            ['k1 | sales_0', 'SALES', 0, 3004, 'K'], ['k1 | receipts', 'IGNORE', -1, 0, 'K'],
            ...zPayments('k1 | ', '', ['card', 'swish', 'cash', 'giftcard', 'tips', 'rounding']),
        ]), 'bongia-export.csv', 'text/csv'],
        'tab-separated Windows-1252 text, a row per VAT rate' => ['z-snabbkassan.eml', 'dagsrapport@snabbkassan.example', zAnswer([
            ['25 % | netto', 'SALES', 25, 3001, 'K'], ['25 % | moms', 'OUTPUT_VAT', 25, 2611, 'K'], ['25 % | brutto', 'IGNORE', -1, 0, 'K'],
            ['12 % | netto', 'SALES', 12, 3002, 'K'], ['12 % | moms', 'OUTPUT_VAT', 12, 2621, 'K'], ['12 % | brutto', 'IGNORE', -1, 0, 'K'],
            ['6 % | netto', 'SALES', 6, 3003, 'K'], ['6 % | moms', 'OUTPUT_VAT', 6, 2631, 'K'], ['6 % | brutto', 'IGNORE', -1, 0, 'K'],
            ['0 % | netto', 'SALES', 0, 3004, 'K'], ['0 % | moms', 'IGNORE', -1, 0, 'K'], ['0 % | brutto', 'IGNORE', -1, 0, 'K'],
            ...zPayments('', ' | belopp', [...$sv, 'avrundning']),
        ]), 'snabbkassan-dag.txt', 'text/plain'],
        'XLSX with sales including VAT' => ['z-kassaboken.eml', 'noreply@kassaboken.example', zAnswer([
            ['kassa 1 | försäljning 25%', 'SALES_INCL_VAT', 25, 3001, 'K'], ['kassa 1 | moms 25%', 'OUTPUT_VAT', 25, 2611, 'K'],
            ['kassa 1 | försäljning 12%', 'SALES_INCL_VAT', 12, 3002, 'K'], ['kassa 1 | moms 12%', 'OUTPUT_VAT', 12, 2621, 'K'],
            ['kassa 1 | försäljning 6%', 'SALES_INCL_VAT', 6, 3003, 'K'], ['kassa 1 | moms 6%', 'OUTPUT_VAT', 6, 2631, 'K'],
            ['kassa 1 | momsfritt', 'SALES', 0, 3004, 'K'],
            ...zPayments('kassa 1 | ', '', [...$sv, 'öresavrundning']),
        ]), 'kassaboken.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'plain text printout in the mail body' => ['z-textrapport.eml', 'kassa@cafe-exempel.example', zAnswer([
            ['försäljning 25%', 'SALES_INCL_VAT', 25, 3001, 'K'], ['varav moms 25%', 'OUTPUT_VAT', 25, 2611, 'K'],
            ['försäljning 12%', 'SALES_INCL_VAT', 12, 3002, 'K'], ['varav moms 12%', 'OUTPUT_VAT', 12, 2621, 'K'],
            ['försäljning 6%', 'SALES_INCL_VAT', 6, 3003, 'K'], ['varav moms 6%', 'OUTPUT_VAT', 6, 2631, 'K'],
            ['försäljning 0%', 'SALES', 0, 3004, 'K'], ['totalt', 'IGNORE', -1, 0, 'K'],
            ...zPayments('', '', [...$sv, 'öresavrundning']),
        ]), null, null],
    ];
}

/**
 * The next day's report from the same register: the same layout with
 * another date, so its bytes differ.
 */
function nextDay(string $from, ?string $filename, ?string $mime): string
{
    if ($filename === null) {
        return Inbox::mail($from, 'Z-rapport 0413', str_replace('30.10.2026', '31.10.2026', Inbox::fixture('textrapport.txt')));
    }

    $contents = Inbox::fixture($filename);

    if (str_ends_with($filename, '.xlsx')) {
        $path = tempnam(sys_get_temp_dir(), 'nonox-test-xlsx');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/sharedStrings.xml', str_replace('2026-10-30', '2026-10-31', (string) $zip->getFromName('xl/sharedStrings.xml')));
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);
    } else {
        $contents = str_replace('2026-10-30', '2026-10-31', $contents);
    }

    return Inbox::mail($from, 'Z-rapport', 'Dagens rapport.', [[$filename, $contents, (string) $mime]]);
}

/** @return array<int, array{0: int, 1: int}> account => [debit, credit] */
function summed(array $rows): array
{
    $sums = [];

    foreach ($rows as $row) {
        $account = (int) $row['account'];
        $sums[$account] = [($sums[$account][0] ?? 0) + (int) $row['debit_ore'], ($sums[$account][1] ?? 0) + (int) $row['credit_ore']];
    }

    ksort($sums);

    return $sums;
}

it('turns a Z-report into a balanced draft with the VAT per rate, then reads the next one with the template and no AI', function (string $eml, string $from, array $answer, ?string $filename, ?string $mime) {
    Inbox::allow($this->company, $from);
    DailyReportAgent::fake([$answer]);

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture($eml)));

    $chart = CurrentCompany::run($this->company, fn () => Account::query()->pluck('number')->map(fn ($number) => (int) $number)->all());
    $built = DailyReportVoucher::build($message->proposal['fields'], $message->proposal['mapping'], $chart);
    $expected = Z_EXPECTED;
    ksort($expected);

    expect($message->status)->toBe(MessageStatus::DraftReady)
        ->and($message->warnings)->toBe([])
        ->and($message->document_type)->toBe(DocumentType::DailyReport)
        ->and($message->interpreted_by)->toBe(Interpreter::Ai)
        ->and($message->proposal['date'])->toBe('2026-10-30')
        ->and($built['balanced'])->toBeTrue()
        ->and($built['debit'])->toBe(1_736_000)
        ->and($built['vat'])->toBe(Z_VAT)
        ->and(summed($built['rows']))->toBe($expected);

    DailyReportAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Chart of accounts'));

    // A person approves: the voucher is posted with the original mail and its attachments as underlag.
    $approved = app(ApproveInboxMessage::class)($this->owner, $message, ['kind' => 'daily_report', 'date' => '2026-10-30', 'mapping' => $message->proposal['mapping']]);
    $voucher = Voucher::query()->with('rows.account')->findOrFail($approved->voucher_ids[0]);

    expect($approved->status)->toBe(MessageStatus::Booked)
        ->and($voucher->isPosted())->toBeTrue()
        ->and($voucher->voucher_date->toDateString())->toBe('2026-10-30')
        ->and($voucher->rows->sum(fn ($row) => $row->debit_ore->ore))->toBe(1_736_000)
        ->and($voucher->rows->sum(fn ($row) => $row->credit_ore->ore))->toBe(1_736_000)
        ->and(VoucherAttachment::query()->where('voucher_id', $voucher->id)->pluck('sha256'))->toContain($message->eml_sha256)
        ->and(VoucherAttachment::query()->where('voucher_id', $voucher->id)->count())->toBe(1 + $message->attachments->count())
        ->and(InboxTemplate::query()->where('sender', $from)->count())->toBe(1);

    // The next day: same register, same layout, no AI.
    $next = Inbox::fresh(Inbox::receive($this->company, nextDay($from, $filename, $mime)));

    DailyReportAgent::assertPromptedTimes(1);
    expect($next->interpreted_by)->toBe(Interpreter::Template)
        ->and($next->status)->toBe(MessageStatus::DraftReady)
        ->and($next->proposal['date'])->toBe('2026-10-31')
        ->and(DailyReportVoucher::build($next->proposal['fields'], $next->proposal['mapping'], $chart)['vat'])->toBe(Z_VAT);
})->with(zFormats());

it('books the next report automatically only when the person turned it on for the template', function () {
    [$eml, $from, $answer, $filename, $mime] = zFormats()['semicolon CSV, label;amount'];
    Inbox::allow($this->company, $from);
    DailyReportAgent::fake([$answer]);
    $first = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture($eml)));
    app(ApproveInboxMessage::class)($this->owner, $first, ['kind' => 'daily_report', 'date' => '2026-10-30', 'mapping' => $first->proposal['mapping']]);
    $template = InboxTemplate::query()->firstOrFail();

    app(ManageInbox::class)->setAutoBook($this->owner, $this->company, $template->id, true);
    $next = Inbox::fresh(Inbox::receive($this->company, nextDay($from, $filename, $mime)));

    expect($next->status)->toBe(MessageStatus::Booked)
        ->and($next->voucher_ids)->toHaveCount(1)
        ->and(Voucher::query()->findOrFail($next->voucher_ids[0])->isPosted())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'inbox.auto_booked')->where('subject_id', $next->id)->exists())->toBeTrue();
    DailyReportAgent::assertPromptedTimes(1);
});

it('leaves a report in the manual queue with AI off, and learns the template from the mapping a person makes', function () {
    app(ModuleManager::class)->disable('ai', $this->company, $this->owner, true);
    [$eml, $from, $answer] = zFormats()['comma CSV, one row with a column per amount'];
    Inbox::allow($this->company, $from);
    DailyReportAgent::fake();

    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture($eml)));

    expect($message->status)->toBe(MessageStatus::NeedsReview)
        ->and($message->interpreted_by)->toBe(Interpreter::Manual)
        ->and($message->proposal['kind'])->toBe('daily_report')
        ->and(array_keys($message->proposal['fields']))->toContain('k1 | card');

    $mapping = [];

    foreach ($answer['fields'] as $field) {
        $mapping[$field['key']] = ['role' => $field['role'], 'account' => $field['account'], 'side' => $field['side'], 'vat_rate' => $field['vat_rate'] < 0 ? null : $field['vat_rate']];
    }

    $approved = app(ApproveInboxMessage::class)($this->owner, $message, ['kind' => 'daily_report', 'date' => '2026-10-30', 'mapping' => $mapping], ApproveInboxMessage::POST, true);

    expect($approved->status)->toBe(MessageStatus::Booked)
        ->and(InboxTemplate::query()->where('sender', $from)->value('mapping'))->toHaveKey('k1 | card');
    DailyReportAgent::assertNeverPrompted();
});

it('refuses to approve a mapping that does not balance', function () {
    [$eml, $from, $answer] = zFormats()['comma CSV, one row with a column per amount'];
    Inbox::allow($this->company, $from);
    DailyReportAgent::fake([$answer]);
    $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture($eml)));
    $mapping = $message->proposal['mapping'];
    $mapping['k1 | tips']['role'] = 'IGNORE';

    expect(fn () => app(ApproveInboxMessage::class)($this->owner, $message, ['kind' => 'daily_report', 'date' => '2026-10-30', 'mapping' => $mapping]))
        ->toThrow(ValidationException::class);
    expect(Voucher::query()->count())->toBe(0);
});
