<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STAFE OSS Accounting</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: #333; }
        .container { background: white; padding: 3rem; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; text-align: center; }
        h1 { color: #667eea; font-size: 2.5rem; margin-bottom: 1rem; }
        p { color: #666; font-size: 1.1rem; line-height: 1.6; margin-bottom: 1rem; }
        .badge { display: inline-block; background: #10b981; color: white; padding: 0.5rem 1rem; border-radius: 0.5rem; margin: 0.5rem; font-size: 0.9rem; font-weight: bold; }
        .status { margin-top: 2rem; padding: 1rem; background: #f0fdf4; border-left: 4px solid #10b981; text-align: left; }
        .status strong { color: #059669; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🇸🇪 STAFE OSS Accounting</h1>
        <p><strong>Open Source Svenskt Bokföringssystem</strong></p>
        <p>Ett modernt, transparent och korrekt bokföringssystem byggt för svenska små och medelstora aktiebolag.</p>
        
        <div>
            <span class="badge">TALL Stack</span>
            <span class="badge">Laravel 11</span>
            <span class="badge">Jetstream</span>
            <span class="badge">Livewire</span>
        </div>
        
        <div class="status">
            <strong>✅ Systemstatus:</strong><br>
            Laravel är installerat och redo att användas.<br>
            Nästa steg: Konfigurera databas och kör migrationer.
        </div>
    </div>
</body>
</html>
