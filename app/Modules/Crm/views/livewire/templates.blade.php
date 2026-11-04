@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($open)
        <x-ui.card :title="$editingId ? __('Ändra mall') : __('Ny mall')">
            <form wire:submit="save" class="mt-4 grid gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <x-ui.field for="template_name" :label="__('Namn')" error="form.name" :hint="__('Syns bara för er, i listan när någon väljer mall.')">
                        <input id="template_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100">
                    </x-ui.field>
                    <x-ui.field for="template_subject" :label="__('Ämne')" error="form.subject">
                        <input id="template_subject" type="text" class="{{ $input }} mt-1" wire:model="form.subject" maxlength="200">
                    </x-ui.field>
                    <x-ui.field for="template_body" :label="__('Text')" error="form.body">
                        <textarea id="template_body" rows="12" class="{{ $input }} mt-1 font-mono text-sm" wire:model="form.body"></textarea>
                    </x-ui.field>
                </div>
                <aside class="rounded-2xl bg-stone-50 p-4 text-sm ring-1 ring-stone-200" aria-labelledby="merge-fields-title">
                    <h3 id="merge-fields-title" class="font-semibold text-ink">{{ __('Fält') }}</h3>
                    <p class="mt-1 text-gray-600">{{ __('Skriv fältet med klammerparenteser. Det byts mot mottagarens uppgifter när mejlet skickas.') }}</p>
                    <dl class="mt-3 space-y-2">
                        @foreach($fields as $field => $description)
                            <div><dt><code class="font-semibold text-ink">{{ '{'.$field.'}' }}</code></dt><dd class="text-gray-600">{{ $description }}</dd></div>
                        @endforeach
                    </dl>
                </aside>
                <div class="flex flex-wrap items-center gap-4 lg:col-span-3">
                    <x-button wire:loading.attr="disabled">{{ __('Spara mallen') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canSettings && ! $open)
            <x-button type="button" wire:click="start">{{ __('Ny mall') }}</x-button>
        @endif
        @if($templates->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga mallar ännu')">{{ __('En mall är ett mejl ni skickar ofta, med fält för namn och företag. Den används med Mejla med mall på en kund eller en person.') }}</x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-templates">
                @foreach($templates as $template)
                    <li wire:key="template-{{ $template->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="min-w-0 flex-1 basis-60">
                            <span class="block font-medium text-gray-900">{{ $template->name }}</span>
                            <span class="block text-sm text-gray-600">{{ $template->subject }}</span>
                        </span>
                        @if($canSettings)
                            <span class="flex gap-4 text-sm">
                                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="start({{ $template->id }})">{{ __('Ändra') }}</button>
                                <button type="button" class="min-h-11 text-gray-700 underline" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('Ta bort mallen? Mejl som redan är skickade påverkas inte.') }}">{{ __('Ta bort') }}</button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
