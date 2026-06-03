<?php

namespace App\Models;

use Database\Factories\SecurityContainerLinkMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SecurityContainerLinkMember extends Pivot
{
    /** @use HasFactory<SecurityContainerLinkMemberFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'security_container_link_members';
}
