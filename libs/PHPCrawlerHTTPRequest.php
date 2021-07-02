<?php

namespace PHPCrawl;

use ErrorException;
use Exception;
use PHPCrawl\Enums\PHPCrawlerHTTPProtocols;
use PHPCrawl\Enums\PHPCrawlerRequestErrors;
use PHPCrawl\Utils\PHPCrawlerEncodingUtils;
use PHPCrawl\Utils\PHPCrawlerUtils;
use RuntimeException;
use function get_class;

/**
 * Class for performing HTTP-requests.
 *
 * @package phpcrawl
 * @internal
 */
class PHPCrawlerHTTPRequest
{
    /**
     * The user-agent-string
     */
    public $userAgentString = 'PHPCrawl';

    /**
     * The HTTP protocol version to use.
     */
    public $http_protocol_version = 2;

    /**
     * Timeout-value for socket-connection
     */
    public $socketConnectTimeout = 10;

    /**
     * Socket-read-timeout
     */
    public $socketReadTimeout = 5;

    /**
     * Limit for content-size to receive
     *
     * @var int The kimit n bytes
     */
    protected $content_size_limit = 0;

    /**
     * Global counter for traffic this instance of the HTTPRequest-class caused.
     *
     * @var int Traffic in bytes
     */
    protected $global_traffic_count = 0;

    /**
     * Numer of bytes received from the header
     *
     * @var float Number of bytes
     */
    protected $header_bytes_received;

    /**
     * Number of bytes received from the content
     *
     * @var float Number of bytes
     */
    protected $content_bytes_received;

    /**
     * The time it took to tranfer the data of this document
     *
     * @var float Time in seconds and milliseconds
     */
    protected $data_transfer_time;

    /**
     * The time it took to connect to the server
     *
     * @var float Time in seconds and milliseconds or NULL if connection could not be established
     */
    protected $server_connect_time;

    /**
     * The server resonse time
     *
     * @var float time in seconds and milliseconds or NULL if the server didn't respond
     */
    protected $server_response_time;

    /**
     * Contains all rules defining the content-types that should be received
     *
     * @var array Numeric array conatining the regex-rules
     */
    protected $receive_content_types = [];

    /**
     * Contains all rules defining the content-types of pages/files that should be streamed directly to
     * a temporary file (instead of to memory)
     *
     * @var array Numeric array conatining the regex-rules
     */
    protected $receive_to_file_content_types = [];

    /**
     * Contains all rules defining the content-types defining which documents shoud get checked for links.
     *
     * @var array Numeric array conatining the regex-rules
     */
    protected $linksearch_content_types = ['#text/html# i'];

    /**
     * The TMP-File to use when a page/file should be streamed to file.
     *
     * @var string
     */
    protected $tmpFile = 'phpcrawl.tmp';

    /**
     * The URL for the request as PHPCrawlerURLDescriptor-object
     *
     * @var PHPCrawlerURLDescriptor
     */
    protected $UrlDescriptor;

    /**
     * The parts of the URL for the request as returned by PHPCrawlerUtils::splitURL()
     *
     * @var array
     */
    protected $url_parts = [];

    /**
     * DNS-cache
     *
     * @var PHPCrawlerDNSCache
     */
    public $DNSCache;

    /**
     * Link-finder object
     *
     * @var PHPCrawlerLinkFinder
     */
    protected $LinkFinder;

    /**
     * The last response-header this request-instance received.
     */
    protected $lastResponseHeader;

    /**
     * Array containing cookies to send with the request
     *
     * @array
     */
    protected $cookie_array = [];

    /**
     * Array containing POST-data to send with the request
     *
     * @var array
     */
    protected $post_data = [];

    /**
     * The proxy to use
     *
     * @var array Array containing the keys "proxy_host", "proxy_port", "proxy_username", "proxy_password".
     */
    protected $proxy;

    /**
     * The socket used for HTTP-requests
     */
    protected $socket;

    /**
     * The bytes contained in the socket-buffer directly after the server responded
     */
    protected $socket_prefill_size;

    /**
     * Enalbe/disable request for gzip encoded content.
     */
    protected $request_gzip_content = false;

    protected $header_check_callback_function;

    protected $content_buffer_size = 200000;
    protected $chunk_buffer_size = 20240;
    protected $socket_read_buffer_size = 1024;
    protected $source_overlap_size = 1500;

    protected $certificateVerify = true;

    public function __construct()
    {
        // Init LinkFinder
        $this->LinkFinder = new PHPCrawlerLinkFinder();

        // Init DNS-cache
        $this->DNSCache = new PHPCrawlerDNSCache();

        // Cookie-Descriptor

        // ResponseHeader-class

        // PHPCrawlerHTTPProtocols-class
    }

    /**
     * Sets the URL for the request.
     * @param PHPCrawlerURLDescriptor $UrlDescriptor
     */
    public function setUrl(PHPCrawlerURLDescriptor $UrlDescriptor): void
    {
        $this->UrlDescriptor = $UrlDescriptor;

        // Split the URL into its parts
        $this->url_parts = PHPCrawlerUtils::splitURL($UrlDescriptor->url_rebuild);
    }

