@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div>
    <x-ui.card :title="$isArticles ? __('Skriv ut artikeletiketter') : __('Skriv ut platsetiketter')" :description="$total === 1 ? __('En etikett blir en PDF som öppnas i en ny flik.') : __(':count etiketter blir en PDF som öppnas i en ny flik.', ['count' => $total])">
        @if($templates->isEmpty())
            <x-ui.empty-state :title="__('Ingen etikettmall')">{{ __('Be den som har hand om inställningarna att skapa en mall under Lager, Inställningar, Etiketter.') }}</x-ui.empty-state>
        @else
            <form method="post" action="{{ route('inventory.labels.print') }}" target="_blank" x-on:submit="$wire.printed()" class="grid gap-5 sm:grid-cols-6">
                @csrf
                <input type="hidden" name="kind" value="{{ $isArticles ? 'articles' : 'locations' }}">
                <input type="hidden" name="template_id" value="{{ $template?->id }}">
                @foreach($copies as $id => $count)
                    @if($isArticles)
                        <input type="hidden" name="copies[{{ $id }}]" value="{{ $count }}">
                    @else
                        <input type="hidden" name="ids[]" value="{{ $id }}">
                    @endif
                @endforeach

                <x-ui.field for="label-template" :label="__('Mall')" class="sm:col-span-3">
                    <select id="label-template" wire:model.live="templateId" class="{{ $input }}">
                        @foreach($templates as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->summary() }}@if($option->is_default) · {{ __('standard') }}@endif</option>
                        @endforeach
                    </select>
                </x-ui.field>

                @if($isArticles)
                    <x-ui.field for="label-mode" :label="__('Antal etiketter')" class="sm:col-span-2">
                        <select id="label-mode" wire:model.live="mode" class="{{ $input }}">
                            <option value="given">{{ $givenLabel }}</option>
                            <option value="stock">{{ __('Enligt lagersaldo (högst :max per artikel)', ['max' => App\Modules\Inventory\Actions\PrintLabels::MAX_COPIES]) }}</option>
                            <option value="fixed">{{ __('Samma antal för alla') }}</option>
                        </select>
                    </x-ui.field>
                    @if($mode === 'fixed')
                        <x-ui.field for="label-fixed" :label="__('Antal per artikel')" class="sm:col-span-1">
                            <input id="label-fixed" type="number" min="1" max="{{ App\Modules\Inventory\Actions\PrintLabels::MAX_COPIES }}" wire:model.live.debounce.400ms="fixed" class="{{ $input }} text-right tabular-nums">
                        </x-ui.field>
                    @endif
                @endif

                @if($isSheet)
                    <x-ui.field for="label-start" :label="__('Börja på etikett nr')" class="sm:col-span-2" :hint="__('Har arket redan använda etiketter? Räkna radvis från övre vänstra hörnet, högst :max.', ['max' => $template->perPage()])">
                        <input id="label-start" name="start" type="number" min="1" max="{{ $template->perPage() }}" wire:model="start" class="{{ $input }} text-right tabular-nums">
                    </x-ui.field>
                @endif

                @if($isArticles)
                    <div class="sm:col-span-6">
                        <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="mark_printed" value="1" wire:model="markPrinted" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Markera artiklarna som utskrivna') }}
                        </label>
                        @if(! $withPrices)
                            <p class="mt-1 text-sm text-gray-600">{{ __('Priset skrivs inte ut, eftersom din roll inte visar priser.') }}</p>
                        @endif
                    </div>
                @endif

                @if($tooMany)
                    <p class="text-sm font-medium text-red-700 sm:col-span-6" role="alert">{{ __('Det blir :count etiketter. Högst :max går att skriva ut på en gång; dela upp utskriften.', ['count' => $total, 'max' => $max]) }}</p>
                @endif

                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" :disabled="$tooMany || $template === null">{{ __('Skriv ut') }}</x-button>
                    <x-secondary-button type="button" wire:click="close">{{ __('Stäng') }}</x-secondary-button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
