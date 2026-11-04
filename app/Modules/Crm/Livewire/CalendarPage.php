<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Crm\Actions\ChooseCalendar;
use App\Modules\Crm\Actions\DisconnectCalendarAccount;
use App\Modules\Crm\Actions\IssueCalendarFeed;
use App\Modules\Crm\Actions\RevokeCalendarFeed;
use App\Modules\Crm\Actions\SetCalendarDetails;
use App\Modules\Crm\Calendar\CalendarProviders;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Calendar\Providers\CalendarProvider;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Calendar\Providers\RemoteCalendar;
use App\Modules\Crm\Livewire\Concerns\ActsInCompany;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarFeed;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kalender (ADR-049): the user's own calendar link for the CRM in this
 * company, with the details choice and instructions for Google, Outlook and
 * Apple, and, only while a provider is configured, the two-way connection
 * to the user's own Google or Microsoft calendar. Everything here is the
 * user's own; crm:view is enough.
 */
class CalendarPage extends Component
{
    use ActsInCompany;
    use ShowsToasts;

    /** The new link, shown once right after it is made. */
    #[Locked]
    public ?string $feedUrl = null;

    /** The details choice for a new link. */
    public bool $newDetails = false;

    /** @var list<array{id: string, name: string}> the user's writable calendars, read when asked for */
    public array $calendars = [];

    public string $calendarId = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Activity::class, $this->company()]), 403);
    }

    public function issueFeed(IssueCalendarFeed $issue): void
    {
        $this->feedUrl = $issue($this->user(), $this->company(), $this->newDetails && CalendarRules::detailsAllowed($this->company()));
        $this->toast(__('Din kalenderlänk är skapad. Kopiera den nu, den visas bara en gång.'));
    }

    public function revokeFeed(RevokeCalendarFeed $revoke): void
    {
        $this->feedUrl = null;
        $revoke($this->user(), $this->company())
            ? $this->toast(__('Kalenderlänken är avstängd. Kalendrar som använder den slutar uppdateras.'))
            : $this->toast(__('Det finns ingen kalenderlänk att stänga av.'), 'info');
    }

    public function setFeedDetails(bool $on, SetCalendarDetails $set): void
    {
        try {
            $set($this->user(), $this->company(), SetCalendarDetails::FEED, $on);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($on ? __('Kalendern visar nu detaljerna.') : __('Kalendern visar nu bara typ och företag.'));
    }

    public function setConnectionDetails(bool $on, SetCalendarDetails $set): void
    {
        try {
            $set($this->user(), $this->company(), SetCalendarDetails::CONNECTION, $on);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $connection = $this->connection();

        if ($connection instanceof CalendarConnection) {
            // Every event is written again with the new choice.
            SyncCalendarConnection::dispatch($this->company()->id, $connection->id, true);
        }

        $this->toast($on ? __('Den kopplade kalendern får nu detaljerna.') : __('Den kopplade kalendern får nu bara typ och företag.'));
    }

    public function loadCalendars(CalendarSync $sync): void
    {
        $connection = $this->connection();

        if (! $connection instanceof CalendarConnection || ! $connection->isActive()) {
            return;
        }

        try {
            $provider = $sync->provider($connection);
            $calendars = $sync->call($connection, fn (string $token): array => $provider->calendars($token));
        } catch (CalendarProviderException) {
            $this->toast(__('Kalendrarna kunde inte hämtas. Försök igen om en stund.'), 'error');

            return;
        }

        $this->calendars = array_map(fn (RemoteCalendar $calendar): array => ['id' => $calendar->id, 'name' => $calendar->name], $calendars);
        $this->calendarId = (string) $connection->calendar_id;
    }

    public function chooseCalendar(ChooseCalendar $choose): void
    {
        try {
            $choose($this->user(), $this->company(), $this->calendarId);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (CalendarProviderException) {
            $this->toast(__('Kalendern kunde inte bytas. Försök igen om en stund.'), 'error');

            return;
        }

        $this->calendars = [];
        $this->toast(__('NONOX skriver nu i den valda kalendern.'));
    }

    public function disconnect(DisconnectCalendarAccount $disconnect): void
    {
        $disconnect($this->user(), $this->company())
            ? $this->toast(__('Kalendern är bortkopplad. NONOX händelser tas bort från den och åtkomsten återkallas.'))
            : $this->toast(__('Ingen kalender var kopplad.'), 'info');
        $this->calendars = [];
    }

    private function connection(): ?CalendarConnection
    {
        return CalendarConnection::query()->where('user_id', $this->user()->id)->first();
    }

    public function render(CalendarProviders $providers): View
    {
        $company = $this->company();

        return view('crm::livewire.calendar', [
            'feed' => CalendarFeed::query()->where('user_id', $this->user()->id)->whereNull('revoked_at')->first(),
            'connection' => $this->connection(),
            'providers' => CalendarRules::syncAllowed($company) ? array_map(fn (CalendarProvider $provider) => $provider->key(), $providers->configured()) : [],
            'detailsAllowed' => CalendarRules::detailsAllowed($company),
        ]);
    }
}
