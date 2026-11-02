<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Waitlist\WaitlistStatus;
use App\Mail\WaitlistDigestMail;
use App\Models\WaitlistEntry;
use App\Support\Hq\HqAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Mails the HQ admins a summary of the last day's entries on the waiting
 * list (ADR-038), instead of a mail per entry. Nothing is sent on a day
 * without new entries.
 */
class SendWaitlistDigestCommand extends Command
{
    protected $signature = 'waitlist:digest';

    protected $description = 'Mejlar HQ en sammanfattning av det senaste dygnets anmälningar till kön.';

    public function handle(): int
    {
        $admins = HqAccess::admins();

        /** @var list<string> $companies */
        $companies = WaitlistEntry::query()
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('created_at')
            ->pluck('company_name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        if ($admins === [] || $companies === []) {
            $this->comment('Inget att skicka.');

            return self::SUCCESS;
        }

        $pending = WaitlistEntry::query()->where('status', WaitlistStatus::Pending->value)->count();

        foreach ($admins as $admin) {
            $this->info("Skickar sammanfattningen till {$admin} …");
            Mail::to($admin)->queue(new WaitlistDigestMail($companies, $pending));
        }

        $this->comment('Skickade sammanfattningen till '.count($admins).' mottagare.');

        return self::SUCCESS;
    }
}
