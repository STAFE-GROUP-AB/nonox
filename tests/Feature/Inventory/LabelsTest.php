<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\AuditLog;
use App\Modules\Inventory\Actions\LabelTemplates;
use App\Modules\Inventory\Actions\PrintLabels;
use App\Modules\Inventory\Actions\SaveLabelTemplate;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelRenderer;
use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Livewire\LabelPrintPanel;
use App\Modules\Inventory\Livewire\LabelStudioPage;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Livewire\ReceiptsPage;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ModuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Labels and the label studio (ADR-041): templates with one default per
 * kind, the A4 grid rules, printing articles, receipts and locations as PDF,
 * prices only for those who may see them, and "Ej utskrivna".
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->places = Inventory::locations($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner, 'V-1', 'Kaffekopp', ['gtin' => '7350000000016']);
    $this->articleTemplate = LabelTemplate::query()->where('kind', 'ARTICLE')->sole();
    $this->locationTemplate = LabelTemplate::query()->where('kind', 'LOCATION')->sole();
});

/** @return array<string, mixed> */
function rollTemplate(array $overrides = []): array
{
    return [
        'name' => 'Rulle 62 × 29',
        'kind' => 'ARTICLE',
        'media' => 'ROLL',
        'width_mm' => '62',
        'height_mm' => '29',
        'padding_mm' => '2',
        'layout' => 'AUTO',
        'elements' => ['name', 'price', 'vat_note', 'barcode'],
        ...$overrides,
    ];
}

it('seeds one default article and one default location template when Lager is turned on', function () {
    expect($this->articleTemplate->is_default)->toBeTrue()
        ->and($this->articleTemplate->media->value)->toBe('SHEET')
        ->and($this->articleTemplate->perPage())->toBe(24)
        ->and($this->locationTemplate->is_default)->toBeTrue()
        ->and($this->locationTemplate->elements)->toContain('qr');

    app(LabelTemplates::class)->ensureDefaults($this->company, $this->owner);
    expect(LabelTemplate::query()->count())->toBe(2);
});

it('creates, changes, duplicates and deletes templates with one default per kind', function () {
    $save = app(SaveLabelTemplate::class);
    $roll = $save($this->owner, $this->company, rollTemplate());

    expect($roll->is_default)->toBeFalse()
        ->and($roll->columns)->toBe(1)
        ->and($roll->width_mm)->toBe(62.0);

    app(LabelTemplates::class)->makeDefault($this->owner, $this->company, $roll);
    expect($roll->fresh()->is_default)->toBeTrue()
        ->and($this->articleTemplate->fresh()->is_default)->toBeFalse()
        ->and(LabelTemplate::query()->where('kind', 'ARTICLE')->where('is_default', true)->count())->toBe(1);

    $copy = app(LabelTemplates::class)->duplicate($this->owner, $this->company, $roll);
    expect($copy->name)->toBe('Rulle 62 × 29 (kopia)')
        ->and($copy->is_default)->toBeFalse();

    $save($this->owner, $this->company, rollTemplate(['name' => 'Rulle ändrad', 'kind' => 'LOCATION', 'elements' => ['name']]), $roll);
    expect($roll->fresh()->name)->toBe('Rulle ändrad')
        ->and($roll->fresh()->kind)->toBe(LabelKind::Article);

    app(LabelTemplates::class)->delete($this->owner, $this->company, $roll->fresh());
    expect(LabelTemplate::query()->find($roll->id))->toBeNull()
        ->and(LabelTemplate::query()->where('kind', 'ARTICLE')->where('is_default', true)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'inventory.label_template_deleted')->count())->toBe(1);
});

it('never deletes the last template of a kind', function () {
    app(LabelTemplates::class)->delete($this->owner, $this->company, $this->locationTemplate);
})->throws(ValidationException::class, 'enda mallen');

