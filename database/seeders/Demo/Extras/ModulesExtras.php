<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\VoucherRow;
use App\Modules\Banking\Actions\ConnectBank;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\Actions\MapBankAccount;
use App\Modules\Banking\Actions\MatchBankTransactions;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ProviderTransaction;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Connect\Actions\CreateApiKey;
use App\Modules\Connect\ConnectModule;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Actions\InterpretInboxMessage;
use App\Modules\Inbox\Actions\ManageInbox;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The restaurant's optional modules, for the manual's settings, bank and
 * supplier pages, without any call outside NONOX:
 *
 * - Inkorg: the cash register system mails its daily report as CSV. The
 *   first one is mapped to accounts by hand and booked, which makes it the
 *   sender's template; the bar register's report today is read with that
 *   template and waits for approval.
 * - Bank: connected through the app's own test bank (only where the
 *   installation uses it, never a real bank), with the last weeks of the
 *   business account's payments as the bank's transactions.
 * - NONOX Connect: an API key for the cash register system, when the
 *   server has its OAuth keys.
 *
 * NONOX AI is left off: turning it on would send documents to an AI
 * provider.
 */
final class ModulesExtras extends DemoExtra
{
    private const string REGISTER = 'dagsrapport@kassasystem-demo.example.test';

    /** How the register's report rows are booked: role, account, VAT rate. */
    private const array MAPPING = [
        'försäljning mat 12% netto' => ['SALES', 3002, 12],
        'moms 12%' => ['OUTPUT_VAT', 2621, 12],
        'försäljning dryck 25% netto' => ['SALES', 3001, 25],
        'moms 25%' => ['OUTPUT_VAT', 2611, 25],
        'kort' => ['PAYMENT_CARD', 1930, null],
        'swish' => ['PAYMENT_SWISH', 1930, null],
        'kontant' => ['PAYMENT_CASH', 1910, null],
        'summa brutto' => ['IGNORE', 0, null],
        'antal kvitton' => ['IGNORE', 0, null],
    ];

    /** How many days of the business account the test bank shows. */
    private const int BANK_DAYS = 35;

    public function industries(): array
    {
        return ['bokforing-for-restauranger'];
    }

    public function afterAll(DemoContext $context): void
    {
        config(['mail.default' => 'log']);

        $this->inbox($context);
        $this->bank($context);
        $this->connect($context);
    }

    private function inbox(DemoContext $context): void
    {
        $modules = app(ModuleManager::class);
        $modules->enable(InboxModule::KEY, $context->company, $context->user);
        app(ManageInbox::class)->allow($context->user, $context->company, self::REGISTER, 'Kassasystemet, dagsrapporter');

        $today = $context->today;
        $first = $this->receive($context, 'Kassa 1, matsalen', $today, [14_020_00, 4_160_00], [16_410_00, 2_890_00, 1_030_40]);

        $mapping = [];

        foreach ((array) ($first->proposal['fields'] ?? []) as $key => $field) {
            $label = mb_strtolower(trim(explode('|', (string) $key)[0]));
            [$role, $account, $rate] = self::MAPPING[$label] ?? ['IGNORE', 0, null];
            $mapping[$key] = array_filter(['role' => $role, 'account' => $account, 'vat_rate' => $rate, 'side' => in_array($role, ['SALES', 'OUTPUT_VAT'], true) ? 'K' : 'D'], fn (mixed $value): bool => $value !== null);
        }

        app(ApproveInboxMessage::class)($context->user, $first, ['kind' => 'daily_report', 'mapping' => $mapping, 'date' => $today->toDateString()], ApproveInboxMessage::POST, warningsConfirmed: true);

        // The bar's register sends the same layout: read with the template, waiting for a person.
        $this->receive($context, 'Kassa 2, baren', $today, [1_150_00, 3_980_00], [4_720_00, 1_040_00, 676_70]);
    }

    /**
     * One daily report from the register, as the e-mail it sends.
     *
     * @param  array{0: int, 1: int}  $net  öre: food at 12 %, drinks at 25 %
     * @param  array{0: int, 1: int, 2: int}  $paid  öre: card, Swish, cash (they add up to the gross)
     */
    private function receive(DemoContext $context, string $register, CarbonImmutable $date, array $net, array $paid): InboxMessage
    {
        $vat = [intdiv($net[0] * 12, 100), intdiv($net[1] * 25, 100)];
        $gross = $net[0] + $net[1] + $vat[0] + $vat[1];
        $paid[2] = $gross - $paid[0] - $paid[1];
        $amount = fn (int $ore): string => sprintf('%d,%02d', intdiv($ore, 100), $ore % 100);
        $csv = implode("\r\n", [
            "Z-rapport;{$register}",
            "Datum;{$date->toDateString()}",
            'Rubrik;Belopp',
            "Försäljning mat 12% netto;{$amount($net[0])}",
            "Moms 12%;{$amount($vat[0])}",
            "Försäljning dryck 25% netto;{$amount($net[1])}",
            "Moms 25%;{$amount($vat[1])}",
            "Summa brutto;{$amount($gross)}",
            "Kort;{$amount($paid[0])}",
            "Swish;{$amount($paid[1])}",
            "Kontant;{$amount($paid[2])}",
            'Antal kvitton;'.intdiv($gross, 31_000),
        ])."\r\n";

        $address = InboxAddress::forSlug(InboxAddress::mailbox($context->company)->slug);
        $email = (new Email)
            ->from(new Address(self::REGISTER, 'Kassasystem Demo'))
            ->to($address)
            ->subject("Z-rapport {$date->toDateString()} {$register}")
            ->text("Dagsrapport från {$register}. Rapporten finns i bilagan.\n\nDet här är ett automatiskt utskick från kassasystemet (demo).")
            ->attach($csv, 'z-rapport-'.$date->toDateString().'.csv', 'text/csv');

        // What Mailgun reports for a message that passed SPF from the register's own domain.
        $message = app(AcceptInboundMail::class)($context->company, $email->toString(), Channel::Mailgun, $address, null, ['X-Mailgun-Spf' => 'Pass', 'X-Mailgun-Dkim-Check-Result' => 'Pass', 'sender' => self::REGISTER])
            ?? throw new \RuntimeException('The demo daily report was not accepted.');

        // With a queue other than sync the reading waits for a worker: read it now.
        if ($message->fresh()?->proposal === null) {
            app(InterpretInboxMessage::class)($message);
        }

        return $message->fresh() ?? $message;
    }

