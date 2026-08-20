<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        // Federated (Entra-provisioned) users have no password hash: there is
        // nothing to change and no current password to confirm — password
        // management belongs to the IdP. Users with a password (local and
        // linked accounts) keep the stock page.
        if ($this->getUser()->getAttributeValue('password') === null) {
            return $schema->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
            ]);
        }

        return parent::form($schema);
    }
}
