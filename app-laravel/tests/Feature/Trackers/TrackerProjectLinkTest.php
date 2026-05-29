<?php

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Trackers\TrackerConfigRepository;
use Illuminate\Database\QueryException;

it('can attach tracker project links to a software system', function () {
    $system = SoftwareSystem::factory()->create();

    $link = $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'project_name' => 'Application',
        'is_default' => false,
    ]);

    expect($system->trackerProjectLinks()->count())->toBe(1)
        ->and($link->tracker_id)->toBe('jira')
        ->and($link->project_key)->toBe('APP');
});

it('can attach tracker project links to a security container', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);

    $link = $container->trackerProjectLinks()->create([
        'tracker_id' => 'github',
        'project_key' => 'org/repo',
        'project_name' => null,
        'is_default' => false,
    ]);

    expect($container->trackerProjectLinks()->count())->toBe(1)
        ->and($link->tracker_id)->toBe('github')
        ->and($link->project_key)->toBe('org/repo');
});

it('enforces unique tracker and project key per owner', function () {
    $system = SoftwareSystem::factory()->create();

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
    ]);

    expect(fn () => $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
    ]))->toThrow(QueryException::class);
});

it('allows the same tracker and project key on different owners', function () {
    $system1 = SoftwareSystem::factory()->create();
    $system2 = SoftwareSystem::factory()->create();

    $system1->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'APP']);
    $system2->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'APP']);

    expect(TrackerProjectLink::query()->count())->toBe(2);
});

it('deletes tracker project links when owner system is deleted', function () {
    $system = SoftwareSystem::factory()->create();
    $system->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'APP']);

    $system->delete();

    expect(TrackerProjectLink::query()->count())->toBe(0);
});

it('deletes tracker project links when owner container is deleted', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);
    $container->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'APP']);

    $container->delete();

    expect(TrackerProjectLink::query()->count())->toBe(0);
});

it('nullifies created_by_user_id when user is deleted', function () {
    $user = User::factory()->create();
    $system = SoftwareSystem::factory()->create();
    $link = $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'created_by_user_id' => $user->id,
    ]);

    $user->delete();

    expect($link->fresh()?->created_by_user_id)->toBeNull();
});

it('gets and sets the jira default project key', function () {
    $repo = app(TrackerConfigRepository::class);

    expect($repo->getJiraDefaultProjectKey())->toBeNull();

    $repo->setJiraDefaultProjectKey('MYPROJ');

    expect($repo->getJiraDefaultProjectKey())->toBe('MYPROJ');
});

it('clears the jira default project key when set to null', function () {
    $repo = app(TrackerConfigRepository::class);

    $repo->setJiraDefaultProjectKey('MYPROJ');
    $repo->setJiraDefaultProjectKey(null);

    expect($repo->getJiraDefaultProjectKey())->toBeNull();
});

it('clears the jira default project key when set to blank string', function () {
    $repo = app(TrackerConfigRepository::class);

    $repo->setJiraDefaultProjectKey('MYPROJ');
    $repo->setJiraDefaultProjectKey('   ');

    expect($repo->getJiraDefaultProjectKey())->toBeNull();
});

it('updates the jira default project key idempotently', function () {
    $repo = app(TrackerConfigRepository::class);

    $repo->setJiraDefaultProjectKey('FIRST');
    $repo->setJiraDefaultProjectKey('SECOND');

    expect($repo->getJiraDefaultProjectKey())->toBe('SECOND');

    $repo->setJiraDefaultProjectKey('SECOND');

    expect($repo->getJiraDefaultProjectKey())->toBe('SECOND');
});
