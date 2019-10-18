<?php

namespace PHPCrawl;

use PHPCrawl\Utils\PHPCrawlerUtils;

/**
 * Describes the single parts of an URL.
 *
 * @package phpcrawl
 * @internal
 */
class PHPCrawlerUrlPartsDescriptor
{
    public $protocol;

    public $host;

    public $path;

    public $file;

    public $domain;

    public $port;

    public $auth_username;

    public $auth_password;

    /**
     * Returns the PHPCrawlerUrlPartsDescriptor-object for the given URL.
     *
     * @param $url
     * @return PHPCrawlerUrlPartsDescriptor
     */
    public static function fromURL($url): PHPCrawlerUrlPartsDescriptor
    {
        $parts = PHPCrawlerUtils::splitURL($url);

        $tmp = new self();

        $tmp->protocol = $parts['protocol'];
        $tmp->host = $parts['host'];
        $tmp->path = $parts['path'];
        $tmp->file = $parts['file'];
        $tmp->domain = $parts['domain'];
        $tmp->port = $parts['port'];
        $tmp->auth_username = $parts['auth_username'];
        $tmp->auth_password = $parts['auth_password'];

        return $tmp;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