    /**
     * Adds a cookie to send with the request.
     *
     * @param string $name Cookie-name
     * @param string $value Cookie-value
     */
    public function addCookie($name, $value): void
    {
        $this->cookie_array[$name] = $value;
    }

    /**
     * Adds a cookie to send with the request.
     *
     * @param PHPCrawlerCookieDescriptor $Cookie
     */
    public function addCookieDescriptor(PHPCrawlerCookieDescriptor $Cookie): void
    {
        $this->addCookie($Cookie->name, $Cookie->value);
    }

    /**
     * Adds a bunch of cookies to send with the request
     *
     * @param array $cookies Numeric array containins cookies as PHPCrawlerCookieDescriptor-objects
     */
    public function addCookieDescriptors($cookies): void
    {
        foreach ($cookies as $xValue) {
            $this->addCookieDescriptor($xValue);
        }
    }

    /**
     * Removes all cookies to send with the request.
     */
    public function clearCookies(): void
    {
        $this->cookie_array = [];
    }

    /**
     * Sets the html-tags from which to extract/find links from.
     *
     * @param array $tag_array Numeric array containing the tags, i.g. ["href", "src", "url", ...]
     * @return bool
     */
    public function setLinkExtractionTags($tag_array): bool
    {
        if (!is_array($tag_array)) {
            return false;
        }

        $this->LinkFinder->extract_tags = $tag_array;
        return true;
    }

    /**
     * Specifies whether redirect-links set in http-headers should get searched for.
     *
     * @param $mode
     * @return bool
     */
    public function setFindRedirectURLs($mode): bool
    {
        if (!is_bool($mode)) {
            return false;
        }

        $this->LinkFinder->find_redirect_urls = $mode;

        return true;
    }

    /**
     * Adds post-data to send with the request.
     * @param $key
     * @param $value
     */
    public function addPostData($key, $value): void
    {
        $this->post_data[$key] = $value;
    }

    /**
     * Removes all post-data to send with the request.
     */
    public function clearPostData(): void
    {
        $this->post_data = [];
    }

    /**
     * @param $proxy_host
     * @param $proxy_port
     * @param null $proxy_username
     * @param null $proxy_password
     */
    public function setProxy($proxy_host, $proxy_port, $proxy_username = null, $proxy_password = null): void
    {
        $this->proxy = [];
        $this->proxy['proxy_host'] = $proxy_host;
        $this->proxy['proxy_port'] = $proxy_port;
        $this->proxy['proxy_username'] = $proxy_username;
        $this->proxy['proxy_password'] = $proxy_password;
    }

    /**
     * Sets basic-authentication login-data for protected URLs.
     * @param $username
     * @param $password
     */
    public function setBasicAuthentication($username, $password): void
    {
        $this->url_parts['auth_username'] = $username;
        $this->url_parts['auth_password'] = $password;
    }

    /**
     * Set if certificates for ssl connections should be verified
     * This should only be disabled in debug/local mode
     * @param bool $verify
     */
    public function setCertificateVerify($verify = true): void
    {
        $this->certificateVerify = $verify;
    }

    /**
     * Enables/disables aggresive linksearch
     *
     * @param bool $mode
     * @return bool
     */
    public function enableAggressiveLinkSearch($mode): bool
    {
        if (!is_bool($mode)) {
            return false;
        }

        $this->LinkFinder->aggressive_search = $mode;
        return true;
    }

    /**
     * @param $obj
     * @param $method_name
     */
    public function setHeaderCheckCallbackFunction($obj, $method_name): void
    {
        $this->header_check_callback_function = [$obj, $method_name];
    }

