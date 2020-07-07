<?php

namespace PHPCrawl;

use PHPCrawl\Utils\PHPCrawlerUtils;

/**
 * Class for filtering URLs by given filter-rules.
 *
 * @package phpcrawl
 * @internal
 */
class PHPCrawlerURLFilter
{
    /**
     * The full qualified and normalized URL the crawling-prpocess was started with.
     *
     * @var string
     */
    protected $starting_url = '';

    /**
     * The URL-parts of the starting-url.
     *
     * @var array The URL-parts as returned by PHPCrawlerUtils::splitURL()
     */
    protected $starting_url_parts = [];

    /**
     * Array containing regex-rules for URLs that should be followed.
     *
     * @var array
     */
    protected $url_follow_rules = [];

    /**
     * Array containing regex-rules for URLs that should NOT be followed.
     *
     * @var array
     */
    protected $url_filter_rules = [];

    /**
     * Defines whether nofollow-tags should get obeyed.
     *
     * @var bool
     */
    public $obey_nofollow_tags = false;

    /**
     * The general follow-mode of the crawler
     *
     * @var int The follow-mode
     *
     *          0 -> follow every links
     *          1 -> stay in domain
     *          2 -> stay in host
     *          3 -> stay in path
     */
    public $general_follow_mode = 2;

    /**
     * The maximum crawling-depth
     *
     * @var int
     */
    public $max_crawling_depth = null;

    /**
     * Current PHPCrawlerDocumentInfo-object of the current document
     *
     * @var PHPCrawlerDocumentInfo
     */
    protected $CurrentDocumentInfo = null;

    /**
     * Sets the base-URL of the crawling process some rules relate to
     *
     * @param string $starting_url The URL the crawling-process was started with.
     */
    public function setBaseURL($starting_url): void
    {
        $this->starting_url = $starting_url;

        // Parts of the starting-URL
        $this->starting_url_parts = PHPCrawlerUtils::splitURL($starting_url);
    }

    /**
     * Filters the given URLs (contained in the given PHPCrawlerDocumentInfo-object) by the given rules.
     *
     * @param PHPCrawlerDocumentInfo $DocumentInfo PHPCrawlerDocumentInfo-object containing all found links of the current document.
     */
    public function filterUrls(PHPCrawlerDocumentInfo $DocumentInfo): void
    {
        PHPCrawlerBenchmark::start('filtering_urls');

        $this->CurrentDocumentInfo = $DocumentInfo;

        $filtered_urls = [];

        foreach ($DocumentInfo->links_found_url_descriptors as $x => $xValue) {
            if (!$this->urlMatchesRules($xValue)) {
                $DocumentInfo->links_found_url_descriptors[$x] = null;
            }
        }

        $this->CurrentDocumentInfo = null;

        PHPCrawlerBenchmark::stop('filtering_urls');
    }

    /**
     * Filters out all non-redirect-URLs from the URLs given in the PHPCrawlerDocumentInfo-object
     *
     * @param PHPCrawlerDocumentInfo $DocumentInfo PHPCrawlerDocumentInfo-object containing all found links of the current document.
     * @param bool $decrease_link_depths
     */
    public static function keepRedirectUrls(PHPCrawlerDocumentInfo $DocumentInfo, $decrease_link_depths = false): void
    {
        foreach ($DocumentInfo->links_found_url_descriptors as $x => $xValue) {
            if ($xValue->is_redirect_url == false) {
                $DocumentInfo->links_found_url_descriptors[$x] = null;
            } else if ($decrease_link_depths == true) // Decrease linkdepths
            {
                $xValue->url_link_depth--;
            }
        }
    }

