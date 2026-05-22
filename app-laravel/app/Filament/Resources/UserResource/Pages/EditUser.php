<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Users\UserAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);
        abort_unless($record instanceof User, 404);

        return app(UserAdminService::class)->update($record, $data, $actor);
    }
}
