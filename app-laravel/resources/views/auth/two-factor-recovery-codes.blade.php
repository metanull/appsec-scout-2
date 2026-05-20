<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} &mdash; Recovery codes</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border-radius: .5rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; width: 100%; max-width: 26rem; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .5rem; }
        p { font-size: .875rem; color: #6b7280; margin: 0 0 1rem; }
        .codes { font-family: monospace; font-size: .875rem; background: #f3f4f6; padding: 1rem; border-radius: .375rem; margin-bottom: 1rem; list-style: none; padding: .75rem 1rem; }
        .codes li { padding: .2rem 0; }
        .warning { background: #fef3c7; border: 1px solid #f59e0b; border-radius: .375rem; padding: .75rem 1rem; font-size: .875rem; color: #92400e; margin-bottom: 1rem; }
        a { display: block; width: 100%; padding: .625rem; background: #6366f1; color: #fff; border: none; border-radius: .375rem; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
        a:hover { background: #4f46e5; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Save your recovery codes</h1>

        <div class="warning">
            Store these codes in a safe place. Each code can only be used once.
            If you lose access to your authenticator app, you can use a recovery code to sign in.
        </div>

        <ul class="codes">
            @foreach ($recoveryCodes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>

        <a href="{{ url('/') }}">I have saved my codes &mdash; Continue</a>
    </div>
</body>
</html>
