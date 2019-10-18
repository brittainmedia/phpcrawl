<?php

namespace PHPCrawl;

/**
 * Describes a URL within the PHPCrawl-system.
 *
 * @package phpcrawl
 */
class PHPCrawlerURLDescriptor
{
    /**
     * The complete, full qualified and normalized URL
     *
     * @var string
     */
    public $url_rebuild;

    /**
     * The raw link to this URL as it was found in the HTML-source, i.e. "../dunno/index.php"
     */
    public $link_raw;

    /**
     * The html-codepart that contained the link to this URL, i.e. "<a href="../foo.html">LINKTEXT</a>"
     */
    public $linkcode;

    /**
     * The linktext or html-code the link to this URL was layed over.
     */
    public $linktext;

    /**
     * The URL of the page that contained the link to the URL described here.
     *
     * @var string
     */
    public $refering_url;

    /**
     * Flag indicating whether this URL was target of an HTTP-redirect.
     *
     * @var string
     */
    public $is_redirect_url = false;

    /**
     * The URL/link-depth of this URL relevant to the entry-URL of the crawling-process
     *
     * @var int
     */
    public $url_link_depth;

    /**
     * Initiates an URL-descriptor
     *
     * @param $url_rebuild
     * @param null $link_raw
     * @param null $linkcode
     * @param null $linktext
     * @param null $refering_url
     * @param null $url_link_depth
     * @internal
     */
    public function __construct($url_rebuild, $link_raw = null, $linkcode = null, $linktext = null, $refering_url = null, $url_link_depth = null)
    {
        $this->url_rebuild = $url_rebuild;

        if (!empty($link_raw)) {
            $this->link_raw = $link_raw;
        }
        if (!empty($linkcode)) {
            $this->linkcode = $linkcode;
        }
        if (!empty($linktext)) {
            $this->linktext = $linktext;
        }
        if (!empty($refering_url)) {
            $this->refering_url = $refering_url;
        }
        if ($url_link_depth !== null) {
            $this->url_link_depth = (int)$url_link_depth;
        }
    }
}
