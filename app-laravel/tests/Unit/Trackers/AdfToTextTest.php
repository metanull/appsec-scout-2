<?php

use App\Trackers\Jira\AdfToText;

it('returns empty string for empty doc', function () {
    $adf = ['version' => 1, 'type' => 'doc', 'content' => []];

    expect(AdfToText::toText($adf))->toBe('');
});

it('extracts plain text from paragraph', function () {
    $adf = [
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello world'],
                ],
            ],
        ],
    ];

    expect(AdfToText::toText($adf))->toBe('Hello world');
});

it('extracts URLs from link marks', function () {
    $adf = [
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'See this alert',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com/alerts/42']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $urls = AdfToText::extractUrls($adf);

    expect($urls)->toContain('https://example.com/alerts/42');
});

it('extracts URLs from inlineCard nodes', function () {
    $adf = [
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'inlineCard', 'attrs' => ['url' => 'https://example.com/issues/99']],
                ],
            ],
        ],
    ];

    $urls = AdfToText::extractUrls($adf);

    expect($urls)->toContain('https://example.com/issues/99');
});

it('deduplicates extracted urls', function () {
    $adf = [
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'link1',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://same.com/path']]],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'link2',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://same.com/path']]],
                    ],
                ],
            ],
        ],
    ];

    expect(AdfToText::extractUrls($adf))->toHaveCount(1);
});

it('ignores mentions', function () {
    $adf = [
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'CC: '],
                    ['type' => 'mention', 'attrs' => ['id' => 'acct-1', 'text' => '@user']],
                ],
            ],
        ],
    ];

    expect(AdfToText::toText($adf))->toBe('CC:');
});

it('extracts urls from plain text with inline href', function () {
    $text = 'See https://example.com/alert/5 and also https://other.com/path?q=1.';

    $urls = AdfToText::urlsFromText($text);

    expect($urls)->toContain('https://example.com/alert/5')
        ->and($urls)->toContain('https://other.com/path?q=1');
});

it('strips trailing punctuation from urls in plain text', function () {
    $text = 'Found at https://example.com/path.';

    $urls = AdfToText::urlsFromText($text);

    expect($urls)->toContain('https://example.com/path')
        ->and(collect($urls))->not->toContain('https://example.com/path.');
});

it('returns empty list when no urls present', function () {
    expect(AdfToText::urlsFromText('No URLs here.'))->toBe([]);
});
