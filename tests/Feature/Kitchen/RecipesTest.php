<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Structures\StructureWriter;
use App\Modules\Kitchen\Actions\SaveRecipe;
use App\Modules\Kitchen\Livewire\MenuPage;
use App\Modules\Kitchen\Livewire\RecipePage;
use App\Modules\Kitchen\Livewire\RecipesPage;
use App\Modules\Kitchen\Support\MenuReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Kitchen;

/*
 * Recipes and the menu (ADR-073 §3, §4): a recipe saved from the page as a
 * new version, switching it off, the menu with margins and the till's
 * sales, and a fixed number of queries with 5 and with 50 dishes.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->places['bin1']);
});

it('creates a recipe from the page and audits it without names', function () {
    $dish = Kitchen::dish($this->company, $this->owner, 'M-5', 'Champinjonsoppa', '95,00');
    $a = $this->fx['articles'];

    Livewire::test(RecipePage::class)
        ->set('form.output_article_id', (string) $dish->id)
        ->set('form.name', 'Champinjonsoppa')
        ->set('form.yield', '4')
        ->set('form.rows.0.article_id', (string) $a['champinjoner']->id)
        ->set('form.rows.0.quantity', '400')
        ->set('form.rows.0.unit', 'g')
        ->set('form.rows.0.loss', '10')
        ->call('addRow')
        ->set('form.rows.1.article_id', (string) $a['gradde']->id)
        ->set('form.rows.1.quantity', '2')
        ->set('form.rows.1.unit', 'dl')
        ->call('save')
        ->assertDispatched('toast', message: 'Receptet är sparat som version 1, som gäller från 2026-11-10.')
        ->assertRedirect(route('kitchen.recipes.show', $dish->id));

    $recipe = BillOfMaterials::query()->where('output_article_id', $dish->id)->sole();
    expect($recipe->rows->pluck('quantity_milli')->all())->toBe([400, 200])
        ->and($recipe->rows[0]->loss_bp)->toBe(1_000);

    $audit = AuditLog::query()->where('action', 'kitchen.recipe_saved')->latest('id')->first();
    expect($audit?->payload)->toMatchArray(['article_id' => $dish->id, 'version' => 1]);
});

it('saves a change as a new version and keeps the old one', function () {
    $dish = $this->fx['articles']['dish'];

    Livewire::test(RecipePage::class, ['recept' => $dish->id])
        ->call('edit')
        ->assertSet('form.rows.3.quantity', '250')
        ->assertSet('form.rows.3.unit', 'g')
        ->set('form.yield', '12')
        ->call('save')
        ->assertDispatched('toast', message: 'Receptet är sparat som version 2, som gäller från 2026-11-10.');

    expect(BillOfMaterials::query()->where('output_article_id', $dish->id)->pluck('yield_milli', 'version')->all())->toBe([1 => 10_000, 2 => 12_000]);
});

it('shows the validation error on the row and as a toast', function () {
    Livewire::test(RecipePage::class, ['recept' => $this->fx['articles']['dish']->id])
        ->call('edit')
        ->set('form.rows.0.loss', '100')
        ->call('save')
        ->assertHasErrors('form.rows.0.loss')
        ->assertDispatched('toast', type: 'error');
});

it('switches a recipe off after a confirmation and lists it as inactive', function () {
    Livewire::test(RecipePage::class, ['recept' => $this->fx['articles']['dish']->id])
        ->call('setActive', false)
        ->assertDispatched('toast', message: 'Receptet är inaktiverat.');

    Livewire::test(RecipesPage::class)->assertSee('Inaktivt');
});

it('lists dishes on the menu with margin and the till\'s sales in the period', function () {
    $till = Kitchen::till($this->company, $this->owner, $this->places['bin1']);
    Kitchen::sale($till, '2026-11-03', [[$this->fx['articles']['dish'], 10_000, 150_000]]);
    Kitchen::sale($till, '2026-11-04', [[$this->fx['articles']['dish'], -1_000, -15_000]]);

    $rows = MenuReport::for($this->company, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-08'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'net_price_ore' => 16_875,
            'portion_cost_ore' => 2_385,
            'contribution_ore' => 14_490,
            'margin_bp' => 8_587,
            'food_cost_bp' => 1_413,
            'sold_milli' => 9_000,
            'revenue_ore' => 135_000,
            'realised_price_ore' => 15_000,
            'earned_ore' => 135_000 - 21_465,
        ]);

    Livewire::withQueryParams(['fran' => '2026-11-01', 'till' => '2026-11-08'])->test(MenuPage::class)->assertSee('150,00')->assertSee('135,35');
});

it('runs the same number of queries for 5 and for 50 dishes', function () {
    $count = function (int $dishes): int {
        for ($i = 0; $i < $dishes; $i++) {
            $dish = Kitchen::dish($this->company, $this->owner, "Q-{$dishes}-{$i}", "Rätt {$dishes}-{$i}", '100,00');
            app(StructureWriter::class)->save($this->company, $this->owner, StructureKind::Recipe, $dish, [
                'name' => $dish->name, 'yield' => '2', 'yield_unit' => BillOfMaterials::PORTIONS, 'valid_from' => '2026-11-01',
                'rows' => [['article_id' => $this->fx['articles']['lok']->id, 'quantity' => '0,1'], ['article_id' => $this->fx['articles']['vitloksmor']->id, 'quantity' => '0,05']],
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MenuPage::class);
        Livewire::test(RecipesPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Warm up the company and the session once, so both counts start alike.
    Livewire::test(MenuPage::class);
    $five = $count(5);
    $fifty = $count(45);

    expect($fifty)->toBe($five);
});

it('needs an explicit confirmation to date a recipe back, and audits it (review P2.7)', function () {
    $dish = $this->fx['articles']['dish'];
    $input = ['name' => 'Pasta med svamp', 'valid_from' => '2026-11-05', 'yield' => '10', 'yield_unit' => 'PORTIONS', 'rows' => [['article_id' => $this->fx['articles']['pasta']->id, 'quantity' => '1']]];

    expect(fn () => app(SaveRecipe::class)($this->owner, $this->company, $dish, $input))->toThrow(ValidationException::class, 'bakåt i tiden');

    $recipe = app(SaveRecipe::class)($this->owner, $this->company, $dish, [...$input, 'confirm_backdate' => true]);
    expect($recipe->valid_from->toDateString())->toBe('2026-11-05')
        ->and(AuditLog::query()->where('action', 'kitchen.recipe_backdated')->latest('id')->first()?->payload)->toMatchArray(['valid_from' => '2026-11-05']);
});

it('needs the confirmation for a date before the latest completed stock count (review P2.7)', function () {
    $counts = app(StockCounts::class);
    $count = $counts->start($this->owner, $this->company, null, CarbonImmutable::parse('2026-11-12'));
    $counts->complete($this->owner, $this->company, $count);
    $input = ['name' => 'Pasta med svamp', 'valid_from' => '2026-11-11', 'yield' => '10', 'yield_unit' => 'PORTIONS', 'rows' => [['article_id' => $this->fx['articles']['pasta']->id, 'quantity' => '1']]];

    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00'));
    expect(fn () => app(SaveRecipe::class)($this->owner, $this->company, $this->fx['articles']['dish'], $input))->toThrow(ValidationException::class, 'inventeringen 2026-11-12');
});