    /**
     * Sends the HTTP-request and receives the page/file.
     *
     * @return PHPCrawlerDocumentInfo
     * @throws Exception
     */
    public function sendRequest(): PHPCrawlerDocumentInfo
    {
        // Prepare LinkFinder
        $this->LinkFinder->resetLinkCache();
        $this->LinkFinder->setSourceUrl($this->UrlDescriptor);

        // Initiate the Response-object and pass base-infos
        $PageInfo = new PHPCrawlerDocumentInfo();
        $PageInfo->url = $this->UrlDescriptor->url_rebuild;
        $PageInfo->protocol = $this->url_parts['protocol'];
        $PageInfo->host = $this->url_parts['host'];
        $PageInfo->path = $this->url_parts['path'];
        $PageInfo->file = $this->url_parts['file'];
        $PageInfo->query = $this->url_parts['query'];
        $PageInfo->port = $this->url_parts['port'];
        $PageInfo->url_link_depth = $this->UrlDescriptor->url_link_depth;
        $PageInfo->error_code = 0;
        $PageInfo->error_string = '';

        // Create header to send
        $request_header_lines = $this->buildRequestHeader();
        $header_string = trim(implode('', $request_header_lines));
        $PageInfo->header_send = $header_string;

        // Open socket
        $this->openSocket($PageInfo->error_code, $PageInfo->error_string);
        $PageInfo->server_connect_time = $this->server_connect_time;

        // If error occured
        if (isset($PageInfo->error_code) && $PageInfo->error_code > 0) {
            // If proxy-error -> throw exception
            if ($PageInfo->error_code == PHPCrawlerRequestErrors::ERROR_PROXY_UNREACHABLE) {
                throw new RuntimeException("Unable to connect to proxy '" . $this->proxy['proxy_host'] . "' on port '" . $this->proxy['proxy_port'] . "'");
            }

            $PageInfo->error_occured = true;
            return $PageInfo;
        }

        // Send request
        $this->sendRequestHeader($request_header_lines);

        // Read response-header
        $response_header = $this->readResponseHeader($PageInfo->error_code, $PageInfo->error_string);
        $PageInfo->server_response_time = $this->server_response_time;

        // If error occured
        if (isset($PageInfo->error_code) && $PageInfo->error_code > 0) {
            $PageInfo->error_occured = true;
            return $PageInfo;
        }

        // Set header-infos
        $this->lastResponseHeader = new PHPCrawlerResponseHeader($response_header, $this->UrlDescriptor->url_rebuild);
        $PageInfo->responseHeader = $this->lastResponseHeader;
        $PageInfo->header = $this->lastResponseHeader->header_raw;
        $PageInfo->http_status_code = $this->lastResponseHeader->http_status_code;
        $PageInfo->content_type = $this->lastResponseHeader->content_type;
        $PageInfo->cookies = $this->lastResponseHeader->cookies;

        // Referer-Infos
        if (isset($this->UrlDescriptor->refering_url) && $this->UrlDescriptor->refering_url != null) {
            $PageInfo->referer_url = $this->UrlDescriptor->refering_url;
            $PageInfo->refering_linkcode = $this->UrlDescriptor->linkcode;
            $PageInfo->refering_link_raw = $this->UrlDescriptor->link_raw;
            $PageInfo->refering_linktext = $this->UrlDescriptor->linktext;
        }

        // Check if content should be received
        $receive = $this->decideRecevieContent($this->lastResponseHeader);

        if ($receive == false) {
            fclose($this->socket);
            $PageInfo->received = false;
            $PageInfo->links_found_url_descriptors = $this->LinkFinder->getAllURLs(); // Maybe found a link/redirect in the header
            $PageInfo->meta_attributes = $this->LinkFinder->getAllMetaAttributes();
            return $PageInfo;
        }

        $PageInfo->received = true;

        // Check if content should be streamd to file
        $stream_to_file = $this->decideStreamToFile($response_header);

        // Read content
        $response_content = $this->readResponseContent($stream_to_file, $PageInfo->error_code, $PageInfo->error_string, $PageInfo->received_completely);

        // If error occured
        if (isset($PageInfo->error_code) && $PageInfo->error_code > 0) {
            $PageInfo->error_occured = true;
        }

        fclose($this->socket);

        // Complete ResponseObject
        $PageInfo->content = $response_content;
        $PageInfo->source = &$PageInfo->content;
        $PageInfo->received_completly = $PageInfo->received_completely;

        if ($stream_to_file == true) {
            $PageInfo->received_to_file = true;
            $PageInfo->content_tmp_file = $this->tmpFile;
        } else {
            $PageInfo->received_to_memory = true;
        }

        $PageInfo->links_found_url_descriptors = $this->LinkFinder->getAllURLs();
        $PageInfo->meta_attributes = $this->LinkFinder->getAllMetaAttributes();

        // Info about received bytes
        $PageInfo->bytes_received = $this->content_bytes_received;
        $PageInfo->header_bytes_received = $this->header_bytes_received;

        $dtr_values = $this->calulateDataTransferRateValues();
        if ($dtr_values != null) {
            $PageInfo->data_transfer_rate = $dtr_values['data_transfer_rate'];
            $PageInfo->unbuffered_bytes_read = $dtr_values['unbuffered_bytes_read'];
            $PageInfo->data_transfer_time = $dtr_values['data_transfer_time'];
        }

        $PageInfo->setLinksFoundArray();

        $this->LinkFinder->resetLinkCache();

        return $PageInfo;
    }

    /**
     * Calculates data tranfer rate values
     *
     * @return array|int
     */
    protected function calulateDataTransferRateValues()
    {
        $vals = [];

        // Works like this:
        // After the server resonded, the socket-buffer is already filled with bytes,
        // that means they were received within the server-response-time.

        // To calulate the real data transfer rate, these bytes have to be substractred from the received
        // bytes beofre calulating the rate.
        if ($this->data_transfer_time > 0 && $this->content_bytes_received > 4 * $this->socket_prefill_size) {
            $vals['unbuffered_bytes_read'] = $this->content_bytes_received + $this->header_bytes_received - $this->socket_prefill_size;
            $vals['data_transfer_rate'] = $vals['unbuffered_bytes_read'] / $this->data_transfer_time;
            $vals['data_transfer_time'] = $this->data_transfer_time;
        } else {
            $vals = null;
        }

        return $vals;
    }

