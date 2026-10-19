<?php

return [
    'jira' => [
        'reconciliation_labels' => [
            'security',
            'vulnerability',
            'appsec-scout',
        ],
    ],
    'github' => [
        // The Issues API matches every given label at once, so each label is listed separately.
        'reconciliation_labels' => [
            'security',
        ],
    ],
];
