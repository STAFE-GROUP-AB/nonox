{{--
    One entry of a timeline (Timeline): an activity, or for a customer a
    quote, order, invoice, credit invoice, payment or reminder. Each kind has
    its icon and a soft colour of its own. An open activity says when it is
    planned (in the accent when overdue) and, for those who may, has Klar,
    which runs the page's complete-with-next-step flow. Links into the
    ledger only for those who may open it.
--}}
@props(['entry', 'canManage' => false, 'canEdit' => null, 'canSeeInvoices' => false, 'limitedLedger' => false, 'showAccount' => false, 'showPerson' => true, 'now'])

@php
    $zone = 'Europe/Stockholm';
    $at = \Carbon\CarbonImmutable::parse($entry->at)->setTimezone($zone);
    $isActivity = $entry->kind === 'activity';
    $type = $isActivity ? $entry->status : $entry->kind;
    $tone = match ($type) {
        'NOTE' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'CALL' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'MEETING' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'EMAIL' => 'bg-violet-50 text-violet-800 ring-violet-200',
        'TASK' => 'bg-stone-100 text-ink ring-stone-200',
        'invoice', 'credit' => 'bg-accent-50 text-accent-800 ring-accent-200',
        'payment' => 'bg-green-50 text-green-800 ring-green-200',
        'reminder' => 'bg-rose-50 text-rose-800 ring-rose-200',
        default => 'bg-stone-100 text-gray-700 ring-stone-200',
    };
    $label = match ($entry->kind) {
        'activity' => App\Modules\Crm\Domain\ActivityType::from($entry->status)->label(),
        'quote' => __('Offert :number', ['number' => $entry->number]),
        'order' => __('Order :number', ['number' => $entry->number]),
        'invoice' => __('Faktura :number', ['number' => $entry->number]),
        'credit' => __('Kreditfaktura :number', ['number' => $entry->number]),
        'payment' => __('Betalning av faktura :number', ['number' => $entry->number]),
        'reminder' => __('Påminnelse :sequence för faktura :number', ['sequence' => $entry->status, 'number' => $entry->number]),
        default => '',
    };
    $url = ! $canSeeInvoices ? null : match ($entry->kind) {
        'quote' => route('invoicing.quotes.show', $entry->ref),
        'order' => route('invoicing.orders.show', $entry->ref),
        'invoice', 'credit', 'payment', 'reminder' => route('invoicing.invoices.show', $entry->ref),
        default => null,
    };
    $open = $isActivity && $entry->done_at === null && $entry->status !== 'NOTE';
    $due = $open && $entry->due_at ? \Carbon\CarbonImmutable::parse($entry->due_at) : null;
    $late = $due !== null && $due->lessThan($now);
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
@endphp

<article {{ $attributes->class(['flex gap-3 rounded-2xl bg-white p-4 shadow-xs ring-1 sm:gap-4', 'ring-accent-200' => $late, 'ring-stone-200' => ! $late]) }} data-testid="crm-entry">
    <span class="inline-flex size-10 flex-none items-center justify-center rounded-xl ring-1 {{ $tone }}"><x-crm::icon :name="$type" /></span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <h3 class="min-w-0 font-semibold text-ink">
                @if($url)<a href="{{ $url }}" class="hover:text-accent-700">{{ $label }}</a>@else{{ $label }}@endif
                @if($isActivity && $entry->subject !== $label)<span class="font-normal text-gray-700"> · {{ $entry->subject }}</span>@endif
            </h3>
            <time datetime="{{ $at->toIso8601String() }}" class="flex-none text-xs tabular-nums text-gray-500">{{ $isActivity ? $at->translatedFormat('j M Y H:i') : $at->translatedFormat('j M Y') }}</time>
        </div>

        <p class="mt-0.5 text-sm text-gray-600">
            @if($isActivity)
                {{ collect([
                    $open ? ($due ? __('Planerad till :time', ['time' => $due->setTimezone($zone)->translatedFormat('j M H:i')]) : __('Inte gjord än')) : null,
                    $entry->outcome ? App\Modules\Crm\Domain\CallOutcome::from($entry->outcome)->label() : null,
                    $entry->user_name,
                ])->filter()->implode(' · ') }}
                @if($late)<span class="font-medium text-accent-700"> · {{ __('försenad') }}</span>@endif
            @elseif($entry->amount_ore !== null && ! ($limitedLedger && in_array($entry->kind, ['invoice', 'credit', 'payment'], true)))
                <x-ui.amount :money="$money((int) $entry->amount_ore)" class="font-medium text-ink" />
                @if(in_array($entry->kind, ['invoice', 'credit', 'quote', 'order'], true)) {{ __('exkl. moms') }}@endif
                @if($entry->kind === 'reminder') {{ __('kvar att betala') }}@endif
            @endif
        </p>

        @if($isActivity && (($showAccount && $entry->account_name) || $entry->deal_title || ($showPerson && $entry->person_name)))
            <p class="mt-1 flex flex-wrap gap-x-3 text-sm">
                @if($showPerson && $entry->person_name)
                    <a href="{{ route('crm.people.show', $entry->person_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $entry->person_name }}</a>
                @endif
                @if($showAccount && $entry->account_name)
                    <a href="{{ route('crm.accounts.show', $entry->account_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $entry->account_name }}</a>
                @endif
                @if($entry->deal_title)
                    <a href="{{ route('crm.deals.show', $entry->deal_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Affär') }}: {{ $entry->deal_title }}</a>
                @endif
            </p>
        @endif

        @if($isActivity && $entry->body)
            <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-800">{{ $entry->body }}</p>
        @endif

        @if($isActivity && ($entry->email_id ?? null))
            <p class="mt-1 text-sm"><a href="{{ route('crm.emails', ['visa' => $entry->email_id]) }}" class="inline-flex min-h-11 items-center font-medium text-accent-600 hover:text-accent-700 sm:min-h-0">{{ __('Visa hela mejlet') }}</a></p>
        @endif

        @if($open && $canManage)
            <div class="mt-3 flex flex-wrap gap-3">
                <button type="button" class="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-600 sm:min-h-9" wire:click="startCompletion({{ $entry->ref }})">
                    <x-crm::icon name="check" class="size-4" />{{ __('Klar') }}
                </button>
                @if($canEdit ?? $canManage)
                    <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700 sm:min-h-9" wire:click="editActivity({{ $entry->ref }})">{{ __('Ändra') }}</button>
                @endif
            </div>
        @endif
    </div>
</article>
