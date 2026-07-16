<?php

return [
    'azdo' => [
        'enabled' => (bool) env('SOURCE_AZDO_ENABLED', false),
        'interval_minutes' => (int) env('SOURCE_AZDO_INTERVAL_MINUTES', 30),
    ],
    'asoc' => [
        'enabled' => (bool) env('SOURCE_ASOC_ENABLED', false),
        'interval_minutes' => (int) env('SOURCE_ASOC_INTERVAL_MINUTES', 30),
    ],
    'detectify' => [
        'enabled' => (bool) env('SOURCE_DETECTIFY_ENABLED', false),
        'interval_minutes' => (int) env('SOURCE_DETECTIFY_INTERVAL_MINUTES', 30),
    ],
    'jira' => [
        'enabled' => (bool) env('TRACKER_JIRA_ENABLED', false),
        'interval_minutes' => (int) env('TRACKER_JIRA_INTERVAL_MINUTES', 30),
        'reconciliation_labels' => [
            'security',
            'vulnerability',
            'appsec-scout',
        ],
    ],
    'github' => [
        'enabled' => (bool) env('TRACKER_GITHUB_ENABLED', false),
        'interval_minutes' => (int) env('TRACKER_GITHUB_INTERVAL_MINUTES', 30),
    ],
    'azdo-repos' => [
        'enabled' => (bool) env('SOURCE_CONTROL_AZDO_ENABLED', false),
        'interval_minutes' => (int) env('SOURCE_CONTROL_AZDO_INTERVAL_MINUTES', 30),
    ],
    'github-repos' => [
        'enabled' => (bool) env('SOURCE_CONTROL_GITHUB_ENABLED', false),
        'interval_minutes' => (int) env('SOURCE_CONTROL_GITHUB_INTERVAL_MINUTES', 30),
    ],
];
