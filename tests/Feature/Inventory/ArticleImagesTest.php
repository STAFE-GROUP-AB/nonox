<?php

declare(strict_types=1);

use App\Livewire\Invoicing\Articles;
use App\Models\Article;
use App\Models\AuditLog;
use App\Modules\Ai\AiModule;
use App\Modules\Connect\ConnectModule;
use App\Modules\Inventory\Actions\ArticleImages;
use App\Modules\Inventory\Actions\SuggestArticleDetails;
use App\Modules\Inventory\Agents\ArticleAgent;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Livewire\ArticlesPage;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Support\ArticleSuggestion;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Inventory;

/*
 * Pictures on articles and "Fyll i med NONOX AI" (ADR-039, ADR-018):
 * resized JPEGs on the private disk, in order with the first as the main
 * image, served only to those who see the register or Lager, and an AI
 * proposal that changes nothing until the person ticks the fields.
 */

const ARTICLE_AI_KEY = 'sk-test-abcdefghijklmnopqrstuvwxyz0123456789ABCD';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Inventory::enable($this->company, $this->owner);
    $this->article = Inventory::article($this->company, $this->owner, 'J-1', 'Jacka');
});

function photo(string $name = 'jacka.jpg', int $width = 3000, int $height = 2000): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function articleAiOn(): void
{
    $modules = app(ModuleManager::class);
    $modules->enable(AiModule::KEY, test()->company, test()->owner);
    $modules->updateSettings(AiModule::KEY, test()->company, test()->owner, ['provider' => 'OPENAI', 'api_keys' => ['OPENAI' => Crypt::encryptString(ARTICLE_AI_KEY)]]);
}

/** @return array<string, mixed> */
function articleAnswer(array $overrides = []): array
{
    return [
        'name' => 'Vinterjacka herr', 'description' => 'Begagnad vinterjacka i ull med dragkedja. Små slitage vid ärmarna.',
        'brand' => 'Nordvik', 'model' => 'Fjäll', 'colours' => ['blå', 'svart', 'blå'], 'materials' => ['ull'],
        'properties' => [['key' => 'Storlek', 'value' => 'M'], ['key' => 'Skick', 'value' => 'Begagnad']],
        'weight_grams' => 950, 'length_mm' => 0, 'width_mm' => 0, 'height_mm' => 0,
        'gtin' => '7310865004703', 'notes' => 'Etiketten är delvis dold.',
        ...$overrides,
    ];
}

it('stores photos resized as JPEG, keeps them in order and removes them', function () {
    $component = Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->set('tab', 'avancerad')
        ->assertSee('Bilder')
        ->assertSee('Ta foto')
        ->assertSeeHtml('capture="environment"')
        ->set('upload', [photo('a.jpg'), photo('b.png', 800, 600)])
        ->assertDispatched('toast');

    $images = ArticleImage::query()->orderBy('position')->get();
    expect($images)->toHaveCount(2)
        ->and($images->pluck('position')->all())->toBe([0, 1])
        ->and($images[0]->path)->toStartWith("inventory/articles/{$this->company->id}/article-")->toEndWith('.jpg');

    $size = getimagesizefromstring((string) Storage::disk('local')->get($images[0]->path));
    expect($size[0])->toBe(1600)->and($size[1])->toBe(1067)->and($size['mime'])->toBe('image/jpeg');

    $component->call('moveImage', $images[1]->id, 0)
        ->set("alts.{$images[1]->id}", 'Framsidan')
        ->call('saveAlt', $images[1]->id)
        ->assertDispatched('toast');

    expect($this->article->fresh()->mainImage->id)->toBe($images[1]->id)
        ->and($images[1]->fresh()->alt)->toBe('Framsidan')
        ->and($images[0]->fresh()->position)->toBe(1);

    $component->call('deleteImage', $images[1]->id);

    expect(ArticleImage::query()->count())->toBe(1)
        ->and($images[0]->fresh()->position)->toBe(0)
        ->and(Storage::disk('local')->exists($images[1]->path))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'inventory.article_image%')->pluck('action')->unique()->sort()->values()->all())
        ->toBe(['inventory.article_image_added', 'inventory.article_image_deleted', 'inventory.article_image_described', 'inventory.article_images_reordered']);
});

