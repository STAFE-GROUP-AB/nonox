<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounting\AttachToVoucher;
use App\Migration\Fortnox\FortnoxConnection;
use App\Models\LoginCode;
use App\Models\TeamInvitation;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Expense;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes personal data that no longer has a purpose (GDPR art. 5.1 c and e).
 * Nothing that is räkenskapsinformation is touched: only sign-in codes,
 * stale invitations, tokens of moves that were abandoned, and rejected
 * absence and outlay requests that never reached the payroll or the books.
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
    ];

    protected $signature = 'privacy:prune';

    protected $description = 'Gallrar personuppgifter som inte längre behövs (inloggningskoder, gamla inbjudningar, avbrutna flyttar, avslagna ansökningar).';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Every company at once: these rows have no purpose left in any company.
        $counts = CompanyScope::bypass(function () use ($now): array {
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
                if ($expense->receipt_path !== null) {
                    Storage::disk(AttachToVoucher::DISK)->delete($expense->receipt_path);
                }

                $expense->delete();
                $expenses++;
            });

            return ['login_codes' => $codes, 'invitations' => $invitations, 'fortnox_tokens' => $tokens, 'absences' => $absences, 'expenses' => $expenses];
        });

        Audit::record('privacy.pruned', null, $counts);

        foreach ($counts as $kind => $count) {
            $this->line("{$kind}: {$count}");
        }

        $this->comment('Gallringen är klar.');

        return self::SUCCESS;
    }
}
