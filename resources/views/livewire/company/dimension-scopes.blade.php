<x-action-section>
    <x-slot name="title">{{ __('Behörighet per enhet') }}</x-slot>
    <x-slot name="description">
        {{ __('Begränsa en person till vissa kostnadsställen och projekt. Personen ser då bara verifikationer, leverantörsfakturor och rapporter för dem, och inga rapporter för hela företaget.') }}
        <span class="mt-2 block">{{ __('Administratörer, redovisningskonsulter och revisorer behöver hela bokföringen och kan inte begränsas.') }}</span>
    </x-slot>
    <x-slot name="content">
        @if($members->isEmpty())
            <p class="text-sm text-gray-600" data-dimension-scope-empty>{{ __('Ingen i företaget har en roll som kan begränsas. Bjud in någon som Bokförare, Attestant eller Enhetschef under Uppgifter och personer.') }}</p>
        @else
            <ul class="divide-y divide-gray-100" data-dimension-scope-members>
                @foreach($members as $member)
                    @php
                        $centres = $costCenters->whereIn('id', $member['cost_center_ids'])->map->label()->values()->all();
                        $chosenProjects = $projects->whereIn('id', $member['project_ids'])->map->label()->values()->all();
                    @endphp
                    <li class="py-4 first:pt-0 last:pb-0" wire:key="scope-member-{{ $member['user_id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900">{{ $member['name'] }} <span class="text-sm font-normal text-gray-500">· {{ $member['role_label'] }}</span></p>
                                <p class="text-sm text-gray-600">
                                    @if(! $member['limited'])
                                        {{ __('Når hela företaget enligt sin roll.') }}
                                    @elseif($centres === [] && $chosenProjects === [])
                                        {{ __('Begränsad, men har inga kostnadsställen eller projekt ännu, och når därför ingenting.') }}
                                    @else
                                        {{ __('Når bara:') }} {{ implode(', ', [...$centres, ...$chosenProjects]) }}
                                    @endif
                                </p>
                            </div>
                            @if($editing !== $member['user_id'])
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="edit({{ $member['user_id'] }})" class="font-semibold text-accent-600 hover:text-accent-700">{{ $member['limited'] ? __('Ändra') : __('Begränsa') }}</button>
                                    @if($member['limited'] && ($member['cost_center_ids'] !== [] || $member['project_ids'] !== []))
                                        <button type="button" wire:click="lift({{ $member['user_id'] }})" wire:confirm="{{ $member['role'] === App\Domain\Company\CompanyRole::UnitManager->value ? __('Ta bort kostnadsställena och projekten? En enhetschef når då ingenting.') : __('Ta bort begränsningen? Personen når då hela företaget enligt sin roll.') }}" class="font-semibold text-gray-700 hover:text-gray-900">{{ __('Ta bort begränsningen') }}</button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if($editing === $member['user_id'])
                            <form wire:submit="save" class="mt-4 space-y-4 rounded-xl border border-gray-200 bg-stone-50 p-4" data-dimension-scope-form>
                                @if($member['module_role'])
                                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note" data-dimension-scope-module-warning>{{ __(':role är en roll i en modul. En begränsad person når ingenting i modulen ännu, bara verifikationer, leverantörsfakturor och rapporter som rollen redan ger.', ['role' => $member['role_label']]) }}</p>
                                @endif
                                <div class="grid gap-6 sm:grid-cols-2">
                                    <fieldset>
                                        <legend class="text-sm font-semibold text-gray-900">{{ __('Kostnadsställen') }}</legend>
                                        @forelse($costCenters as $costCenter)
                                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-800">
                                                <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $costCenter->id }}" wire:model="costCenterIds">
                                                {{ $costCenter->label() }}
                                            </label>
                                        @empty
                                            <p class="mt-2 text-sm text-gray-600">{{ __('Företaget har inga kostnadsställen.') }}</p>
                                        @endforelse
                                    </fieldset>
                                    <fieldset>
                                        <legend class="text-sm font-semibold text-gray-900">{{ __('Projekt') }}</legend>
                                        @forelse($projects as $project)
                                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-800">
                                                <input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $project->id }}" wire:model="projectIds">
                                                {{ $project->label() }}
                                            </label>
                                        @empty
                                            <p class="mt-2 text-sm text-gray-600">{{ __('Företaget har inga projekt.') }}</p>
                                        @endforelse
                                    </fieldset>
                                </div>
                                <x-input-error for="member" />
                                <x-input-error for="costCenters" />
                                <x-input-error for="projects" />
                                <p class="text-xs text-gray-500">{{ __('Utan något valt tas begränsningen bort. En enhetschef når då ingenting.') }}</p>
                                <div class="flex flex-wrap gap-3">
                                    <x-button type="submit">{{ __('Spara behörighet') }}</x-button>
                                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-slot>
</x-action-section>