it('refuses duplicate names, elements of the other kind and a padding larger than the label', function (array $input, string $field) {
    try {
        app(SaveLabelTemplate::class)($this->owner, $this->company, rollTemplate($input));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'same name' => [['name' => 'Prisetikett 70 × 37 mm (A4-ark)'], 'name'],
    'price on a location label' => [['kind' => 'LOCATION', 'elements' => ['code', 'price']], 'elements.1'],
    'padding' => [['padding_mm' => '15'], 'padding_mm'],
    'nothing shown' => [['elements' => []], 'elements'],
]);

it('takes the grid from a sheet layout, centres it on plain paper and refuses a grid off the page', function () {
    $save = app(SaveLabelTemplate::class);
    $sheet = $save($this->owner, $this->company, rollTemplate(['name' => 'Ark', 'media' => 'SHEET', 'sheet_preset' => 'a4-2x7-99x38']));
    expect([$sheet->columns, $sheet->rows, $sheet->width_mm, $sheet->sheet_top_mm, $sheet->pitch_x_mm])->toBe([2, 7, 99.1, 15.15, 101.6]);

    $plain = $save($this->owner, $this->company, rollTemplate(['name' => 'Papper', 'media' => 'SHEET', 'sheet_preset' => 'a4-plain', 'width_mm' => '50', 'height_mm' => '25']));
    expect([$plain->columns, $plain->rows, $plain->sheet_left_mm, $plain->sheet_top_mm, $plain->cutting_guides])->toBe([4, 11, 5.0, 11.0, true]);

    try {
        $save($this->owner, $this->company, rollTemplate(['name' => 'För brett', 'media' => 'SHEET', 'sheet_preset' => 'custom', 'width_mm' => '70', 'height_mm' => '37', 'columns' => 3, 'rows' => 8, 'sheet_left_mm' => '5', 'sheet_top_mm' => '0', 'pitch_x_mm' => '70', 'pitch_y_mm' => '37']));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('columns')
            ->and($exception->errors()['columns'][0])->toContain('215 mm');
    }

    try {
        $save($this->owner, $this->company, rollTemplate(['name' => 'Överlapp', 'media' => 'SHEET', 'sheet_preset' => 'custom', 'width_mm' => '50', 'height_mm' => '25', 'columns' => 2, 'rows' => 2, 'pitch_x_mm' => '40', 'pitch_y_mm' => '25']));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('pitch_x_mm');
    }
});

it('lets only those with inventory:settings change templates', function (string $role, bool $allowed) {
    $member = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);

    if (! $allowed) {
        $this->expectException(AuthorizationException::class);
    }

    app(SaveLabelTemplate::class)($member, $this->company, rollTemplate());
    expect(LabelTemplate::query()->where('name', 'Rulle 62 × 29')->exists())->toBeTrue();
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', false],
    'stock clerk' => ['stock_clerk', false],
    'auditor' => ['auditor', false],
]);

it('gives the print and price abilities per role', function (string $role, bool $print, bool $prices) {
    $member = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($member);

    expect($gate->allows('printLabels', [StockMovement::class, $this->company]))->toBe($print)
        ->and($gate->allows('printPrices', [StockMovement::class, $this->company]))->toBe($prices);
})->with([
    'owner' => ['owner', true, true],
    'bookkeeper' => ['bookkeeper', true, true],
    'stock clerk' => ['stock_clerk', true, false],
    'auditor' => ['auditor', false, false],
    'approver' => ['approver', false, false],
]);

it('prints article labels as a PDF with the price and marks them printed without touching updated_at', function () {
    $updatedAt = $this->article->fresh()->updated_at;
    $this->travel(5)->minutes();

    $result = app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 30], 1, true);

    expect($result['pdf'])->toStartWith('%PDF-')
        ->and($result['labels'])->toBe(30)
        ->and($result['pages'])->toBe(2);

    $article = $this->article->fresh();
    expect($article->labels_printed_at)->not->toBeNull()
        ->and($article->labels_printed_count)->toBe(30)
        ->and($article->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'inventory.labels_printed')->sole()->payload)->toMatchArray(['labels' => 30, 'prices' => true]);

    $html = app(LabelRenderer::class)->html($this->articleTemplate, [LabelData::forArticle($article, true)]);
    expect($html)->toContain('248,75')->toContain('inkl. moms 25 %')->toContain('7350000000016');
});

it('prints for Lagerpersonal without any price', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($clerk);

    $result = app(PrintLabels::class)->articles($clerk, $this->company, $this->articleTemplate, [$this->article->id => 1]);
    expect($result['labels'])->toBe(1)
        ->and(AuditLog::query()->where('action', 'inventory.labels_printed')->sole()->payload['prices'])->toBeFalse();

    $data = LabelData::forArticle($this->article, Gate::forUser($clerk)->allows('printPrices', [StockMovement::class, $this->company]));
    $html = app(LabelRenderer::class)->html($this->articleTemplate, [$data]);
    expect($data->price)->toBeNull()
        ->and($html)->not->toContain('248,75')->not->toContain('inkl. moms')->toContain('Kaffekopp');

    Livewire::test(LabelPrintPanel::class, ['kind' => 'ARTICLE', 'items' => [$this->article->id => 1]])
        ->assertSee('Priset skrivs inte ut');
});