    /**
     * Opens the socket to the host.
     *
     * @param int    &$error_code Error-code by referenct if an error occured.
     * @param string &$error_string Error-string by reference
     *
     * @return bool   TRUE if socket could be opened, otherwise FALSE.
     */
    protected function openSocket(&$error_code, &$error_string): ?bool
    {
        PHPCrawlerBenchmark::reset('connecting_server');
        PHPCrawlerBenchmark::start('connecting_server');

        // SSL or not?
        if ($this->url_parts['protocol'] === 'https://') {
            $protocol_prefix = "ssl://";
        } else {
            $protocol_prefix = "";
        }

        // If SSL-request, but openssl is not installed
        if ($protocol_prefix === 'ssl://' && !extension_loaded('openssl')) {
            $error_code = PHPCrawlerRequestErrors::ERROR_SSL_NOT_SUPPORTED;
            $error_string = 'Error connecting to ' . $this->url_parts['protocol'] . $this->url_parts['host'] . ': SSL/HTTPS-requests not supported, extension openssl not installed.';
        }

        // Get IP for hostname
        $ip_address = $this->DNSCache->getIP($this->url_parts['host']);

        // since PHP 5.6 SNI_server_name is deprecated
        if (version_compare(PHP_VERSION, '5.6.0') >= 0)
        {
            $serverName = 'peer_name';
        }
        else
        {
            $serverName = 'SNI_server_name';
        }

        try {
            // Open socket
            if (isset($this->proxy) && $this->proxy != null) {

                // SSL or not?
                if (parse_url($this->proxy['proxy_host'], PHP_URL_SCHEME) === 'https') {
                    $context = stream_context_create(array(
                        'ssl' => array(
                            $serverName => $this->proxy['proxy_host'],
                        ),
                    ));
                    $protocol_prefix = "ssl://";
                    $this->socket = stream_socket_client($protocol_prefix . parse_url($this->proxy['proxy_host'], PHP_URL_HOST) . ':' . $this->proxy['proxy_port'], $error_code, $error_str,
                        $this->socketConnectTimeout, STREAM_CLIENT_CONNECT, $context);

                } else {
                    $protocol_prefix = "";
                    $this->socket = stream_socket_client($protocol_prefix . parse_url($this->proxy['proxy_host'], PHP_URL_HOST) . ':' . $this->proxy['proxy_port'], $error_code, $error_str,
                        $this->socketConnectTimeout, STREAM_CLIENT_CONNECT);
                }



            } else {
                // If ssl -> perform Server name indication
                if ($this->url_parts['protocol'] === 'https://') {

                    if($this->certificateVerify){
                        $context = stream_context_create(array(
                            'ssl' => array(
                                $serverName => $this->url_parts["host"],
                            ),
                        ));

                    } else {
                        $context = stream_context_create([
                            "ssl"=> [
                                "verify_peer"=> false,
                                "verify_peer_name"=> false,
                            ],
                        ]);
                    }
                    $this->socket = stream_socket_client($protocol_prefix . $ip_address . ':443', $error_code, $error_str,
                        $this->socketConnectTimeout, STREAM_CLIENT_CONNECT, $context);
                } else {
                    $this->socket = stream_socket_client($protocol_prefix . $ip_address . ':' . $this->url_parts['port'], $error_code, $error_str,
                        $this->socketConnectTimeout, STREAM_CLIENT_CONNECT); // NO $context here, memory-leak-bug in php v. 5.3.x!!
                }

            }
        } catch(\ErrorException $e){
            $error_string .= $e;
            $this->socket = false;
        }


        $this->server_connect_time = PHPCrawlerBenchmark::stop('connecting_server');

        // If socket not opened -> throw error
        if ($this->socket == false) {
            $this->server_connect_time = null;

            // If proxy not reachable
            if (isset($this->proxy) && $this->proxy != null) {
                $error_code = PHPCrawlerRequestErrors::ERROR_PROXY_UNREACHABLE;
                $error_string .= 'Error connecting to proxy ' . $this->proxy['proxy_host'] . ': Host unreachable (' . $error_str . ').';
                return false;
            }

            $error_code = PHPCrawlerRequestErrors::ERROR_HOST_UNREACHABLE;
            $error_string .= 'Error connecting to ' . $this->url_parts['protocol'] . $this->url_parts['host'] . ': Host unreachable (' . $error_str . ').';
            return false;
        } else {
            return true;
        }
    }

    /**
     * Send the request-header.
     * @param $request_header_lines
     */
    protected function sendRequestHeader($request_header_lines): void
    {
        // Header senden
        foreach ($request_header_lines as $xValue) {
            if (is_resource($this->socket)) {
                fwrite($this->socket, $xValue);
            }
        }
    }

