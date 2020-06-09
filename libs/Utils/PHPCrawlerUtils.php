<?php

namespace PHPCrawl\Utils;

use Exception;
use PHPCrawl\PHPCrawlerCookieDescriptor;
use PHPCrawl\PHPCrawlerHTTPRequest;
use PHPCrawl\PHPCrawlerURLDescriptor;
use PHPCrawl\PHPCrawlerUrlPartsDescriptor;
use RuntimeException;

/**
 * Static util-methods used by phpcrawl.
 *
 * @package phpcrawl
 * @internal
 */
class PHPCrawlerUtils
{

    public const OS_WINDOWS = 1;
    public const OS_NIX = 2;
    public const OS_OTHER = 3;

    /**
     * Splits an URL into its parts
     * @param $url
     * @return array
     */
    public static function splitURL($url): ?array
    {
        // Add protocol to the URL (otherwise parse_url will not work)
        if (!preg_match('#^[a-z0-9-]+://# i', $url)) {
            $url = "http://" . $url;
        }

        $parts = parse_url($url);

        if ($parts == false || !isset($parts)) {
            return null;
        }

        $protocol = $parts['scheme'] . '://';
        $host = ($parts["host"] ?? "");
        $path = ($parts["path"] ?? "");
        $query = (isset($parts['query']) ? '?' . $parts['query'] : '');
        $auth_username = ($parts["user"] ?? "");
        $auth_password = ($parts["pass"] ?? "");
        $port = ($parts["port"] ?? "");

        // Host is case-insensitive
        $host = strtolower($host);

        // File
        preg_match('#^(.*/)([^/]*)$#', $path, $match); // Everything from the last one "/"
        if (isset($match[0])) {
            $file = trim($match[2]);
            $path = trim($match[1]);
        } else {
            $file = '';
        }

        // The domainname from the host
        // Host: www.foo.com -> Domain: foo.com
        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            $domain = $host;
        } else if (preg_match('#^[0-9]+$#', str_replace('.', '', $host))) // IP
        {
            $domain = $host;
        } else {
            $pos = strpos($host, '.');
            $domain = substr($host, $pos + 1);
        }

        // DEFAULT VALUES for protocol, path, port etc. (if not set yet)

        // If protocol is empty -> protocol is "http: //"
        if ($protocol == '') {
            $protocol = "http://";
        }

        // If port is empty -> set port to 80 or 443
        // (depending on the protocol)
        if ($port == '') {
            if (strtolower($protocol) === 'http://') {
                $port = 80;
            }
            if (strtolower($protocol) === 'https://') {
                $port = 443;
            }
        }

        // if path is empty -> path is "/"
        if ($path == '') {
            $path = "/";
        }

        // build array
        $url_parts['protocol'] = $protocol;
        $url_parts['host'] = $host;
        $url_parts['path'] = $path;
        $url_parts['file'] = $file;
        $url_parts['query'] = $query;
        $url_parts['domain'] = $domain;
        $url_parts['port'] = $port;

        $url_parts['auth_username'] = $auth_username;
        $url_parts['auth_password'] = $auth_password;

