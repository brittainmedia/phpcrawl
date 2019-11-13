<?php

use PHPCrawl\PHPCrawler;
use PHPCrawl\PHPCrawlerDocumentInfo;

/**
 * Class MyCrawler
 */
class MyCrawler extends PHPCrawler
{
    /**
     * @param PHPCrawlerDocumentInfo $DocInfo
     * @return int|void
     */
    public function handleDocumentInfo($DocInfo)
    {
        // Just detect linebreak for output ("\n" in CLI-mode, otherwise "<br>")..
        if (PHP_SAPI === 'cli') {
            $lb = "\n";
        } else {
            $lb = "<br />";
        }


        // Print the URL and the HTTP-status-Code
        echo 'Page requested: ' . $DocInfo->url . ' (' . $DocInfo->http_status_code . ')' . $lb;
        // Print the refering URL
        echo 'Referer-page: ' . $DocInfo->referer_url . $lb;
        // Print if the content of the document was be recieved or not
        if ($DocInfo->received == true) {
            echo "Content received: " . $DocInfo->bytes_received . " bytes" . $lb;
        } else {
            echo "Content not received" . $lb;
        }

        echo 'Error: ' . var_export($DocInfo->error_string, TRUE);

        // Now you should do something with the content of the actual
        // received page or file ($DocInfo->source), we skip it in this example
        echo $lb;
        flush();
    }
}