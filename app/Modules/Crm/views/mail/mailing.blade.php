{{-- A mailing (ADR-048): the company's colour and name, the body, and the sender's identity and unsubscribe link in every message. No images and nothing loaded from elsewhere. --}}
@php($companyName = $company->legal_name ?: $company->name)
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        .crm-mailing-body a { color: {{ $brand->color }}; }
        .crm-mailing-body img { max-width: 100%; height: auto; }
        @media (max-width: 620px) { .crm-mailing-card { padding: 20px 16px !important; } }
    </style>
</head>
<body style="margin: 0; padding: 0; background: #f4f4f2; font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f4f4f2;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px;">
                    <tr>
                        <td style="border-top: 6px solid {{ $brand->color }}; background: #ffffff; padding: 20px 32px 0; font-size: 14px; font-weight: 700; color: {{ $brand->accent }};">{{ $companyName }}</td>
                    </tr>
                    <tr>
                        <td class="crm-mailing-card crm-mailing-body" style="background: #ffffff; padding: 16px 32px 32px; font-size: 16px; line-height: 1.6;">{!! $body !!}</td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; font-size: 12px; line-height: 1.6; color: #52606d;">
                            @foreach($footer as $line)
                                {{ $line }}<br>
                            @endforeach
                            @if($unsubscribeUrl)
                                <br>{{ __('Du får det här utskicket från :company.', ['company' => $companyName]) }} <a href="{{ $unsubscribeUrl }}" style="color: #1f2933; text-decoration: underline;">{{ __('Avregistrera dig från utskicken') }}</a>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
