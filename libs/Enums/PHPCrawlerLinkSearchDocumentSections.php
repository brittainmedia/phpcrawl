<?php

namespace PHPCrawl\Enums;

/**
 * Possible values for defining the sections of HTML-documents that will get ignored by the internal link-finding algorythm.
 *
 * @package phpcrawl.enums
 */
class PHPCrawlerLinkSearchDocumentSections
{
    /**
     * Script-parts of html-documents (<script>...</script>)
     */
    public const SCRIPT_SECTIONS = 1;

    /**
     * HTML-comments of html-documents (<!-->...<-->)
     */
    public const HTML_COMMENT_SECTIONS = 2;

    /**
     * Javascript-triggering attributes like onClick, onMouseOver etc.
     */
    public const JS_TRIGGERING_SECTIONS = 4;

    /**
     * All of the listed sections
     */
    public const ALL_SPECIAL_SECTIONS = 7;
}
