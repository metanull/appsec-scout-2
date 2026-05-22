<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Users\UserAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): User
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        /** @var array{name: string, email: string, password: string, roles?: array<int, string>} $data */

        return app(UserAdminService::class)->create($data, $actor);
    }
}
