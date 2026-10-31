<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\StaffLedger\Actions\CheckInFromPhone;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Support\KioskStrings;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Personalliggare → Min incheckning: the signed-in person's QR code for the
 * kiosk and, at locations where the employer allows it, check-in and
 * check-out in the phone with a location check. In the person's language.
 */
class MyCheckIn extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public function mount(): void
    {
        abort_unless($this->allowed('staff-ledger:self') || $this->allowed('staff-ledger:view'), 403);
    }

    public function checkIn(int $locationId, float $latitude, float $longitude, float $accuracy, CheckInFromPhone $checkIn): void
    {
        $person = $this->person();
        abort_unless($person instanceof Person, 404);
        $strings = KioskStrings::for($person->language) + KioskStrings::for('sv');

        try {
            $entry = $checkIn($this->user(), $person, Location::query()->findOrFail($locationId), $latitude, $longitude, $accuracy);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $done = $entry->effectiveDirection() === Direction::In ? $strings['checked_in'] : $strings['checked_out'];
        $this->toast("{$done} {$entry->occurred_at->format('H:i')}");
    }

    public function locationDenied(): void
    {
        $person = $this->person();
        $strings = KioskStrings::for($person->language ?? 'sv') + KioskStrings::for('sv');
        $this->toast($strings['mine_location_denied'], 'error');
    }

    public function render(): View
    {
        $person = $this->person();
        $language = $person->language ?? 'sv';
        $strings = KioskStrings::for($language) + KioskStrings::for('sv');
        $present = $person === null ? collect() : collect(LedgerQuery::present())->filter(fn (Shift $shift): bool => $shift->personId === $person->id)->keyBy('locationId');

        return view('staff-ledger::livewire.mine', [
            'person' => $person,
            'strings' => $strings,
            'language' => $language,
            'rtl' => in_array($language, KioskStrings::RTL, true),
            'locations' => Location::query()->whereNull('archived_at')->where('mobile_enabled', true)->orderBy('name')->get(),
            'present' => $present,
        ]);
    }

    private function person(): ?Person
    {
        return Person::query()->where('user_id', $this->user()->id)->whereNull('archived_at')->first();
    }
}
