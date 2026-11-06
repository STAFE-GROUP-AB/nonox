<?php

declare(strict_types=1);

namespace App\Actions\SkatteverketEvents;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\SkatteverketEvent;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Skatteverket\Kundhandelser\KundhandelserApi;
use App\Support\Skatteverket\Redovisare;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fetches Skatteverket's customer events about the company (ADR-054 stage
 * 4) when a person asks, with that person's own sign-in, and keeps them for
 * that person only, in full: Allmänna villkor Kundhändelser 2 and 5.1 make
 * the representative who makes the request the end user, and the content can
 * be secret (7.3). Another user in the company fetches their own. Never in
 * the background: the sign-in is the person's and lives about an hour.
 *
 * Only legal persons: the API's terms cover juridiska personer (Allmänna
 * villkor Kundhändelser 1.1), and a sole trader's personnummer would bring
 * the owner's private matters into the company. Reading needs reports:view.
 * An event the person already has keeps its handled mark.
 */
class FetchSkatteverketEvents
{
    /**
     * @return array{fetched: int, new: int, awaiting: int}
     *
     * @throws AuthorizationException|ValidationException|SkatteverketFailed
     */
    public function __invoke(User $user, Company $company): array
    {
        self::authorize($user, $company, 'reports:view');

        $number = OrganisationNumber::tryFrom((string) $company->org_number)
            ?? throw ValidationException::withMessages(['company' => __('Företagets organisationsnummer saknas eller är fel. Fyll i det i företagets uppgifter först.')]);

        if ($number->isPersonalIdentityNumber()) {
            throw ValidationException::withMessages(['company' => __('Kundhändelser från Skatteverket kan bara hämtas för juridiska personer, till exempel aktiebolag och handelsbolag. För en enskild firma finns händelserna på Mina sidor hos Skatteverket.')]);
        }

        $identifierare = Redovisare::of((string) $company->org_number)
            ?? throw ValidationException::withMessages(['company' => __('Företagets organisationsnummer saknas eller är fel. Fyll i det i företagets uppgifter först.')]);
        // A sign-in made for another service lacks the scope: the person signs in again, and the new sign-in keeps the other services (SkatteverketAuth::scopesToRequest).
        $token = SkatteverketAuth::accessToken($user, Skatteverket::SCOPE_KUNDHANDELSER)
            ?? throw ValidationException::withMessages(['skatteverket' => __('Logga in hos Skatteverket för kundhändelser först.')]);

        $events = (new KundhandelserApi($token))->fetch($identifierare);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $events): array {
            $now = CarbonImmutable::now();
            $ids = array_column($events, 'id');
            $known = $ids === [] ? [] : SkatteverketEvent::query()->fetchedBy($user)->whereIn('event_id', $ids)->pluck('event_id')->all();

            $rows = array_map(fn (array $event): array => [
                'company_id' => $company->id,
                'fetched_by' => $user->id,
                'event_id' => $event['id'],
                'type' => $event['type'],
                'producer' => $event['producer'],
                'occurred_at' => $event['occurredAt'],
                'title' => $event['title'],
                'description' => $event['description'],
                'language' => $event['language'],
                'requires_action' => $event['requiresAction'],
                'case_closed' => $event['closed'],
                'due_date' => $event['dueDate'],
                // Upsert writes the values as they are, without the model's casts.
                'links' => $event['links'] === [] ? null : json_encode($event['links'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'kopplings_id' => $event['kopplingsId'],
                'skatteslag' => $event['skatteslag'],
                'kundamne' => $event['kundamne'] === [] ? null : json_encode($event['kundamne'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'korrespondens_referens' => $event['korrespondensReferens'],
                'notice' => $event['notice'],
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $events);

            foreach (array_chunk($rows, 500) as $chunk) {
                SkatteverketEvent::query()->upsert($chunk, ['company_id', 'fetched_by', 'event_id'], [
                    'type', 'producer', 'occurred_at', 'title', 'description', 'language', 'requires_action', 'case_closed', 'due_date', 'links',
                    'kopplings_id', 'skatteslag', 'kundamne', 'korrespondens_referens', 'notice', 'fetched_at', 'updated_at',
                ]);
            }

            $result = [
                'fetched' => count($events),
                'new' => count($events) - count($known),
                'awaiting' => SkatteverketEvent::query()->fetchedBy($user)->awaitingCompany()->count(),
            ];

            // Counts only: the events' texts are Skatteverket's free text and stay out of the audit log.
            Audit::record('skatteverket.events_fetched', null, $result, $company, $user);

            return $result;
        }));
    }

    /** @throws AuthorizationException */
    public static function authorize(User $user, Company $company, string $permission): void
    {
        if (! Skatteverket::isAvailableTo($user) || ! $user->hasTeamPermission($company, $permission)) {
            throw new AuthorizationException;
        }
    }
}
