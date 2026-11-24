<?php

/*
 * Brendy CMS
 * https://brendigo.com
 * Product author and maintainer: Brendigo
*/

// Do not expose the PHP runtime on any response, including early rejections.
if (function_exists('header_remove')) header_remove('X-Powered-By');

// Reject request forms that Brendy never uses before loading plugins, themes or
// mutable storage. This mirrors the Apache rules on Nginx/LiteSpeed/custom stacks.
$requestMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$allowedRequestMethods = array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS');
if (!in_array($requestMethod, $allowedRequestMethods, true)) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $allowedRequestMethods));
    exit('Metoda nije dopuštena.');
}
$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
if (strlen($requestUri) > 8192 || strpos($requestUri, "\0") !== false || preg_match('/[\x00-\x20\x7F]/', $requestUri) ||
    preg_match('/%(?:00|0a|0d)/i', $requestUri)) {
    http_response_code(414);
    exit('Zahtjev je predug ili neispravan.');
}
$requestPath = explode('?', $requestUri, 2)[0];
$decodedPath = rawurldecode($requestPath);
if ($requestPath === '' || $requestPath[0] !== '/' ||
    preg_match('/%(?![0-9A-Fa-f]{2})|%(?:2f|5c)/i', $requestPath) ||
    preg_match('/[\x00-\x1F\x7F]/', $decodedPath) || strpos($decodedPath, chr(92)) !== false ||
    preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath) || preg_match('//u', $decodedPath) !== 1) {
    http_response_code(400);
    header('Cache-Control: no-store');
    exit('Neispravna putanja zahtjeva.');
}
unset($requestPath, $decodedPath);
if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '') {
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'], FILTER_VALIDATE_INT);
    if ($contentLength === false || $contentLength < 0) {
        http_response_code(400);
        exit('Neispravna duljina zahtjeva.');
    }
    if ($contentLength > 134217728) {
        http_response_code(413);
        exit('Zahtjev je prevelik.');
    }
}
unset($requestMethod, $allowedRequestMethods, $requestUri, $contentLength);

define('BRENDY', true);
define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/jezgra/pokretanje/restore-gate.php';
brendyRestoreGate(__DIR__);

// Brendy stores mutable site data in the Croatian `sadrzaj` directory.
// Existing 1.3.x installations are detected read-compatibly so an upgrade never
// hides the current site database or starts a fresh installer by accident.
$contentDirectory = 'sadrzaj';
$legacyContentDirectory = 'brendy-content';
$newSiteDatabase = __DIR__ . '/sadrzaj/databases/site.php';
$legacySiteDatabase = __DIR__ . '/brendy-content/databases/site.php';
foreach (array('sadrzaj', 'brendy-content') as $storageName) {
    $storageRoot = __DIR__ . '/' . $storageName;
    if (is_link($storageRoot) || is_link($storageRoot . '/databases')) {
        http_response_code(500);
        exit('Sigurnosna provjera spremišta sadržaja nije prošla.');
    }
}
unset($storageName, $storageRoot);
$newSitePresent = is_file($newSiteDatabase) && !is_link($newSiteDatabase);
$legacySitePresent = is_file($legacySiteDatabase) && !is_link($legacySiteDatabase) &&
    is_dir(__DIR__ . '/brendy-content') && !is_link(__DIR__ . '/brendy-content');

// Never guess when both the current and legacy locations contain a site
// database. This can happen after an interrupted/incorrect older upgrade and
// silently choosing either copy risks hiding newer content.
if ($newSitePresent && $legacySitePresent) {
    http_response_code(409);
    exit('Pronađene su dvije Brendy baze sadržaja (sadrzaj i brendy-content). Radi zaštite podataka CMS je zaustavljen dok se ne odabere ispravna baza.');
}
if (is_link($newSiteDatabase) || is_link($legacySiteDatabase)) {
    http_response_code(500);
    exit('Sigurnosna provjera nije prošla: baza sadržaja ne smije biti simbolička poveznica.');
}
if (!$newSitePresent && $legacySitePresent) {
	$contentDirectory = $legacyContentDirectory;
}

define('BRENDY_CONTENT_DIRECTORY', $contentDirectory);
unset($contentDirectory, $legacyContentDirectory, $newSiteDatabase, $legacySiteDatabase, $newSitePresent, $legacySitePresent);

// Check if Brendy is installed.
$siteDatabase = __DIR__ . '/' . BRENDY_CONTENT_DIRECTORY . '/databases/site.php';
if (!is_file($siteDatabase) || is_link($siteDatabase)) {
	$scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
		? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
	if ($scriptName === '' || $scriptName[0] !== '/' || strlen($scriptName) > 4096 ||
		preg_match('/[\x00-\x1F\x7F]/', $scriptName)) {
		$scriptName = '/index.php';
	}
	$base = dirname($scriptName);
	$base = ($base === '/' || $base === '.' || $base === '\\') ? '' : '/' . trim($base, '/');
	header('Location: ' . $base . '/install.php', true, 302);
	exit('<a href="./install.php">Najprije instalirajte Brendy CMS.</a>');
}
unset($siteDatabase);

// Runtime preflight for an already installed site. Shared-hosting control panels
// can change the active PHP version/extensions after installation; fail with a
// deterministic diagnostic before the core reaches a missing function/class.
$runtimeErrors = array();
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
	$runtimeErrors[] = 'PHP 8.2 ili noviji';
}
foreach (array('mbstring', 'json', 'gd', 'dom', 'session', 'fileinfo') as $runtimeModule) {
	if (!extension_loaded($runtimeModule)) $runtimeErrors[] = 'PHP modul ' . $runtimeModule;
}
if (!empty($runtimeErrors)) {
	http_response_code(500);
	header_remove('X-Powered-By');
	if (!headers_sent()) {
		header('Content-Type: text/plain; charset=UTF-8');
		header('Cache-Control: no-store, max-age=0');
		header('X-Content-Type-Options: nosniff');
	}
	exit("Brendy CMS se ne može sigurno pokrenuti. Nedostaje: " . implode(', ', $runtimeErrors) . ". Omogućite zahtjev na hostingu i ponovno učitajte stranicu.");
}
unset($runtimeErrors, $runtimeModule);

// Load time init
$loadTime = microtime(true);

// Product guard. Brendy core and extensions use only Brendy identifiers.


// Directory separator


// PHP paths for init
define('PATH_ROOT', __DIR__.DS);
define('PATH_BOOT', PATH_ROOT.'jezgra'.DS.'pokretanje'.DS);

// Init
require(PATH_BOOT.'init.php');

// Admin area
if ($url->whereAmI()==='admin') {
	require(PATH_BOOT.'admin.php');
}
// Site
else {
	require(PATH_BOOT.'site.php');
}
