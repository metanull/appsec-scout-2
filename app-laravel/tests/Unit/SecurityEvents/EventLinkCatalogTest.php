<?php

use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\SecurityEvents\EventLinkCatalog;

describe('EventLinkCatalog', function () {
    it('returns an empty catalog when no links are present', function () {
        $event = new SecurityEvent;
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        expect($catalog)->toBeEmpty();
    });

    it('includes the source alert URL from the url column', function () {
        $event = new SecurityEvent;
        $event->forceFill(['url' => 'https://dev.azure.com/org/project/_alerts/123']);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        $urls = array_column($catalog, 'url');
        expect($urls)->toContain('https://dev.azure.com/org/project/_alerts/123');
    });

    it('deduplicates identical URLs', function () {
        $event = new SecurityEvent;
        $event->forceFill([
            'url' => 'https://example.com/alert/1',
            'metadata' => [
                'links' => [
                    ['label' => 'Duplicate', 'url' => 'https://example.com/alert/1'],
                ],
            ],
        ]);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        $urls = array_column($catalog, 'url');
        expect(count(array_filter($urls, fn ($u) => $u === 'https://example.com/alert/1')))->toBe(1);
    });

    it('includes CVE links from metadata', function () {
        $event = new SecurityEvent;
        $event->forceFill([
            'metadata' => [
                'cve' => 'CVE-2023-12345',
            ],
        ]);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        $urls = array_column($catalog, 'url');
        expect($urls)->toContain('https://nvd.nist.gov/vuln/detail/CVE-2023-12345');
    });

    it('includes CWE links from metadata', function () {
        $event = new SecurityEvent;
        $event->forceFill([
            'metadata' => [
                'cwe' => 'CWE-79',
            ],
        ]);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        $urls = array_column($catalog, 'url');
        expect($urls)->toContain('https://cwe.mitre.org/data/definitions/79.html');
    });

    it('includes metadata links array from normalizer output', function () {
        $event = new SecurityEvent;
        $event->forceFill([
            'metadata' => [
                'links' => [
                    ['label' => 'Rule documentation', 'url' => 'https://docs.example.com/rule/1'],
                    ['label' => 'NVD', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2023-00001'],
                ],
            ],
        ]);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        $urls = array_column($catalog, 'url');
        expect($urls)->toContain('https://docs.example.com/rule/1');
        expect($urls)->toContain('https://nvd.nist.gov/vuln/detail/CVE-2023-00001');
    });

    it('includes work item URLs as tracker links', function () {
        $event = new SecurityEvent;
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);

        $link = new WorkItemLink;
        $link->tracker_id = 'jira';
        $link->work_item_id = 'PROJ-42';
        $link->work_item_title = 'Fix CVE finding';
        $link->work_item_url = 'https://jira.example.com/browse/PROJ-42';

        $event->setRelation('workItemLinks', collect([$link]));

        $catalog = app(EventLinkCatalog::class)->build($event);

        $trackerLinks = array_filter($catalog, fn ($l) => $l['kind'] === 'tracker');
        expect($trackerLinks)->not->toBeEmpty();

        $urls = array_column($catalog, 'url');
        expect($urls)->toContain('https://jira.example.com/browse/PROJ-42');
    });

    it('skips unsafe non-http URLs', function () {
        $event = new SecurityEvent;
        $event->forceFill([
            'url' => 'javascript:alert(1)',
            'metadata' => [
                'links' => [
                    ['label' => 'Unsafe', 'url' => 'file:///etc/passwd'],
                ],
            ],
        ]);
        $event->setRelation('softwareSystem', null);
        $event->setRelation('container', null);
        $event->setRelation('workItemLinks', collect());

        $catalog = app(EventLinkCatalog::class)->build($event);

        expect($catalog)->toBeEmpty();
    });

    it('assigns correct kind labels', function () {
        expect(EventLinkCatalog::kindLabel('source'))->toBe('Source');
        expect(EventLinkCatalog::kindLabel('code'))->toBe('Code');
        expect(EventLinkCatalog::kindLabel('remediation'))->toBe('Remediation');
        expect(EventLinkCatalog::kindLabel('standard'))->toBe('Standards');
        expect(EventLinkCatalog::kindLabel('tracker'))->toBe('Tracker');
        expect(EventLinkCatalog::kindLabel('unknown'))->toBe('Other');
    });
});
