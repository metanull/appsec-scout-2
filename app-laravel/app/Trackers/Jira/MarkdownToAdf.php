<?php

namespace App\Trackers\Jira;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;

final class MarkdownToAdf
{
    private const MAX_DESCRIPTION_BYTES = 16384;

    private const TRUNCATION_MARKER = '...(truncated)';

    /**
     * @return array{version: int, type: string, content: list<array<string, mixed>>}
     */
    public static function convert(string $markdown): array
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $parser = new MarkdownParser($environment);
        $document = $parser->parse(self::truncateInput($markdown));
        $content = self::convertBlocks($document);

        if ($content === []) {
            $content[] = ['type' => 'paragraph', 'content' => []];
        }

        return [
            'version' => 1,
            'type' => 'doc',
            'content' => $content,
        ];
    }

    private static function truncateInput(string $markdown): string
    {
        if (strlen($markdown) <= self::MAX_DESCRIPTION_BYTES) {
            return $markdown;
        }

        $characters = mb_strlen($markdown);
        $best = self::TRUNCATION_MARKER;
        $low = 0;
        $high = $characters;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = mb_substr($markdown, 0, $mid) . self::TRUNCATION_MARKER;

            if (strlen($candidate) <= self::MAX_DESCRIPTION_BYTES) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertBlocks(Node $node): array
    {
        $content = [];

        foreach ($node->children() as $child) {
            $converted = self::convertBlock($child);

            if ($converted === null) {
                continue;
            }

            if (array_is_list($converted)) {
                array_push($content, ...$converted);

                continue;
            }

            $content[] = $converted;
        }

        return $content;
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private static function convertBlock(Node $node): ?array
    {
        return match (true) {
            $node instanceof Heading => [
                'type' => 'heading',
                'attrs' => ['level' => max(1, min(6, $node->getLevel()))],
                'content' => self::convertInlineChildren($node),
            ],
            $node instanceof Paragraph => [
                'type' => 'paragraph',
                'content' => self::convertInlineChildren($node),
            ],
            $node instanceof ListBlock => [
                'type' => $node->getListData()->type === ListBlock::TYPE_ORDERED ? 'orderedList' : 'bulletList',
                'content' => array_values(array_filter(array_map(
                    fn (Node $child): ?array => $child instanceof ListItem ? [
                        'type' => 'listItem',
                        'content' => self::convertBlocks($child),
                    ] : null,
                    iterator_to_array($node->children(), false),
                ))),
            ],
            $node instanceof BlockQuote => [
                'type' => 'blockquote',
                'content' => self::convertBlocks($node),
            ],
            $node instanceof FencedCode => [
                'type' => 'codeBlock',
                'attrs' => ['language' => $node->getInfoWords()[0] ?? 'text'],
                'content' => [[
                    'type' => 'text',
                    'text' => $node->getLiteral(),
                ]],
            ],
            $node instanceof ThematicBreak => ['type' => 'rule'],
            $node instanceof Table => self::convertTable($node),
            $node instanceof Document => self::convertBlocks($node),
            $node instanceof TableSection, $node instanceof TableRow, $node instanceof TableCell => self::fallbackParagraph($node),
            default => self::fallbackParagraph($node),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertTable(Table $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }

                $cells = [];

                foreach ($row->children() as $cell) {
                    if (! $cell instanceof TableCell) {
                        continue;
                    }

                    $cells[] = [
                        'type' => $cell->getType() === TableCell::TYPE_HEADER ? 'tableHeader' : 'tableCell',
                        'attrs' => [],
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => self::convertInlineChildren($cell),
                        ]],
                    ];
                }

                $rows[] = [
                    'type' => 'tableRow',
                    'content' => $cells,
                ];
            }
        }

        return [
            'type' => 'table',
            'attrs' => ['isNumberColumnEnabled' => false, 'layout' => 'default'],
            'content' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function convertInlineChildren(Node $node, array $marks = []): array
    {
        $content = [];

        foreach ($node->children() as $child) {
            array_push($content, ...self::convertInline($child, $marks));
        }

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function convertInline(Node $node, array $marks = []): array
    {
        return match (true) {
            $node instanceof Text => self::textNodes($node->getLiteral(), $marks),
            $node instanceof Strong => self::convertInlineChildren($node, [...$marks, ['type' => 'strong']]),
            $node instanceof Emphasis => self::convertInlineChildren($node, [...$marks, ['type' => 'em']]),
            $node instanceof Code => self::textNodes($node->getLiteral(), [...$marks, ['type' => 'code']]),
            $node instanceof Link => self::convertInlineChildren($node, [...$marks, ['type' => 'link', 'attrs' => ['href' => $node->getUrl()]]]),
            $node instanceof Newline => [['type' => 'hardBreak']],
            $node instanceof HtmlInline => self::textNodes($node->getLiteral(), $marks),
            default => self::textNodes(self::plainText($node), $marks),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private static function textNodes(string $text, array $marks = []): array
    {
        if ($text === '') {
            return [];
        }

        $parts = preg_split("/(\r\n|\r|\n)/", $text) ?: [$text];
        $nodes = [];

        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $node = ['type' => 'text', 'text' => $part];

                if ($marks !== []) {
                    $node['marks'] = $marks;
                }

                $nodes[] = $node;
            }

            if ($index < count($parts) - 1) {
                $nodes[] = ['type' => 'hardBreak'];
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fallbackParagraph(Node $node): ?array
    {
        $text = self::plainText($node);

        if ($text === '') {
            return null;
        }

        return [
            'type' => 'paragraph',
            'content' => self::textNodes($text),
        ];
    }

    private static function plainText(Node $node): string
    {
        if ($node instanceof StringContainerInterface) {
            return $node->getLiteral();
        }

        $text = '';

        foreach ($node->children() as $child) {
            $text .= self::plainText($child);
        }

        return $text;
    }
}
