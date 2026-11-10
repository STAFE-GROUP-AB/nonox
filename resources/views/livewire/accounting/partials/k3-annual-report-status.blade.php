{{-- K3 annual report (ADR-059, S1-P10, Beta): what refuses it, with the rule ids, and what is left to answer. --}}
<div class="space-y-4">
    <div class="rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700 ring-1 ring-stone-200">
        <x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge>
        <span class="ml-2">{{ __('Årsredovisningen enligt K3 (BFNAR 2012:1) för ett mindre aktiebolag. Låt en redovisningskonsult granska den innan den skrivs under; NONOX tar inte fram den som iXBRL ännu.') }}</span>
    </div>

    @if($k3Refusals !== [])
        <section class="rounded-2xl bg-red-50 p-4 ring-1 ring-red-200" aria-labelledby="k3-refusals">
            <h2 id="k3-refusals" class="text-sm font-semibold text-red-900">{{ __('Det här hindrar årsredovisningen') }}</h2>
            <p class="mt-1 text-sm text-red-900">{{ __('K3 tillämpas helt eller inte alls. Så länge något av detta gäller tar NONOX inte fram årsredovisningen.') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-900">
                @foreach($k3Refusals as $refusal)
                    <li wire:key="k3-refusal-{{ $loop->index }}">{{ $refusal['message'] }} <span class="whitespace-nowrap text-xs font-semibold">({{ $refusal['rule'] }})</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($k3Questions !== [])
        <section class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200" aria-labelledby="k3-questions">
            <h2 id="k3-questions" class="text-sm font-semibold text-amber-900">{{ __('Frågor att besvara') }}</h2>
            <p class="mt-1 text-sm text-amber-900">{{ __('Förhandsvisningen är ett utkast tills frågorna är besvarade. Svara under Uppgifter för K3, eller skriv noten med egna ord under Noter.') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                @foreach($k3Questions as $question)
                    <li wire:key="k3-question-{{ $loop->index }}">{{ $question['message'] }}</li>
                @endforeach
            </ul>
        </section>
    @elseif($k3Refusals === [])
        <p class="rounded-2xl bg-green-50 px-4 py-3 text-sm text-green-900 ring-1 ring-green-200">{{ __('Alla frågor är besvarade. Årsredovisningen kan laddas ner som PDF.') }}</p>
    @endif
</div>
