<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Modules\Kitchen\Domain\MealReason;
use App\Modules\Kitchen\Domain\MealType;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Support\RecipeStock;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff meals (ADR-073 §7). A meal of a dish is exploded through the
 * recipe valid on the day and leaves stock as INTERNAL_USE ("Eget bruk")
 * with the reason; "valfri rätt" (no dish) has no recipe and moves no
 * stock, and is costed at the company's average portion cost. The
 * raw-material cost is kept as underlag, never as a VAT base: no VAT is
 * booked on staff meals (RS-R13, TODO(legal-verify) L7). No voucher.
 *
 * Without payroll the log holds counts only and names nobody. With payroll
 * on, a staff meal (PERSONALMAT) names the employee, in whole meals, with
 * what the employee pays per meal by deduction from pay; representation
 * and the owner's meals name nobody.
 *
 * Staff meals sold at a staff price through the till are sales and arrive
 * from the till; they are not entered here (RS-R17).
 */
class RegisterStaffMeal
{
    use GuardsKitchen;

    public function __construct(private readonly StockLedger $ledger, private readonly ModuleManager $modules) {}

    /**
     * @param  array<string, mixed>  $input  date, meal, article_id (empty = valfri rätt), quantity, reason, employee_id, paid_per_meal (kronor as written), location_id
     */
    public function __invoke(User $user, Company $company, array $input): StaffMeal
    {
        $this->guard($user, $company, 'register');
        $payroll = $this->modules->isEnabled(PayrollModule::KEY, $company);

        $validated = Validator::make($input, [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'meal' => ['required', Rule::enum(MealType::class)],
            'article_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', Rule::enum(MealReason::class)],
            'employee_id' => ['nullable', 'integer'],
            'paid_per_meal' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'integer'],
        ])->validate();

        $date = CarbonImmutable::parse($validated['date'] ?? CarbonImmutable::today()->toDateString())->startOfDay();

        if ($date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => __('Registrera måltiden när den har ätits.')]);
        }

        $reason = MealReason::from((string) $validated['reason']);
        $employeeId = ($validated['employee_id'] ?? null) === null ? null : (int) $validated['employee_id'];
        $quantity = self::quantity((string) (($validated['quantity'] ?? '') ?: '1'));
        $paid = self::money((string) ($validated['paid_per_meal'] ?? ''));

        if ($employeeId !== null && (! $payroll || $reason !== MealReason::Staff)) {
            throw ValidationException::withMessages(['employee_id' => $payroll
                ? __('Bara personalmat registreras per anställd. Representation och ägarens måltider registreras utan namn.')
                : __('Utan Lön registreras personalmat bara som antal, utan namn.')]);
        }

        if ($payroll && $reason === MealReason::Staff && $employeeId === null) {
            throw ValidationException::withMessages(['employee_id' => __('Välj vem som åt. Lön är på, och kostförmånen redovisas per anställd.')]);
        }

        if ($employeeId !== null && $quantity % Quantity::SCALE !== 0) {
            throw ValidationException::withMessages(['quantity' => __('Ange hela måltider för en anställd.')]);
        }

        return CurrentCompany::run($company, fn (): StaffMeal => DB::transaction(function () use ($user, $company, $validated, $date, $reason, $employeeId, $quantity, $paid): StaffMeal {
            if ($employeeId !== null) {
                Employee::query()->findOrFail($employeeId);
            }

            $article = ($validated['article_id'] ?? null) === null ? null : Article::query()->findOrFail((int) $validated['article_id']);
            $book = StructureBook::for($company);
            $lines = [];
            $rawCost = null;

            if ($article === null) {
                $average = KitchenSettings::for($company)['average_meal_cost_ore'];
                $rawCost = $average === null ? null : Quantity::value($quantity, (int) $average);
            } else {
                $lines = RecipeStock::lines($book, $article, $quantity, $date)['lines'];
                $unit = $book->unitCost($article->id, UnitCosts::at($company, $date), $date)['ore'];
                $rawCost = $unit === null ? null : Quantity::value($quantity, $unit);
            }

            $location = $lines === [] ? null : $this->location($company, $validated['location_id'] ?? null);

            $meal = StaffMeal::create([
                'served_on' => $date->toDateString(),
                'meal' => MealType::from((string) $validated['meal']),
                'article_id' => $article?->id,
                'quantity_milli' => $quantity,
                'reason' => $reason,
                'employee_id' => $employeeId,
                'paid_per_meal_ore' => $paid,
                'location_id' => $location?->id,
                'raw_cost_ore' => $rawCost,
                'created_by' => $user->id,
            ]);

            if ($location !== null) {
                $this->ledger->post($company, $user, MovementType::InternalUse, array_map(fn (int $articleId, int $milli): array => [
                    'article_id' => $articleId,
                    'location_id' => $location->id,
                    'quantity_milli' => -$milli,
                    'reason' => $reason->value,
                ], array_keys($lines), array_values($lines)), $date, $meal, __('Eget bruk: :reason', ['reason' => $reason->label()]));
            }

            Audit::record('kitchen.staff_meal_registered', $meal, [
                'meal' => $meal->meal->value,
                'reason' => $reason->value,
                'article_id' => $article?->id,
                'quantity_milli' => $quantity,
                'employee_id' => $employeeId,
            ], $company, $user);

            return $meal;
        }));
    }

    /** A new entry with the opposite quantity; the goods go back with RETURN movements. */
    public function correct(User $user, Company $company, StaffMeal $meal): StaffMeal
    {
        $this->guard($user, $company, 'register');

        return CurrentCompany::run($company, fn (): StaffMeal => DB::transaction(function () use ($user, $company, $meal): StaffMeal {
            $meal = StaffMeal::query()->lockForUpdate()->findOrFail($meal->id);

            if ($meal->corrects_meal_id !== null || StaffMeal::query()->where('corrects_meal_id', $meal->id)->exists()) {
                throw ValidationException::withMessages(['meal' => __('Raden är redan rättad, eller är själv en rättelse.')]);
            }

            $today = CarbonImmutable::today();
            $correction = StaffMeal::create([
                'served_on' => $meal->served_on->toDateString(),
                'meal' => $meal->meal,
                'article_id' => $meal->article_id,
                'quantity_milli' => -$meal->quantity_milli,
                'reason' => $meal->reason,
                'employee_id' => $meal->employee_id,
                'paid_per_meal_ore' => $meal->paid_per_meal_ore,
                'location_id' => $meal->location_id,
                'raw_cost_ore' => $meal->raw_cost_ore === null ? null : -$meal->raw_cost_ore,
                'corrects_meal_id' => $meal->id,
                'created_by' => $user->id,
            ]);

            // Back at the cost per unit they left at: the valuation on the day before they left (review P3.6).
            $costs = UnitCosts::at($company, CarbonImmutable::parse($meal->served_on->toDateString())->subDay());
            $movements = StockMovement::query()->where('source_type', $meal->getMorphClass())->where('source_id', (string) $meal->id)->where('type', MovementType::InternalUse->value)->get();

            if ($movements->isNotEmpty()) {
                $this->ledger->post($company, $user, MovementType::Return, array_values($movements->map(fn (StockMovement $movement): array => [
                    'article_id' => $movement->article_id,
                    'location_id' => $movement->location_id,
                    'quantity_milli' => -$movement->quantity_milli,
                    'unit_cost_ore' => $costs->of($movement->article_id)['ore'],
                    'reason' => 'RATTELSE_PERSONALMAT',
                ])->all()), $today, $correction, __('Rättelse av personalmat'));
            }

            Audit::record('kitchen.staff_meal_corrected', $correction, ['corrects_meal_id' => $meal->id], $company, $user);

            return $correction;
        }));
    }

    private function location(Company $company, mixed $locationId): StockLocation
    {
        $id = $locationId === null || $locationId === '' ? KitchenSettings::locationId($company) : (int) $locationId;

        if ($id === null) {
            throw ValidationException::withMessages(['location_id' => __('Välj lagerställe, eller ange kökets lagerställe under Kök → Inställningar.')]);
        }

        return StockLocation::query()->where('is_active', true)->find($id)
            ?? throw ValidationException::withMessages(['location_id' => __('Lagerstället finns inte eller är inaktivt.')]);
    }

    private static function quantity(string $text): int
    {
        try {
            $milli = Quantity::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => __('Antalet går inte att läsa. Skriv till exempel 1 eller 3.')]);
        }

        if ($milli <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Antalet måste vara mer än noll.')]);
        }

        return $milli;
    }

    private static function money(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        try {
            $ore = Money::parse($text)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['paid_per_meal' => __('Beloppet går inte att läsa. Skriv till exempel 60,00.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages(['paid_per_meal' => __('Beloppet kan inte vara negativt.')]);
        }

        return $ore;
    }
}
