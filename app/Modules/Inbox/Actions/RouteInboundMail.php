<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxMailbox;
use App\Modules\Inbox\Models\UnassignedMessage;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\MailParser;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Decides which company a message to the cloud's receiving domain is for
 * (ADR-023):
 *
 * - {slug}[+alias]@in.nonox.app: the company with that slug.
 * - mejl@in.nonox.app (shared): the company whose allowlist has the
 *   sender. No match: dropped and logged, without a bounce, so nobody
 *   learns anything. More than one: held as an UnassignedMessage until a
 *   member of one of those companies claims it; never assigned by guess.
 *
 * Unknown addresses and companies without the module are dropped the
 * same way, so the endpoint cannot be used to probe for addresses.
 */
class RouteInboundMail
{
    public function __construct(
        private readonly AcceptInboundMail $accept,
        private readonly MailParser $parser,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @param  list<string>  $recipients
     * @param  array<string, string>  $transport
     * @return int how many inboxes took the message
     */
    public function __invoke(string $raw, array $recipients, array $transport = []): int
    {
        if (strlen($raw) > AcceptInboundMail::maxBytes()) {
            Log::info('Inbox: dropped a message that is too large.', ['size' => strlen($raw)]);

            return 0;
        }

        $mail = $this->parser->parse($raw);
        $accepted = 0;
        $done = [];

        foreach ($recipients as $recipient) {
            $parsed = InboxAddress::parse($recipient);

            if ($parsed === null) {
                continue;
            }

            [$local, $alias] = $parsed;
            $companies = $local === InboxAddress::sharedLocalPart()
                ? $this->bySender($mail->fromEmail)
                : $this->bySlug($local);

            if ($companies === []) {
                Log::info('Inbox: dropped a message without a matching company.', ['recipient' => $recipient, 'shared' => $local === InboxAddress::sharedLocalPart()]);

                continue;
            }

            if (count($companies) > 1) {
                $accepted += $this->hold($raw, $recipient, $mail->fromEmail, $mail->fromName, $mail->subject, $transport, $companies);

                continue;
            }

            $company = $companies[0];

            if (isset($done[$company->id])) {
                continue;
            }

            $done[$company->id] = true;
            $accepted += ($this->accept)($company, $raw, Channel::Mailgun, mb_strtolower($recipient), $alias, $transport, $mail) === null ? 0 : 1;
        }

        return $accepted;
    }

    /** @return list<Company> */
    private function bySlug(string $slug): array
    {
        // The receiving address is looked up across companies; the message then goes into that company only.
        $companyId = CompanyScope::bypass(fn (): mixed => InboxMailbox::query()->where('slug', $slug)->value('company_id'));
        $company = $companyId === null ? null : Company::query()->find((int) $companyId);

        return $company instanceof Company && $this->modules->isEnabled(InboxModule::KEY, $company) ? [$company] : [];
    }

    /**
     * Companies with the inbox on whose allowlist has the sender's address
     * or domain.
     *
     * @return list<Company>
     */
    private function bySender(string $fromEmail): array
    {
        if ($fromEmail === '') {
            return [];
        }

        // The shared address routes by the sender, which needs every company's allowlist.
        $ids = CompanyScope::bypass(fn (): array => AllowedSender::query()->whereIn('pattern', AllowedSender::patternsFor($fromEmail))->distinct()->pluck('company_id')->all());
        $enabled = array_flip($this->modules->companiesWith(InboxModule::KEY));
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => isset($enabled[$id])));

        return $ids === [] ? [] : array_values(Company::query()->whereIn('id', $ids)->orderBy('id')->get()->all());
    }

    /**
     * @param  array<string, string>  $transport
     * @param  list<Company>  $companies
     */
    private function hold(string $raw, string $recipient, string $from, ?string $fromName, ?string $subject, array $transport, array $companies): int
    {
        $sha256 = hash('sha256', $raw);

        if (UnassignedMessage::query()->where('eml_sha256', $sha256)->exists()) {
            return 1;
        }

        $id = Str::uuid()->toString();
        $path = "inkorg/delad/{$id}.eml";
        Storage::disk(AcceptInboundMail::DISK)->put($path, $raw);

        $message = new UnassignedMessage;
        $message->id = $id;
        $message->fill([
            'recipient' => mb_substr(mb_strtolower($recipient), 0, 255),
            'from_email' => mb_substr($from, 0, 255),
            'from_name' => $fromName,
            'subject' => $subject,
            'received_at' => now(),
            'eml_path' => $path,
            'eml_sha256' => $sha256,
            'eml_size' => strlen($raw),
            'transport' => array_intersect_key($transport, array_flip(['X-Mailgun-Spf', 'X-Mailgun-Dkim-Check-Result', 'X-Mailgun-Sflag'])),
            'candidate_company_ids' => array_map(fn (Company $company): int => $company->id, $companies),
        ])->save();

        foreach ($companies as $company) {
            Audit::record('inbox.held_for_company', $message, ['from' => $from, 'candidates' => count($companies)], $company);
        }

        return 1;
    }
}
