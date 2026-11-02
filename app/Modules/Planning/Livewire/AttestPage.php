<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Planning\Actions\AttestDay;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\ChooseCompensation;
use App\Modules\Planning\Actions\ReopenAttestation;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\AttestData;
use App\Modules\Planning\Support\AttestRow;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planering → Tid att attestera (/planering/attest, PLANNING_MODULE.md §7):
 * per person and day the plan, the actual time, the difference, the OB
 * split, the payroll basis and the deviations. Attest a day, a person or
 * every day without deviations with one click, then send the attested days
 * to payroll. The period defaults to the month of the open payroll run.
 * Thin: the actions do the work.
 */
class AttestPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const array FILTERS = ['alla', 'avvikelser', 'att-attestera', 'attesterade'];

    #[Url(as: 'fran')]
    public string $from = '';

    #[Url(as: 'till')]
    public string $to = '';

    #[Url(as: 'person')]
    public ?int $person = null;

    #[Url(as: 'visa')]
    public string $filter = 'alla';

    public bool $showEditor = false;

    /** @var array{employee_id: ?int, date: string, intervals: list<array{start: string, end: string, break_minutes: int|string}>, note: string, none: bool, new: bool} */
    public array $form = ['employee_id' => null, 'date' => '', 'intervals' => [], 'note' => '', 'none' => false, 'new' => false];

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view') && $this->allowed('planning:attest'), 403);
        [$from, $to] = $this->period();
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'alla';
    }

    public function attestDay(int $employeeId, string $date, AttestDay $attest): void
    {
        $this->run(function () use ($employeeId, $date, $attest): string {
            $attest($this->user(), $this->company(), $employeeId, CarbonImmutable::parse($date, LocalTimestamp::ZONE));

            return __('Dagen är attesterad.');
        });
    }

    public function attestPerson(int $employeeId, AttestPeriod $attest): void
    {
        $this->run(function () use ($employeeId, $attest): string {
            ['from' => $from, 'to' => $to] = $this->dates();
            $result = $attest($this->user(), $this->company(), $from, $to, $employeeId);

            return self::attested($result);
        });
    }

    public function attestClean(AttestPeriod $attest): void
    {
        $this->run(function () use ($attest): string {
            ['from' => $from, 'to' => $to] = $this->dates();
            $result = $attest($this->user(), $this->company(), $from, $to, $this->person, onlyWithoutDeviations: true);

            return self::attested($result);
        });
    }

    public function send(SendToPayroll $send): void
    {
        $this->run(function () use ($send): string {
            ['from' => $from, 'to' => $to] = $this->dates();
            $result = $send($this->user(), $this->company(), $from, $to, $this->person);

            if ($result['skipped'] !== []) {
                $this->toast(__(':count dagar skickades inte: :reasons', ['count' => count($result['skipped']), 'reasons' => implode(' ', $result['skipped'])]), 'error');
            }

            return $result['sent'] === 0
                ? __('Inga attesterade dagar att skicka till lönen.')
                : trans_choice('{1} 1 dag är skickad till lönen.|[2,*] :count dagar är skickade till lönen.', $result['sent'], ['count' => $result['sent']]);
        });
    }

    public function reopen(int $attestationId, ReopenAttestation $reopen): void
    {
        $this->run(function () use ($attestationId, $reopen): string {
            $attestation = Attestation::query()->findOrFail($attestationId);
            $sent = $attestation->status === Attestation::SENT;
            $reopen($this->user(), $this->company(), $attestation);

            return $sent ? __('Dagen är öppnad igen. Tidrapporterna i lönen är borttagna.') : __('Dagen är öppnad igen.');
        });
    }

    public function chooseCompensation(int $attestationId, string $choice, ChooseCompensation $choose): void
    {
        $this->run(function () use ($attestationId, $choice, $choose): string {
            $choose($this->user(), $this->company(), Attestation::query()->findOrFail($attestationId), $choice);

            return $choice === WeekBasis::COMP_TIME
                ? __('Övertiden sparas som komptid när dagen skickas till lönen.')
                : __('Övertiden betalas ut när dagen skickas till lönen.');
        });
    }

    /** Opens the editor for a day that has no row yet: work without a planned shift, for example when the staff ledger is not used. */
    public function addDay(): void
    {
        abort_unless($this->allowed('planning:attest'), 403);
        $this->resetErrorBag();
        $this->form = [
            'employee_id' => $this->person,
            'date' => CarbonImmutable::today(LocalTimestamp::ZONE)->min(self::date($this->to) ?? CarbonImmutable::today(LocalTimestamp::ZONE))->toDateString(),
            'intervals' => [['start' => '', 'end' => '', 'break_minutes' => 0]],
            'note' => '',
            'none' => false,
            'new' => true,
        ];
        $this->showEditor = true;
    }

    public function edit(int $employeeId, string $date, AttestData $data): void
    {
        abort_unless($this->allowed('planning:attest'), 403);
        $day = CarbonImmutable::parse($date, LocalTimestamp::ZONE);
        $row = collect($data->load($this->company(), $day, $day, $employeeId)['rows'])->first();
        $intervals = [];

        foreach ($row instanceof AttestRow ? $row->comparison->actual : [] as $interval) {
            $intervals[] = ['start' => $interval->start?->format('H:i') ?? '', 'end' => $interval->end?->format('H:i') ?? '', 'break_minutes' => $interval->breakMinutes];
        }

        if ($intervals === [] && $row instanceof AttestRow) {
            foreach ($row->comparison->planned as $interval) {
                $intervals[] = ['start' => $interval->start?->format('H:i') ?? '', 'end' => $interval->end?->format('H:i') ?? '', 'break_minutes' => $interval->breakMinutes];
            }
        }

        $this->resetErrorBag();
        $this->form = [
            'employee_id' => $employeeId,
            'date' => $day->toDateString(),
            'intervals' => $intervals === [] ? [['start' => '', 'end' => '', 'break_minutes' => 0]] : array_slice($intervals, 0, SaveActualTime::MAX_INTERVALS),
            'note' => $row instanceof AttestRow ? (string) $row->attestation?->note : '',
            'none' => false,
            'new' => false,
        ];
        $this->showEditor = true;
    }

    public function addInterval(): void
    {
        if (count($this->form['intervals']) < SaveActualTime::MAX_INTERVALS) {
            $this->form['intervals'][] = ['start' => '', 'end' => '', 'break_minutes' => 0];
        }
    }

    public function saveActual(SaveActualTime $save): void
    {
        try {
            $save($this->user(), $this->company(), [
                'employee_id' => $this->form['employee_id'],
                'date' => $this->form['date'],
                'intervals' => $this->form['none'] ? [] : array_values(array_filter($this->form['intervals'], fn (array $interval): bool => $interval['start'] !== '' || $interval['end'] !== '')),
                'note' => $this->form['note'] === '' ? null : $this->form['note'],
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showEditor = false;
        $this->toast(__('Den faktiska tiden är sparad. Personalliggaren är oförändrad.'));
    }

    public function clearActual(SaveActualTime $save): void
    {
        $this->run(function () use ($save): string {
            $save($this->user(), $this->company(), ['employee_id' => $this->form['employee_id'], 'date' => $this->form['date'], 'clear' => true]);
            $this->showEditor = false;

            return __('Den ändrade tiden är borttagen. Tiden räknas från personalliggaren igen.');
        });
    }

    public function render(AttestData $data): View
    {
        ['from' => $from, 'to' => $to] = $this->dates();
        $loaded = $data->load($this->company(), $from, $to, $this->person);
        $all = collect($loaded['rows']);
        $rows = $all->filter(fn (AttestRow $row): bool => match ($this->filter) {
            'avvikelser' => $row->hasDeviations(),
            'att-attestera' => $row->isOpen(),
            'attesterade' => ! $row->isOpen(),
            default => true,
        });

        return view('planning::livewire.attest', [
            'groups' => $rows->groupBy(fn (AttestRow $row): int => $row->employee->id),
            'employees' => $this->person === null ? $loaded['employees'] : Employee::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'ledger' => $loaded['ledger'],
            'clean' => $all->filter(fn (AttestRow $row): bool => $row->canAttest() && ! $row->hasDeviations())->count(),
            'open' => $all->filter(fn (AttestRow $row): bool => $row->isOpen())->count(),
            'sendable' => $all->filter(fn (AttestRow $row): bool => $row->status() === Attestation::ATTESTED && $row->lockReason === null)->count(),
            'sent' => $all->filter(fn (AttestRow $row): bool => $row->status() === Attestation::SENT)->count(),
            'fromDate' => $from,
            'toDate' => $to,
        ]);
    }

    /**
     * The period from the URL, else the month of the first open regular
     * payroll run, else this month so far.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(): array
    {
        $from = self::date($this->from);
        $to = self::date($this->to);

        if ($from !== null && $to !== null && $to->greaterThanOrEqualTo($from) && $from->diffInDays($to) <= AttestPeriod::MAX_DAYS) {
            return [$from, $to];
        }

        $period = PayrollRun::query()->where('type', 'REGULAR')->whereIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])->orderBy('period')->value('period');
        $month = is_string($period) ? CarbonImmutable::parse("{$period}-01", LocalTimestamp::ZONE) : CarbonImmutable::today(LocalTimestamp::ZONE)->startOfMonth();

        return [$month, $month->endOfMonth()->startOfDay()];
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalTimestamp::ZONE) ?: null;
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    private function dates(): array
    {
        [$from, $to] = $this->period();

        return ['from' => $from, 'to' => $to];
    }

    /** @param  array{attested: int, skipped: int}  $result */
    private static function attested(array $result): string
    {
        $message = trans_choice('{0} Inga dagar att attestera.|{1} 1 dag är attesterad.|[2,*] :count dagar är attesterade.', $result['attested'], ['count' => $result['attested']]);

        return $result['skipped'] > 0
            ? $message.' '.trans_choice('{1} 1 dag väntar: den har en avvikelse att rätta eller titta på.|[2,*] :count dagar väntar: de har avvikelser att rätta eller titta på.', $result['skipped'], ['count' => $result['skipped']])
            : $message;
    }

    /** @param  callable(): string  $action */
    private function run(callable $action): void
    {
        abort_unless($this->allowed('planning:attest'), 403);

        try {
            $this->toast($action());
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');
        }
    }
}
