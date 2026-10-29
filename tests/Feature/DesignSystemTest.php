<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Support\Facades\Blade;

it('shows amounts in Swedish with tabular digits', function () {
    $html = Blade::render('<x-ui.amount :money="$money" />', ['money' => Money::parse('1 234,50')]);

    expect($html)->toContain("1\u{00A0}234,50\u{00A0}kr")
        ->toContain('tabular-nums')
        ->not->toContain('text-accent-700');
});

it('marks negative amounts with a minus sign and colour', function () {
    $html = Blade::render('<x-ui.amount :money="$money" />', ['money' => Money::parse('-50')]);

    expect($html)->toContain('-50,00')
        ->toContain('text-accent-700');
});

it('renders cards with a title and an action', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.card title="Rubrik" description="Förklaring">
            <x-slot:action><x-ui.link href="/x">Gör något</x-ui.link></x-slot:action>
            Innehåll
        </x-ui.card>
        BLADE);

    expect($html)->toContain('Rubrik')->toContain('Förklaring')->toContain('Innehåll')->toContain('Gör något →');
});
