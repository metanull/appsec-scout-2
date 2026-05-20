<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} &mdash; Set up two-factor authentication</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border-radius: .5rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; width: 100%; max-width: 26rem; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .5rem; }
        p { font-size: .875rem; color: #6b7280; margin: 0 0 1rem; }
        .qr { display: flex; justify-content: center; margin: 1rem 0; }
        .qr svg { width: 160px; height: 160px; }
        .secret { font-family: monospace; font-size: .75rem; background: #f3f4f6; padding: .5rem; border-radius: .375rem; word-break: break-all; margin-bottom: 1rem; }
        label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .25rem; }
        input { display: block; width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .375rem; font-size: .875rem; margin-bottom: 1rem; letter-spacing: .15em; }
        input:focus { outline: 2px solid #6366f1; }
        .error { color: #dc2626; font-size: .8rem; margin-top: -.75rem; margin-bottom: 1rem; }
        button { width: 100%; padding: .625rem; background: #6366f1; color: #fff; border: none; border-radius: .375rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #4f46e5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Set up two-factor authentication</h1>
        <p>Scan the QR code below with your authenticator app (e.g. Google Authenticator, Authy), then enter the 6-digit code to confirm.</p>

        <div class="qr">{!! $qrCodeSvg !!}</div>

        <p>Cannot scan? Enter this key manually:</p>
        <div class="secret">{{ $secretKey }}</div>

        <form method="POST" action="{{ route('two-factor.setup.confirm') }}">
            @csrf

            <label for="code">Confirmation code</label>
            <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" required autofocus autocomplete="one-time-code" placeholder="000000">
            @error('code') <p class="error">{{ $message }}</p> @enderror

            <button type="submit">Confirm and continue</button>
        </form>
    </div>
</body>
</html>