it('keeps photos of a new article until it is saved', function () {
    Livewire::test(Articles::class)
        ->call('create')
        ->set('upload', [photo()])
        ->assertSet('pending', fn (array $pending): bool => count($pending) === 1)
        ->set('form.number', 'K-1')->set('form.name', 'Kopp')->set('form.price', '99')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $article = Article::query()->where('number', 'K-1')->sole();
    expect($article->images()->count())->toBe(1);
});

it('refuses files that are not pictures and more than the limit', function () {
    Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->set('upload', [UploadedFile::fake()->createWithContent('faktura.pdf', '%PDF-1.4')])
        ->assertDispatched('toast', message: 'Välj en bild i JPG, PNG, WebP eller HEIC.', type: 'error');

    expect(ArticleImage::query()->count())->toBe(0);

    $images = app(ArticleImages::class);
    foreach (range(1, ArticleImages::MAX_IMAGES) as $n) {
        $images->add($this->owner, $this->company, $this->article, photo("{$n}.jpg", 40, 40));
    }

    expect(fn () => $images->add($this->owner, $this->company, $this->article, photo('extra.jpg', 40, 40)))
        ->toThrow(ValidationException::class, 'högst 12 bilder');
    // The refused picture leaves no file behind.
    expect(Storage::disk('local')->allFiles("inventory/articles/{$this->company->id}"))->toHaveCount(ArticleImages::MAX_IMAGES);
});

it('lets those who manage articles change pictures, not Lagerpersonal', function () {
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');

    expect(fn () => app(ArticleImages::class)->add($clerk, $this->company, $this->article, photo()))
        ->toThrow(AuthorizationException::class);
});

it('serves pictures to the register and Lager, and to no other company', function () {
    $image = app(ArticleImages::class)->add($this->owner, $this->company, $this->article, photo());
    $url = route('inventory.articleImages.show', ['article' => $this->article->id, 'image' => $image->id]);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $small = $this->get("{$url}?storlek=liten")->assertOk()->getContent();
    expect(getimagesizefromstring((string) $small)[0])->toBe(240);

    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($clerk)->get($url)->assertOk();

    [$stranger] = Bookkeeping::company();
    $this->actingAs($stranger)->get($url)->assertNotFound();

    $this->actingAs($this->owner);
    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get($url)->assertNotFound();
});

it('shows the main picture in the article lists with a fixed number of queries', function () {
    app(ArticleImages::class)->add($this->owner, $this->company, $this->article, photo());

    Livewire::test(Articles::class)->assertSeeHtml('storlek=liten');
    Livewire::test(ArticlesPage::class)->assertSeeHtml('storlek=liten')->assertSee('Snabbregistrera med kamera');

    $queries = function (string $component): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $before = [$queries(Articles::class), $queries(ArticlesPage::class)];

    foreach (range(2, 6) as $n) {
        $article = Inventory::article($this->company, $this->owner, "J-{$n}", "Jacka {$n}");
        app(ArticleImages::class)->add($this->owner, $this->company, $article, photo("{$n}.jpg", 40, 40));
    }

    expect([$queries(Articles::class), $queries(ArticlesPage::class)])->toBe($before);
});

it('gives the pictures over the API with the same token', function () {
    Connect::setUp();
    app(ModuleManager::class)->enable(ConnectModule::KEY, $this->company, $this->owner);
    $image = app(ArticleImages::class)->add($this->owner, $this->company, $this->article, photo());
    $token = Connect::apiKey($this->owner, $this->company, ['inventory:view']);

    $url = $this->withToken($token)->getJson('/api/v1/inventory/articles')->assertOk()
        ->assertJsonPath('data.0.images.0.id', $image->id)
        ->assertJsonPath('data.0.images.0.position', 0)
        ->json('data.0.images.0.url');

    $this->withToken($token)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    [$otherOwner, $other] = Connect::company();
    $foreign = Connect::apiKey($otherOwner, $other, ['inventory:view']);
    Inventory::enable($other, $otherOwner);
    $this->withToken($foreign)->get($url)->assertNotFound();
});

