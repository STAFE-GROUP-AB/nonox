{{-- "Mejla med mall" (ADR-048): the panel on the account card and the person page. Expects $templates, $mailRecipients (id => label; '' for the account's own address), $input and $privatePerson. --}}
<x-ui.card :title="__('Mejla med mall')" :description="__('Mejlet skickas från NONOX med företagets namn som avsändare. Svaret går till din e-postadress, och mejlet loggas här.')">
    <form wire:submit="sendTemplateMail" class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-ui.field for="mail_to" :label="__('Till')" error="mail.person_id">
            <select id="mail_to" class="{{ $input }} mt-1" wire:model="mail.person_id">
                @foreach($mailRecipients as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.field for="mail_template" :label="__('Mall')" error="mail.template_id">
            <select id="mail_template" class="{{ $input }} mt-1" wire:model.live="mail.template_id">
                <option value="">{{ __('Ingen mall') }}</option>
                @foreach($templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.field for="mail_subject" :label="__('Ämne')" error="mail.subject" class="sm:col-span-2">
            <input id="mail_subject" type="text" class="{{ $input }} mt-1" wire:model="mail.subject" maxlength="200">
        </x-ui.field>
        <x-ui.field for="mail_body" :label="__('Text')" error="mail.body" class="sm:col-span-2" :hint="__('Fälten, till exempel {förnamn} och {säljare}, fylls i när mejlet skickas.')">
            <textarea id="mail_body" rows="9" class="{{ $input }} mt-1" wire:model="mail.body"></textarea>
        </x-ui.field>
        @if($privatePerson)
            <p class="text-sm text-gray-700 sm:col-span-2">{{ __('Mottagaren är en privatperson. Är mejlet marknadsföring krävs samtycke, eller att personen redan är kund (marknadsföringslagen 19 §).') }}</p>
        @endif
        <label class="flex items-start gap-2 text-sm sm:col-span-2">
            <input type="checkbox" class="mt-0.5 rounded border-gray-300" wire:model="mail.not_marketing">
            <span>{{ __('Mejlet är inte marknadsföring (till exempel ett svar om en order). Behövs bara om mottagaren har avregistrerat sig från utskick.') }}</span>
        </label>
        @error('mail.not_marketing')<p class="text-sm font-medium text-accent-700 sm:col-span-2" role="alert">{{ $message }}</p>@enderror
        @error('mail.account_id')<p class="text-sm font-medium text-accent-700 sm:col-span-2" role="alert">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
            <x-button wire:loading.attr="disabled">{{ __('Skicka mejlet') }}</x-button>
            <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
        </div>
    </form>
</x-ui.card>
