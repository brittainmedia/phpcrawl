<?php
include PATH_SCRIPT . 'MyCrawler.php';

// Now, create a instance of your class, define the behaviour
// of the crawler (see class-reference for more options and details)
// and start the crawling-process.
$crawler = new MyCrawler();

// URL to crawl
$crawler->setURL('https://example.com/');

// Only receive content of files with content-type "text/html"
//$crawler->addContentTypeReceiveRule('#text/html#');
// Ignore links to pictures, dont even request pictures
#$crawler->addURLFilterRule("#\.(jpg|jpeg|gif|png)$# i");
// Store and send cookie-data like a browser does
$crawler->enableCookieHandling(true);

// SSL handling
$passPhrase = '';
$certificateData = [
	"countryName" => "GB",
	"stateOrProvinceName" => "Somerset",
	"localityName" => "Glastonbury",
	"organizationName" => "The Brain Room Limited",
	"organizationalUnitName" => "PHP Documentation Team",
	"commonName" => "Wez Furlong",
	"emailAddress" => "wez@example.com"
];
$crawler->createPEMCertificate($passPhrase, $certificateData);

// Set the traffic-limit to 1 MB (in bytes,
// for testing we dont want to "suck" the whole site)
$crawler->setTrafficLimit(1000 * 1024);

// Thats enough, now here we go
$crawler->go();


// At the end, after the process is finished, we print a short
// report (see method getProcessReport() for more information)
$report = $crawler->getProcessReport();
if (PHP_SAPI === 'cli') {
	$lb = "\n";
} else {
	$lb = "<br />";
}
echo 'Summary:' . $lb;
echo 'Abort: ' . $report->abort_reason . $lb;
echo 'Links followed: ' . $report->links_followed . $lb;
echo 'Documents received: ' . $report->files_received . $lb;
echo 'Bytes received: ' . $report->bytes_received . ' bytes' . $lb;
echo 'Process runtime: ' . $report->process_runtime . ' sec' . $lb;