<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} &mdash; Sign in</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border-radius: .5rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; width: 100%; max-width: 22rem; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 1.5rem; }
        label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .25rem; }
        input { display: block; width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .375rem; font-size: .875rem; margin-bottom: 1rem; }
        input:focus { outline: 2px solid #6366f1; }
        .error { color: #dc2626; font-size: .8rem; margin-top: -.75rem; margin-bottom: 1rem; }
        button { width: 100%; padding: .625rem; background: #6366f1; color: #fff; border: none; border-radius: .375rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #4f46e5; }
        .row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .row label { margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ config('app.name') }}</h1>

        <form method="POST" action="{{ url('/user/login') }}">
            @csrf

            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email') <p class="error">{{ $message }}</p> @enderror

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            @error('password') <p class="error">{{ $message }}</p> @enderror

            <div class="row">
                <label>
                    <input type="checkbox" name="remember" style="width:auto;margin:0 .25rem 0 0">
                    Remember me
                </label>
            </div>

            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
