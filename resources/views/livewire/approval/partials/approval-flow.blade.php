{{--
    Where a subject stands in the company's own approval policy (ADR-081
    gap 8): the steps, the decisions so far and what the viewer can do.
    Needs $approval (ApprovalState), $memberNames and $canResubmit.
--}}
@php
    use App\Domain\Approval\ApprovalDecisionKind;
    use App\Domain\Approval\Eligibility;
    use App\Domain\Approval\StepDescription;

    $snapshot = $approval->snapshot;
@endphp

<x-ui.card data-approval-flow :title="__('Attestflöde')" :description="$snapshot ? __('Följer :name (version :version).', ['name' => $snapshot->name, 'version' => $snapshot->version]) : null">
    @if($snapshot)
        <ol class="space-y-1 text-sm">
            @foreach($snapshot->steps as $index => $step)
                @php
                    $number = $index + 1;
                @endphp
                <li class="flex flex-wrap justify-between gap-3">
                    <span>{{ __('Steg :number: :who', ['number' => $number, 'who' => StepDescription::approvers($step, $memberNames)]) }}</span>
                    <span class="text-gray-600">
                        @if($approval->pending === null && ! $approval->awaitingResubmission && $approval->history->isNotEmpty())
                            {{ __('Klart') }}
                        @elseif($approval->awaitingResubmission)
                            {{ __('Avbrutet') }}
                        @elseif($number < $approval->currentStep())
                            {{ __('Klart') }}
                        @elseif($number === $approval->currentStep())
                            {{ __('Pågår, :given av :required godkända', ['given' => $approval->approvalsAtStep(), 'required' => $step->requiredCount]) }}
                        @else
                            {{ __('Väntar') }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    @endif

    @if($approval->awaitingResubmission)
        <div class="mt-4 rounded-2xl bg-accent-50 p-4 text-sm text-accent-900" role="status">
            <p class="font-medium">{{ __('Avvisad i attesten.') }}</p>
            @if($approval->rejectionReason())
                <p class="mt-1">{{ __('Skäl: :reason', ['reason' => $approval->rejectionReason()]) }}</p>
            @endif
            @if($canResubmit)
                <button type="button" class="mt-3 font-medium text-accent-700 underline" wire:click="resubmit" wire:loading.attr="disabled">{{ __('Skicka till attest igen') }}</button>
            @endif
        </div>
    @elseif($approval->pending !== null || $approval->history->isEmpty())
        @if(! $approval->canDecide() && $approval->eligibility->reason === Eligibility::PREPARER)
            <p class="mt-4 text-sm text-gray-700">{{ $approval->eligibility->message() }}</p>
        @elseif(! $approval->canDecide() && $approval->eligibility->reason === Eligibility::ALREADY_DECIDED)
            <p class="mt-4 text-sm text-gray-700">{{ $approval->eligibility->message() }}</p>
        @endif
    @endif

    @php
        $decisions = $approval->history->flatMap(fn ($request) => $request->decisions);
    @endphp
    @if($decisions->isNotEmpty())
        <h3 class="mt-5 text-sm font-semibold text-gray-900">{{ __('Beslut') }}</h3>
        <ul class="mt-2 divide-y divide-gray-100 text-sm">
            @foreach($decisions as $decision)
                <li wire:key="decision-{{ $decision->id }}" class="py-2">
                    <span class="tabular-nums text-gray-600">{{ $decision->decided_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                    {{ __(':name :decision steg :step', ['name' => $decision->user?->name ?? __('tidigare medlem'), 'decision' => mb_strtolower($decision->decision->label()), 'step' => $decision->step]) }}
                    @if($decision->on_behalf_of)
                        <span class="text-gray-600">{{ __('på uppdrag av :name', ['name' => $decision->onBehalfOf?->name ?? __('tidigare medlem')]) }}</span>
                    @endif
                    @if($decision->decision === ApprovalDecisionKind::Reject && $decision->reason)
                        <span class="block text-gray-600">{{ $decision->reason }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
