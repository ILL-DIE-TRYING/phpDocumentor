<?php

declare(strict_types=1);

namespace phpDocumentor\Guides\Markdown;

use League\CommonMark\Environment\Environment as CommonMarkEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\NodeWalker;
use League\CommonMark\Parser\MarkdownParser;
use phpDocumentor\Guides\Markdown\Parsers\AbstractBlock;
use phpDocumentor\Guides\MarkupLanguageParser as ParserInterface;
use phpDocumentor\Guides\Nodes\AnchorNode;
use phpDocumentor\Guides\Nodes\CodeNode;
use phpDocumentor\Guides\Nodes\DocumentNode;
use phpDocumentor\Guides\Nodes\ListNode;
use phpDocumentor\Guides\Nodes\ParagraphNode;
use phpDocumentor\Guides\Nodes\RawNode;
use phpDocumentor\Guides\Nodes\SpanNode;
use phpDocumentor\Guides\Nodes\TitleNode;
use phpDocumentor\Guides\ParserContext;
use RuntimeException;

use function get_class;
use function md5;
use function strtolower;

final class MarkupLanguageParser implements ParserInterface
{
    /** @var MarkdownParser */
    private $markdownParser;

    /** @var ParserContext|null */
    private $environment;

    /** @var array<AbstractBlock> */
    private $parsers;

    /** @var DocumentNode */
    private $document;

    public function __construct()
    {
        $cmEnvironment = new CommonMarkEnvironment(['html_input' => 'strip']);
        $cmEnvironment->addExtension(new CommonMarkCoreExtension());
        $this->markdownParser = new MarkdownParser($cmEnvironment);
        $this->parsers = [
            new Parsers\Paragraph(),
            new Parsers\ListBlock(),
            new Parsers\ThematicBreak(),
        ];
    }

    public function supports(string $inputFormat): bool
    {
        return strtolower($inputFormat) === 'md';
    }

    public function parse(ParserContext $environment, string $contents): DocumentNode
    {
        $this->environment = $environment;
        $environment->reset();

        $ast = $this->markdownParser->parse($contents);

        return $this->parseDocument($ast->walker(), md5($contents));
    }

    public function parseDocument(NodeWalker $walker, string $hash): DocumentNode
    {
        $document = new DocumentNode($hash);
        $this->document = $document;

        while ($event = $walker->next()) {
            $node = $event->getNode();

            /** @var \phpDocumentor\Guides\Markdown\ParserInterface $parser */
            foreach ($this->parsers as $parser) {
                if (!$parser->supports($event)) {
                    continue;
                }

                $document->addNode($parser->parse($this, $walker));
            }

            // ignore all Entering events; these are only used to switch to another context and context switching
            // is defined above
            if ($event->isEntering()) {
                continue;
            }

            if (!$event->isEntering() && $node instanceof Document) {
                return $document;
            }

            if ($node instanceof Heading) {
                $content = $node->firstChild();
                if ($content instanceof Text === false) {
                    continue;
                }

                $title = new TitleNode(
                    new SpanNode($content->getLiteral(), []),
                    $node->getLevel()
                );
                $document->addNode($title);
                continue;
            }

            if ($node instanceof Text) {
                $spanNode = SpanNode::create($this, $node->getLiteral());
                $document->addNode($spanNode);
                continue;
            }

            if ($node instanceof Code) {
                $spanNode = new CodeNode([$node->getLiteral()]);
                $document->addNode($spanNode);
                continue;
            }

            if ($node instanceof Link) {
                $spanNode = new AnchorNode($node->getUrl());
                $document->addNode($spanNode);
                continue;
            }

            if ($node instanceof FencedCode) {
                $spanNode = new CodeNode([$node->getLiteral()]);
                $document->addNode($spanNode);
                continue;
            }

            if ($node instanceof HtmlBlock) {
                $spanNode = new RawNode($node->getLiteral());
                $document->addNode($spanNode);
                continue;
            }

            echo 'DOCUMENT CONTEXT: I am '
                . 'leaving'
                . ' a '
                . get_class($node)
                . ' node'
                . "\n";
        }

        return $document;
    }

    public function parseParagraph(NodeWalker $walker): ParagraphNode
    {
        $parser = new Parsers\Paragraph();

        return $parser->parse($this, $walker);
    }

    public function parseListBlock(NodeWalker $walker): ListNode
    {
        $parser = new Parsers\ListBlock();

        return $parser->parse($this, $walker);
    }

    public function getEnvironment(): ParserContext
    {
        if ($this->environment === null) {
            throw new RuntimeException(
                'A parser\'s Environment should not be consulted before parsing has started'
            );
        }

        return $this->environment;
    }

    public function getDocument(): DocumentNode
    {
        return $this->document;
    }
}
