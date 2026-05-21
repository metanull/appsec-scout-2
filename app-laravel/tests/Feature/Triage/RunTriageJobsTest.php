<?php

use App\Credentials\Vault;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\BfgRunResult;
use App\Triage\BfgService;
use App\Triage\CodesearchRunResult;
use App\Triage\CodesearchService;
use App\Triage\RunBfgJob;
use App\Triage\RunCodesearchJob;
use App\Triage\RunTrivyJob;
use App\Triage\TrivyRunResult;
use App\Triage\TrivyService;
use Illuminate\Support\Facades\File;

it('run trivy job invokes the shared service', function () {
    $event = SecurityEvent::factory()->create();
    $user = User::factory()->create();

    app()->bind(TrivyService::class, fn () => new class extends TrivyService
    {
        public function __construct() {}

        public function run(string $gitUrl, ?int $attachToEventId = null, ?int $createdByUserId = null): TrivyRunResult
        {
            EventAttachment::query()->create([
                'event_id' => $attachToEventId,
                'kind' => 'trivy-sarif',
                'mime' => 'application/sarif+json',
                'name' => 'job.sarif',
                'payload' => '{}',
                'size_bytes' => 2,
                'created_at' => now(),
                'created_by_user_id' => $createdByUserId,
            ]);

            return new TrivyRunResult('{}', 1);
        }
    });

    (new RunTrivyJob($event->id, 'https://example.com/repo.git', $user->id))->handle(app(TrivyService::class));

    expect(EventAttachment::query()->where('kind', 'trivy-sarif')->exists())->toBeTrue();
});

it('run bfg job invokes the shared service and deletes the uploaded file', function () {
    $event = SecurityEvent::factory()->create();
    $user = User::factory()->create();
    $secretList = storage_path('app/job-secret-list.txt');
    File::ensureDirectoryExists(dirname($secretList));
    File::put($secretList, 'regex:password=***REMOVED***');

    app()->bind(BfgService::class, fn () => new class extends BfgService
    {
        public function __construct() {}

        public function run(string $gitUrl, string $secretListFile, ?int $attachToEventId = null, ?int $createdByUserId = null): BfgRunResult
        {
            EventAttachment::query()->create([
                'event_id' => $attachToEventId,
                'kind' => 'bfg-report',
                'mime' => 'text/plain',
                'name' => 'job-report.txt',
                'payload' => 'report',
                'size_bytes' => 6,
                'created_at' => now(),
                'created_by_user_id' => $createdByUserId,
            ]);

            return new BfgRunResult('report', 'bundle', 1, 2);
        }
    });

    (new RunBfgJob($event->id, 'https://example.com/repo.git', $secretList, $user->id))->handle(app(BfgService::class));

    expect(EventAttachment::query()->where('kind', 'bfg-report')->exists())->toBeTrue()
        ->and(File::exists($secretList))->toBeFalse();
});

it('run codesearch job uses the current users azdo pat and invokes the shared service', function () {
    $event = SecurityEvent::factory()->create();
    $user = User::factory()->create();

    app(Vault::class)->set('azdo.pat', $user->id, 'user-pat');
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
        ->and($attachment?->payload)->toContain('user-pat');
});
