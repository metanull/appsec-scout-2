<?php

use App\Credentials\Vault;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\CodesearchRunResult;
use App\Triage\CodesearchService;
use App\Triage\RunCodesearchJob;

it('run codesearch job uses system azdo pat and invokes the shared service', function () {
    $event = SecurityEvent::factory()->create();
    $user = User::factory()->create();

    app(Vault::class)->set('azdo.pat', null, 'system-pat');
    app(Vault::class)->set('azdo.organization', null, 'testorg');

    app()->bind(CodesearchService::class, fn () => new class extends CodesearchService
    {
        public function __construct() {}

        public function run(string $pat, string $searchText, ?string $scope = null, ?int $attachToEventId = null, ?int $createdByUserId = null): CodesearchRunResult
        {
            EventAttachment::query()->create([
                'event_id' => $attachToEventId,
                'kind' => 'codesearch-json',
                'mime' => 'application/json',
                'name' => 'job-codesearch.json',
                'payload' => json_encode(['pat' => $pat], JSON_THROW_ON_ERROR),
                'size_bytes' => 18,
                'created_at' => now(),
                'created_by_user_id' => $createdByUserId,
            ]);

            return new CodesearchRunResult(['count' => 1, 'results' => []]);
        }
    });

    (new RunCodesearchJob($event->id, 'openssl', 'project:SecurityProject', $user->id))
        ->handle(app(CodesearchService::class), app(Vault::class));

    $attachment = EventAttachment::query()->where('kind', 'codesearch-json')->first();

    expect($attachment)->not()->toBeNull()
        ->and($attachment?->payload)->toContain('system-pat');
});
