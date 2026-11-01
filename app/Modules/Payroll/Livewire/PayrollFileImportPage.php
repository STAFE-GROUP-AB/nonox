<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Modules\Payroll\Actions\PayrollFileImports;
use App\Modules\Payroll\Actions\PayrollImportSources;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A payroll file read into a run: what was matched and what was not, the
 * external codes still to map (saved for the source system), and the lines
 * a person approves as inputs to the run.
 */
class PayrollFileImportPage extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const array FILTERS = ['att-godkanna', 'anmarkning', 'godkanda', 'hoppas-over', 'alla'];

    #[Locked]
    public int $runId;

    #[Locked]
    public int $importId;

    #[Url(as: 'visa')]
    public string $filter = 'att-godkanna';

    public function mount(PayrollRun $run, PayrollFileImport $import): void
    {
        abort_unless(Gate::allows('view', $run) && $import->payroll_run_id === $run->id, 404);

        $this->runId = $run->id;
        $this->importId = $import->id;
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'att-godkanna';
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function map(string $code, string $choice, PayrollFileImports $imports): void
    {
        if ($choice === '') {
            return;
        }

        $ignored = $choice === 'ignore';
        $imports->map($this->user(), $this->run(), $this->import(), $code, $ignored ? null : (int) $choice, $ignored);

        $this->toast($ignored
            ? __(':code läses inte in, i den här filen och i kommande filer från samma system.', ['code' => $code])
            : __(':code är kopplad till lönearten. Kopplingen gäller också kommande filer från samma system.', ['code' => $code]));
    }

    public function reprocess(PayrollFileImports $imports): void
    {
        $imports->reprocess($this->user(), $this->run(), $this->import());

        $this->toast(__('Filen är inläst igen. Godkända rader är kvar som de var.'));
    }

    public function approveAll(PayrollFileImports $imports): void
    {
        $count = $imports->approve($this->user(), $this->run(), $this->import());

        $this->toast(__(':count rader är godkända och ligger i lönekörningen. Beräkna lönekörningen igen.', ['count' => $count]));
    }

    public function approve(int $lineId, PayrollFileImports $imports): void
    {
        $imports->approve($this->user(), $this->run(), $this->import(), [$lineId]);

        $this->toast(__('Raden är godkänd och ligger i lönekörningen. Beräkna lönekörningen igen.'));
    }

    public function reject(int $lineId, PayrollFileImports $imports): void
    {
        $imports->reject($this->user(), $this->run(), $this->import(), $lineId);

        $this->toast(__('Raden är avvisad och kommer inte med i lönen.'));
    }

    public function unapprove(int $lineId, PayrollFileImports $imports): void
    {
        $imports->unapprove($this->user(), $this->run(), $this->import(), $lineId);

        $this->toast(__('Godkännandet är ångrat och raden är borttagen ur lönekörningen. Beräkna lönekörningen igen.'));
    }

    public function delete(PayrollFileImports $imports): void
    {
        $run = $this->run();
        $import = $this->import();
        $imports->delete($this->user(), $run, $import);

        Toast::flash(__(':file är borttagen ur lönekörningen.', ['file' => $import->original_name]));
        $this->redirectRoute('payroll.runs.show', $run, navigate: false);
    }

    public function render(): View
    {
        $run = $this->run();
        $import = PayrollFileImport::query()->with(['source:id,name', 'uploader:id,name'])->findOrFail($this->importId);
        $user = $this->user();
        $company = $user->currentCompany();
        $base = PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id);
        $counts = (clone $base)
            ->selectRaw("status, jsonb_array_length(problems) > 0 AND status = 'PROPOSED' AS has_problems, count(*) AS lines")
            ->groupBy('status', 'has_problems')
            ->get()
            ->reduce(function (array $carry, PayrollFileImportLine $row): array {
                $key = $row->getAttribute('has_problems') ? 'problems' : strtolower($row->status);
                $carry[$key] = ($carry[$key] ?? 0) + (int) $row->getAttribute('lines');
                $carry['all'] += (int) $row->getAttribute('lines');

                return $carry;
            }, ['all' => 0]);
        $unmapped = (clone $base)->whereJsonContains('problems', 'unmapped_code')->select('external_code')->distinct()->orderBy('external_code')->pluck('external_code');
        $unknownEmployees = (clone $base)->where(fn ($query) => $query->whereJsonContains('problems', 'unknown_employee')->orWhereJsonContains('problems', 'employee_conflict'))
            ->select(['employee_ref', 'person_ref'])
            ->distinct()
            ->get()
            ->map(fn (PayrollFileImportLine $line): string => trim(($line->employee_ref === null ? '' : __('anställningsnummer :number', ['number' => $line->employee_ref])).' '.($line->person_ref ?? '')))
            ->unique()
            ->values();

        $lines = (clone $base)
            ->with(['employee:id,employee_number,first_name,last_name', 'payCode:id,code,name_sv'])
            ->when($this->filter === 'att-godkanna', fn ($query) => $query->where('status', PayrollFileImportLine::PROPOSED)->whereRaw("problems = '[]'::jsonb"))
            ->when($this->filter === 'anmarkning', fn ($query) => $query->where('status', PayrollFileImportLine::PROPOSED)->whereRaw("problems <> '[]'::jsonb"))
            ->when($this->filter === 'godkanda', fn ($query) => $query->where('status', PayrollFileImportLine::APPROVED))
            ->when($this->filter === 'hoppas-over', fn ($query) => $query->whereIn('status', [PayrollFileImportLine::SKIPPED, PayrollFileImportLine::REJECTED]))
            ->orderBy('line_no')
            ->paginate(100);

        return view('payroll::livewire.file-import', [
            'run' => $run,
            'import' => $import,
            'counts' => $counts,
            'unmapped' => $unmapped,
            'unknownEmployees' => $unknownEmployees,
            'lines' => $lines,
            'payCodes' => PayrollImportSources::payCodes(),
            'canEdit' => Gate::allows('update', $run),
            'canDownload' => $company !== null && $user->hasTeamPermission($company, 'payroll:view-sensitive'),
        ]);
    }

    /** What a problem or warning on a line means, in Swedish. */
    public static function message(string $key): string
    {
        return match ($key) {
            'unknown_employee' => __('Ingen anställd har det anställningsnumret eller personnumret.'),
            'employee_conflict' => __('Anställningsnumret och personnumret pekar på olika personer.'),
            'unmapped_code' => __('Koden är inte kopplad till någon löneart.'),
            'missing_code' => __('Raden saknar löneart.'),
            'missing_value' => __('Raden saknar antal och belopp.'),
            'bad_number' => __('Ett tal på raden går inte att läsa, eller har fler decimaler än öre och tusendels timmar.'),
            'bad_date' => __('Datumet går inte att läsa.'),
            'negative' => __('Negativa värden läses inte in. Lägg in rättelsen för hand i lönekörningen.'),
            'rate_required' => __('Raden saknar à-pris och belopp. Bara timlön kan räknas med anställningens timlön.'),
            'absence_code' => __('Frånvaro läses inte in från filen. Registrera den under Frånvaro i lönekörningen.'),
            'balance_code' => __('Schematid och saldon är inte lön och läses inte in.'),
            'ignored_code' => __('Koden är vald att inte läsas in.'),
            'zero' => __('Raden har inget antal eller belopp.'),
            'not_employed' => __('Den anställda har ingen anställning under lönekörningens period.'),
            'outside_period' => __('Datumet ligger utanför lönekörningens period.'),
            'amount_differs' => __('Beloppet stämmer inte med antal × à-pris. Beloppet används.'),
            default => $key,
        };
    }

    private function run(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->runId);
    }

    private function import(): PayrollFileImport
    {
        return PayrollFileImport::query()->where('payroll_run_id', $this->runId)->findOrFail($this->importId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
