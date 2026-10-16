<?php

use App\Trackers\Jira\MarkdownToAdf;

dataset('markdown-adf-cases', [
    'paragraph' => ['paragraph'],
    'heading' => ['heading'],
    'bullet-list' => ['bullet-list'],
    'ordered-list' => ['ordered-list'],
    'blockquote' => ['blockquote'],
    'code-block' => ['code-block'],
    'rule' => ['rule'],
    'table' => ['table'],
    'inline-marks' => ['inline-marks'],
    'unsupported-html' => ['unsupported-html'],
]);

it('converts supported markdown fixtures into expected adf', function (string $name) {
    $markdown = markdownFixtureText("MarkdownToAdf/{$name}.md");
    $expected = markdownFixtureJson("MarkdownToAdf/{$name}.json");

    expect(MarkdownToAdf::convert($markdown))->toEqual($expected);
})->with('markdown-adf-cases');

it('matches the grouped ten-event adf snapshot', function () {
    $expected = markdownFixtureJson('MarkdownToAdf/grouped-10.json');

    expect(MarkdownToAdf::convert(markdownFixtureText('DescriptionBuilder/grouped-10.md')))->toEqual($expected);
});

it('produces identical adf output for the same markdown twice', function () {
    $markdown = markdownFixtureText('DescriptionBuilder/grouped-10.md');

    expect(MarkdownToAdf::convert($markdown))->toEqual(MarkdownToAdf::convert($markdown));
});

it('encodes table cell attrs as json objects, never arrays', function () {
    // Regression: an empty PHP array serializes to the JSON array `[]`, which Jira
    // rejects as "not valid Atlassian Document Format (ADF) content". This only
    // surfaced on grouped work items, whose description carries a severity table.
    $json = json_encode(MarkdownToAdf::convert(markdownFixtureText('DescriptionBuilder/grouped-10.md')), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('"attrs":[]');
});

it('keeps only the code mark on inline code inside bold or italic text', function () {
    // Regression: Jira rejected a work-item comment with INVALID_INPUT because inline code
    // inside bold text carried both the strong and the code mark, which ADF does not allow.
    $paragraph = MarkdownToAdf::convertUntruncated('**Rule `CKV_TF_1` failed** and *see `main.tf`*')['content'][0]['content'];

    expect($paragraph)->toEqual([
        ['type' => 'text', 'text' => 'Rule ', 'marks' => [['type' => 'strong']]],
        ['type' => 'text', 'text' => 'CKV_TF_1', 'marks' => [['type' => 'code']]],
        ['type' => 'text', 'text' => ' failed', 'marks' => [['type' => 'strong']]],
        ['type' => 'text', 'text' => ' and '],
        ['type' => 'text', 'text' => 'see ', 'marks' => [['type' => 'em']]],
        ['type' => 'text', 'text' => 'main.tf', 'marks' => [['type' => 'code']]],
    ]);
});

it('keeps the link mark on inline code inside a bold link', function () {
    $paragraph = MarkdownToAdf::convertUntruncated('**[`docs.md`](https://example.test)**')['content'][0]['content'];

    expect($paragraph)->toEqual([
        ['type' => 'text', 'text' => 'docs.md', 'marks' => [
            ['type' => 'link', 'attrs' => ['href' => 'https://example.test']],
            ['type' => 'code'],
        ]],
    ]);
});

it('truncates markdown input at sixteen kilobytes before conversion', function () {
    $markdown = str_repeat("Paragraph text that exceeds the size cap.\n\n", 1200);
    $adf = MarkdownToAdf::convert($markdown);
    $lastNode = $adf['content'][count($adf['content']) - 1];

    expect(json_encode($adf))->not->toBeFalse()
        ->and(json_encode($adf))->toContain('...(truncated)')
        ->and($lastNode['type'])->toBe('paragraph');
});

function markdownFixtureText(string $path): string
{
    return str_replace("\r\n", "\n", trim(file_get_contents(base_path('tests/Fixtures/Trackers/' . $path))));
}

/** @return array<string, mixed> */
function markdownFixtureJson(string $path): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/Trackers/' . $path)), true, flags: JSON_THROW_ON_ERROR);
}
