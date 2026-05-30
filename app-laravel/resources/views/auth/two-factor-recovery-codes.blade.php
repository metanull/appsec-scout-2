<x-auth-layouts.auth :title="config(''app.name'') . '' — Recovery codes''" heading="Save your recovery codes">

    <div class="rounded-lg border border-warning-300 bg-warning-50 dark:bg-warning-900/10 dark:border-warning-700 px-4 py-3 text-sm text-warning-800 dark:text-warning-300">
        Store these codes in a safe place. Each code can only be used once.
        If you lose access to your authenticator app, you can use a recovery code to sign in.
    </div>

    <ul class="rounded-lg bg-gray-100 dark:bg-gray-800 px-4 py-3 space-y-1">
        @foreach ($recoveryCodes as $code)
            <li class="font-mono text-sm text-gray-800 dark:text-gray-200">{{ $code }}</li>
        @endforeach
    </ul>

    <a
        href="{{ url(''/'') }}"
        class="fi-btn block w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white text-center shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600"
    >
        I have saved my codes &mdash; Continue
    </a>

</x-auth-layouts.auth>
