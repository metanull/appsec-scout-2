<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entra ID Federated Sign-In
    |--------------------------------------------------------------------------
    |
    | When enabled, the login page offers "Sign in with Microsoft" alongside
    | the local password form, and /auth/entra/* routes become active. Local
    | password + TOTP authentication is unaffected either way; the OAuth
    | client settings live under services.entra.
    |
    */

    'enabled' => (bool) env('ENTRA_ENABLED', false),

];
