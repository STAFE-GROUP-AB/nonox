@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($open)
        <x-ui.card :title="$editingId ? __('Ändra formulär') : __('Nytt formulär')" :description="__('Namn, e-post och en ruta för samtycke finns alltid med. Välj vilka fält som också ska visas.')">
            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.field for="form_name" :label="__('Rubrik')" error="form.name" class="sm:col-span-2">
                    <input id="form_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100">
                </x-ui.field>

                @foreach($labels as $field => $label)
                    <x-ui.field :for="'form_field_'.$field" :label="$label" :error="'form.fields.'.$field">
                        <select id="form_field_{{ $field }}" class="{{ $input }} mt-1" wire:model="form.fields.{{ $field }}">
                            @foreach($states as $state => $stateLabel)
                                <option value="{{ $state }}">{{ $stateLabel }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endforeach

                <x-ui.field for="form_consent" :label="__('Samtyckestext')" error="form.consent_text" class="sm:col-span-2" :hint="__('Visas bredvid rutan som den som fyller i måste kryssa i. Skriv vad ni gör med uppgifterna, till exempel om ni skickar nyhetsbrev. Ändrar ni texten får den en ny version, och varje anmälan sparar texten den godkände.')">
                    <textarea id="form_consent" rows="3" class="{{ $input }} mt-1" wire:model="form.consent_text"></textarea>
                </x-ui.field>
                <x-ui.field for="form_privacy" :label="__('Länk till er integritetspolicy')" error="form.privacy_url" class="sm:col-span-2">
                    <input id="form_privacy" type="url" class="{{ $input }} mt-1" wire:model="form.privacy_url" placeholder="https://">
                </x-ui.field>
                <x-ui.field for="form_thanks" :label="__('Tack-text (frivilligt)')" error="form.thank_you" class="sm:col-span-2">
                    <textarea id="form_thanks" rows="2" class="{{ $input }} mt-1" wire:model="form.thank_you"></textarea>
                </x-ui.field>
                <x-ui.field for="form_owner" :label="__('Ansvarig för nya leads')" error="form.owner_id">
                    <select id="form_owner" class="{{ $input }} mt-1" wire:model="form.owner_id">
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="form_tags" :label="__('Taggar på nya prospekt')" error="form.tags">
                    <input id="form_tags" type="text" class="{{ $input }} mt-1" wire:model="form.tags" placeholder="{{ __('webb, mässa') }}">
                </x-ui.field>
                <x-ui.field for="form_origins" :label="__('Webbplatser som får visa formuläret')" error="form.embed_origins" class="sm:col-span-2" :hint="__('En per rad, till exempel https://www.exempel.se. Bara de kan visa formuläret i en iframe. Tomt: formuläret visas bara på sin egen sida hos NONOX.')">
                    <textarea id="form_origins" rows="2" class="{{ $input }} mt-1 font-mono text-sm" wire:model="form.embed_origins"></textarea>
                </x-ui.field>
                <label class="flex min-h-11 items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" class="rounded border-gray-300" wire:model="form.is_active"> {{ __('Tar emot anmälningar') }}</label>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara formuläret') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if(! $open)
            <x-button type="button" wire:click="start">{{ __('Nytt formulär') }}</x-button>
        @endif
        @if($forms->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga formulär ännu')">{{ __('Ett formulär på er webbplats som skapar ett prospekt, en kontaktperson med samtycke och en uppgift att följa upp. Utan kakor och utan skript från andra.') }}</x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-forms">
                @foreach($forms as $item)
                    @php($url = route('crm.forms.show', $item->token))
                    <li wire:key="form-{{ $item->id }}" class="space-y-2 py-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="min-w-0 flex-1 basis-60">
                                <span class="block font-medium text-gray-900">{{ $item->name }} @unless($item->is_active)<x-ui.badge class="ms-1">{{ __('Stängt') }}</x-ui.badge>@endunless</span>
                                <span class="block text-sm text-gray-600">{{ trans_choice(':count anmälan|:count anmälningar', $item->submission_count, ['count' => $item->submission_count]) }} · {{ __('samtyckestext version :version', ['version' => $item->consent_version]) }}</span>
                            </span>
                            <span class="flex gap-4 text-sm">
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna') }}</a>
                                <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="start({{ $item->id }})">{{ __('Ändra') }}</button>
                                <button type="button" class="min-h-11 text-gray-700 underline" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Ta bort formuläret? Ett formulär som har tagit emot anmälningar kan bara stängas.') }}">{{ __('Ta bort') }}</button>
                            </span>
                        </div>
                        <details class="text-sm">
                            <summary class="inline-flex min-h-11 cursor-pointer items-center font-medium text-ink sm:min-h-0">{{ __('Lägg in på er webbplats') }}</summary>
                            <p class="mt-2 text-gray-600">{{ __('Klistra in koden där formuläret ska synas. Lägg först till webbplatsens adress under Webbplatser som får visa formuläret.') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-xl bg-stone-100 p-3 text-xs text-ink" data-testid="crm-form-snippet"><code>{{ App\Modules\Crm\Livewire\FormsPage::snippet($item) }}</code></pre>
                            <p class="mt-2 text-gray-600">{{ __('Skriptet är valfritt: det anpassar höjden efter formuläret. Formulärets egen adress:') }} <span class="break-all font-medium text-ink">{{ $url }}</span></p>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
