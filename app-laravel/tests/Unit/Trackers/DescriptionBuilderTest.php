<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Trackers\DescriptionBuilder;
use Illuminate\Support\Carbon;

it('builds the expected single-event markdown snapshot', function () {
    $builder = new DescriptionBuilder;

    expect($builder->buildSingle(makeEvent([
        'source_event_id' => 'secret-101',
        'title' => 'Hardcoded production PAT',
        'description' => 'The repository contains a committed personal access token.',
        'severity' => EventSeverity::Critical,
        'state' => EventState::Open,
        'type' => EventType::Secret,
        'rule_id' => 'GHAS-SECRET-1',
        'url' => 'https://example.test/alerts/secret-101',
        'remediation' => 'Rotate the token and move it into the credential vault.',
        'file_path' => 'config/secrets.php',
        'start_line' => 18,
        'first_seen_at' => Carbon::parse('2026-05-01 08:00:00'),
        'last_seen_at' => Carbon::parse('2026-05-20 09:30:00'),
    ])))->toBe(trackerFixtureText('DescriptionBuilder/single.md'));
});

it('builds the expected grouped markdown snapshot', function () {
    $builder = new DescriptionBuilder;

    $events = [
        makeEvent([
            'source_event_id' => 'secret-101',
            'title' => 'Hardcoded production PAT',
            'description' => 'The repository contains a committed personal access token.',
            'severity' => EventSeverity::Critical,
            'type' => EventType::Secret,
            'url' => 'https://example.test/alerts/secret-101',
            'remediation' => 'Rotate the token and move it into the credential vault.',
            'file_path' => 'config/secrets.php',
            'start_line' => 18,
        ]),
        makeEvent([
            'source_event_id' => 'secret-102',
            'title' => 'Another PAT in seed data',
            'description' => 'The repository contains a committed personal access token.',
            'severity' => EventSeverity::High,
            'type' => EventType::Secret,
            'url' => 'https://example.test/alerts/secret-102',
            'remediation' => 'Rotate the token and move it into the credential vault.',
            'file_path' => 'database/seeders/DemoSeeder.php',
            'start_line' => 44,
        ]),
        makeEvent([
            'source_event_id' => 'vuln-201',
            'title' => 'SQL injection path',
            'description' => 'Unsanitized input reaches a SQL query.',
            'severity' => EventSeverity::High,
            'type' => EventType::Vulnerability,
            'url' => 'https://example.test/alerts/vuln-201',
            'remediation' => 'Use parameterized queries for every database call.',
            'file_path' => 'src/Repositories/UserRepository.php',
            'start_line' => 71,
        ]),
    ];

    expect($builder->buildGrouped($events))->toBe(trackerFixtureText('DescriptionBuilder/grouped.md'));
});

it('builds a stable grouped snapshot for ten events', function () {
    $builder = new DescriptionBuilder;

    $events = [];

    for ($index = 1; $index <= 4; $index++) {
        $events[] = makeEvent([
            'source_event_id' => sprintf('secret-%03d', $index),
            'title' => 'Hardcoded credential',
            'description' => 'A plaintext credential was committed to the repository.',
            'severity' => $index === 1 ? EventSeverity::Critical : EventSeverity::High,
            'type' => EventType::Secret,
            'url' => sprintf('https://example.test/alerts/secret-%03d', $index),
            'remediation' => 'Rotate the secret and remove it from git history.',
            'file_path' => sprintf('config/secret-%d.php', $index),
            'start_line' => 10 + $index,
        ]);
    }

    for ($index = 1; $index <= 3; $index++) {
        $events[] = makeEvent([
            'source_event_id' => sprintf('vuln-%03d', $index),
            'title' => 'SQL injection path',
            'description' => 'Unsanitized input reaches a database call.',
            'severity' => EventSeverity::High,
            'type' => EventType::Vulnerability,
            'url' => sprintf('https://example.test/alerts/vuln-%03d', $index),
            'remediation' => 'Use prepared statements and validated identifiers.',
            'file_path' => sprintf('src/Query/Builder%d.php', $index),
            'start_line' => 40 + $index,
        ]);
    }

    for ($index = 1; $index <= 3; $index++) {
        $events[] = makeEvent([
            'source_event_id' => sprintf('dep-%03d', $index),
            'title' => 'Vulnerable dependency',
            'description' => 'The package version is affected by a published CVE.',
            'severity' => EventSeverity::Medium,
            'type' => EventType::Dependency,
            'url' => sprintf('https://example.test/alerts/dep-%03d', $index),
            'remediation' => 'Upgrade the affected package to a patched release.',
            'file_path' => sprintf('package-lock-%d.json', $index),
            'start_line' => 5 + $index,
        ]);
    }

    expect($builder->buildGrouped($events))->toBe(trackerFixtureText('DescriptionBuilder/grouped-10.md'));
});

