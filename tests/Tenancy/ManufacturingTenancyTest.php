<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\Livewire\OperationsPage;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\RowStructure;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Manufacturing;

/*
 * AGENTS.md §13.2 for Tillverkning (ADR-071): company A never sees B's
 * operations, bills of materials or row copies, through Eloquent, the
 * costing, the pages or the actions; B's ids answer 404, and B's
 * articles and operations cannot be put into A's bills of materials.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company] = Manufacturing::company();
        $this->actingAs($owner);
        $fx = Manufacturing::fixtures($company, $owner);
        $quote = Manufacturing::document($company, $owner, [['BORD-180', '2', '12000,00', 'Ekbord 180']]);
        app(SaveRowStructure::class)->choose($owner, $quote, $quote->rows[0]->line_key, $fx['articles']['table']);
        $this->{$side} = compact('owner', 'company', 'fx', 'quote');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every Tillverkning model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([Operation::class, RowStructure::class, RowStructureLine::class]);

it('answers 404 for the other company\'s records', function () {
    $this->get(route('manufacturing.boms.show', $this->b['fx']['articles']['table']->id))->assertNotFound();
    $this->get(route('manufacturing.boms.show', $this->a['fx']['articles']['table']->id))->assertOk();
    $this->get(route('invoicing.quotes.show', ['document' => $this->b['quote']->id]))->assertNotFound();

    Livewire::test(OperationsPage::class)->call('edit', $this->b['fx']['operations']['cut']->id)->assertNotFound();
    Livewire::test(RowStructuresPanel::class, ['documentId' => $this->b['quote']->id])->assertNotFound();
});

it('keeps the costing inside the company', function () {
    $book = ManufacturingBook::for($this->a['company']);

    expect($book->outputs())->toBe([$this->a['fx']['articles']['table']->id])
        ->and($book->operations->keys()->sort()->values()->all())->toBe(collect($this->a['fx']['operations'])->pluck('id')->sort()->values()->all());
});

it('refuses another company\'s article or operation in a bill of materials and a row copy', function () {
    $a = $this->a;

    expect(fn () => app(SaveBillOfMaterials::class)($a['owner'], $a['company'], $a['fx']['articles']['table'], ['name' => 'X', 'yield' => '1', 'rows' => [['article_id' => $this->b['fx']['articles']['leg']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'finns inte')
        ->and(fn () => app(SaveBillOfMaterials::class)($a['owner'], $a['company'], $a['fx']['articles']['table'], ['name' => 'X', 'yield' => '1', 'rows' => [['article_id' => $a['fx']['articles']['leg']->id, 'quantity' => '1'], ['operation_id' => $this->b['fx']['operations']['cut']->id, 'minutes' => '5']]]))
        ->toThrow(ValidationException::class, 'arbetssteg')
        ->and(fn () => app(SaveRowStructure::class)->update($a['owner'], $a['quote'], $a['quote']->rows[0]->line_key, ['yield' => '1', 'lines' => [['article_id' => $this->b['fx']['articles']['leg']->id, 'quantity' => '1']]]))
        ->toThrow(ValidationException::class, 'finns inte')
        ->and(fn () => app(SaveRowStructure::class)->update($a['owner'], $a['quote'], $a['quote']->rows[0]->line_key, ['yield' => '1', 'lines' => [['article_id' => $a['fx']['articles']['leg']->id, 'quantity' => '1'], ['operation_id' => $this->b['fx']['operations']['cut']->id, 'minutes' => '5']]]))
        ->toThrow(ValidationException::class, 'arbetssteg')
        ->and(fn () => app(SaveRowStructure::class)->choose($a['owner'], $this->b['quote'], $this->b['quote']->rows[0]->line_key, $a['fx']['articles']['table']))
        ->toThrow(HttpException::class);

    try {
        app(SaveRowStructure::class)->remove($a['owner'], $this->b['quote'], $this->b['quote']->rows[0]->line_key);
        $this->fail('Removed.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }

    CurrentCompany::set($this->b['company']);
    expect(RowStructure::query()->count())->toBe(1);
});
