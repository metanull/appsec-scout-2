<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class SoftwareSystemLinkMember extends Pivot
{
    public $timestamps = false;

    protected $table = 'software_system_link_members';
}