it('keeps grouped descriptions within sixteen kilobytes', function () {
    $builder = new DescriptionBuilder;
    $events = [];

    for ($index = 1; $index <= 50; $index++) {
        $events[] = makeEvent([
            'source_event_id' => sprintf('secret-%03d', $index),
            'title' => 'Hardcoded credential',
            'description' => str_repeat('This finding contains a long description. ', 40),
            'severity' => EventSeverity::High,
            'type' => EventType::Secret,
            'url' => sprintf('https://example.test/alerts/%03d', $index),
            'remediation' => str_repeat('Rotate the secret immediately. ', 40),
            'file_path' => sprintf('config/secret-%d.php', $index),
            'start_line' => $index,
        ]);
    }

    $markdown = $builder->buildGrouped($events);

    expect(strlen($markdown))->toBeLessThanOrEqual(16384);
});

it('includes an Alert section with view-alert link in single descriptions', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent([
        'url' => 'https://example.test/alerts/42',
    ]));

    expect($markdown)
        ->toContain('### Alert')
        ->toContain('[View alert](https://example.test/alerts/42)');
});

it('omits Alert section when event has no url', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent(['url' => null]));

    expect($markdown)->not->toContain('### Alert');
});

it('includes CVE reference link in single description when metadata has cve', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent([
        'metadata' => ['cve' => 'CVE-2023-12345'],
    ]));

    expect($markdown)
        ->toContain('### References')
        ->toContain('[CVE: CVE-2023-12345](https://nvd.nist.gov/vuln/detail/CVE-2023-12345)');
});

it('includes CWE reference link when metadata has cwe', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent([
        'metadata' => ['cwe' => 'CWE-79'],
    ]));

    expect($markdown)
        ->toContain('### References')
        ->toContain('[CWE: CWE-79](https://cwe.mitre.org/data/definitions/79.html)');
});

it('includes remediation references from ruleHelpUri metadata', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent([
        'metadata' => ['ruleHelpUri' => 'https://docs.example.test/rules/GHAS-001'],
    ]));

    expect($markdown)
        ->toContain('### Remediation References')
        ->toContain('[Rule documentation](https://docs.example.test/rules/GHAS-001)');
});

it('appends source code link to occurrence when version_control_url is set', function () {
    $builder = new DescriptionBuilder;

    $markdown = $builder->buildSingle(makeEvent([
        'url' => 'https://example.test/alerts/5',
        'version_control_url' => 'https://github.test/org/repo/blob/main/src/Foo.php#L10',
        'file_path' => 'src/Foo.php',
        'start_line' => 10,
    ]));

    expect($markdown)
        ->toContain('([alert](https://example.test/alerts/5))')
        ->toContain('([source](https://github.test/org/repo/blob/main/src/Foo.php#L10))');
});

it('shares remediation reference links across a grouped type section', function () {
    $builder = new DescriptionBuilder;

    $events = [
        makeEvent([
            'type' => EventType::Vulnerability,
            'metadata' => ['ruleHelpUri' => 'https://docs.example.test/rules/SQL-1'],
        ]),
        makeEvent([
            'type' => EventType::Vulnerability,
            'metadata' => [],
        ]),
    ];

    $markdown = $builder->buildGrouped($events);

    // Rule doc link appears once from the first event that has it
    expect(substr_count($markdown, '[Rule documentation](https://docs.example.test/rules/SQL-1)'))->toBe(1);
});
