<?php

namespace App\Users;

use App\Audit\Recorder;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class UserAdminService
{
    public function __construct(private readonly Recorder $recorder) {}

    /** @param array{name: string, email: string, password: string, roles?: array<int, string>} $data */
    public function create(array $data, User $actor): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $roles = $this->normalizedRoles($data['roles'] ?? []);

        if ($roles === []) {
            $roles = ['Reader'];
        }

        $user->syncRoles($roles);

        $this->recorder->recordAdminAction('user.created', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $this->recorder->recordAdminAction('user.roles_changed', [
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
            'previous_roles' => [],
            'new_roles' => $roles,
        ]);

        return $user->fresh(['roles']) ?? $user;
    }

    /** @param array{name: string, email: string, roles?: array<int, string>, is_disabled?: bool} $data */
    public function update(User $user, array $data, User $actor): User
    {
        $changes = Arr::only($data, ['name', 'email']);
        $dirtyPayload = [];

        foreach ($changes as $key => $value) {
            if ($user->{$key} !== $value) {
                $dirtyPayload[$key] = ['before' => $user->{$key}, 'after' => $value];
            }
        }

        if ($dirtyPayload !== []) {
            $user->fill($changes);
            $user->save();

            $this->recorder->recordAdminAction('user.updated', [
                'user_id' => $user->id,
                'changes' => $dirtyPayload,
            ]);
        }

        if (array_key_exists('roles', $data)) {
            $roles = $this->normalizedRoles($data['roles']);
            $previousRoles = $user->roles->pluck('name')->values()->all();

            if ($previousRoles !== $roles) {
                $user->syncRoles($roles);

                $this->recorder->recordAdminAction('user.roles_changed', [
                    'user_id' => $user->id,
                    'actor_user_id' => $actor->id,
                    'previous_roles' => $previousRoles,
                    'new_roles' => $roles,
                ]);
            }
        }

        if (($data['is_disabled'] ?? false) && ! $user->is_disabled) {
            $this->disable($user, $actor);
        }

        if (($data['is_disabled'] ?? false) === false && $user->is_disabled) {
            $this->enable($user, $actor);
        }

        return $user->fresh(['roles']) ?? $user;
    }

    public function disable(User $user, User $actor): void
    {
        if ($user->is_disabled) {
            return;
        }

        $user->forceFill([
            'is_disabled' => true,
            'disabled_at' => now(),
        ])->save();

        $this->deleteSessions($user);

        $this->recorder->recordAdminAction('user.disabled', [
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function enable(User $user, User $actor): void
    {
        if (! $user->is_disabled) {
            return;
        }

        $user->forceFill([
            'is_disabled' => false,
            'disabled_at' => null,
        ])->save();

        $this->recorder->recordAdminAction('user.enabled', [
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function resetTwoFactor(User $user, User $actor): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->recorder->recordAdminAction('user.two_factor_reset', [
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function sendPasswordResetLink(User $user, User $actor): void
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new RuntimeException(__($status));
        }

        $this->recorder->recordAdminAction('user.password_reset_link_sent', [
            'user_id' => $user->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function bootstrapAdmin(string $name, string $email, string $password): User
    {
        if (User::query()->exists()) {
            throw new RuntimeException('Bootstrap admin can only be created when no users exist.');
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        $user->syncRoles(['Admin']);

        $this->recorder->recordAdminAction('user.bootstrap_admin', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return $user;
    }

    /** @param array<int, string> $roles
     * @return array<int, string>
     */
    private function normalizedRoles(array $roles): array
    {
        $requested = array_values(array_unique(array_filter(array_map(
            fn (mixed $role): string => trim((string) $role),
            $roles,
        ))));

        if ($requested === []) {
            return [];
        }

        return Role::query()
            ->whereIn('name', $requested)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }

    private function deleteSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
