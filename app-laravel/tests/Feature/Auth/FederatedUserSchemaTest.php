<?php

use App\Models\User;
use Illuminate\Database\QueryException;

it('allows creating a federated user with no password and an Entra object id', function () {
    $user = User::query()->create([
        'name' => 'Federated User',
        'email' => 'federated@example.com',
        'password' => null,
        'entra_object_id' => '11111111-1111-1111-1111-111111111111',
    ]);

    expect($user->refresh()->password)->toBeNull()
        ->and($user->entra_object_id)->toBe('11111111-1111-1111-1111-111111111111');
});

it('rejects two users sharing the same Entra object id', function () {
    User::query()->create([
        'name' => 'First',
        'email' => 'first@example.com',
        'password' => null,
        'entra_object_id' => '22222222-2222-2222-2222-222222222222',
    ]);

    expect(fn () => User::query()->create([
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => null,
        'entra_object_id' => '22222222-2222-2222-2222-222222222222',
    ]))->toThrow(QueryException::class);
});

it('rejects password login for a user without a password', function () {
    User::query()->create([
        'name' => 'Federated User',
        'email' => 'federated@example.com',
        'password' => null,
        'entra_object_id' => '33333333-3333-3333-3333-333333333333',
    ]);

    $response = $this->post('/user/login', [
        'email' => 'federated@example.com',
        'password' => 'any-password-at-all',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest();
});
