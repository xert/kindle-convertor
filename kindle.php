<?php

if (isset($_GET['url'])) {
    $url = $_GET['url'];
} elseif (isset($argv[1])) {
    $url = $argv[1];
} else {
    die("Gimme URL!\n");
}

if (!preg_match(',https?://,', $url)) {
    die("Bad URL");
}

define ('ROOT', dirname(__FILE__));

require_once ROOT.'/lib/Readability/Readability.php';
require_once ROOT.'/lib/Url/Url.php';
require_once ROOT.'/lib/SmartDOMDocument/SmartDOMDocument.php';

$html = @file_get_contents($url);

if (!$html) {
    die("Can't read given URL\n");
}

// Note: PHP Readability expects UTF-8 encoded content.
// If your content is not UTF-8 encoded, convert it 
// first before passing it to PHP Readability. 
// Both iconv() and mb_convert_encoding() can do this.
$dom = new SmartDOMDocument();
$dom->loadHTML($html);
$encoding = $dom->encoding;
if (strtoupper($encoding) !== 'UTF-8') {
    $html = iconv($encoding, 'UTF-8', $html);
}

// give it to Readability
$readability = new Readability($html, $url);
// print debug output? 
// useful to compare against Arc90's original JS version - 
// simply click the bookmarklet with FireBug's console window open
$readability->debug = false;
// convert links to footnotes?
$readability->convertLinksToFootnotes = true;
// process it
$result = $readability->init();
// does it look like we found what we wanted?
if ($result) {
    $title = $readability->getTitle()->textContent;
	$content = $readability->getContent()->innerHTML;

    $dom = new SmartDOMDocument();
    $dom->loadHTML($content);

    $imgCounter = 0;
    $images = array();
    foreach ($dom->getElementsByTagName("img") AS $image) {
        $src = $image->getAttribute('src');

        $imgUrl = url_to_absolute($url, $src);
        if (!preg_match(',https?://,', $imgUrl)) {
            die("Bad image URL");
        }

        $tmp = explode('.', $src);
        $ext = array_pop($tmp);

        $file = ++$imgCounter.'.'.$ext;
        $images[] = $file;

        file_put_contents(ROOT.'/tmp/'.$file, file_get_contents($imgUrl));

        $image->setAttribute('src', './'.$file);
    }

    foreach ($dom->getElementsByTagName("a") AS $link) {
        $src = $link->getAttribute('href');
        $aHref = url_to_absolute($url, $src);
        $link->setAttribute('href', $aHref);
    }

    $content = (string)$dom;

	// if we've got Tidy, let's clean it up for output
	if (function_exists('tidy_parse_string')) {
		$tidy = tidy_parse_string($content, array('indent'=>true, 'show-body-only' => true), 'UTF8');
		$tidy->cleanRepair();
		$content = $tidy->value;
	}
    
} else {
    $title   = 'Error :-(';
	$content = 'Looks like we couldn\'t find the content. :(';
}

$html = <<<EOT
<html>
<head>
    <meta http-equiv=Content-Type content="text/html; charset=utf-8">
    <title>$title</title>
</head>
<body>
$content
</body>
</html>
EOT;

file_put_contents(ROOT.'/tmp/content.html', $html);

$opf = file_get_contents(ROOT.'/opf.tpl');

$opf = str_replace('{TITLE}', $title, $opf);
$opf = str_replace('{URL}', $url, $opf);
$opf = str_replace('{DATE}', date('Y-m-d'), $opf);
$opf = str_replace('{ID}', uniqid('xert.kindle.', true), $opf);

file_put_contents(ROOT.'/tmp/article.opf', $opf);
$command = ROOT.'/bin/kindlegen '.ROOT.'/tmp/article.opf';
chdir(ROOT.'/tmp');
exec($command, $output, $status);

$title = iconv("utf-8", "us-ascii//TRANSLIT", $title);
$title = preg_replace('~[^-a-zA-Z0-9_ ]+~', '', $title);
$title = str_replace('  ', ' ', $title);

//rename(ROOT.'/tmp/article.mobi', ROOT.'/'.$title.'.mobi');

unlink(ROOT.'/tmp/content.html');
unlink(ROOT.'/tmp/article.opf');
foreach ($images AS $image) {
    unlink(ROOT.'/tmp/'.$image);
}

$mobi = file_get_contents(ROOT.'/tmp/article.mobi');
unlink(ROOT.'/tmp/article.mobi');

header("Cache-Control: no-cache");
header("Expires: -1");
header('Content-Disposition: attachment; filename="'.$title.'.mobi"');
header('Content-Transfer-Encoding: binary');
header('Content-type: application/x-mobipocket-ebook');
header("Content-Description: File Transfer");
header("Content-Length: " . strlen($mobi));
header("Connection: close");

flush();
echo $mobi;
flush();
exit;
