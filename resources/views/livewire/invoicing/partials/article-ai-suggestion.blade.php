{{-- NONOX AI's proposal next to the form's values: tick the fields to use (ADR-039, ADR-018). --}}
<section class="mb-5 rounded-2xl border border-accent-200 bg-accent-50 p-4" aria-labelledby="article-ai-heading" role="region">
    <h3 id="article-ai-heading" class="font-semibold text-ink">{{ __('Förslag från NONOX AI') }}</h3>
    <p class="mt-1 text-sm text-gray-700">{{ __('Bocka i det du vill använda. Tomma fält är förbockade; det som redan står i formuläret ändras bara om du bockar i det. Pris och konto föreslås aldrig.') }}</p>
    @if(filled($aiSuggestion['notes'] ?? ''))
        <p class="mt-2 text-sm text-gray-700">{{ $aiSuggestion['notes'] }}</p>
    @endif
    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-accent-200 text-left text-gray-600">
                    <th scope="col" class="py-2 pr-3 font-medium"><span class="sr-only">{{ __('Använd') }}</span></th>
                    <th scope="col" class="py-2 pr-3 font-medium">{{ __('Uppgift') }}</th>
                    <th scope="col" class="py-2 pr-3 font-medium">{{ __('Nu') }}</th>
                    <th scope="col" class="py-2 font-medium">{{ __('Förslag') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-accent-100">
                @foreach($aiRows as $row)
                    <tr wire:key="ai-row-{{ $row['field'] }}" class="align-top">
                        <td class="py-2 pr-3">
                            <input id="ai-use-{{ $row['field'] }}" type="checkbox" value="{{ $row['field'] }}" wire:model="aiChosen" class="size-5 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                        </td>
                        <td class="py-2 pr-3 font-medium text-ink"><label for="ai-use-{{ $row['field'] }}">{{ $row['label'] }}</label></td>
                        <td class="py-2 pr-3 text-gray-600">{{ $row['current'] === '' ? '–' : Str::limit($row['current'], 160) }}</td>
                        <td class="py-2 text-ink">
                            @if($row['field'] === 'properties')<span class="block text-xs text-gray-600">{{ __('Läggs till:') }}</span>@endif
                            {{ $row['suggested'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-4">
        <x-button type="button" wire:click="applySuggestion">{{ __('Använd valda') }}</x-button>
        <button type="button" class="text-sm text-gray-700 underline" wire:click="dismissSuggestion">{{ __('Stäng utan att ändra') }}</button>
    </div>
</section>