    /**
     * Checks whether a given URL matches the rules applied to the URLFilter.
     *
     * @param PHPCrawlerURLDescriptor $url The URL as a PHPCrawlerURLDescriptor-object
     * @return bool TRUE if the URL matches the defined rules.
     */
    protected function urlMatchesRules(PHPCrawlerURLDescriptor $url): bool
    {
        try{
            // URL-parts of the URL to check against the filter-rules
            $url_parts = PHPCrawlerUtils::splitURL($url->url_rebuild);
        } catch (Throwable $t) {
            return false;
        }

        // Kick out all links that are NOT of protocol "http" or "https"
        if (( !isset($url_parts) || !isset($url_parts['protocol']))
        || ($url_parts['protocol'] !== 'http://' && $url_parts['protocol'] !== 'https://')) {
            return false;
        }

        // Kick out URLs exceeding the maximum crawling-depth
        if ($this->max_crawling_depth !== null && $url->url_link_depth > $this->max_crawling_depth) {
            return false;
        }

        // If meta-tag "robots"->"nofollow" is present and obey_nofollow_tags is TRUE -> always kick out URL
        if ($this->obey_nofollow_tags == true &&
            isset($this->CurrentDocumentInfo->meta_attributes['robots']) &&
            preg_match('#nofollow# i', $this->CurrentDocumentInfo->meta_attributes['robots'])) {
            return false;
        }

        // If linkcode contains "rel='nofollow'" and obey_nofollow_tags is TRUE -> always kick out URL
        if ($this->obey_nofollow_tags == true) {
            if (preg_match("#^<[^>]*rel\s*=\s*(?|\"\s*nofollow\s*\"|'\s*nofollow\s*'|\s*nofollow\s*)[^>]*>#", $url->linkcode)) {
                return false;
            }
        }

        // Filter URLs to other domains if wanted
        if ($this->general_follow_mode >= 1) {
            if ($url_parts['domain'] != $this->starting_url_parts['domain']) {
                return false;
            }
        }

        // Filter URLs to other hosts if wanted
        if ($this->general_follow_mode >= 2) {
            // Ignore "www." at the beginning of the host, because "www.foo.com" is the same host as "foo.com"
            if (preg_replace("#^www\.#", '', $url_parts['host']) != preg_replace("#^www\.#", '', $this->starting_url_parts['host'])) {
                return false;
            }
        }

        // Filter URLs leading path-up if wanted
        if ($this->general_follow_mode == 3) {
            if ($url_parts['protocol'] != $this->starting_url_parts['protocol'] ||
                preg_replace("#^www\.#", '', $url_parts['host']) != preg_replace("#^www\.#", '', $this->starting_url_parts['host']) ||
                strpos($url_parts['path'], $this->starting_url_parts['path']) !== 0) {
                return false;
            }
        }

        // Filter URLs by url_filter_rules
        for ($x = 0, $xMax = count($this->url_filter_rules); $x < $xMax; $x++) {
            if (preg_match($this->url_filter_rules[$x], $url->url_rebuild)) {
                return false;
            }
        }

        // Filter URLs by url_follow_rules
        if (count($this->url_follow_rules) > 0) {
            $match_found = false;
            for ($x = 0, $xMax = count($this->url_follow_rules); $x < $xMax; $x++) {
                if (preg_match($this->url_follow_rules[$x], $url->url_rebuild)) {
                    $match_found = true;
                    break;
                }
            }

            if ($match_found == false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $regex
     * @return bool
     */
    public function addURLFollowRule($regex): bool
    {
        $check = PHPCrawlerUtils::checkRegexPattern($regex); // Check pattern

        if ($check == true) {
            $this->url_follow_rules[] = trim($regex);
        }
        return $check;
    }

    /**
     * Adds a rule to the list of rules that decide which URLs found on a page should be ignored by the crawler.
     * @param $regex
     * @return bool
     */
    public function addURLFilterRule($regex): bool
    {
        $check = PHPCrawlerUtils::checkRegexPattern($regex); // Check pattern

        if ($check == true) {
            $this->url_filter_rules[] = trim($regex);
        }
        return $check;
    }

    /**
     * Adds a bunch of rules to the list of rules that decide which URLs found on a page should be ignored by the crawler.
     * @param $regex_array
     */
    public function addURLFilterRules($regex_array): void
    {
        foreach ($regex_array as $xValue) {
            $this->addURLFilterRule($xValue);
        }
    }
}
