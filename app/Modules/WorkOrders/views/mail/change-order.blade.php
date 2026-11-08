<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $changeOrder->label() }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ $changeOrder->label() }}: {{ $changeOrder->title }}</h1>
        <p>{{ __('Här är din kopia av ändrings- eller tilläggsarbetet för arbetsorder :number. Status: :status.', ['number' => $changeOrder->workOrder?->number, 'status' => mb_strtolower($changeOrder->status->label())]) }}</p>
        @if($changeOrder->status === App\Modules\WorkOrders\Domain\ChangeOrderStatus::PerformedWithoutAnswer)
            <p>{{ __('Vi försökte nå dig innan arbetet utfördes men fick inget besked. Skälet och våra försök att nå dig står i dokumentet.') }}</p>
        @endif
        <p>{{ __('Hör av dig om något inte stämmer.') }}</p>
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ __('Mejlet skickades av :company genom NONOX. Svar går till :company.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
