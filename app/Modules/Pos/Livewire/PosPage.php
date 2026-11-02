<?php

declare(strict_types=1);

namespace App\Modules\Pos\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Pos\Actions\ConnectPos;
use App\Modules\Pos\Actions\CreateDailyDraft;
use App\Modules\Pos\Actions\DisconnectPos;
use App\Modules\Pos\Actions\LinkPosSaleRow;
use App\Modules\Pos\Actions\ReconcileSales;
use App\Modules\Pos\Actions\SavePosConnectionSettings;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Drivers\Onslip\OnslipDriver;
use App\Modules\Pos\Http\PosAuthController;
use App\Modules\Pos\Jobs\SyncProductsJob;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosLogEntry;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Modules\Pos\Support\PosApiFailed;
use App\Modules\Pos\Support\PosGate;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Anslutningar → Kassa (ADR-042): the tills that can be connected, each
 * connection with its status, settings and log, the till lines waiting for
 * an article (Omatchade) and the days whose sales are not yet in a voucher.
 */
class PosPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithPagination;

    public const string NAME = 'pos.page';

    public ?int $confirmingDisconnect = null;

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{id: int, name: string, vat_rate: ?int}> */
    public array $productGroups = [];

    /** @var array<int, string> row id => what the person typed (article number, GTIN, SKU) */
    public array $articleFor = [];

    public function mount(): void
    {
        abort_unless(PosGate::canView($this->user(), $this->company()), 403);
    }

    public function connect(string $provider, ConnectPos $connect): void
    {
        $till = Provider::tryFrom($provider) ?? abort(404);

        try {
            ['url' => $url, 'flow' => $flow] = $connect->start($this->user(), $this->company(), $till, route('pos.callback', ['provider' => $till->value]));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        session()->put(PosAuthController::SESSION, $flow);
        $this->redirect($url);
    }

    public function askDisconnect(int $connectionId): void
    {
        $this->authorizeManage();
        $this->confirmingDisconnect = PosConnection::query()->findOrFail($connectionId)->id;
    }

    public function disconnect(DisconnectPos $disconnect): void
    {
        if ($this->confirmingDisconnect === null) {
            return;
        }

        $connection = PosConnection::query()->findOrFail($this->confirmingDisconnect);
        $this->guarded(fn () => $disconnect($this->user(), $this->company(), $connection));
        $this->confirmingDisconnect = null;
        $this->toast(__(':till är bortkopplad. Det som redan kommit in finns kvar.', ['till' => $connection->provider->label()]));
    }

    public function edit(int $connectionId): void
    {
        $this->authorizeManage();
        $connection = PosConnection::query()->findOrFail($connectionId);
        $this->editing = $connection->id;
        $this->productGroups = [];
        $this->form = [
            'sync_direction' => $connection->syncDirection(),
            'push_products' => $connection->pushesProducts(),
            'push_stock' => $connection->pushesStock(),
            'location_id' => $connection->location_id === null ? '' : (string) $connection->location_id,
            'product_groups' => array_map('strval', (array) ($connection->settings['product_groups'] ?? [])),
        ];
    }

    public function loadProductGroups(OnslipDriver $driver): void
    {
        $this->authorizeManage();
        $connection = PosConnection::query()->findOrFail((int) $this->editing);

        try {
            $this->productGroups = $driver->productGroups($connection);
        } catch (PosApiFailed|ConnectionException) {
            $this->toast(__('Varugrupperna gick inte att hämta från kassan. Försök igen om en stund.'), 'error');
        }
    }

    public function saveSettings(SavePosConnectionSettings $save): void
    {
        $connection = PosConnection::query()->findOrFail((int) $this->editing);
        $input = [...$this->form, 'location_id' => ($this->form['location_id'] ?? '') === '' ? null : (int) $this->form['location_id']];

        try {
            $save($this->user(), $this->company(), $connection, $input);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->editing = null;
        $this->toast(__('Inställningarna för :till är sparade.', ['till' => $connection->provider->label()]));
    }

    public function syncProducts(int $connectionId): void
    {
        $this->authorizeManage();
        $connection = PosConnection::query()->findOrFail($connectionId);
        SyncProductsJob::dispatch($connection->company_id, $connection->id);
        $this->toast(__('Artiklarna skickas till kassan om en liten stund.'));
    }

    public function fetchSales(int $connectionId, ReconcileSales $reconcile): void
    {
        $this->authorizeManage();
        $connection = PosConnection::query()->findOrFail($connectionId);

        try {
            $result = $reconcile($connection, CarbonImmutable::today('Europe/Stockholm')->subDays(2), CarbonImmutable::now('Europe/Stockholm')->addMinute());
        } catch (PosApiFailed|ConnectionException) {
            $this->toast(__('Kassan svarade inte som väntat. Försök igen om en stund.'), 'error');

            return;
        }

        $this->toast(__(':count nya försäljningar hämtades.', ['count' => $result['new']]));
    }

    public function linkRow(int $rowId, LinkPosSaleRow $link): void
    {
        $row = PosSaleRow::query()->findOrFail($rowId);
        $code = trim((string) ($this->articleFor[$rowId] ?? ''));
        $article = $code === '' ? null : Article::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('number', $code)->orWhere('gtin', $code)->orWhere('scanner_code', $code)->orWhere('sku', $code))
            ->first();

        if (! $article instanceof Article) {
            $this->addError("articleFor.{$rowId}", __('Ingen aktiv artikel har det numret eller den koden.'));
            $this->toast(__('Ingen aktiv artikel har det numret eller den koden.'), 'error');

            return;
        }

        try {
            $count = $link($this->user(), $this->company(), $row, $article->id);
        } catch (ValidationException $exception) {
            $this->addError("articleFor.{$rowId}", $this->firstError($exception));
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->articleFor[$rowId]);
        $this->toast(trans_choice(':count rad är kopplad till :article och dragen från lagret.|:count rader är kopplade till :article och dragna från lagret.', $count, ['count' => $count, 'article' => $article->number]));
    }

    public function ignoreRow(int $rowId, LinkPosSaleRow $link): void
    {
        $row = PosSaleRow::query()->findOrFail($rowId);

        try {
            $link->ignore($this->user(), $this->company(), $row);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->toast(__('Raden är undantagen och drar inget från lagret.'));
    }

    public function createDraft(string $date, CreateDailyDraft $create): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            abort(404);
        }

        try {
            $voucher = $create($this->user(), $this->company(), CarbonImmutable::parse($date));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Toast::flash(__('Verifikationsutkastet för :date är skapat i serie K. Kontrollera det mot kassans dagsrapport och bokför det.', ['date' => $date]));
        $this->redirectRoute('accounting.vouchers.edit', ['voucher' => $voucher->id]);
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $connections = PosConnection::query()->with('location:id,full_code,name')->orderByDesc('connected_at')->get();
        $logs = PosLogEntry::query()->whereIn('pos_connection_id', $connections->modelKeys())->latest('id')->limit(30)->get()->groupBy('pos_connection_id');

        return view('pos::livewire.page', [
            'providers' => Provider::cases(),
            'connections' => $connections->groupBy(fn (PosConnection $connection): string => $connection->provider->value),
            'logs' => $logs,
            'unmatched' => PosSaleRow::query()->where('status', PosSaleRow::UNMATCHED)->with('sale:id,pos_connection_id,provider,external_id,business_date,sold_at')->orderByDesc('id')->paginate(25, pageName: 'omatchade'),
            'days' => PosSale::query()->whereNull('voucher_id')
                ->select('business_date', DB::raw('count(*) as sales'), DB::raw('sum(total_ore) as total_ore'))
                ->groupBy('business_date')->orderByDesc('business_date')->limit(31)->get(),
            'locations' => $this->editing === null ? collect() : StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'canManage' => $user->hasTeamPermission($company, 'settings:manage'),
            'canLink' => $user->hasTeamPermission($company, 'inventory:manage'),
            'canDraft' => $user->hasTeamPermission($company, 'vouchers:create'),
            'disconnected' => ConnectionStatus::Disconnected,
        ]);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'settings:manage'), 403);
    }

    /** @param  callable(): mixed  $action */
    private function guarded(callable $action): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
