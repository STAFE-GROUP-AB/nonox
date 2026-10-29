<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Läs in SIE-fil')" :lead="__('Flytta in bokföringen från ett annat program. Ett räkenskapsår per fil.')" />
        <x-ui.bookkeeping-nav />

        <x-ui.card :title="__('Så går det till')">
            <ol class="list-decimal space-y-2 ps-5 text-gray-700">
                <li>{{ __('Ta fram en SIE-fil av typ 4 i ditt gamla program. Den brukar heta något med SIE4 och sluta på .se. Gör en fil för varje räkenskapsår.') }}</li>
                <li>{{ __('Ladda upp filen här. Ingenting ändras än: du får först en rapport om vad filen innehåller och om något behöver rättas.') }}</li>
                <li>{{ __('Läs rapporten och läs in filen. Efteråt jämförs varje konto med filen, så du ser att allt kom med.') }}</li>
            </ol>
            <p class="mt-4 text-sm text-gray-600">{{ __('Har du flera år: börja med det år som redan finns här, oftast det pågående, och fortsätt sedan bakåt ett år i taget.') }}</p>
        </x-ui.card>

        <x-ui.card :title="__('Ladda upp en fil')">
            <form method="POST" action="{{ route('accounting.sieImports.store') }}" enctype="multipart/form-data" class="space-y-4" x-data="{ sending: false }" x-on:submit="sending = true">
                @csrf
                <x-ui.field for="file" :label="__('SIE-fil')" :hint="__('Högst :size MB.', ['size' => $maxMegabytes])">
                    <input id="file" name="file" type="file" required class="mt-1 block w-full text-sm text-gray-700 file:me-4 file:rounded-lg file:border-0 file:bg-accent-50 file:px-4 file:py-2 file:font-medium file:text-accent-700 hover:file:bg-accent-100">
                </x-ui.field>
                <x-button x-bind:disabled="sending">
                    <span x-show="! sending">{{ __('Granska filen') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Granskar…') }}</span>
                </x-button>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Tidigare filer')">
            @if($imports->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Du har inte läst in någon fil ännu.') }}</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach($imports as $import)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <a href="{{ route('accounting.sieImports.show', $import) }}" class="font-medium text-gray-900 hover:text-accent-700">{{ $import->original_name }}</a>
                                <p class="text-sm text-gray-600">
                                    {{ $import->report['fiscal_year']['label'] ?? __('Okänt räkenskapsår') }}
                                    · {{ __(':count verifikationer', ['count' => $import->report['vouchers'] ?? 0]) }}
                                    · {{ $import->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    · {{ $import->user?->name }}
                                </p>
                            </div>
                            <x-ui.badge :tone="$import->status === App\Domain\Accounting\SieImport\SieImportStatus::Completed ? 'success' : 'neutral'">{{ $import->status->label() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
