<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\Staffing\StaffingReport;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Planering → Bemanning och kostnad (/planering/bemanning, PLANNING_MODULE.md
 * §7): planned and actual hours and labour cost against the sales in the
 * books, per day and week, with last year's same weeks. Read only; the
 * figures come from StaffingReport.
 */
class StaffingPage extends Component
{
    use InCurrentCompany;

    public const array PRESETS = ['vecka', 'forra-veckan', 'fyra-veckor', 'manad', 'forra-manaden'];

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'avdelning')]
    public ?int $area = null;

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);

        if ($this->parse($this->from) === null || $this->parse($this->to) === null) {
            $this->preset('fyra-veckor');
        }

        $this->normalise();
    }

    public function preset(string $preset): void
    {
        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        [$from, $to] = match ($preset) {
            'vecka' => [$monday, $monday->addDays(6)],
            'forra-veckan' => [$monday->subWeek(), $monday->subDay()],
            'manad' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'forra-manaden' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$monday->subWeeks(3), $monday->addDays(6)],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function previous(): void
    {
        $this->shift(-1);
    }

    public function next(): void
    {
        $this->shift(1);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->normalise();
        }
    }

    public function render(StaffingReport $report): View
    {
        [$from, $to] = $this->period();
        $data = $report->load($this->company(), $from, $to, $this->area);

        return view('planning::livewire.staffing', [
            ...$data,
            'csvUrl' => route('planning.staffing.csv', array_filter(['fran' => $this->from, 'till' => $this->to, 'avdelning' => $this->area])),
        ]);
    }

    /** Moves the period by its own length, so four weeks go to the four weeks before. */
    private function shift(int $direction): void
    {
        [$from, $to] = $this->period();
        $days = (int) $from->diffInDays($to) + 1;

        $this->from = $from->addDays($direction * $days)->toDateString();
        $this->to = $to->addDays($direction * $days)->toDateString();
    }

    private function normalise(): void
    {
        [$from, $to] = $this->period();
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(): array
    {
        $today = CarbonImmutable::today(LocalTimestamp::ZONE);

        return StaffingReport::period($this->parse($this->from) ?? $today, $this->parse($this->to) ?? $today);
    }

    private function parse(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $date, LocalTimestamp::ZONE) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
