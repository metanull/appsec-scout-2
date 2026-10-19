<?php

use App\Models\CuratedLink;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\SecurityEvents\EntityNavigationCatalog;
use Tests\Fakes\FakeTracker;

it('builds a system navigation catalog from all supported sources', function () {
    // Building navigation must never call a tracker: the project URL comes from the link.
    $tracker = bindFakeWorkItemTracker(new FakeTracker);

    $system = new SoftwareSystem;
    $system->forceFill([
        'url' => 'https://example.com/system',
        'metadata' => [
            'links' => [
                ['label' => 'System docs', 'url' => 'https://docs.example.com/system'],
            ],
        ],
    ]);
    $system->setRelation('curatedLinks', collect([
        CuratedLink::factory()->make([
            'label' => 'System curated',
            'kind' => 'source',
            'url' => 'https://curated.example.com/system',
        ]),
    ]));
    $system->setRelation('trackerProjectLinks', collect([
        tap(new TrackerProjectLink, fn ($l) => $l->forceFill([
            'tracker_id' => 'fake-tracker',
            'project_key' => 'APP',
            'project_name' => 'App Project',
            'metadata' => [TrackerProjectLink::METADATA_PROJECT_URL => 'https://tracker.test/projects/APP'],
        ])),
    ]));
    $system->setRelation('repositoryMappings', collect([
        tap(new RepositoryMapping, fn ($m) => $m->forceFill([
            'repository_name' => 'acme-app',
            'repository_url' => 'https://github.com/acme/acme-app',
        ])),
    ]));

    $catalog = app(EntityNavigationCatalog::class)->buildForSoftwareSystem($system);

    expect(array_column($catalog, 'url'))->toContain(
        'https://example.com/system',
        'https://docs.example.com/system',
        'https://curated.example.com/system',
        'https://tracker.test/projects/APP',
        'https://github.com/acme/acme-app',
    );

    expect($tracker->fetchProjectsCalls)->toBe(0);

    expect($catalog)->toContainEqual([
        'label' => 'System curated',
        'url' => 'https://curated.example.com/system',
        'kind' => 'source',
        'kind_label' => 'Source',
        'external' => true,
    ]);
});

it('builds a container navigation catalog from all supported sources', function () {
    // Building navigation must never call a tracker: the project URL comes from the link.
    $tracker = bindFakeWorkItemTracker(new FakeTracker);

    $container = new SecurityContainer;
    $container->forceFill([
        'url' => 'https://example.com/container',
        'metadata' => [
            'links' => [
                ['label' => 'Container docs', 'url' => 'https://docs.example.com/container'],
            ],
        ],
    ]);
    $container->setRelation('curatedLinks', collect([
        CuratedLink::factory()->make([
            'label' => 'Container curated',
            'kind' => 'remediation',
            'url' => 'https://curated.example.com/container',
        ]),
    ]));
    $container->setRelation('trackerProjectLinks', collect([
        tap(new TrackerProjectLink, fn ($l) => $l->forceFill([
            'tracker_id' => 'fake-tracker',
            'project_key' => 'OPS',
            'project_name' => 'Ops Project',
            'metadata' => [TrackerProjectLink::METADATA_PROJECT_URL => 'https://tracker.test/projects/OPS'],
        ])),
    ]));
    $container->setRelation('repositoryMappings', collect([
        tap(new RepositoryMapping, fn ($m) => $m->forceFill([
            'repository_name' => 'acme-container',
            'repository_url' => 'https://github.com/acme/acme-container',
        ])),
    ]));

    $catalog = app(EntityNavigationCatalog::class)->buildForSecurityContainer($container);

    expect(array_column($catalog, 'url'))->toContain(
        'https://example.com/container',
        'https://docs.example.com/container',
        'https://curated.example.com/container',
        'https://tracker.test/projects/OPS',
        'https://github.com/acme/acme-container',
    );
});

it('prefers curated links over broader owner links', function () {
    $system = new SoftwareSystem;
    $system->forceFill([
        'url' => 'https://example.com/shared',
    ]);
    $system->setRelation('curatedLinks', collect([
        CuratedLink::factory()->make([
            'label' => 'Specific system curated',
            'kind' => 'source',
            'url' => 'https://example.com/shared',
        ]),
    ]));
    $system->setRelation('trackerProjectLinks', collect());
    $system->setRelation('repositoryMappings', collect());

    $catalog = app(EntityNavigationCatalog::class)->buildForSoftwareSystem($system);

    expect($catalog)->toHaveCount(1)
        ->and($catalog[0]['label'])->toBe('Specific system curated');
});

it('skips unsafe navigation links', function () {
    $container = new SecurityContainer;
    $container->forceFill([
        'url' => 'javascript:alert(1)',
        'metadata' => [
            'links' => [
                ['label' => 'Unsafe', 'url' => 'file:///etc/passwd'],
            ],
        ],
    ]);
    $container->setRelation('curatedLinks', collect([
        CuratedLink::factory()->make([
            'label' => 'Unsafe curated',
            'kind' => 'source',
            'url' => 'javascript:alert(1)',
        ]),
    ]));
    $container->setRelation('trackerProjectLinks', collect());
    $container->setRelation('repositoryMappings', collect());

    $catalog = app(EntityNavigationCatalog::class)->buildForSecurityContainer($container);

    expect($catalog)->toBeEmpty();
});
