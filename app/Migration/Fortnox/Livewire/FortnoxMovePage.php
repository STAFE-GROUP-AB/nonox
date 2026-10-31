<?php

declare(strict_types=1);

namespace App\Migration\Fortnox\Livewire;

use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Migration\Fortnox\FortnoxConnection;
use App\Migration\Fortnox\FortnoxImportedFile;
use App\Migration\Fortnox\FortnoxMove;
use App\Migration\Fortnox\FortnoxOAuth;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * The move from Fortnox, step by step: connect, fetch each financial year
 * as SIE4 for review, registers, attachments in batches, open items, and
 * finish (which deletes the tokens).
 */
class FortnoxMovePage extends Component
{
    use ShowsToasts;

    public const string NAME = 'fortnox.move-page';

    /** @var list<array{id: int, from: string, to: string}> */
    public array $years = [];

    /** @var array<string, mixed>|null */
    public ?array $openItems = null;

    public bool $fetchingFiles = false;

    public function mount(): void
    {
        abort_unless(Gate::allows('importFortnox', $this->company()), 403);
    }

    public function loadYears(FortnoxMove $move): void
    {
        $this->attempt(fn () => $this->years = $move->financialYears($this->connection()));
    }

    public function fetchYear(FortnoxMove $move, int $fortnoxYear): void
    {
        $year = collect($this->years)->firstWhere('id', $fortnoxYear);

        if ($year === null) {
            return;
        }

        $this->attempt(function () use ($move, $year): void {
            $import = $move->fetchSieYear($this->user(), $this->company(), $this->connection(), $year['id'], substr($year['from'], 0, 4).'-'.substr($year['to'], 0, 4));
            Toast::flash(__('Räkenskapsåret :from – :to är hämtat från Fortnox. Granska rapporten och läs in det.', ['from' => $year['from'], 'to' => $year['to']]));
            $this->redirectRoute('accounting.sieImports.show', $import);
        });
    }

    public function importRegisters(FortnoxMove $move): void
    {
        $this->attempt(function () use ($move): void {
            $result = $move->importRegisters($this->user(), $this->company(), $this->connection());
            $this->toast(__(':customers kunder, :suppliers leverantörer och :articles artiklar har lagts till.', [
                'customers' => $result['customers']['created'],
                'suppliers' => $result['suppliers']['created'],
                'articles' => $result['articles']['created'],
            ]));
        });
    }

    public function startFiles(FortnoxMove $move): void
    {
        $this->attempt(function () use ($move): void {
            $connection = $this->connection();

            foreach ($this->importedYears($connection) as $fortnoxYear) {
                $move->queueFiles($this->company(), $connection, $fortnoxYear);
            }

            $this->fetchingFiles = true;
        });
    }

    /** Called by wire:poll while files are being fetched. */
    public function nextFiles(FortnoxMove $move): void
    {
        if (! $this->fetchingFiles) {
            return;
        }

        $this->attempt(function () use ($move): void {
            $waiting = $move->importFiles($this->user(), $this->company(), $this->connection());

            if ($waiting === 0) {
                $this->fetchingFiles = false;
                $this->toast(__('Alla underlag är hämtade.'));
            }
        });
    }

    public function compareOpenItems(FortnoxMove $move): void
    {
        $this->attempt(fn () => $this->openItems = $move->openItems($this->company(), $this->connection()));
    }

    public function complete(FortnoxMove $move): void
    {
        $this->attempt(function () use ($move): void {
            $move->complete($this->user(), $this->company(), $this->connection());
            $this->toast(__('Flytten är klar. Anslutningen till Fortnox är borttagen.'));
        });
    }

    public function render(): View
    {
        $connection = FortnoxConnection::query()->first();
        $imports = $connection === null ? collect() : SieImport::query()->whereIn('id', array_values((array) ($connection->progress['years'] ?? [])))->get()->keyBy('id');
        $files = FortnoxImportedFile::query()->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status');

        return view('fortnox::move-page', [
            'configured' => FortnoxOAuth::isConfigured(),
            'connection' => $connection,
            'imports' => $imports,
            'files' => $files->map(fn (mixed $count): int => (int) $count),
            'failedFiles' => FortnoxImportedFile::query()->where('status', FortnoxImportedFile::FAILED)->latest('id')->limit(10)->get(),
        ]);
    }

    /** @return list<int> Fortnox years whose SIE import has been read in */
    private function importedYears(FortnoxConnection $connection): array
    {
        $years = [];

        foreach ((array) ($connection->progress['years'] ?? []) as $fortnoxYear => $importId) {
            $import = is_numeric($importId) ? SieImport::query()->find((int) $importId) : null;

            if ($import?->fiscal_year_id !== null && $import->status === SieImportStatus::Completed) {
                $years[] = (int) $fortnoxYear;
            }
        }

        return $years;
    }

    private function attempt(callable $step): void
    {
        try {
            $step();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (RuntimeException $exception) {
            report($exception);
            $this->fetchingFiles = false;
            $this->toast(__('Fortnox svarade inte som väntat: :message', ['message' => $exception->getMessage()]), 'error');
        }
    }

    private function connection(): FortnoxConnection
    {
        $connection = FortnoxConnection::query()->first();

        if ($connection === null || ! $connection->isConnected()) {
            throw ValidationException::withMessages(['fortnox' => __('Anslut till Fortnox först.')]);
        }

        return $connection;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
