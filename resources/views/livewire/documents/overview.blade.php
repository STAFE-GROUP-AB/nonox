@php
    use Illuminate\Support\Number;
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-0 flex-1 basis-64">
            <label for="overview_search" class="block text-sm font-medium text-gray-700">{{ __('Sök i rubriken') }}</label>
            <input id="overview_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="search">
            <p class="mt-1 text-xs text-gray-500">{{ __('Sökningen läser inte krypterade rubriker, som personaldokument och dokument för NONOX Avtal.') }}</p>
        </div>
        <div>
            <label for="overview_source" class="block text-sm font-medium text-gray-700">{{ __('Källa') }}</label>
            <select id="overview_source" class="{{ $input }} mt-1" wire:model.live="source">
                <option value="">{{ __('Alla källor') }}</option>
                @foreach($sources as $option)
                    <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($documents->isEmpty())
        <x-ui.empty-state :title="$filtered ? __('Inga dokument matchar') : __('Inga dokument ännu')">
            {{ $filtered ? __('Prova ett annat ord eller en annan källa.') : __('Här samlas företagets dokument och filerna i övriga delar av NONOX så snart det finns några.') }}
        </x-ui.empty-state>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Rubrik') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Källa') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Hör till') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Datum') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Storlek') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($documents as $document)
                        <tr wire:key="overview-{{ $document->source }}-{{ $document->sourceId }}-{{ $document->parentId }}">
                            <td class="px-4 py-3">
                                <a href="{{ $document->url }}" class="font-medium text-accent-700 underline" @if($document->source === 'document' || $document->source === 'agreement') wire:navigate @else target="_blank" rel="noopener" @endif>{{ $document->title !== '' ? $document->title : __('Utan rubrik') }}</a>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ $document->label }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ $document->subtitle ?? '–' }}</td>
                            <td class="px-4 py-3 tabular-nums text-gray-700">{{ $document->date?->toDateString() ?? '–' }}</td>
                            <td class="px-4 py-3 tabular-nums text-gray-700">{{ $document->size !== null ? Number::fileSize((int) $document->size, precision: 1) : '–' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    @endif
</div>