it('refuses printing for an auditor and caps copies and totals', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(PrintLabels::class)->articles($auditor, $this->company, $this->articleTemplate, [$this->article->id => 1]))
        ->toThrow(AuthorizationException::class);

    $result = app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 9999], 1, false);
    expect($result['labels'])->toBe(PrintLabels::MAX_COPIES)
        ->and($this->article->fresh()->labels_printed_at)->toBeNull();

    $many = collect(range(2, 12))->mapWithKeys(fn (int $n) => [Inventory::article($this->company, $this->owner, "V-{$n}", "Vara {$n}")->id => 500])->all();
    expect(fn () => app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, $many))
        ->toThrow(ValidationException::class, 'Högst 5000');

    expect(fn () => app(PrintLabels::class)->articles($this->owner, $this->company, $this->locationTemplate, [$this->article->id => 1]))
        ->toThrow(ValidationException::class, 'artikeletiketter');
});

it('prints over the POST route as an inline PDF and starts on a later position of the sheet', function () {
    $response = $this->post(route('inventory.labels.print'), [
        'template_id' => $this->articleTemplate->id,
        'kind' => 'articles',
        'copies' => [$this->article->id => 24],
        'start' => 3,
        'mark_printed' => '1',
    ]);

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toContain('etiketter-');

    $result = app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 24], 3, false);
    expect($result['pages'])->toBe(2);
});

it('encodes location labels for the scanner and prints a branch in walking order', function () {
    $html = app(LabelRenderer::class)->html($this->locationTemplate, [LabelData::forLocation($this->places['bin1'])]);
    expect($html)->toContain('HL-A-01');

    $data = LabelData::forLocation($this->places['bin1']);
    expect($data->qr)->toBe('L:HL-A-01')
        ->and($data->barcode['value'])->toBe('L:HL-A-01')
        ->and($data->barcode['text'])->toBe('HL-A-01')
        ->and($data->barcode['symbology']->value)->toBe('CODE128');

    Livewire::test(LocationsPage::class)
        ->call('printLabels', $this->places['zone']->id, true)
        ->assertSet('printing', [$this->places['zone']->id => 1, $this->places['bin1']->id => 1, $this->places['bin2']->id => 1])
        ->assertSee('Skriv ut platsetiketter');

    $result = app(PrintLabels::class)->locations($this->owner, $this->company, $this->locationTemplate, [$this->places['bin1']->id, $this->places['bin2']->id]);
    expect($result['labels'])->toBe(2)->and($result['pages'])->toBe(1);

    $this->post(route('inventory.labels.print'), ['template_id' => $this->locationTemplate->id, 'kind' => 'locations', 'ids' => [$this->places['bin1']->id]])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('selects articles on the list, filters Ej utskrivna and offers labels for what came in', function () {
    $second = Inventory::article($this->company, $this->owner, 'V-2', 'Tekanna');

    Livewire::test(ArticlesPage::class)
        ->call('printLabels')
        ->assertDispatched('toast', type: 'error')
        ->set('selected', [(string) $this->article->id, (string) $second->id])
        ->call('printLabels')
        ->assertSet('printing', [$this->article->id => 1, $second->id => 1])
        ->assertSee('Skriv ut artikeletiketter')
        ->dispatch('labels-printed')
        ->assertSet('printing', null)
        ->assertSet('selected', []);

    app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 1]);

    Livewire::test(ArticlesPage::class)
        ->set('status', 'ej-utskrivna')
        ->assertSee('Tekanna')
        ->assertDontSee('Kaffekopp');

    $receipt = Inventory::receive($this->company, $this->owner, $second, $this->places['bin1'], '6', '40');

    Livewire::test(ReceiptsPage::class)
        ->set('status', 'alla')
        ->assertSee('Etiketter för det som kom in')
        ->call('printLabels', $receipt->id)
        ->assertSet('printing', [$second->id => 6]);
});

