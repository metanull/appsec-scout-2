<?php

namespace App\Models;

use Database\Factories\SecurityContainerLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class SecurityContainerLink extends Model
{
    /** @use HasFactory<SecurityContainerLinkFactory> */
    use HasFactory;

    /** @return BelongsToMany<SecurityContainer, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SecurityContainer::class, 'security_container_link_members', 'link_id', 'security_container_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
