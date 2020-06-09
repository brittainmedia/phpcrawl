<?php

namespace PHPCrawl\CookieCache;

use Exception;
use PDO;
use PDOException;
use PHPCrawl\PHPCrawlerBenchmark;
use PHPCrawl\PHPCrawlerCookieDescriptor;
use PHPCrawl\Utils\PHPCrawlerUtils;
use RuntimeException;

/**
 * Class for storing/caching cookies in a SQLite-db-file.
 *
 * @package phpcrawl
 * @internal
 */
class PHPCrawlerSQLiteCookieCache extends PHPCrawlerCookieCacheBase
{
    protected $PDO;

    protected $sqlite_db_file;

    /**
     * PHPCrawlerSQLiteCookieCache constructor.
     * @param $sqlite_db_file
     * @param bool $create_tables
     * @throws Exception
     */
    public function __construct($sqlite_db_file, $create_tables = false)
    {
        $this->sqlite_db_file = $sqlite_db_file;
        $this->openConnection($create_tables);
    }

    /**
     * Adds a cookie to the cookie-cache.
     *
     * @param PHPCrawlerCookieDescriptor $Cookie The cookie to add.
     */
    public function addCookie(PHPCrawlerCookieDescriptor $Cookie)
    {
        $source_domain = $Cookie->source_domain;
        $cookie_domain = $Cookie->domain;
        $cookie_path = $Cookie->path;
        $cookie_name = $Cookie->name;

        $cookie_hash = md5($cookie_domain . '_' . $cookie_path . '_' . $cookie_name);

        $this->PDO->exec('INSERT OR REPLACE INTO cookies (cookie_hash, source_domain, source_url, name, value, domain, path, expires, expire_timestamp, cookie_send_time)
                      VALUES (' . $this->PDO->quote($cookie_hash) . ',
                              ' . $this->PDO->quote($Cookie->source_domain) . ',
                              ' . $this->PDO->quote($Cookie->source_url) . ',
                              ' . $this->PDO->quote($Cookie->name) . ',
                              ' . $this->PDO->quote($Cookie->value) . ',
                              ' . $this->PDO->quote($Cookie->domain) . ',
                              ' . $this->PDO->quote($Cookie->path) . ',
                              ' . $this->PDO->quote($Cookie->expires) . ',
                              ' . $this->PDO->quote($Cookie->expire_timestamp) . ',
                              ' . $this->PDO->quote($Cookie->cookie_send_time) . ')');

    }

    /**
     * Adds a bunch of cookies to the cookie-cache.
     *
     * @param array $cookies Numeric array conatining the cookies to add as PHPCrawlerCookieDescriptor-objects
     */
    public function addCookies($cookies)
    {
        PHPCrawlerBenchmark::start('adding_cookies_to_cache');

        $this->PDO->exec('BEGIN EXCLUSIVE TRANSACTION');

        foreach ($cookies as $xValue) {
            $this->addCookie($xValue);
        }

        $this->PDO->exec('COMMIT');

        PHPCrawlerBenchmark::stop('adding_cookies_to_cache');
    }

    /**
     * Returns all cookies from the cache that are adressed to the given URL
     *
     * @param string $target_url The target-URL
     * @return array  Numeric array conatining all matching cookies as PHPCrawlerCookieDescriptor-objects
     */
    public function getCookiesForUrl($target_url)
    {
        PHPCrawlerBenchmark::start('getting_cookies_from_cache');

        $url_parts = PHPCrawlerUtils::splitURL($target_url);

        $return_cookies = [];

        $Result = $this->PDO->query("SELECT * FROM cookies WHERE source_domain = '" . $url_parts['domain'] . "';");
        $rows = $Result->fetchAll(PDO::FETCH_ASSOC);
        $Result->closeCursor();

        foreach ($rows as $xValue) {
            // Does the cookie-domain match?
            // Tail-matching, see http://curl.haxx.se/rfc/cookie_spec.html:
            // A domain attribute of "acme.com" would match host names "anvil.acme.com" as well as "shipping.crate.acme.com"
            if ($xValue['domain'] == $url_parts['host'] || preg_match('#' . preg_quote($xValue['domain']) . '$#', $url_parts['host'])) {
                // Does the path match?
                if (preg_match('#^' . preg_quote($xValue['path']) . '#', $url_parts['path'])) {
                    $Cookie = new PHPCrawlerCookieDescriptor($xValue['source_url'], $xValue['name'], $xValue['value'], $xValue['expires'], $xValue['path'], $xValue['domain']);
                    $return_cookies[$Cookie->name] = $Cookie; // Use cookie-name as index to avoid double-cookies
                }
            }
        }

        // Convert to numeric array
        $return_cookies = array_values($return_cookies);

        PHPCrawlerBenchmark::stop('getting_cookies_from_cache');

        return $return_cookies;
    }

    /**
     * Creates the sqlite-db-file and opens connection to it.
     *
     * @param bool $create_tables Defines whether all necessary tables should be created
     * @throws Exception
     */
    protected function openConnection($create_tables = false): void
    {
        //PHPCrawlerBenchmark::start("Connecting to SQLite-cache-db");

        // Open sqlite-file
        try {
            $this->PDO = new PDO('sqlite:' . $this->sqlite_db_file);
        } catch (PDOException $e) {
            throw new RuntimeException('Error creating SQLite-cache-file, ' . $e->getMessage() . ', try installing sqlite3-extension for PHP.');
        }

        $this->PDO->exec('PRAGMA journal_mode = OFF');

        $this->PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        $this->PDO->setAttribute(PDO::ATTR_TIMEOUT, 100);

        if ($create_tables == true) {
            // Create url-table (if not exists)
            $this->PDO->exec('CREATE TABLE IF NOT EXISTS cookies (id integer PRIMARY KEY AUTOINCREMENT,
                                                            cookie_hash TEXT UNIQUE,
                                                            source_domain TEXT,
                                                            source_url TEXT,
                                                            name TEXT,
                                                            value TEXT,
                                                            domain TEXT,
                                                            path TEXT,
                                                            expires TEXT,
                                                            expire_timestamp INTEGER,
                                                            cookie_send_time INTEGER);');

            // Create indexes (seems that indexes make the whole thingy slower)
            $this->PDO->exec('CREATE INDEX IF NOT EXISTS cookie_hash ON cookies (cookie_hash);');

            $this->PDO->exec('ANALYZE;');
        }

        //PHPCrawlerBenchmark::stop("Connecting to SQLite-cache-db");
    }

    /**
     * Cleans up the cache after it is not needed anymore.
     */
    public function cleanup()
    {
        $this->PDO = null; // Has to be done, otherwise sqlite-file is locked on Windows-OS
        unlink($this->sqlite_db_file);
    }
}
