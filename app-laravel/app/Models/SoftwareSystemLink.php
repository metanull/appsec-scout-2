<?php

namespace App\Models;

use Database\Factories\SoftwareSystemLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class SoftwareSystemLink extends Model
{
    /** @use HasFactory<SoftwareSystemLinkFactory> */
    use HasFactory;

    /** @return BelongsToMany<SoftwareSystem, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareSystem::class, 'software_system_link_members', 'link_id', 'software_system_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
