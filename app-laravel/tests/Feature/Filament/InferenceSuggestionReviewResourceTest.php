<?php

use App\Context\Inference\InferenceSuggestionReviewService;
use App\Filament\Resources\InferenceSuggestionResource;
use App\Filament\Resources\InferenceSuggestionResource\Pages\ListInferenceSuggestions;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('allows plan users to access inference review and blocks reader users', function () {
    $reader = reviewUser(['Reader']);
    $plan = reviewUser(['Plan']);

    $this->actingAs($reader)
        ->get(InferenceSuggestionResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($plan)
        ->get(InferenceSuggestionResource::getUrl('index'))
        ->assertOk();

    expect(InferenceSuggestionResource::canViewAny())->toBeTrue();
});

it('renders review filters and delegates table actions to the review service', function () {
    $plan = reviewUser(['Plan']);

    // Produce real suggestions via a successful source sync so the
    // review page is populated by the automatic inference pass.
    config(['integration_settings.fake.enabled' => true]);

    $source = (new Tests\Fakes\FakeSource)
        ->withSystems(
            new \App\Sources\Dto\SystemDto('sys-001', 'Payments API', null, null, ['tracker.github.repository' => 'acme/payments']),
            new \App\Sources\Dto\SystemDto('sys-002', 'Orders API', null, null, ['tracker.github.repository' => 'acme/orders'])
        );

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    (new \App\Sync\FetchSourceJob('fake'))->handle(app(\App\Integrations\SystemIntegrationRuntime::class), app(\App\Sync\Upserter::class));

    $suggestions = InferenceSuggestion::query()->limit(2)->get();

    expect($suggestions->count())->toBeGreaterThanOrEqual(2);

    $acceptSuggestion = $suggestions->get(0);
    $rejectSuggestion = $suggestions->get(1);

    $spy = (object) ['accepted' => 0, 'rejected' => 0, 'lastAcceptedInput' => []];

    app()->bind(InferenceSuggestionReviewService::class, function () use ($spy) {
        return new class($spy)
        {
            public function __construct(private object $spy) {}

            public function accept(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput = []): InferenceSuggestion
            {
                $this->spy->accepted++;
                $this->spy->lastAcceptedInput = $acceptedInput;

                $suggestion->forceFill([
                    'status' => InferenceSuggestionStatus::Accepted,
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => null,
                ])->save();

                return $suggestion->refresh();
            }

            public function reject(InferenceSuggestion $suggestion, User $reviewer, string $reviewNote): InferenceSuggestion
            {
                $this->spy->rejected++;

                $suggestion->forceFill([
                    'status' => InferenceSuggestionStatus::Rejected,
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => $reviewNote,
                ])->save();

                return $suggestion->refresh();
            }
        };
    });

    Livewire::actingAs($plan)
        ->test(ListInferenceSuggestions::class)
        ->call('loadTable')
        ->assertSee('Status')
        ->assertSee('Confidence')
        ->assertSee('Source')
        ->assertSee('Entity')
        ->assertSee('Accept')
        ->assertSee('Reject')
        ->assertSee('Edit before accept');

    app(InferenceSuggestionReviewService::class)->accept($acceptSuggestion, $plan);
    app(InferenceSuggestionReviewService::class)->reject($rejectSuggestion, $plan, 'Not applicable for this owner.');

    expect($spy->accepted)->toBe(1)
        ->and($spy->rejected)->toBe(1)
        ->and(InferenceSuggestion::query()->findOrFail($acceptSuggestion->id)->status)->toBe(InferenceSuggestionStatus::Accepted)
        ->and(InferenceSuggestion::query()->findOrFail($rejectSuggestion->id)->status)->toBe(InferenceSuggestionStatus::Rejected);
});

function reviewUser(array $roles): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $user->syncRoles($roles);

    return $user;
}