it('proposes fields from the pictures and changes only the ticked ones', function () {
    articleAiOn();
    ArticleAgent::fake([articleAnswer(), articleAnswer(['gtin' => '7310865004704'])]);
    CurrentCompany::set($this->company);
    app(ArticleImages::class)->add($this->owner, $this->company, $this->article, photo());

    $component = Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->assertSee('Fyll i med NONOX AI')
        ->call('suggestWithAi')
        ->assertSee('Förslag från NONOX AI')
        ->assertSee('Vinterjacka herr')
        ->assertSee('Etiketten är delvis dold.')
        // The name is filled in already, so it is not ticked; empty fields are.
        ->assertSet('aiChosen', ['description', 'brand', 'model', 'colours', 'materials', 'properties', 'weight_grams', 'gtin']);

    expect($this->article->fresh()->description)->toBeNull();

    $component->set('aiChosen', ['brand', 'colours', 'gtin'])
        ->call('applySuggestion')
        ->assertDispatched('toast')
        ->assertSet('form.name', 'Jacka')
        ->assertSet('form.brand', 'Nordvik')
        ->assertSet('form.colours', 'blå, svart')
        ->assertSet('form.gtin', '7310865004703')
        ->assertSet('form.description', '')
        ->assertSet('aiSuggestion', null);

    ArticleAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1
        && $prompt->attachments->first() instanceof Image
        && ! str_contains($prompt->prompt, 'Chart of accounts')
        && $prompt->provider->driver() === 'openai');

    // A barcode with a wrong check digit is dropped.
    $component->call('suggestWithAi');
    expect(collect($component->get('aiSuggestion'))->get('gtin'))->toBe('');

    $audit = AuditLog::query()->where('action', 'ai.document_analyzed')->first();
    expect($audit->payload)->toMatchArray(['kind' => 'article', 'provider' => 'OPENAI'])
        ->and(json_encode($audit->payload))->not->toContain('Nordvik');
});

it('adds proposed attributes without replacing those the article has', function () {
    $form = ['properties' => [['key' => 'storlek', 'value' => 'L']]];
    $suggestion = SuggestArticleDetails::clean(articleAnswer());

    $rows = collect(ArticleSuggestion::rows($suggestion, $form))->keyBy('field');
    expect($rows['properties']['suggested'])->toBe('Skick: Begagnad');

    expect(ArticleSuggestion::apply($form, $suggestion, ['properties'])['properties'])
        ->toBe([['key' => 'storlek', 'value' => 'L'], ['key' => 'Skick', 'value' => 'Begagnad']]);
});

it('reads a fresh photo in the camera flow and saves the article only on save', function () {
    articleAiOn();
    ArticleAgent::fake([articleAnswer()]);

    $this->get(route('invoicing.articles', ['ny' => 1, 'kamera' => 1]))->assertOk()->assertSee('Ta ett foto av varan');

    Livewire::withQueryParams(['ny' => 1, 'kamera' => 1])->test(Articles::class)
        ->assertSet('camera', true)
        ->set('upload', [photo()])
        ->assertDispatched(Articles::AI_EVENT)
        ->call('suggestWithAi')
        ->call('applySuggestion')
        ->assertSet('form.name', 'Vinterjacka herr');

    expect(Article::query()->count())->toBe(1);
});

it('hides the button without the AI module, a key or the right to use AI', function () {
    Livewire::test(Articles::class)->call('edit', $this->article->id)->assertDontSee('Fyll i med NONOX AI');

    app(ModuleManager::class)->enable(AiModule::KEY, $this->company, $this->owner);
    Livewire::test(Articles::class)->call('edit', $this->article->id)->assertDontSee('Fyll i med NONOX AI');

    articleAiOn();
    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    expect(SuggestArticleDetails::availableFor($this->owner, $this->company))->toBeTrue()
        ->and(SuggestArticleDetails::availableFor($clerk, $this->company))->toBeFalse();

    expect(fn () => app(SuggestArticleDetails::class)($clerk, $this->company, $this->article, ['jpeg']))
        ->toThrow(AuthorizationException::class);
});

it('says in Swedish when the provider fails, and never reaches another company', function () {
    articleAiOn();
    ArticleAgent::fake(fn () => throw new RuntimeException('boom'));
    app(ArticleImages::class)->add($this->owner, $this->company, $this->article, photo());

    Livewire::test(Articles::class)
        ->call('edit', $this->article->id)
        ->call('suggestWithAi')
        ->assertDispatched('toast', type: 'error')
        ->assertSet('aiSuggestion', null);

    [$strangerOwner, $stranger] = Bookkeeping::company();
    $foreign = Inventory::article($stranger, $strangerOwner, 'X-1', 'Annans');
    CurrentCompany::set($this->company);

    expect(fn () => app(SuggestArticleDetails::class)($this->owner, $this->company, $foreign, ['jpeg']))
        ->toThrow(NotFoundHttpException::class);
});
