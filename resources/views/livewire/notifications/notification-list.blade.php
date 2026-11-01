<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-600">{{ trans_choice('{0} Inga olästa notiser.|{1} :count oläst notis.|[2,*] :count olästa notiser.', $unread, ['count' => $unread]) }}</p>
        @if($unread > 0)
            <button type="button" wire:click="markAllRead" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-gray-800 ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Markera alla som lästa') }}</button>
        @endif
    </div>

    @if($notifications->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga notiser')">{{ __('Här hamnar det när någon lämnar en uppgift till dig, kommenterar eller blir klar med något du har lämnat.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <ul class="divide-y divide-stone-200 overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            @foreach($notifications as $notification)
                @php($data = $notification->data)
                <li wire:key="notice-{{ $notification->id }}" @class(['flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between', 'bg-accent-50/40' => $notification->read_at === null])>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500">
                            <span class="font-semibold uppercase tracking-wide text-gray-700">{{ $data['company_name'] ?? '' }}</span>
                            · <span class="tabular-nums">{{ $notification->created_at->format('Y-m-d H:i') }}</span>
                            @if($notification->read_at === null)<span class="ms-1 rounded-full bg-accent-600 px-2 py-0.5 font-semibold text-white">{{ __('Ny') }}</span>@endif
                        </p>
                        <p class="mt-1 text-sm text-ink">{{ App\Notifications\TaskActivity::sentence($data) }}</p>
                    </div>
                    @if(isset($data['company_id'], $data['task_id']) && in_array((int) $data['company_id'], $memberOf, true))
                        <div class="shrink-0">
                            @include('firm.open-in-company', [
                                'companyId' => $data['company_id'],
                                'taskId' => $data['task_id'],
                                'notice' => $notification->id,
                                'label' => __('Öppna uppgiften'),
                            ])
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        {{ $notifications->links() }}
    @endif
</div>
