<x-app-layout>
    <div class="mx-auto w-full max-w-xl space-y-6 px-4 py-10 sm:px-6">
        <x-ui.card :title="__(':bank (testbank)', ['bank' => $bank])" :description="__('Det här är en låtsasbank för test och demo. En riktig bank visar sin egen sida och ber om BankID här.')">
            <p class="text-sm text-gray-700">{{ __('NONOX ber om att få läsa saldon och transaktioner till och med :date. NONOX kan inte flytta pengar.', ['date' => $validUntil]) }}</p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ $approve }}" class="inline-flex items-center rounded-full bg-ink px-5 py-2 text-sm font-semibold text-white">{{ __('Godkänn') }}</a>
                <a href="{{ $decline }}" class="inline-flex items-center rounded-full px-5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-stone-300">{{ __('Avbryt') }}</a>
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
