<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Users\UserAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);
        abort_unless($record instanceof User, 404);

        /** @var array{name: string, email: string, roles?: array<int, string>, is_disabled?: bool} $data */

        return app(UserAdminService::class)->update($record, $data, $actor);
    }
}