    /**
     * The business account through the test bank: its transactions are the
     * payments booked on 1930 in the last weeks, plus the bank's own fee,
     * so the bank page has transactions to match against the bookkeeping.
     */
    private function bank(DemoContext $context): void
    {
        // Only the app's test bank; an installation set up for a real bank is left alone.
        if (BankDataProviders::driver() !== FakeBankDataProvider::KEY || ! BankDataProviders::isConfigured()) {
            return;
        }

        app(ModuleManager::class)->enable(BankingModule::KEY, $context->company, $context->user);

        $connect = app(ConnectBank::class);
        $start = $connect->start($context->user, $context->company, 'Demobanken', 'business', route('banking.callback'));
        $flow = $start['flow'];
        $connection = $connect->complete($context->user, $context->company, $flow, 'fake-'.CarbonImmutable::parse($flow['valid_until'])->toDateString().'-demo');

        $business = BankFeedAccount::query()->where('bank_connection_id', $connection->id)->get()
            ->first(fn (BankFeedAccount $account): bool => str_ends_with((string) $account->provider_account_id, '-1'))
            ?? throw new \RuntimeException('The test bank gave no business account.');
        app(MapBankAccount::class)($context->user, $context->company, $business, 1930);

        // The test bank keys its accounts by the number after the dash in the account id.
        FakeBankDataProvider::$transactions[substr((string) $business->provider_account_id, -1)] = $this->transactions($context);

        try {
            app(FetchBankData::class)($context->company, $connection, $context->user);
        } finally {
            FakeBankDataProvider::reset();
        }

        // Matched up to a week ago, as after the last reconciliation; the latest week waits.
        $match = app(MatchBankTransactions::class);
        $older = BankFeedTransaction::query()->where('bank_feed_account_id', $business->id)->whereDate('booking_date', '<', $context->end->subWeek()->toDateString())->get();

        foreach ($match->proposals($business, $older) as $transactionId => $row) {
            $match->confirm($context->user, $context->company, $older->firstOrFail('id', $transactionId), $row->id);
        }
    }

    /** @return list<ProviderTransaction> */
    private function transactions(DemoContext $context): array
    {
        $bank = (int) Account::query()->where('number', 1930)->value('id');
        $from = $context->end->subDays(self::BANK_DAYS);
        $rows = VoucherRow::query()
            ->select('voucher_rows.*', 'vouchers.voucher_date as booked_on', 'vouchers.description as voucher_description')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('voucher_rows.account_id', $bank)
            ->whereBetween('vouchers.voucher_date', [$from->toDateString(), $context->end->toDateString()])
            ->orderBy('vouchers.voucher_date')
            ->orderBy('voucher_rows.id')
            ->get();

        $transactions = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse((string) $row->getAttribute('booked_on'));
            $amount = $row->debit_ore->ore - $row->credit_ore->ore;
            $text = (string) $row->getAttribute('voucher_description');
            $transactions[] = new ProviderTransaction("demo-{$row->id}", null, $day, $day, $amount, 'SEK', $amount > 0 ? 'Inbetalning' : mb_substr($text, 0, 60), mb_substr($text, 0, 140), null);
        }

        // The bank's monthly fee, not yet booked: something to book from the bank page.
        $fee = $context->end->startOfMonth();
        $transactions[] = new ProviderTransaction('demo-fee-'.$fee->format('Ym'), null, $fee, $fee, -125_00, 'SEK', 'Demobanken', 'Månadsavgift företagskonto', null);

        return $transactions;
    }

    /** An API key for the cash register system, when the server can run NONOX Connect. */
    private function connect(DemoContext $context): void
    {
        if (! ConnectModule::hasSigningKeys()) {
            return;
        }

        app(ModuleManager::class)->enable(ConnectModule::KEY, $context->company, $context->user);
        app(CreateApiKey::class)($context->user, $context->company, [
            'name' => 'Kassasystemet (demo)',
            'scopes' => ['vouchers:view', 'vouchers:create'],
            'expires_on' => $context->today->addDays(180)->toDateString(),
        ]);
    }
}