        return $url_parts;
    }

    /**
     * Builds an URL from it's single parts.
     *
     * @param array $url_parts Array conatining the URL-parts.
     *                         The keys should be:
     *
     *                         "protocol" (z.B. "http://") OPTIONAL
     *                         "host"     (z.B. "www.bla.de")
     *                         "path"     (z.B. "/test/palimm/") OPTIONAL
     *                         "file"     (z.B. "index.htm") OPTIONAL
     *                         "port"     (z.B. 80) OPTIONAL
     *                         "auth_username" OPTIONAL
     *                         "auth_password" OPTIONAL
     * @param bool $normalize If TRUE, the URL will be returned normalized.
     *                          (I.e. http://www.foo.com/path/ insetad of http://www.foo.com:80/path/)
     * @return string The URL
     *
     * @throws Exception
     */
    public static function buildURLFromParts($url_parts, $normalize = false): string
    {
        // Host has to be set aat least
        if (!isset($url_parts['host'])) {
            throw new RuntimeException('Cannot generate URL, host not specified!');
        }

        if (!isset($url_parts['protocol']) || $url_parts['protocol'] == '') {
            $url_parts["protocol"] = "http://";
        }
        if (!isset($url_parts['port'])) {
            $url_parts["port"] = 80;
        }
        if (!isset($url_parts['path'])) {
            $url_parts["path"] = "";
        }
        if (!isset($url_parts['file'])) {
            $url_parts["file"] = "";
        }
        if (!isset($url_parts['query'])) {
            $url_parts["query"] = "";
        }
        if (!isset($url_parts['auth_username'])) {
            $url_parts["auth_username"] = "";
        }
        if (!isset($url_parts['auth_password'])) {
            $url_parts["auth_password"] = "";
        }

        // Autentication-part
        $auth_part = '';
        if ($url_parts['auth_username'] != '' && $url_parts['auth_password'] != '') {
            $auth_part = $url_parts['auth_username'] . ':' . $url_parts['auth_password'] . '@';
        }

        // Port-part
        $port_part = ':' . $url_parts['port'];

        // Normalize
        if ($normalize == true) {
            if (($url_parts["protocol"] === "http://" && $url_parts["port"] == 80) ||
                ($url_parts["protocol"] === "https://" && $url_parts["port"] == 443)) {
                $port_part = '';
            }

            // Don't add port to links other than "http://" or "https://"
            if ($url_parts['protocol'] !== 'http://' && $url_parts['protocol'] !== 'https://') {
                $port_part = '';
            }
        }

        // If path is just a "/" -> remove it ("www.site.com/" -> "www.site.com")
        if ($url_parts['path'] === '/' && $url_parts['file'] == '' && $url_parts['query'] == '') {
            $url_parts["path"] = "";
        }

        // Put together the url
        return $url_parts['protocol'] . $auth_part . $url_parts['host'] . $port_part . $url_parts['path'] . $url_parts['file'] . $url_parts['query'];
    }

    /**
     * Normalizes an URL
     *
     * I.e. converts http://www.foo.com:80/path/ to http://www.foo.com/path/
     *
     * @param string $url
     * @return string OR NULL on failure
     * @throws Exception
     */
    public static function normalizeURL($url): ?string
    {
        // If the PHP function fails to decode the URL it is likley a bad URL, skip it
        try {
            $url_parts = self::splitURL($url);
        } catch (Exception $e){
            return null;
        }

        if ($url_parts == null) {
            return null;
        }

        return self::buildURLFromParts($url_parts, true);
    }

    /**
     * Checks whether a given RegEx-pattern is valid or not.
     *
     * @param $pattern
     * @return bool
     */
    public static function checkRegexPattern($pattern): ?bool
    {
        $check = preg_match($pattern, 'anything'); // thats the easy way to check a pattern ;)
        return !(is_int($check) == false);
    }

    /**
     * Gets the HTTP-statuscode from a given response-header.
     *
     * @param string $header The response-header
     * @return int            The status-code or NULL if no status-code was found.
     */
    public static function getHTTPStatusCode($header): ?int
    {
        $first_line = strtok($header, "\n");

        preg_match('# [0-9]{3}#', $first_line, $match);

        if (isset($match[0])) {
            return (int)trim($match[0]);
        }

        return null;
    }

    /**
     * Reconstructs a full qualified and normalized URL from a given link relating to the URL the link was found in.
     *
     * @param string $link The link (i.e. "../page.htm")
     * @param PHPCrawlerUrlPartsDescriptor $BaseUrl The base-URL the link was found in as PHPCrawlerUrlPartsDescriptor-object
     *
     * @return string The rebuild, full qualified and normilazed URL the link is leading to (i.e. "http://www.foo.com/page.htm"),
     *                or NULL if the link couldn't be rebuild correctly.
     */
    public static function buildURLFromLink($link, PHPCrawlerUrlPartsDescriptor $BaseUrl): ?string
    {
        $url_parts = $BaseUrl->toArray();

        // Dedoce HTML-entities
        $link = PHPCrawlerEncodingUtils::decodeHtmlEntities($link);

        // Remove anchor ("#..."), but ONLY at the end, not if # is at the beginning !
        $link = preg_replace('/^(.{1,})#.{0,}$/', "\\1", $link);

        // Cases

        // Strange link like "//foo.htm" -> make it to "http://foo.html"
        if (self::startsWith($link, '//')) {
            $link = 'http:' . $link;
        }

        // 1. relative link starts with "/" --> doc_root
        // "/index.html" -> "http://www.foo.com/index.html"
        elseif (self::startsWith($link, '/')) {
            $link = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'] . $link;
        } // 2. "./foo.htm" -> "foo.htm"
        elseif (self::startsWith($link, './')) {
            $link = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'] . $url_parts['path'] . substr($link, 2);
        }

        // 3. Link is an absolute Link with a given protocol and host (f.e. "http://..." or "android-app://...)
        // DO NOTHING
        elseif (preg_match("#^[a-z0-9-]{1,}(://)# i", $link)) {
            // "silly assignment"
            $link = $link;
        } // 4. Link is stuff like "javascript: ..." or something
        elseif (preg_match("/^[a-zA-Z]{0,}:[^\/]{0,1}/", $link)) {
            $link = '';
        }

        // 5. "../../foo.html" -> remove the last path from our actual path
        // and remove "../" from link at the same time until there are
        // no more "../" at the beginning of the link
        elseif (self::startsWith($link, '../')) {
            $new_path = $url_parts['path'];

            while (self::startsWith($link, '../')) {
                $new_path = preg_replace('/\/[^\/]{0,}\/$/', '/', $new_path);
                $link = substr($link, 3);
            }

            $link = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'] . $new_path . $link;
        }

        // 6. link starts with #
        // -> leads to the same site as we are on, trash
        elseif (self::startsWith($link, '#')) {
            $link = '';
        } // 7. link starts with "?"
        elseif (self::startsWith($link, '?')) {
            $link = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'] . $url_parts['path'] . $url_parts['file'] . $link;
        } // 7. thats it, else the abs_path is simply PATH.LINK ...
        else {
            $link = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'] . $url_parts['path'] . $link;
        }

        if ($link == '') {
            return null;
        }

        if ($link === 'http://') {
            return null;
        }

        if ($link === 'https://') {
            return null;
        }

        // Now, at least, replace all HTMLENTITIES with normal text.
        // I.E.: HTML-Code of the link is: <a href="index.php?x=1&amp;y=2">
        // -> Link has to be "index.php?x=1&y=2"
        //$link = PHPCrawlerEncodingUtils::decodeHtmlEntities($link);

        // Replace linebreaks in the link with "" (happens if a link in the sourcecode
        // linebreaks)
        $link = str_replace(["\n", "\r"], '', $link);

        // "Normalize" URL
        $link = self::normalizeURL($link);

        return $link;
    }

    /**
     * Returns the base-URL specified in a meta-tag in the given HTML-source
     *
     * @param $html_source
     * @return string The base-URL or NULL if not found.
     */
    public static function getBaseUrlFromMetaTag($html_source): ?string
    {
        preg_match("#<{1}[ ]{0,}((?i)base){1}[ ]{1,}((?i)href|src)[ ]{0,}=[ ]{0,}([\"']){0,1}([^\"'><\n ]{0,})([\"'><
 ])# i", $html_source, $match);

        if (isset($match[4])) {
            $match[4] = trim($match[4]);
            return $match[4];
        }

        return null;
    }

    /**
     * Returns the redirect-URL from the given HTML-header
     *
     * @param $header
     * @return string The redirect-URL or NULL if not found.
     */
    public static function getRedirectURLFromHeader($header): ?string
    {
        // Get redirect-link from header
        preg_match("/((?i)location:|content-location:)(.{0,})[\n]/", $header, $match);

        if (isset($match[2])) {
            return trim($match[2]);
        }

        return null;
    }

    /**
     * Checks whether a given string matches with one of the given regular-expressions.
     *
     * @param &string $string      The string
     * @param array $regex_array Numerich array containing the regular-expressions to check against.
     *
     * @return bool TRUE if one of the regexes matches the string, otherwise FALSE.
     */
    public static function checkStringAgainstRegexArray($string, $regex_array): bool
    {
        if (count($regex_array) == 0) {
            return true;
        }

        foreach ($regex_array as $x => $xValue) {
            if (preg_match($regex_array[$x], $string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the value of an header-directive from the given HTTP-header.
     *
     * Example:
     * <code>PHPCrawlerUtils::getHeaderValue($header, "content-type");</code>
     *
     * @param string $header The HTTP-header
     * @param string $directive The header-directive
     *
     * @return string The value of the given directive found in the header.
     *                Or NULL if not found.
     */
    public static function getHeaderValue($header, $directive): ?string
    {
        preg_match("#[\r\n]" . $directive . ":(.*)[\r\n;]# Ui", $header, $match);

        if (isset($match[1]) && trim($match[1]) != '') {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * Returns all cookies from the give response-header.
     *
     * @param string $header The response-header
     * @param string $source_url URL the cookie was send from.
     * @return array Numeric array containing all cookies as PHPCrawlerCookieDescriptor-objects.
     */
    public static function getCookiesFromHeader($header, $source_url): array
    {
        $cookies = [];

        $hits = preg_match_all("#[\r\n]set-cookie:(.*)[\r\n]# Ui", $header, $matches);

        if ($hits && $hits != 0) {
            foreach ($matches[1] as $xValue) {
                $cookies[] = PHPCrawlerCookieDescriptor::getFromHeaderLine($xValue, $source_url);
            }
        }

        return $cookies;
    }

    /**
     * Returns the normalized root-URL of the given URL
     *
     * @param string $url The URL, e.g. "www.foo.con/something/index.html"
     * @return string The root-URL, e.g. "http://www.foo.com"
     * @throws Exception
     */
    public static function getRootUrl($url): string
    {
        $url_parts = self::splitURL($url);
        $root_url = $url_parts['protocol'] . $url_parts['host'] . ':' . $url_parts['port'];

        return self::normalizeURL($root_url);
    }

    /**
     * Deletes a directory recursivly
     * @param $dir
     */
    public static function rmDir($dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    if (filetype($dir . DIRECTORY_SEPARATOR . $object) === 'dir') {
                        self::rmDir($dir . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($dir . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            reset($objects);

            rmdir($dir);
        }
    }

    /**
     * Serializes data (objects, arrays etc.) and writes it to the given file.
     * @param $target_file
     * @param $data
     */
    public static function serializeToFile($target_file, $data): void
    {
        $serialized_data = serialize($data);
        file_put_contents($target_file, $serialized_data);
    }

    /**
     * Returns deserialized data that is stored in a file.
     *
     * @param string $file The file containing the serialized data
     *
     * @return mixed The data or NULL if the file doesn't exist
     */
    public static function deserializeFromFile($file)
    {
        if (file_exists($file)) {
            $serialized_data = file_get_contents($file);
            return unserialize($serialized_data);
        }

        return null;
    }

    /**
     * Sorts a twodimensiolnal array.
     * @param $array
     * @param $sort_args
     */
    public static function sort2dArray(&$array, $sort_args): void
    {
        $args = func_get_args();

        // F?r jedes zu sortierende Feld ein eigenes Array bilden
        foreach ($array as $fieldKey => $field) {
            for ($x = 1, $xMax = count($args); $x < $xMax; $x++) {
                // Ist das Argument ein String, sprich ein Sortier-Feld?
                if (is_string($args[$x])) {
                    $value = $array[$field][$args[$x]];

                    ${$args[$x]}[] = $value;
                }
            }
        }

        // Argumente f?r array_multisort bilden
        for ($x = 1, $xMax = count($args); $x < $xMax; $x++) {
            if (is_string($args[$x])) {
                // Argument ist ein TMP-Array
                $params[] = &${$args[$x]};
            } else {
                // Argument ist ein Sort-Flag so wie z.B. "SORT_ASC"
                $params[] = &$args[$x];
            }
        }

        // Der letzte Parameter ist immer das zu sortierende Array (Referenz!)
        $params[] = &$array;

        // Array sortieren
        array_multisort(...$params);

    }

    /**
     * Determinates the systems temporary-directory.
     *
     * @return string
     */
    public static function getSystemTempDir(): string
    {
        return sys_get_temp_dir() . '/';
    }

    /**
     * Gets all meta-tag atteributes from the given HTML-source.
     *
     * @param &string &$html_source
     * @return array Assoziative array conatining all found meta-attributes.
     *               The keys are the meta-names, the values the content of the attributes.
     *               (like $tags["robots"] = "nofollow")
     *
     */
    public static function getMetaTagAttributes($html_source): array
    {
        preg_match_all("#<\s*meta\s+" .
            "name\s*=\s*(?|\"([^\"]+)\"|'([^']+)'|([^\s><'\"]+))\s+" .
            "content\s*=\s*(?|\"([^\"]+)\"|'([^']+)'|([^\s><'\"]+))" .
            '.*># Uis', $html_source, $matches);

        $tags = [];
        for ($x = 0, $xMax = count($matches[0]); $x < $xMax; $x++) {
            $meta_name = strtolower(trim($matches[1][$x]));
            $meta_value = strtolower(trim($matches[2][$x]));

            $tags[$meta_name] = $meta_value;
        }

        return $tags;
    }

    /**
     * Checks wether the given string is an UTF8-encoded string.
     *
     * Taken from http://www.php.net/manual/de/function.mb-detect-encoding.php
     * (comment from "prgss at bk dot ru")
     *
     * @param string $string The string
     * @return bool TRUE if the string is UTF-8 encoded.
     */
    public static function isUTF8String($string): ?bool
    {
        $sample = iconv('utf-8', 'utf-8', $string);

        return md5($sample) == md5($string);
    }

    /**
     * Checks whether the given string is a valid, urlencoded URL (by RFC)
     *
     * @param string $string The string
     * @return bool TRUE if the string is a valid url-string.
     */
    public static function isValidUrlString($string): ?bool
    {
        if (preg_match("#^[a-z0-9/.&=?%-_.!~*'()]+$# i", $string)) {
            return true;
        }

        return false;
    }

    /**
     * Decodes GZIP-encoded HTTP-data
     * @param $content
     * @return false|string
     */
    public static function decodeGZipContent($content)
    {
        return gzinflate(substr($content, 10, -8));
    }

    /**
     * Checks whether the given data is gzip-encoded
     * @param $content
     * @return bool|null
     */
    public static function isGzipEncoded($content): ?bool
    {
        return strpos($content, "\x1f\x8b\x08") === 0;
    }


    /**
     * Gets the content from the given file or URL
     *
     * @param string $uri The URI (like "file://../myfile.txt" or "http://foo.com")
     * @param string $request_user_agent_string The UrserAgent-string to use for URL-requests
     * @param bool $throw_exception If set to true, an exception will get thrown in case of an IO-error
     * @return string The content of thr URI or NULL if the content couldn't be read
     * @throws Exception
     * @throws Exception
     */
    public static function getURIContent($uri, $request_user_agent_string = null, $throw_exception = false): string
    {
        $UriParts = PHPCrawlerUrlPartsDescriptor::fromURL($uri);

        $error_str = '';

        // If protocol is "file"
        if ($UriParts->protocol === 'file://') {
            $file = preg_replace('#^file://#', '', $uri);

            if (file_exists($file) && is_readable($file)) {
                return file_get_contents($file);
            }

            $error_str = "Error reading from file '" . $file . "'";
        } // If protocol is "http" or "https"
        elseif ($UriParts->protocol === 'http://' || $UriParts->protocol === 'https://') {
            $uri = self::normalizeURL($uri);
            $Request = new PHPCrawlerHTTPRequest();
            $Request->setUrl(new PHPCrawlerURLDescriptor($uri));

            if ($request_user_agent_string !== null) {
                $Request->userAgentString = $request_user_agent_string;
            }

            $DocInfo = $Request->sendRequest();

            if ($DocInfo->received == true) {
                return $DocInfo->source;
            }

            $error_str = "Error reading from URL '" . $uri . "'";
        } // if protocol is not supported
        else {
            $error_str = "Unsupported protocol-type '" . $UriParts->protocol . "'";
        }

        // Throw exception?
        if ($throw_exception == true) {
            throw new RuntimeException($error_str);
        }

        return null;
    }

    /**
     * Get current operating system
     *
     * @return int
     */
    public static function getOS(): int
    {
        $os = strtoupper(PHP_OS);
        if (strpos($os, 'WIN') === 0) {
            return self::OS_WINDOWS;
        }

        if ($os === 'LINUX' || $os === 'FREEBSD' || $os === 'DARWIN') {
            return self::OS_NIX;
        }
        return self::OS_OTHER;
    }

    /**
     * Check if a string starts with something
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        return (strpos($haystack, $needle) === 0);
    }

    /**
     * @see: https://www.devdungeon.com/content/how-use-ssl-sockets-php
     *
     * @param string $passPhrase
     * @param array $certificateData
     * @return false|int
     */
    public static function generateOpenSSLPEM(string $passPhrase, array $certificateData = array()): int
    {

        if (count($certificateData) == 0) {

            // https://www.php.net/manual/en/function.openssl-csr-new.php
            $certificateData = [
                "countryName" => "GB",
                "stateOrProvinceName" => "Somerset",
                "localityName" => "Glastonbury",
                "organizationName" => "The Brain Room Limited",
                "organizationalUnitName" => "PHP Documentation Team",
                "commonName" => "Wez Furlong",
                "emailAddress" => "wez@example.com"
            ];
        }

        // Generate certificate
        $privateKey = openssl_pkey_new();
        $certificate = openssl_csr_new($certificateData, $privateKey);
        $certificate = openssl_csr_sign($certificate, null, $privateKey, 365);

        // Generate PEM file
        $pem = [];
        openssl_x509_export($certificate, $pem[0]);
        openssl_pkey_export($privateKey, $pem[1], '');
        $pem = implode($pem);

        // Save PEM file
        $pemfile = self::getSystemTempDir() . '/phpcrawl.pem';
        return file_put_contents($pemfile, $pem);
    }
}


