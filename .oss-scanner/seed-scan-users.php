<?php

// Seeds the OSS Scanner image's local site with one account per role, each with TOTP already enrolled, so a
// reproduction can log in at any privilege level. Run once at image build time by .oss-scanner/Dockerfile through
// `php artisan tinker`; never used by the application itself.

use App\Models\User;

$secret = getenv('SCAN_TOTP_SECRET');

foreach (['Reader', 'Triage', 'Plan', 'Sync', 'Admin'] as $role) {
    $user = User::query()->create([
        'name' => $role,
        'email' => strtolower($role).'@example.com',
        'password' => 'password',
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $user->syncRoles([$role]);
    $user->saveAppAuthenticationSecret($secret);
}
