<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

it('renders the Filament login page at /login', function () {
    $this->get('/login')->assertOk();
});

it('hides the Microsoft sign-in action when Entra is disabled', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Sign in with Microsoft');
});

it('shows the Microsoft sign-in action when Entra is enabled', function () {
    config(['entra.enabled' => true]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in with Microsoft');
});

it('still authenticates a password user through the Filament login page', function () {
    $user = User::factory()->create([
        'email' => 'password-user@example.com',
        'password' => 'password',
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'password-user@example.com',
            'password' => 'password',
        ])
        ->call('authenticate');

    $this->assertAuthenticatedAs($user);
});
