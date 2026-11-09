@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($zone = config('app.timezone'))

<div class="grid gap-6 lg:grid-cols-5">
    @if($paidNotes->isNotEmpty())
        <x-ui.card class="lg:col-span-5" :title="__('Kunder som har meddelat att de har betalat')" :description="__('Kundens egen notering. Inget är bokfört; registrera betalningen när den har kommit in.')">
            <ul class="mt-3 divide-y divide-gray-100 text-sm">
                @foreach($paidNotes as $note)
                    <li class="py-2">
                        <a href="{{ route('invoicing.invoices.show', ['invoice' => $note->invoice_id]) }}" class="font-medium text-accent-700 underline">{{ $note->invoice?->title() }}</a>
                        · {{ $note->invoice?->customer?->name }} · {{ __('betald :date enligt :name', ['date' => $note->paid_on->toDateString(), 'name' => $note->contact?->identity?->name ?? __('Borttagen kontakt')]) }}
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card class="lg:col-span-2" :title="__('Frågor')">
        <div class="mt-3 flex gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
            <button type="button" wire:click="$set('filter', 'oppna')" @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-gray-900 text-white' => $filter !== 'avslutade', 'ring-1 ring-gray-300' => $filter === 'avslutade']) aria-pressed="{{ $filter !== 'avslutade' ? 'true' : 'false' }}">{{ __('Öppna') }}</button>
            <button type="button" wire:click="$set('filter', 'avslutade')" @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-gray-900 text-white' => $filter === 'avslutade', 'ring-1 ring-gray-300' => $filter !== 'avslutade']) aria-pressed="{{ $filter === 'avslutade' ? 'true' : 'false' }}">{{ __('Avslutade') }}</button>
        </div>
        @if($threads->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('Inga frågor här')">
                {{ __('När en kund skriver i kundportalen syns frågan här, och du får en notis.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100 text-sm">
                @foreach($threads as $item)
                    <li wire:key="thread-{{ $item->id }}">
                        <button type="button" wire:click="open({{ $item->id }})" class="block w-full py-3 text-left {{ $thread?->id === $item->id ? 'font-semibold' : '' }}">
                            <span class="block text-gray-900">{{ $item->subject }}@if($item->kind->value === 'DISPUTE') <span class="ms-1 rounded-sm bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ __('Bestridande') }}</span>@endif</span>
                            <span class="block text-gray-600">{{ $item->customer?->name }} · {{ $item->answered ? __('Besvarad') : __('Väntar på svar') }} · <span class="tabular-nums">{{ $item->last_message_at->setTimezone($zone)->format('Y-m-d') }}</span></span>
                        </button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $threads->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.card class="lg:col-span-3" :title="$thread?->subject ?? __('Välj en fråga')">
        @if(! $thread)
            <p class="mt-3 text-sm text-gray-600">{{ __('Välj en fråga i listan för att läsa och svara.') }}</p>
        @else
            <p class="mt-1 text-sm text-gray-600">{{ $thread->customer?->name }} · {{ $thread->kind->label() }} · {{ $thread->isOpen() ? __('Öppen') : __('Avslutad') }}@if($thread->keep) · {{ __('Sparas') }}@endif</p>
            <ol class="mt-4 space-y-3">
                @foreach($thread->messages as $message)
                    <li class="rounded-xl p-3 text-sm ring-1 {{ $message->fromContact() ? 'bg-white ring-gray-200' : 'bg-stone-50 ring-stone-300' }}">
                        <p class="font-semibold">{{ $message->fromContact() ? ($message->contact?->identity?->name ?? __('Borttagen kontakt')) : ($message->user?->name ?? __('Företaget')) }} <span class="font-normal text-gray-600 tabular-nums">{{ $message->created_at->setTimezone($zone)->format('Y-m-d H:i') }}</span></p>
                        <p class="mt-1 whitespace-pre-line">{{ $message->body }}</p>
                        @foreach($message->attachments as $file)
                            <p class="mt-1"><a href="{{ route('portal.admin.attachment', ['thread' => $thread->id, 'attachment' => $file->id]) }}" class="font-medium text-accent-700 underline">{{ __('Bilaga: :name', ['name' => $file->original_name]) }}</a></p>
                        @endforeach
                    </li>
                @endforeach
            </ol>

            @if($canReply)
                <form wire:submit="send" class="mt-4 space-y-3">
                    <x-ui.field for="portal-reply" :label="__('Svar')" error="body">
                        <textarea id="portal-reply" rows="4" maxlength="4000" class="{{ $input }} mt-1" wire:model="reply"></textarea>
                    </x-ui.field>
                    <x-ui.field for="portal-reply-files" :label="__('Bifoga filer (frivilligt)')" error="files">
                        <input id="portal-reply-files" type="file" multiple accept="application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-sm" wire:model="replyFiles">
                        <p class="mt-1 text-xs text-gray-600">{{ __('PDF, JPEG eller PNG, högst 10 MB per fil och fem filer.') }}</p>
                    </x-ui.field>
                    <p class="text-xs text-gray-600">{{ __('Kunden får ett mejl om att du har svarat, med en länk. Själva svaret står bara i kundportalen.') }}</p>
                    <div class="flex flex-wrap gap-3">
                        <x-button wire:loading.attr="disabled">{{ __('Skicka svar') }}</x-button>
                        @if($thread->isOpen())
                            <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="close" wire:confirm="{{ __('Avsluta frågan? Kunden kan öppna den igen genom att skriva.') }}">{{ __('Avsluta') }}</button>
                        @else
                            <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="close(false)">{{ __('Öppna igen') }}</button>
                        @endif
                        <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="keep({{ $thread->keep ? 'false' : 'true' }})">{{ $thread->keep ? __('Sluta spara') : __('Spara längre') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </x-ui.card>
</div>