    /**
     * Reads the response-header.
     *
     * @param int    &$error_code Error-code by reference if an error occured.
     * @param string &$error_string Error-string by reference
     *
     * @return string The response-header or NULL if an error occured
     */
    protected function readResponseHeader(&$error_code, &$error_string): ?string
    {
        PHPCrawlerBenchmark::reset('server_response_time');
        PHPCrawlerBenchmark::start('server_response_time');

        $status = stream_get_meta_data($this->socket);
        $source_read = '';
        $header = '';
        $server_responded = false;

        while ($status['eof'] == false) {
            stream_set_timeout($this->socket, $this->socketReadTimeout);

            // Read line from socket
            $line_read = fgets($this->socket, 1024);

            // Server responded
            if ($server_responded == false) {
                $server_responded = true;
                $this->server_response_time = PHPCrawlerBenchmark::stop('server_response_time');

                // Determinate socket prefill size
                $status = stream_get_meta_data($this->socket);
                $this->socket_prefill_size = $status['unread_bytes'];

                // Start data-transfer-time bechmark
                PHPCrawlerBenchmark::reset('data_transfer_time');
                PHPCrawlerBenchmark::start('data_transfer_time');
            }

            $source_read .= $line_read;

            $this->global_traffic_count += strlen($line_read);

            $status = stream_get_meta_data($this->socket);

            // Socket timed out
            if ($status['timed_out'] == true) {
                $error_code = PHPCrawlerRequestErrors::ERROR_SOCKET_TIMEOUT;
                $error_string = 'Socket-stream timed out (timeout set to ' . $this->socketReadTimeout . ' sec).';
                return $header;
            }

            // No "HTTP" at beginnig of response
            if (stripos($source_read, 'http') !== 0) {
                $error_code = PHPCrawlerRequestErrors::ERROR_NO_HTTP_HEADER;
                $error_string = 'HTTP-protocol error.';
                return $header;
            }

            // Header found and read (2 newlines) -> stop
            if (substr($source_read, -4, 4) === "\r\n\r\n" || substr($source_read, -2, 2) === "\n\n") {
                $header = substr($source_read, 0, -2);
                break;
            }
        }

        // Stop data-transfer-time bechmark
        PHPCrawlerBenchmark::stop('data_transfer_time');

        // Header was found
        if ($header != '') {
            // Search for links (redirects) in the header
            $this->LinkFinder->processHTTPHeader($header);
            $this->header_bytes_received = strlen($header);
            return $header;
        }

        // No header found
        if ($header == '') {
            $this->server_response_time = null;
            $error_code = PHPCrawlerRequestErrors::ERROR_NO_HTTP_HEADER;
            $error_string = "Host doesn't respond with a HTTP-header.";
            return null;
        }
        return null;
    }

    /**
     * Reads the response-content.
     *
     * @param bool $stream_to_file If TRUE, the content will be streamed diretly to the temporary file and
     *                                this method will not return the content as a string.
     * @param int     &$error_code Error-code by reference if an error occured.
     * @param string $error_string
     * @param bool $document_received_completely
     * @return string  The response-content/source. May be emtpy if an error ocdured or data was streamed to the tmp-file.
     * @throws Exception
     */
    protected function readResponseContent($stream_to_file = false, $error_code = 0, $error_string = '', $document_received_completely = false): string
    {
        $this->content_bytes_received = 0;

        // If content should be streamed to file
        if ($stream_to_file == true) {
            $fp = fopen($this->tmpFile, 'wb');

            if ($fp == false) {
                $error_code = PHPCrawlerRequestErrors::ERROR_TMP_FILE_NOT_WRITEABLE;
                $error_string = "Couldn't open the temporary file " . $this->tmpFile . ' for writing.';
                return '';
            }
        }

        // Init
        $source_portion = '';
        $source_complete = '';
        $document_received_completely = true;
        $document_completed = false;
        $gzip_encoded_content = null;

        // Resume data-transfer-time benchmark
        PHPCrawlerBenchmark::start('data_transfer_time');

        while ($document_completed == false) {
            // Get chunk from content
            $content_chunk = $this->readResponseContentChunk($document_completed, $error_code, $error_string, $document_received_completely);
            $source_portion .= $content_chunk;

            // Check if content is gzip-encoded (check only first chunk)
            if ($gzip_encoded_content === null) {
                if (PHPCrawlerEncodingUtils::isGzipEncoded($content_chunk)) {
                    $gzip_encoded_content = true;
                } else {
                    $gzip_encoded_content = false;
                }
            }

            // Stream to file or store source in memory
            if ($stream_to_file == true) {
                fwrite($fp, $content_chunk);
            } else {
                $source_complete .= $content_chunk;
            }

            // Decode gzip-encoded content when done with document
            if ($document_completed == true && $gzip_encoded_content == true) {
                $source_complete = $source_portion = PHPCrawlerEncodingUtils::decodeGZipContent($source_complete);
            }

            // Find links in portion of the source
            if (($gzip_encoded_content == false && $stream_to_file == false && strlen($source_portion) >= $this->content_buffer_size) || $document_completed == true) {
                if (PHPCrawlerUtils::checkStringAgainstRegexArray($this->lastResponseHeader->content_type, $this->linksearch_content_types)) {
                    PHPCrawlerBenchmark::stop('data_transfer_time');
                    $this->LinkFinder->findLinksInHTMLChunk($source_portion);

                    if ($this->source_overlap_size > 0) {
                        $source_portion = substr($source_portion, -$this->source_overlap_size);
                    } else {
                        $source_portion = "";
                    }

                    PHPCrawlerBenchmark::start('data_transfer_time');
                }
            }
        }

        if ($stream_to_file == true) {
            fclose($fp);
        }

        // Stop data-transfer-time benchmark
        PHPCrawlerBenchmark::stop('data_transfer_time');
        $this->data_transfer_time = PHPCrawlerBenchmark::getElapsedTime('data_transfer_time');

        return $source_complete;
    }