it('works out copies by stock and a fixed number in the print step', function () {
    Inventory::receive($this->company, $this->owner, $this->article, $this->places['bin1'], '7', '40');

    $panel = Livewire::test(LabelPrintPanel::class, ['kind' => 'ARTICLE', 'items' => [$this->article->id => 1]])
        ->assertSet('templateId', $this->articleTemplate->id)
        ->assertSeeHtml('name="copies['.$this->article->id.']" value="1"')
        ->set('mode', 'stock')
        ->assertSeeHtml('name="copies['.$this->article->id.']" value="7"')
        ->set('mode', 'fixed')
        ->set('fixed', '3')
        ->assertSeeHtml('name="copies['.$this->article->id.']" value="3"')
        ->assertSee('Börja på etikett nr');

    $panel->call('printed')->assertDispatched('toast', message: 'Etiketterna öppnas som PDF i en ny flik.')->assertDispatched('labels-printed');
});

it('edits a template in the studio with a live preview and warnings, and gives toasts', function () {
    $studio = Livewire::test(LabelStudioPage::class)
        ->assertSee('Prisetikett 70 × 37 mm (A4-ark)')
        ->call('create', 'ARTICLE')
        ->set('form.name', 'Hyllkant')
        ->assertSee('Förhandsvisning')
        ->assertSee('Kaffekopp')
        ->set('form.elements', ['name', 'price', 'qr', 'barcode'])
        ->assertSee('Streckkoden (EAN-13) behöver minst')
        ->set('form.padding_mm', '13')
        ->assertSee('Mallen går inte att spara än')
        ->set('form.padding_mm', '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Etikettmallen är skapad.');

    $template = LabelTemplate::query()->where('name', 'Hyllkant')->sole();
    expect($template->media->value)->toBe('ROLL')
        ->and($template->width_mm)->toBe(50.0);

    $studio->call('edit', $template->id)
        ->set('form.media', 'SHEET')
        ->set('form.sheet_preset', 'custom')
        ->set('form.columns', '5')
        ->assertSee('A4 är 210 mm')
        ->set('form.columns', '4')
        ->assertSee('Så hamnar etiketterna på A4')
        ->call('save')
        ->assertDispatched('toast', message: 'Etikettmallen är sparad.');

    $studio->call('makeDefault', $template->id)->assertDispatched('toast', message: 'Hyllkant är nu standardmall för artikeletikett.');
    $studio->call('duplicate', $template->id)->assertDispatched('toast', message: 'Mallen är kopierad.');
    $studio->call('delete', $template->id)->assertDispatched('toast', message: 'Mallen Hyllkant är borttagen.');
    $studio->call('delete', $this->locationTemplate->id)->assertDispatched('toast', type: 'error');

    $this->get(route('inventory.labels.sample', $this->articleTemplate->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('inventory.labels'))->assertOk()->assertSee('Etiketter');
});

it('keeps the studio from those without inventory:settings', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($clerk);

    $this->get(route('inventory.labels'))->assertOk()->assertSee('Du kan inte ändra etikettmallarna');
    Livewire::test(LabelStudioPage::class)->assertForbidden();
    $this->get(route('inventory.labels.sample', $this->articleTemplate->id))->assertForbidden();
});

it('renders the article list in the same number of queries for 2 and 12 articles', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ArticlesPage::class)->set('status', 'ej-utskrivna');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Inventory::article($this->company, $this->owner, 'V-2', 'Tekanna');
    $count();
    $few = $count();

    foreach (range(3, 12) as $n) {
        Inventory::article($this->company, $this->owner, "V-{$n}", "Vara {$n}");
    }

    expect($count())->toBe($few);
});

it('builds the print PDF with a constant number of queries', function () {
    $ids = [$this->article->id => 1];

    foreach (range(2, 21) as $n) {
        $ids[Inventory::article($this->company, $this->owner, "V-{$n}", "Vara {$n}")->id] = 1;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, array_slice($ids, 0, 2, true));
    $two = count(DB::getQueryLog());
    DB::flushQueryLog();
    app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, $ids);
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($two);
});

it('answers 404 when Lager is off', function () {
    app(ModuleManager::class)->disable('inventory', $this->company, $this->owner, true);

    $this->get(route('inventory.labels'))->assertNotFound();
    $this->post(route('inventory.labels.print'), ['template_id' => $this->articleTemplate->id, 'kind' => 'articles', 'copies' => [$this->article->id => 1]])->assertNotFound();
});

it('adds up the printed count over several prints', function () {
    app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 2]);
    app(PrintLabels::class)->articles($this->owner, $this->company, $this->articleTemplate, [$this->article->id => 3]);

    expect(Article::query()->find($this->article->id)->labels_printed_count)->toBe(5);
});
