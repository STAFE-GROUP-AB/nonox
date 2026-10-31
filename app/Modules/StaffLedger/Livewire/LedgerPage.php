<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\ShiftBuilder;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Personalliggare → Liggaren: every entry of a period with the corrections
 * that refer to it, a manual registration for a manager, and corrections.
 * Nothing in the ledger is ever changed or removed.
 */
class LedgerPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'lokal')]
    public string $location = '';

    #[Url(as: 'sok')]
    public string $search = '';

    public bool $manual = false;

    /** @var array<string, string> */
    public array $manualForm = ['person_id' => '', 'location_id' => '', 'direction' => 'OUT', 'at' => '', 'reason' => ''];

    public ?int $correcting = null;

    /** @var array<string, string> */
    public array $correctionForm = ['kind' => 'TIME', 'at' => '', 'reason' => ''];

    public function mount(): void
    {
        abort_unless($this->allowed('staff-ledger:view'), 403);

        $this->from = $this->from ?: CarbonImmutable::today()->subDays(6)->toDateString();
        $this->to = $this->to ?: CarbonImmutable::today()->toDateString();
    }

    public function openManual(?int $personId = null): void
    {
        $this->manual = true;
        $this->manualForm = ['person_id' => (string) $personId, 'location_id' => $this->location ?: (string) Location::query()->whereNull('archived_at')->value('id'), 'direction' => 'OUT', 'at' => CarbonImmutable::now()->format('Y-m-d\TH:i'), 'reason' => ''];
    }

    public function saveManual(RecordManualEntry $record): void
    {
        try {
            $at = self::parseLocal($this->manualForm['at']);
            $direction = Direction::tryFrom($this->manualForm['direction']);

            if ($at === null || $direction === null) {
                throw ValidationException::withMessages(['at' => __('Ange datum och tid.')]);
            }

            $record(
                $this->user(),
                $this->company(),
                Person::query()->findOrFail((int) $this->manualForm['person_id']),
                Location::query()->findOrFail((int) $this->manualForm['location_id']),
                $direction,
                $at,
                $this->manualForm['reason'],
            );
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->manual = false;
        $this->toast(__('Registreringen är sparad i liggaren med din orsak.'));
    }

    public function openCorrection(int $entryId): void
    {
        $entry = Entry::query()->findOrFail($entryId);
        $this->correcting = $entry->id;
        $this->correctionForm = ['kind' => 'TIME', 'at' => $entry->occurred_at->format('Y-m-d\TH:i'), 'reason' => ''];
    }

    public function saveCorrection(CorrectEntry $correct): void
    {
        try {
            $kind = CorrectionKind::tryFrom($this->correctionForm['kind']) ?? CorrectionKind::Time;
            $correct($this->user(), $this->company(), Entry::query()->findOrFail($this->correcting), $kind, self::parseLocal($this->correctionForm['at']), $this->correctionForm['reason']);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->correcting = null;
        $this->toast(__('Rättelsen är sparad. Den ursprungliga posten finns kvar och visas bredvid.'));
    }

    public function render(ModuleManager $modules): View
    {
        $from = (CarbonImmutable::createFromFormat('Y-m-d', $this->from) ?: CarbonImmutable::today())->startOfDay();
        $to = (CarbonImmutable::createFromFormat('Y-m-d', $this->to) ?: CarbonImmutable::today())->endOfDay();
        $locationId = $this->location === '' ? null : (int) $this->location;
        $needle = mb_strtolower(trim($this->search));

        $all = LedgerQuery::entries($locationId, $from, $to);
        $times = ShiftBuilder::effectiveTimes($all);
        $corrections = $all->where('type', EntryType::Correction)->groupBy('corrects_entry_id');
        $entries = $all->where('type', '<>', EntryType::Correction)
            ->filter(fn (Entry $entry): bool => $entry->occurred_at >= $from && $entry->occurred_at <= $to)
            ->filter(fn (Entry $entry): bool => $needle === '' || str_contains(mb_strtolower($entry->person_name), $needle))
            ->sortByDesc(fn (Entry $entry): string => $entry->occurred_at->format('Y-m-d H:i:s').sprintf('%012d', $entry->id))
            ->values();

        $writable = $modules->isEnabled(StaffLedgerModule::KEY, $this->company()) && $this->allowed('staff-ledger:manage');

        return view('staff-ledger::livewire.ledger', [
            'entries' => $entries,
            'corrections' => $corrections,
            'times' => $times,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'people' => $writable ? Person::query()->whereNull('archived_at')->orderBy('first_name')->get(['id', 'first_name', 'last_name']) : collect(),
            'canManage' => $writable,
            'sensitive' => $this->allowed('staff-ledger:view-sensitive'),
        ]);
    }

    private static function parseLocal(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value) ?: null;
    }
}
