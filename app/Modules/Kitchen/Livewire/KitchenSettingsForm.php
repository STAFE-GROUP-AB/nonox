<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kök's settings, on Kök → Inställningar and under Moduler (ADR-073): the
 * kitchen's stock location, the target food cost, the cost of a "valfri
 * rätt" staff meal (kitchen:manage), and drawing ingredients on sale,
 * which changes how stock moves and so needs inventory:settings.
 */
class KitchenSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'kitchen.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'kitchen:manage'), 403);

        $this->companyId = $company->id;
        $stored = KitchenSettings::for($company);
        $this->settings = [
            'location_id' => (string) ($stored['location_id'] ?? ''),
            'target_food_cost' => $stored['target_food_cost_bp'] === null ? '' : str_replace('.', ',', (string) BigDecimal::of((int) $stored['target_food_cost_bp'])->dividedBy(100, 2)),
            'average_meal_cost' => $stored['average_meal_cost_ore'] === null ? '' : Money::ofOre((int) $stored['average_meal_cost_ore'])->format(withCurrency: false),
            'draw_on_sale' => (bool) $stored['draw_on_sale'],
        ];
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'kitchen:manage'), 403);
        $stored = KitchenSettings::for($company);

        try {
            $location = $this->settings['location_id'] === '' ? null : (int) $this->settings['location_id'];

            if ($location !== null && ! CurrentCompany::run($company, fn (): bool => StockLocation::query()->whereKey($location)->where('is_active', true)->exists())) {
                throw ValidationException::withMessages(['settings.location_id' => __('Lagerstället finns inte eller är inaktivt.')]);
            }

            $values = [
                'location_id' => $location,
                'target_food_cost_bp' => self::percent((string) $this->settings['target_food_cost']),
                'average_meal_cost_ore' => self::money((string) $this->settings['average_meal_cost']),
            ];
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $draw = (bool) $this->settings['draw_on_sale'];

        if ($draw !== (bool) $stored['draw_on_sale']) {
            abort_unless($this->user()->hasTeamPermission($company, 'inventory:settings'), 403);
            $values['draw_on_sale'] = $draw;
            // Only sales from the day it is switched on are drawn, never the history.
            $values['draw_on_sale_from'] = $draw ? CarbonImmutable::today()->toDateString() : $stored['draw_on_sale_from'];
        }

        $modules->updateSettings(KitchenModule::KEY, $company, $this->user(), $values);
        $this->resetErrorBag();
        $this->toast(__('Inställningarna för köket är sparade.'));
    }

    public function render(): View
    {
        $company = Company::query()->findOrFail($this->companyId);

        return view('kitchen::livewire.settings-form', [
            'locations' => CurrentCompany::run($company, fn () => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name'])),
            'canDraw' => $this->user()->hasTeamPermission($company, 'inventory:settings'),
        ]);
    }

    private static function percent(string $text): ?int
    {
        $text = str_replace([' ', ',', '%'], ['', '.', ''], trim($text));

        if ($text === '') {
            return null;
        }

        try {
            $bp = BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages(['settings.target_food_cost' => __('Skriv målet i procent, till exempel 30.')]);
        }

        if ($bp <= 0 || $bp >= 10_000) {
            throw ValidationException::withMessages(['settings.target_food_cost' => __('Målet ska vara mellan 0 och 100 procent.')]);
        }

        return $bp;
    }

    private static function money(string $text): ?int
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $ore = Money::parse($text)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['settings.average_meal_cost' => __('Beloppet går inte att läsa. Skriv till exempel 45,00.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages(['settings.average_meal_cost' => __('Beloppet kan inte vara negativt.')]);
        }

        return $ore;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
