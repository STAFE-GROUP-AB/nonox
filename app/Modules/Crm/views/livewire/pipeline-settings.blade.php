@php $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs'; @endphp

<div class="space-y-6">
    <x-ui.card :title="__('Säljtrattar')" :description="__('Stegen en affär går igenom, med sannolikheten att en affär i steget blir vunnen. Högst :max steg per säljtratt; vunnen och förlorad är inga steg utan avslutar affären.', ['max' => $max])">
        <x-slot:cta>
            @if($editing === '')
                <x-button type="button" wire:click="startPipeline">{{ __('Ny säljtratt') }}</x-button>
            @endif
        </x-slot:cta>

        @if($editing !== '')
            <form wire:submit="savePipeline" class="space-y-4 rounded-2xl bg-stone-50 p-4" data-testid="crm-pipeline-form">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="pipeline_name" :label="__('Namn')" error="name">
                        <input id="pipeline_name" type="text" class="{{ $input }} mt-1" wire:model="pipelineForm.name" placeholder="{{ __('Till exempel: Service') }}">
                    </x-ui.field>
                    <label class="flex min-h-11 items-center gap-3 self-end text-sm text-gray-800">
                        <input type="checkbox" class="size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="pipelineForm.is_default">
                        <span>{{ __('Förvald för nya affärer') }}</span>
                    </label>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-ink">{{ __('Steg') }}</legend>
                    @error('stages')<p class="mt-1 text-sm text-accent-700">{{ $message }}</p>@enderror
                    <ol class="mt-2 space-y-3">
                        @foreach($pipelineForm['stages'] as $index => $stage)
                            <li wire:key="stage-row-{{ $stage['id'] ?? 'new-'.$index }}" class="rounded-xl bg-white p-3 ring-1 ring-stone-200">
                                <div class="flex flex-wrap items-end gap-3">
                                    <x-ui.field :for="'stage_name_'.$index" :label="__('Steg :n', ['n' => $index + 1])" :error="'stages.'.$index.'.name'" class="min-w-0 flex-1 basis-40">
                                        <input id="stage_name_{{ $index }}" type="text" class="{{ $input }} mt-1" wire:model="pipelineForm.stages.{{ $index }}.name">
                                    </x-ui.field>
                                    <x-ui.field :for="'stage_probability_'.$index" :label="__('Sannolikhet, %')" :error="'stages.'.$index.'.probability'" class="w-28">
                                        <input id="stage_probability_{{ $index }}" type="number" min="0" max="100" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="pipelineForm.stages.{{ $index }}.probability">
                                    </x-ui.field>
                                    <span class="flex gap-1">
                                        <button type="button" class="inline-flex size-11 items-center justify-center rounded-lg ring-1 ring-stone-200 hover:bg-stone-100 disabled:opacity-40" wire:click="moveStage({{ $index }}, -1)" @disabled($index === 0) aria-label="{{ __('Flytta upp') }}">↑</button>
                                        <button type="button" class="inline-flex size-11 items-center justify-center rounded-lg ring-1 ring-stone-200 hover:bg-stone-100 disabled:opacity-40" wire:click="moveStage({{ $index }}, 1)" @disabled($loop->last) aria-label="{{ __('Flytta ned') }}">↓</button>
                                    </span>
                                    @if(count($pipelineForm['stages']) > 1)
                                        <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="removeStage({{ $index }})" @if($stage['id'] !== null && $stage['deals'] === 0) wire:confirm="{{ __('Ta bort steget :name?', ['name' => $stage['name']]) }}" @endif>{{ __('Ta bort') }}</button>
                                    @endif
                                </div>
                                @if($stage['deals'] > 0)
                                    <p class="mt-1 text-xs text-gray-500">{{ trans_choice(':count affär i steget|:count affärer i steget', $stage['deals'], ['count' => $stage['deals']]) }}</p>
                                @endif
                                @if($removingStageId !== null && $removingStageId === $stage['id'])
                                    <div class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-accent-50 p-3">
                                        <x-ui.field :for="'move_to_'.$index" :label="__('Flytta affärerna till')" error="stage" class="min-w-0 flex-1 basis-40">
                                            <select id="move_to_{{ $index }}" class="{{ $input }} mt-1" wire:model="moveTo">
                                                <option value="">{{ __('Välj steg') }}</option>
                                                @foreach($pipelineForm['stages'] as $other)
                                                    @continue($other['id'] === null || $other['id'] === $stage['id'])
                                                    <option value="{{ $other['id'] }}">{{ $other['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </x-ui.field>
                                        <button type="button" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white" wire:click="removeStage({{ $index }})">{{ __('Flytta och ta bort') }}</button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    @if(count($pipelineForm['stages']) < $max)
                        <button type="button" class="mt-3 min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="addStage">{{ __('Lägg till steg') }}</button>
                    @else
                        <p class="mt-3 text-sm text-gray-600">{{ __('Säljtratten har :max steg, det högsta som går.', ['max' => $max]) }}</p>
                    @endif
                </fieldset>

                <div class="flex flex-wrap items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Spara säljtratten') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closeForm">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif

        <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-pipelines">
            @foreach($pipelines as $pipeline)
                <li wire:key="pipeline-{{ $pipeline->id }}" class="flex flex-wrap items-center gap-3 py-3">
                    <span class="min-w-0 flex-1 basis-60">
                        <span class="block font-medium text-gray-900">
                            {{ $pipeline->name }}
                            @if($pipeline->is_default)<x-ui.badge tone="accent" class="ms-1">{{ __('Förvald') }}</x-ui.badge>@endif
                            @if($pipeline->isArchived())<x-ui.badge class="ms-1">{{ __('Arkiverad') }}</x-ui.badge>@endif
                        </span>
                        <span class="block text-sm text-gray-600">{{ $pipeline->stages->map(fn ($stage) => $stage->name.' '.$stage->probability.' %')->implode(' → ') }}</span>
                    </span>
                    <span class="flex flex-wrap gap-4 text-sm">
                        @unless($pipeline->isArchived())
                            <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="startPipeline({{ $pipeline->id }})">{{ __('Ändra') }}</button>
                            <button type="button" class="min-h-11 text-gray-700 underline" wire:click="archive({{ $pipeline->id }}, true)" wire:confirm="{{ __('Arkivera :name? Vunna och förlorade affärer finns kvar, men inga nya kan läggas i den.', ['name' => $pipeline->name]) }}">{{ __('Arkivera') }}</button>
                        @else
                            <button type="button" class="min-h-11 text-gray-700 underline" wire:click="archive({{ $pipeline->id }}, false)">{{ __('Använd igen') }}</button>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Orsaker till förlorade affärer')" :description="__('Den som markerar en affär som förlorad väljer en av dessa. En arkiverad orsak finns kvar på affärerna som har den.')">
        <ul class="divide-y divide-gray-100" data-testid="crm-loss-reasons">
            @foreach($reasons as $reason)
                <li wire:key="reason-{{ $reason->id }}" class="flex flex-wrap items-center gap-3 py-2">
                    <input type="text" class="{{ $input }} min-w-0 flex-1 basis-48 disabled:bg-stone-50 disabled:text-gray-500" wire:model="reasonNames.{{ $reason->id }}" aria-label="{{ __('Orsak') }}" @disabled($reason->archived_at !== null)>
                    @if($reason->archived_at === null)
                        <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="renameReason({{ $reason->id }})">{{ __('Spara') }}</button>
                    @endif
                    <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="toggleReason({{ $reason->id }})">{{ $reason->archived_at === null ? __('Arkivera') : __('Använd igen') }}</button>
                </li>
            @endforeach
        </ul>
        <form wire:submit="addReason" class="mt-4 flex flex-wrap items-end gap-3">
            <x-ui.field for="new_reason" :label="__('Ny orsak')" error="name" class="min-w-0 flex-1 basis-48">
                <input id="new_reason" type="text" class="{{ $input }} mt-1" wire:model="newReason">
            </x-ui.field>
            <x-button wire:loading.attr="disabled" class="min-h-11">{{ __('Lägg till') }}</x-button>
        </form>
    </x-ui.card>
</div>
