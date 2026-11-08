<?php

declare(strict_types=1);

use App\Migration\Import\RegisterKind;
use App\Models\Article;
use App\Models\Supplier;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;
use Tests\Support\RegisterImports;

/*
 * ADR-076, S4: suppliers and articles on the same pipeline.
 */
beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
});

it('imports suppliers with bankgiro and plusgiro checked and the previous number kept', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::fixture('leverantorer-spiris-lik.csv'), 'spiris');
    $suppliers = CurrentCompany::run($this->company, fn () => Supplier::query()->orderBy('number')->get());

    expect($import->report['counts'])->toMatchArray(['read' => 3, 'create' => 2, 'error' => 1])
        ->and($suppliers)->toHaveCount(2)
        ->and($suppliers->pluck('external_ref')->all())->toBe(['2001', '2002'])
        ->and($suppliers[0]->bankgiro)->toBe('505-0109')
        ->and($suppliers[1]->plusgiro)->toBe('12345-5')
        ->and($suppliers[0]->payment_terms_days)->toBe(30)
        ->and(collect($import->report['rows'])->firstWhere('line', 4)['errors'][0])->toContain('Bankgironumret');
});

it('checks IBAN and BIC and a default expense account', function () {
    $csv = "Namn,Iban,Bic,Konto\nOk AB,SE4550000000058398257466,ESSESESS,5410\nFel Iban,SE4550000000058398257467,,\nFel Bic,,XX,\nFel Konto,,,12\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['name' => 'Namn', 'iban' => 'Iban', 'bic' => 'Bic', 'default_account' => 'Konto']]);

    expect($import->report['counts'])->toMatchArray(['create' => 1, 'error' => 3]);
});

it('reports a supplier that already exists by organisation number and leaves it alone', function () {
    RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::fixture('leverantorer-spiris-lik.csv'), 'spiris');
    $again = RegisterImports::run($this->owner, $this->company, RegisterKind::Suppliers, RegisterImports::contents(file_get_contents(base_path('tests/Fixtures/import/leverantorer-spiris-lik.csv'))."\n", 'igen.csv'), 'spiris');

    expect($again->result)->toMatchArray(['created' => 0, 'skipped' => 2])
        ->and(CurrentCompany::run($this->company, fn () => Supplier::query()->count()))->toBe(2);
});

it('imports articles with the price in öre from a decimal comma and a thousand separator', function () {
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Articles, RegisterImports::fixture('artiklar-semikolon.csv'), 'nonox', [
        'mapping' => ['number' => 'Artikelnr', 'name' => 'Benämning', 'unit' => 'Enhet', 'price' => 'Pris', 'vat_rate' => 'Moms'],
    ]);
    $articles = CurrentCompany::run($this->company, fn () => Article::query()->orderBy('number')->get());

    expect($import->report['counts'])->toMatchArray(['read' => 3, 'create' => 2, 'error' => 1])
        ->and($articles->map(fn ($article) => $article->price_ore->ore)->all())->toBe([125000, 4950])
        ->and($articles->pluck('vat_rate')->all())->toBe([2500, 1200])
        ->and($articles->pluck('unit')->all())->toBe(['tim', 'st'])
        ->and($articles->pluck('external_ref')->all())->toBe(['100', '101']);
});

it('refuses an amount that can be read two ways', function () {
    $csv = "Nr,Namn,Pris\n1,Ett,\"1,234\"\n2,Två,1 234.50\n3,Tre,\"1.234,50\"\n";
    $import = RegisterImports::upload($this->owner, $this->company, RegisterKind::Articles, RegisterImports::contents($csv));
    $import = RegisterImports::analyse($this->owner, $import, ['mapping' => ['number' => 'Nr', 'name' => 'Namn', 'price' => 'Pris']]);

    expect($import->report['counts'])->toMatchArray(['create' => 2, 'error' => 1]);
});

it('does not create an article twice on a rerun', function () {
    $mapping = ['number' => 'Artikelnr', 'name' => 'Benämning', 'unit' => 'Enhet', 'price' => 'Pris', 'vat_rate' => 'Moms'];
    RegisterImports::run($this->owner, $this->company, RegisterKind::Articles, RegisterImports::fixture('artiklar-semikolon.csv'), 'nonox', ['mapping' => $mapping]);
    $again = RegisterImports::run($this->owner, $this->company, RegisterKind::Articles, RegisterImports::contents(file_get_contents(base_path('tests/Fixtures/import/artiklar-semikolon.csv'))."\n", 'igen.csv'), 'nonox', ['mapping' => $mapping]);

    expect($again->result)->toMatchArray(['created' => 0, 'skipped' => 2])
        ->and(CurrentCompany::run($this->company, fn () => Article::query()->count()))->toBe(2);
});
