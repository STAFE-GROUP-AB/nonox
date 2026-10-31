<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Console;

use App\Models\Company;
use App\Modules\Inbox\Actions\FetchImapMail;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use Illuminate\Console\Command;

/**
 * Fetches new mail from the IMAP mailboxes of self-hosted inboxes. Runs
 * every few minutes; one company or all with the inbox on.
 */
class FetchInboxCommand extends Command
{
    protected $signature = 'inbox:fetch {company? : The company id}';

    protected $description = 'Fetch new mail from the IMAP mailboxes of the inbox module';

    public function handle(FetchImapMail $fetch, ModuleManager $modules): int
    {
        $ids = $this->argument('company') !== null ? [(int) $this->argument('company')] : $modules->companiesWith(InboxModule::KEY);
        $total = 0;

        foreach (Company::query()->whereIn('id', $ids)->orderBy('id')->get() as $company) {
            $this->info("Fetching mail for company `{$company->id}`...");
            $result = $fetch($company);
            $total += $result['fetched'];

            if ($result['error'] !== null) {
                $this->warn("Company `{$company->id}`: {$result['error']}");
            }
        }

        $this->comment("Fetched {$total} messages.");

        return self::SUCCESS;
    }
}
