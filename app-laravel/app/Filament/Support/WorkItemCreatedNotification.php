<?php

namespace App\Filament\Support;

use App\Trackers\Dto\CreatedWorkItem;
use Filament\Notifications\Notification;

/** Reports a created work item, warning when its comment could not be posted. */
final class WorkItemCreatedNotification
{
    public static function send(CreatedWorkItem $created, string $title): void
    {
        if ($created->commentError === null) {
            Notification::make()->title($title)->success()->send();

            return;
        }

        Notification::make()
            ->title($title . ', but its comment was not posted')
            ->body(sprintf('%s exists and is linked. Add the comment there by hand; see the Error log. Error: %s', $created->workItem->id, $created->commentError))
            ->warning()
            ->persistent()
            ->send();
    }
}