    /**
     * Reads a chunk from the response-content
     *
     * @param $document_completed
     * @param $error_code
     * @param $error_string
     * @param $document_received_completely
     * @return string
     */
    protected function readResponseContentChunk(&$document_completed, &$error_code, &$error_string, &$document_received_completely): string
    {
        $source_chunk = '';
        $stop_receiving = false;
        $bytes_received = 0;
        $document_completed = false;
        $current_chunk_size = 0;

        // If chunked encoding and protocol to use is HTTP 1.1
        if ($this->http_protocol_version == PHPCrawlerHTTPProtocols::HTTP_1_1 && $this->lastResponseHeader->transfer_encoding === 'chunked') {
            // Read size of next chunk
            $chunk_line = fgets($this->socket, 128);
            if (trim($chunk_line) == '') {
                $chunk_line = fgets($this->socket, 128);
            }
            if($chunk_line !== false){
                $current_chunk_size = @hexdec(trim($chunk_line));
            }
        } else {
            $current_chunk_size = $this->chunk_buffer_size;
        }

        if ($current_chunk_size === 0) {
            $stop_receiving = true;
            $document_completed = true;
        }

        while ($stop_receiving == false) {
            stream_set_timeout($this->socket, $this->socketReadTimeout);

            // Set byte-buffer to bytes in socket-buffer (Fix for SSL-hang-bug #56, thanks to MadEgg!)
            $status = stream_get_meta_data($this->socket);
            if ($status['unread_bytes'] > 0) {
                $read_byte_buffer = $status["unread_bytes"];
            } else {
                $read_byte_buffer = $this->socket_read_buffer_size;
            }

            // If chunk will be complete next read -> resize read-buffer to size of remaining chunk
            if ($bytes_received + $read_byte_buffer >= $current_chunk_size && $current_chunk_size > 0) {
                $read_byte_buffer = $current_chunk_size - $bytes_received;
                $stop_receiving = true;
            }

            // Read line from socket
            $line_read = fread($this->socket, $read_byte_buffer);

            $source_chunk .= $line_read;
            $line_length = strlen($line_read);
            $this->content_bytes_received += $line_length;
            $this->global_traffic_count += $line_length;
            $bytes_received += $line_length;

            // Check socket-status
            $status = stream_get_meta_data($this->socket);

            // Check for EOF
            if ($status['unread_bytes'] == 0 && ($status['eof'] == true || feof($this->socket) == true)) {
                $stop_receiving = true;
                $document_completed = true;
            }

            // Socket timed out
            if ($status['timed_out'] == true) {
                $stop_receiving = true;
                $document_completed = true;
                $error_code = PHPCrawlerRequestErrors::ERROR_SOCKET_TIMEOUT;
                $error_string = 'Socket-stream timed out (timeout set to ' . $this->socketReadTimeout . ' sec).';
                $document_received_completely = false;
                return $source_chunk;
            }

            // Check if content-length stated in the header is reached
            if ($this->lastResponseHeader->content_length == $this->content_bytes_received) {
                $stop_receiving = true;
                $document_completed = true;
            }

            // Check if contentsize-limit is reached
            if ($this->content_size_limit > 0 && $this->content_size_limit <= $this->content_bytes_received) {
                $document_received_completely = false;
                $stop_receiving = true;
                $document_completed = true;
            }

        }

        return $source_chunk;
    }

    /**
     * Builds the request-header from the given settings.
     *
     * @return array  Numeric array containing the lines of the request-header
     */
    protected function buildRequestHeader(): array
    {
        // Create header
        $headerlines = [];

        // Methode(GET or POST)
        if (count($this->post_data) > 0) {
            $request_type = "POST";
        } else {
            $request_type = "GET";
        }

        // HTTP protocol
        if ($this->http_protocol_version == PHPCrawlerHTTPProtocols::HTTP_1_1) {
            $http_protocol_verison = "1.1";
        } else {
            $http_protocol_verison = "1.0";
        }

        if (isset($this->proxy) && $this->proxy != null) {
            // A Proxy needs the full qualified URL in the GET or POST headerline.
            $headerlines[] = $request_type . ' ' . $this->UrlDescriptor->url_rebuild . " HTTP/1.0\r\n";
        } else {
            $query = $this->prepareHTTPRequestQuery($this->url_parts['path'] . $this->url_parts['file'] . $this->url_parts['query']);
            $headerlines[] = $request_type . ' ' . $query . ' HTTP/' . $http_protocol_verison . "\r\n";
        }

        $headerlines[] = 'Host: ' . $this->url_parts['host'] . "\r\n";

        $headerlines[] = 'User-Agent: ' . str_replace("\n", '', $this->userAgentString) . "\r\n";
        $headerlines[] = "Accept: */*\r\n";

        // Request GZIP-content
        if ($this->request_gzip_content == true) {
            $headerlines[] = "Accept-Encoding: gzip, deflate\r\n";
        }

        // Referer
        if (isset($this->UrlDescriptor->refering_url) && $this->UrlDescriptor->refering_url != null) {
            $headerlines[] = 'Referer: ' . $this->UrlDescriptor->refering_url . "\r\n";
        }

        // Cookies
        $cookie_header = $this->buildCookieHeader();
        if ($cookie_header != null) {
            $headerlines[] = $this->buildCookieHeader();
        }

        // Authentication
        if ($this->url_parts['auth_username'] != '' && $this->url_parts['auth_password'] != '') {
            $auth_string = base64_encode($this->url_parts['auth_username'] . ':' . $this->url_parts['auth_password']);
            $headerlines[] = 'Authorization: Basic ' . $auth_string . "\r\n";
        }

        // Proxy authentication
        if (isset($this->proxy) && $this->proxy != null && $this->proxy['proxy_username'] != null) {
            $auth_string = base64_encode($this->proxy['proxy_username'] . ':' . $this->proxy['proxy_password']);
            $headerlines[] = 'Proxy-Authorization: Basic ' . $auth_string . "\r\n";
        }

        $headerlines[] = "Connection: close\r\n";

        // Wenn POST-Request
        if ($request_type === 'POST') {
            // Post-Content bauen
            $post_content = $this->buildPostContent();

            $headerlines[] = "Content-Type: multipart/form-data; boundary=---------------------------10786153015124\r\n";
            $headerlines[] = 'Content-Length: ' . strlen((string)$post_content) . "\r\n\r\n";
            $headerlines[] = $post_content;
        } else {
            $headerlines[] = "\r\n";
        }

        return $headerlines;
    }

