<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Users\UserAdminService;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return app(UserAdminService::class)->create($data, $actor);
    }
}
