<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\PrintLabels;
use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Livewire\LabelStudioPage;
use App\Modules\Inventory\Livewire\LocationsPage;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * AGENTS.md §13.2 for labels (ADR-041): company A never sees B's label
 * templates and cannot print B's articles or locations, through Eloquent,
 * the studio, the print action or the PDF routes; B's ids answer 404.
 */

beforeEach(function () {
    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        Inventory::enable($company, $owner);
        $places = Inventory::locations($company, $owner);
        $article = Inventory::article($company, $owner, "{$name}-1", "{$name}vara");
        $template = LabelTemplate::query()->where('kind', 'ARTICLE')->sole();
        $template->update(['name' => "{$name}mall"]);
        $locationTemplate = LabelTemplate::query()->where('kind', 'LOCATION')->sole();
        $this->{$side} = compact('owner', 'company', 'places', 'article', 'template', 'locationTemplate');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes label templates to the current company', function () {
    expect(LabelTemplate::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id])
        ->and(LabelTemplate::query()->find($this->b['template']->id))->toBeNull();

    Livewire::test(LabelStudioPage::class)->assertSee('Alfamall')->assertDontSee('Betamall');
});

it('answers 404 for the other company\'s templates, articles and locations', function () {
    Livewire::test(LabelStudioPage::class)->call('edit', $this->b['template']->id)->assertNotFound();
    Livewire::test(LabelStudioPage::class)->call('makeDefault', $this->b['template']->id)->assertNotFound();
    Livewire::test(LabelStudioPage::class)->call('delete', $this->b['template']->id)->assertNotFound();
    Livewire::test(ArticlesPage::class)->set('selected', [(string) $this->b['article']->id])->call('printLabels')->assertSet('printing', null)->assertDispatched('toast', type: 'error');
    Livewire::test(LocationsPage::class)->call('printLabels', $this->b['places']['bin1']->id)->assertNotFound();

    $this->get(route('inventory.labels.sample', $this->b['template']->id))->assertNotFound();
    $this->post(route('inventory.labels.print'), ['template_id' => $this->b['template']->id, 'kind' => 'articles', 'copies' => [$this->a['article']->id => 1]])->assertNotFound();
    $this->post(route('inventory.labels.print'), ['template_id' => $this->a['template']->id, 'kind' => 'articles', 'copies' => [$this->b['article']->id => 1]])->assertNotFound();
    $this->post(route('inventory.labels.print'), ['template_id' => $this->a['locationTemplate']->id, 'kind' => 'locations', 'ids' => [$this->b['places']['bin1']->id]])->assertNotFound();

    expect(fn () => app(PrintLabels::class)->articles($this->a['owner'], $this->a['company'], $this->a['template'], [$this->b['article']->id => 1]))
        ->toThrow(NotFoundHttpException::class);

    $this->post(route('inventory.labels.print'), ['template_id' => $this->a['template']->id, 'kind' => 'articles', 'copies' => [$this->a['article']->id => 1], 'mark_printed' => '1'])->assertOk();

    CurrentCompany::set($this->b['company']);
    expect($this->b['article']->fresh()->labels_printed_at)->toBeNull()
        ->and($this->b['template']->fresh()->name)->toBe('Betamall');
});

it('keeps a template of B out of reach even by model binding', function () {
    expect(fn () => LabelTemplate::query()->findOrFail($this->b['template']->id))->toThrow(ModelNotFoundException::class);
});
