@php
    $typeLabel = fn (string $type): string => match ($type) {
        'QUOTE' => __('Offert'),
        'AGREEMENT' => __('Avtal'),
        default => __('Eget dokument'),
    };
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('signing.template.new') }}" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-gray-800">{{ __('Ny mall för offert eller avtal') }}</a>
            <a href="{{ route('signing.new') }}" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 text-sm font-medium text-ink ring-1 ring-stone-200 hover:bg-gray-50">{{ __('Ny mall från ett dokument') }}</a>
            <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
                @foreach(['aktiva' => __('Aktiva'), 'arkiverade' => __('Arkiverade')] as $key => $label)
                    <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $show === $key, 'bg-white text-ink ring-1 ring-stone-200' => $show !== $key]) aria-pressed="{{ $show === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <p class="mt-3 text-sm text-gray-600">{{ __('En mall för ett eget dokument skapas i guiden Skicka dokument: ladda upp PDF:en, ange roller och placera fälten, och välj Spara som mall på sista steget. Offerter och avtal har ingen fil, bara roller och inställningar.') }}</p>

        @if($templates->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$show === 'arkiverade' ? __('Inga arkiverade mallar') : __('Inga mallar än')">
                {{ $show === 'arkiverade' ? __('Arkiverade mallar visas här.') : __('En mall sparar roller, steg, kontroller och fält, så att nästa signering bara behöver namn och e-post. Skapa en från ett dokument eller för offerter och avtal.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm" data-testid="signing-templates">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Mall') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('För') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Roller') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Fält') }}</th>
                            <th scope="col" class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($templates as $template)
                            <tr wire:key="template-{{ $template->id }}" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-3">
                                    <span class="font-semibold text-gray-900">{{ $template->name }}</span>
                                    <span class="block text-gray-600">{{ __('Skapad av :name', ['name' => $template->creator?->name ?? '–']) }}</span>
                                </td>
                                <td class="py-3 pr-3">{{ $typeLabel($template->document_type) }}@if($template->confidential) <x-ui.badge tone="warning" class="ms-1">{{ __('Konfidentiell') }}</x-ui.badge>@endif</td>
                                <td class="py-3 pr-3 tabular-nums">{{ $template->roles_count }}</td>
                                <td class="py-3 pr-3 tabular-nums">{{ $template->hasFile() ? $template->fields_count : '–' }}</td>
                                <td class="py-3">
                                    <div class="flex flex-wrap justify-end gap-x-4 gap-y-1">
                                        @if(! $template->isArchived() && $template->hasFile())
                                            <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0" wire:click="begin('{{ $template->id }}')">{{ __('Använd') }}</button>
                                        @endif
                                        @if($canChange($template))
                                            <a href="{{ route('signing.template', $template->id) }}" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Ändra') }}</a>
                                            @if($template->isArchived())
                                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="restore('{{ $template->id }}')">{{ __('Återställ') }}</button>
                                            @else
                                                <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="archive('{{ $template->id }}')" wire:confirm="{{ __('Arkivera mallen? Den går inte längre att välja. Signeringar som redan gjorts med den ändras inte.') }}">{{ __('Arkivera') }}</button>
                                            @endif
                                            <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="remove('{{ $template->id }}')" wire:confirm="{{ __('Ta bort mallen? Det går inte att ångra. En mall som har använts kan bara arkiveras.') }}">{{ __('Ta bort') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $templates->links() }}</div>
        @endif
    </x-ui.card>
</div>