    /**
     * Prepares the given HTTP-query-string for the HTTP-request.
     *
     * HTTP-query-strings always should be utf8-encoded and urlencoded afterwards.
     * So "/path/file?test=tat�tata" will be converted to "/path/file?test=tat%C3%BCtata":
     *
     * @param string The quetry-string (like "/path/file?test=tat�tata")
     * @return string
     */
    protected function prepareHTTPRequestQuery($query): string
    {
        // If string already is a valid URL -> do nothing
        if (PHPCrawlerUtils::isValidUrlString($query)) {
            return $query;
        }

        // Decode query-string (for URLs that are partly urlencoded and partly not)
        $query = rawurldecode($query);

        // if query is already utf-8 encoded -> simply urlencode it,
        // otherwise encode it to utf8 first.
        if (PHPCrawlerEncodingUtils::isUTF8String($query) == true) {
            $query = rawurlencode($query);
        } else {
            $query = rawurlencode(utf8_encode($query));
        }

        // Replace url-specific signs back
        $query = str_replace(array('%2F', '%3F', '%3D', '%26'), array('/', '?', '=', '&'), $query);

        return $query;
    }

    /**
     * Builds the post-content from the postdata-array for the header to send with the request (MIME-style)
     *
     * @return array|string
     */
    protected function buildPostContent()
    {
        $post_content = '';

        // Post-Data
        foreach ($this->post_data as $key => $value) {
            $post_content .= "-----------------------------10786153015124\r\n";
            $post_content .= 'Content-Disposition: form-data; name="' . $key . "\"\r\n\r\n";
            $post_content .= $value . "\r\n";
        }

        $post_content .= "-----------------------------10786153015124\r\n";

        return $post_content;
    }

    /**
     * Builds the cookie-header-part for the header to send.
     *
     * @return string  The cookie-header-part, i.e. "Cookie: test=bla; palimm=palaber"
     *                 Returns NULL if no cookies should be send with the header.
     */
    protected function buildCookieHeader(): ?string
    {
        $cookie_string = '';

        foreach ($this->cookie_array as $key => $value) {
            $cookie_string .= '; ' . $key . '=' . $value . '';
        }

        if ($cookie_string != '') {
            return 'Cookie: ' . substr($cookie_string, 2) . "\r\n";
        }

        return null;
    }

    /**
     * Checks whether the content of this page/file should be received (based on the content-type, http-status-code,
     * user-callback and the applied rules)
     *
     * @param PHPCrawlerResponseHeader $responseHeader The response-header as an PHPCrawlerResponseHeader-object
     * @return bool TRUE if the content should be received
     */
    protected function decideRecevieContent(PHPCrawlerResponseHeader $responseHeader): bool
    {
        // Get Content-Type from header
        $content_type = $responseHeader->content_type;

        // Call user header-check-callback-method
        if (isset($this->header_check_callback_function) && $this->header_check_callback_function != null) {
            $ret = call_user_func($this->header_check_callback_function, $responseHeader);
            if ($ret < 0) {
                return false;
            }
        }

        // No Content-Type given
        if ($content_type == null) {
            return false;
        }

        // Status-code not 2xx
        if ($responseHeader->http_status_code == null || $responseHeader->http_status_code > 299 || $responseHeader->http_status_code < 200) {
            return false;
        }

        // Check against the given content-type-rules
        return PHPCrawlerUtils::checkStringAgainstRegexArray($content_type, $this->receive_content_types);
    }

    /**
     * Checks whether the content of this page/file should be streamed directly to file.
     *
     * @param string $response_header The response-header
     * @return bool TRUE if the content should be streamed to TMP-file
     */
    protected function decideStreamToFile($response_header): bool
    {
        if (count($this->receive_to_file_content_types) == 0) {
            return false;
        }

        // Get Content-Type from header
        $content_type = PHPCrawlerUtils::getHeaderValue($response_header, 'content-type');

        // No Content-Type given
        if ($content_type == null) {
            return false;
        }

        // Check against the given rules
        return PHPCrawlerUtils::checkStringAgainstRegexArray($content_type, $this->receive_to_file_content_types);
    }

