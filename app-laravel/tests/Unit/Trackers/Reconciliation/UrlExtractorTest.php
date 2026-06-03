<?php

use App\Trackers\Jira\AdfToText;
use App\Trackers\Reconciliation\UrlExtractor;

it('extracts no urls from empty inputs', function () {
    expect(UrlExtractor::extractFromText(''))->toBe([])
        ->and(UrlExtractor::extractFromMarkdown(''))->toBe([])
        ->and(UrlExtractor::extractFromAdf([]))->toBe([]);
});

it('extracts plain http and https urls from text', function () {
    $text = 'Open http://example.com/a and https://example.com/b for details';

    expect(UrlExtractor::extractFromText($text))
        ->toBe(['http://example.com/a', 'https://example.com/b']);
});

it('strips trailing punctuation from text urls', function () {
    $text = 'Check https://example.com/path., and https://example.com/other;';

    expect(UrlExtractor::extractFromText($text))
        ->toBe(['https://example.com/path', 'https://example.com/other']);
});

it('extracts markdown links and raw markdown urls', function () {
    $markdown = '[Alert](https://example.com/alert/1) and raw https://example.com/raw/2';

    expect(UrlExtractor::extractFromMarkdown($markdown))
        ->toBe(['https://example.com/alert/1', 'https://example.com/raw/2']);
});

it('extracts markdown link url with optional title', function () {
    $markdown = '[Doc](https://example.com/doc "optional title")';

    expect(UrlExtractor::extractFromMarkdown($markdown))
        ->toBe(['https://example.com/doc']);
});

it('extracts urls from adf link mark nodes', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'Alert link',
                'marks' => [[
                    'type' => 'link',
                    'attrs' => ['href' => 'https://example.com/finding/123'],
                ]],
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://example.com/finding/123']);
});

it('extracts urls from adf inline card nodes', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'inlineCard',
                'attrs' => ['url' => 'https://example.com/card/77'],
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://example.com/card/77']);
});

it('extracts raw urls from adf text nodes', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'See https://example.com/raw/1 in this note.',
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://example.com/raw/1']);
});

it('extracts urls from deeply nested adf content', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'panel',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Nested https://example.com/nested',
                ]],
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://example.com/nested']);
});

it('excludes non-http schemes from all extractors', function () {
    $text = 'javascript:alert(1) data:text/plain,abc ftp://example.com/file https://safe.example.com/path';
    $markdown = '[bad](javascript:alert(1)) and [ok](https://safe.example.com/path)';
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'ftp://example.com and https://safe.example.com/path',
            ], [
                'type' => 'inlineCard',
                'attrs' => ['url' => 'javascript:alert(1)'],
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromText($text))
        ->toBe(['https://safe.example.com/path'])
        ->and(UrlExtractor::extractFromMarkdown($markdown))
        ->toBe(['https://safe.example.com/path'])
        ->and(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://safe.example.com/path']);
});

it('deduplicates duplicate urls from adf extraction', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'https://example.com/dup',
                'marks' => [[
                    'type' => 'link',
                    'attrs' => ['href' => 'https://example.com/dup'],
                ]],
            ], [
                'type' => 'inlineCard',
                'attrs' => ['url' => 'https://example.com/dup'],
            ]],
        ]],
    ];

    expect(UrlExtractor::extractFromAdf($adf))
        ->toBe(['https://example.com/dup']);
});

it('keeps adf to text url extraction delegated to url extractor', function () {
    $adf = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'See https://example.com/raw',
                'marks' => [[
                    'type' => 'link',
                    'attrs' => ['href' => 'https://example.com/link'],
                ]],
            ]],
        ]],
    ];

    expect(AdfToText::extractUrls($adf))
        ->toBe(UrlExtractor::extractFromAdf($adf));
});
