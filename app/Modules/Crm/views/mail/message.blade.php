<!doctype html>
<html lang="sv">
<body style="margin: 0; padding: 24px 16px; background: #ffffff; font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #1f2933; line-height: 1.55;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div style="font-size: 15px;">{!! nl2br(e($text)) !!}</div>
        <p style="margin: 32px 0 0; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">{{ __(':sender på :company skickade det här mejlet. Ett svar går till :email. Vill du inte få fler erbjudanden från oss, svara på mejlet så slutar vi.', ['sender' => $replyToName, 'company' => $companyName, 'email' => $replyToEmail]) }}</p>
    </div>
</body>
</html>