    /**
     * Adds a rule to the list of rules that decides which pages or files - regarding their content-type - should be received
     *
     * If the content-type of a requested document doesn't match with the given rules, the request will be aborted after the header
     * was received.
     *
     * @param string $regex The rule as a regular-expression
     * @return bool TRUE if the rule was added to the list.
     *              FALSE if the given regex is not valid.
     */
    public function addReceiveContentType($regex): bool
    {
        $check = PHPCrawlerUtils::checkRegexPattern($regex); // Check pattern

        if ($check == true) {
            $this->receive_content_types[] = strtolower(trim($regex));
        }
        return $check;
    }

    /**
     * Adds a rule to the list of rules that decides what types of content should be streamed diretly to the temporary file.
     *
     * If a content-type of a page or file matches with one of these rules, the content will be streamed directly into the temporary file
     * given in setTmpFile() without claiming local RAM.
     *
     * @param string $regex The rule as a regular-expression
     * @return bool         TRUE if the rule was added to the list and the regex is valid.
     */
    public function addStreamToFileContentType($regex): bool
    {
        $check = PHPCrawlerUtils::checkRegexPattern($regex); // Check pattern

        if ($check == true) {
            $this->receive_to_file_content_types[] = trim($regex);
        }
        return $check;
    }

    /**
     * Sets the temporary file to use when content of found documents should be streamed directly into a temporary file.
     *
     * @param string $tmp_file The TMP-file to use.
     * @return bool|null
     */
    public function setTmpFile($tmp_file): ?bool
    {
        //Check if writable
        $fp = fopen($tmp_file, 'wb');

        if ($fp) {
            fclose($fp);
            $this->tmpFile = $tmp_file;
            return true;
        }

        return false;
    }

    /**
     * Sets the size-limit in bytes for content the request should receive.
     *
     * @param int $bytes
     * @return bool
     */
    public function setContentSizeLimit($bytes): ?bool
    {
        if (preg_match('#^[0-9]*$#', $bytes)) {
            $this->content_size_limit = $bytes;
            return true;
        }

        return false;
    }

    /**
     * Returns the global traffic this instance of the HTTPRequest-class caused so far.
     *
     * @return int The traffic in bytes.
     */
    public function getGlobalTrafficCount(): int
    {
        return $this->global_traffic_count;
    }

    /**
     * Adds a rule to the list of rules that decide what kind of documents should get
     * checked for links in (regarding their content-type)
     *
     * @param string $regex Regular-expression defining the rule
     * @return bool         TRUE if the rule was successfully added
     */
    public function addLinkSearchContentType($regex): bool
    {
        $check = PHPCrawlerUtils::checkRegexPattern($regex); // Check pattern
        if ($check == true) {
            $this->linksearch_content_types[] = trim($regex);
        }
        return $check;
    }

    /**
     * Sets the http protocol version to use for requests
     *
     * @param int $http_protocol_version One of the PHPCrawlerHTTPProtocols-constants, or
     *                                   1 -> HTTP 1.0
     *                                   2 -> HTTP 1.1
     * @return bool|null
     */
    public function setHTTPProtocolVersion($http_protocol_version): ?bool
    {
        if (preg_match('#[1-2]#', $http_protocol_version)) {
            $this->http_protocol_version = $http_protocol_version;
            return true;
        }

        return false;
    }

    /**
     * @param $mode
     */
    public function requestGzipContent($mode): void
    {
        if (is_bool($mode)) {
            $this->request_gzip_content = $mode;
        }
    }

    /**
     * Defines the sections of a document that will get ignroed by the internal link-finder.
     *
     * @param int $document_sections Bitwise combination of the {@link PHPCrawlerLinkSearchDocumentSections}-constants.
     */
    public function excludeLinkSearchDocumentSections($document_sections): void
    {
        $this->LinkFinder->excludeLinkSearchDocumentSections($document_sections);
    }

    /**
     * Adjusts some internal buffer-sizes of the HTTPRequest-class
     *
     * @param int $content_buffer_size content_buffer_size in bytes or NULL if not to change this value.
     * @param int $chunk_buffer_size chunk_buffer_size in bytes or NULL if not to change this value.
     * @param int $socket_read_buffer_size socket_read_buffer_sizein bytes or NULL if not to change this value.
     * @param int $source_overlap_size source_overlap_size in bytes or NULL if not to change this value.
     * @throws Exception
     */
    public function setBufferSizes($content_buffer_size = null, $chunk_buffer_size = null, $socket_read_buffer_size = null, $source_overlap_size = null): void
    {
        if ($content_buffer_size !== null) {
            $this->content_buffer_size = $content_buffer_size;
        }

        if ($chunk_buffer_size !== null) {
            $this->chunk_buffer_size = $chunk_buffer_size;
        }

        if ($socket_read_buffer_size !== null) {
            $this->socket_read_buffer_size = $socket_read_buffer_size;
        }

        if ($source_overlap_size !== null) {
            $this->source_overlap_size = $source_overlap_size;
        }

        if ($this->content_buffer_size < $this->chunk_buffer_size || $this->chunk_buffer_size < $this->socket_read_buffer_size) {
            throw new RuntimeException('Implausible buffer-size-settings assigned to ' . get_class($this) . '.');
        }
    }
}
