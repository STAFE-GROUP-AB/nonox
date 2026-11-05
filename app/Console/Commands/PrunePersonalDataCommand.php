<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Migration\Fortnox\FortnoxConnection;
use App\Models\DeadlineCalendarFeed;
use App\Models\LoginCode;
use App\Models\SkatteverketToken;
use App\Models\TeamInvitation;
use App\Models\VatSubmission;
use App\Modules\Crm\Actions\PruneCrm;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Expense;
use App\Modules\StaffLedger\Actions\PruneStaffLedger;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\ArchivedCompany;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes personal data that no longer has a purpose (GDPR art. 5.1 c and e).
 * Nothing that is räkenskapsinformation is touched: only sign-in codes,
 * stale invitations, tokens of moves that were abandoned, and rejected
 * absence and outlay requests that never reached the payroll or the books,
 * and staff ledger entries and people whose retention period is over
 * (PruneStaffLedger), Mina datum calendar links stopped 30 days ago, Skatteverket sign-ins that have run out (ADR-054), and CRM contact people and prospects with no
 * activity for the company's set number of months (PruneCrm, ADR-043).
 *
 * TODO(legal-verify): the periods in RETENTION (see docs/gdpr/lagringstider.md).
 */
class PrunePersonalDataCommand extends Command
{
    /** Days to keep each kind of data. */
    public const array RETENTION = [
        'login_codes' => 1,
        'invitations' => TeamInvitation::VALID_DAYS,
        'abandoned_fortnox_tokens' => 30,
        'rejected_requests' => 365,
        // A stopped Mina datum calendar link has no use left (ADR-051, stage 2).
        'revoked_calendar_links' => 30,
        // Skatteverket keeps an unsigned VAT draft 30 days (ADR-054).
        'vat_signing_links' => 30,
    ];

    protected $signature = 'privacy:prune';

    protected $description = 'Gallrar personuppgifter som inte längre behövs (inloggningskoder, gamla inbjudningar, avbrutna flyttar, avslagna ansökningar).';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Every company at once: these rows have no purpose left in any company.
        // Archived companies too: their personal data outlives its purpose the same way (ADR-024).
        $counts = ArchivedCompany::maintenance(fn (): array => CompanyScope::bypass(function () use ($now): array {
            $codes = LoginCode::query()->where('created_at', '<', $now->subDays(self::RETENTION['login_codes']))->delete();
            $invitations = TeamInvitation::query()->where('created_at', '<', $now->subDays(self::RETENTION['invitations']))->delete();

            $tokens = FortnoxConnection::query()
                ->whereNull('completed_at')
                ->whereNotNull('refresh_token')
                ->where('updated_at', '<', $now->subDays(self::RETENTION['abandoned_fortnox_tokens']))
                ->get()
                ->each(fn (FortnoxConnection $connection) => $connection->forceFill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null])->save())
                ->count();

            $cutoff = $now->subDays(self::RETENTION['rejected_requests']);
            $absences = Absence::query()->where('status', 'REJECTED')->where('decided_at', '<', $cutoff)->delete();

            $expenses = 0;
            Expense::query()->where('status', 'REJECTED')->where('decided_at', '<', $cutoff)->each(function (Expense $expense) use (&$expenses): void {
                DB::transaction(function () use ($expense): void {
                    $expense->delete();

                    StoredFiles::deleteUnreferenced([$expense->receipt_path]);
                });
                $expenses++;
            });

            // A sign-in with Skatteverket that can no longer be used or refreshed (ADR-054).
            $skatteverket = SkatteverketToken::query()->whereRaw('coalesce(refresh_expires_at, expires_at) < ?', [$now])->delete();

            // Skatteverket removes an unsigned draft 30 days after it was sent; its signing link (which can hold a sole trader's personnummer) goes with it.
            $signingLinks = VatSubmission::query()->whereNotNull('signing_link')->where('sent_at', '<', $now->subDays(self::RETENTION['vat_signing_links']))->update(['signing_link' => null]);

            $calendarLinks = DeadlineCalendarFeed::query()->whereNotNull('revoked_at')->where('revoked_at', '<', $now->subDays(self::RETENTION['revoked_calendar_links']))->delete();

            return ['login_codes' => $codes, 'invitations' => $invitations, 'fortnox_tokens' => $tokens, 'absences' => $absences, 'expenses' => $expenses, 'calendar_links' => $calendarLinks, 'skatteverket_sign_ins' => $skatteverket, 'vat_signing_links' => $signingLinks];
        }));

        $staffLedger = ArchivedCompany::maintenance(fn (): array => app(PruneStaffLedger::class)());
        $counts['staff_ledger_entries'] = $staffLedger['entries'];
        $counts['staff_ledger_people'] = $staffLedger['people'];

        $crm = ArchivedCompany::maintenance(fn (): array => app(PruneCrm::class)());
        $counts['crm_people'] = $crm['people'];
        $counts['crm_prospects'] = $crm['prospects'];

        Audit::record('privacy.pruned', null, $counts);

        foreach ($counts as $kind => $count) {
            $this->line("{$kind}: {$count}");
        }

        $this->comment('Gallringen är klar.');

        return self::SUCCESS;
    }
}
