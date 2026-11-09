<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Kitchen\Actions\RegisterWaste;
use App\Modules\Kitchen\Domain\WasteReason;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kök → Svinn (ADR-073 §6): registering waste of an ingredient or a dish
 * with its reason, plate waste in kg, and the waste log with corrections.
 * The same action as the scanner's Svinn tile. No prices on this page.
 */
class WastePage extends Component
{
    use KitchenPage;
    use WithPagination;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, ?int> cost per unit by article, for this render only */
    protected array $unitCosts = [];

    /** @var array<string, string> */
    public array $plate = ['kg' => '', 'date' => '', 'note' => ''];

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->resetForm();
    }

    public function register(RegisterWaste $register): void
    {
        abort_unless($this->can('register'), 403);

        if ($this->attempt(fn () => $register($this->user(), $this->company(), $this->form), __('Svinnet är registrerat och draget från lagret.'))) {
            $this->resetForm();
        }
    }

    public function correct(int $entryId, RegisterWaste $register): void
    {
        abort_unless($this->can('register'), 403);
        $entry = WasteEntry::query()->findOrFail($entryId);

        $this->attempt(fn () => $register->correct($this->user(), $this->company(), $entry), __('Raden är rättad och varorna är tillbaka i lagret.'), 'entry.');
    }

    public function weigh(RegisterWaste $register): void
    {
        abort_unless($this->can('register'), 403);

        if ($this->attempt(fn () => $register->plate($this->user(), $this->company(), $this->plate['kg'], $this->plate['date'] ?: null, $this->plate['note'] ?: null), __('Tallrikssvinnet är registrerat.'), 'plate.')) {
            $this->plate = ['kg' => '', 'date' => '', 'note' => ''];
        }
    }

    public function render(): View
    {
        $company = $this->company();
        $book = StructureBook::for($company);
        $today = CarbonImmutable::today();
        $dishIds = array_values(array_filter($book->outputs(), fn (int $id): bool => $book->validOn($id, $today) !== null));

        return view('kitchen::livewire.waste', [
            'donationValue' => $this->donationValue($book, $today),
            'canRegister' => $this->can('register'),
            'reasons' => WasteReason::cases(),
            'ingredients' => Article::query()->where('is_stock_item', true)->where('is_active', true)->orderBy('name')->get(['id', 'number', 'name', 'unit']),
            'dishes' => Article::query()->whereKey($dishIds)->orderBy('name')->get(['id', 'number', 'name', 'unit']),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'entries' => WasteEntry::query()->with(['article:id,name,unit', 'location:id,full_code', 'creator:id,name', 'correction:id,corrects_entry_id'])->orderByDesc('registered_on')->orderByDesc('id')->paginate(25),
            'measurements' => WasteMeasurement::query()->orderByDesc('measured_on')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    /**
     * The cost value of a donation, the possible tax base (RS-R11), for
     * those who may see costs; null otherwise or while incomplete.
     */
    private function donationValue(StructureBook $book, CarbonImmutable $today): ?int
    {
        if (($this->form['reason'] ?? '') !== WasteReason::Donated->value || ! $this->can('seeCosts') || ($this->form['article_id'] ?? '') === '') {
            return null;
        }

        try {
            $quantity = Quantity::parse((string) ($this->form['quantity'] ?? ''));
        } catch (InvalidArgumentException) {
            return null;
        }

        $articleId = (int) $this->form['article_id'];
        // Once per article and render: the stock valuation behind it reads every movement.
        if (! array_key_exists($articleId, $this->unitCosts)) {
            $this->unitCosts[$articleId] = $book->unitCost($articleId, UnitCosts::at($this->company(), $today), $today)['ore'];
        }

        $unit = $this->unitCosts[$articleId];

        return $unit === null ? null : Quantity::value($quantity, $unit);
    }

    private function resetForm(): void
    {
        $this->form = [
            'date' => CarbonImmutable::today()->toDateString(),
            'kind' => WasteEntry::INGREDIENT,
            'article_id' => '',
            'quantity' => '',
            'location_id' => (string) (KitchenSettings::locationId($this->company()) ?? ''),
            'reason' => '',
            'recipient' => '',
            'note' => '',
        ];
    }
}
