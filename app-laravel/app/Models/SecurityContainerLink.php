<?php

namespace App\Models;

use App\Audit\Recorder;
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

    protected static function booted(): void
    {
        static::created(function (self $link): void {
            app(Recorder::class)->recordAdminAction('security_container_link_created', [
                'security_container_link_id' => $link->id,
                'name' => $link->name,
                'description' => $link->description,
            ]);
        });

        static::updated(function (self $link): void {
            app(Recorder::class)->recordAdminAction('security_container_link_updated', [
                'security_container_link_id' => $link->id,
                'name' => $link->name,
                'description' => $link->description,
                'changes' => $link->getChanges(),
            ]);
        });

        static::deleted(function (self $link): void {
            app(Recorder::class)->recordAdminAction('security_container_link_deleted', [
                'security_container_link_id' => $link->id,
                'name' => $link->getRawOriginal('name'),
                'description' => $link->getRawOriginal('description'),
            ]);
        });
    }

    /** @return BelongsToMany<SecurityContainer, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SecurityContainer::class, 'security_container_link_members', 'link_id', 'security_container_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** @return BelongsToMany<SecurityEvent, $this> */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(SecurityEvent::class, 'security_container_link_members', 'link_id', 'security_container_id', 'id', 'container_id');
    }

    public function addMember(SecurityContainer $container, int $sortOrder): void
    {
        $this->members()->attach($container->id, ['sort_order' => $sortOrder]);

        app(Recorder::class)->recordAdminAction('security_container_link_member_added', [
            'security_container_link_id' => $this->id,
            'security_container_id' => $container->id,
            'sort_order' => $sortOrder,
        ]);
    }

    public function removeMember(SecurityContainer $container): void
    {
        $this->members()->detach($container->id);

        app(Recorder::class)->recordAdminAction('security_container_link_member_removed', [
            'security_container_link_id' => $this->id,
            'security_container_id' => $container->id,
        ]);
    }

    public function reorderMember(SecurityContainer $container, int $sortOrder): void
    {
        $this->members()->updateExistingPivot($container->id, ['sort_order' => $sortOrder]);

        app(Recorder::class)->recordAdminAction('security_container_link_member_reordered', [
            'security_container_link_id' => $this->id,
            'security_container_id' => $container->id,
            'sort_order' => $sortOrder,
        ]);
    }
}
