<?php

namespace SilverStripe\UserDocs\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\TightBlockInterface;

/**
 * Holds content from an extension doc which is in a different language than the doc it extends.
 *
 * This block is tight if the content it holds would be in a tight list without this block,
 * so paragraphs in it are rendered the same way.
 */
class ExtensionContentBlock extends AbstractBlock implements TightBlockInterface
{
    private string $lang;

    private bool $tight = false;

    /**
     * @param string $lang The language of the content, as a value for the HTML lang attribute
     */
    public function __construct(string $lang)
    {
        parent::__construct();
        $this->lang = $lang;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function isTight(): bool
    {
        return $this->tight;
    }

    public function setTight(bool $tight): void
    {
        $this->tight = $tight;
    }
}
