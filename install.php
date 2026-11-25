<?php

/*
 * Brendy CMS
 * https://brendigo.com
 * Product author and maintainer: Brendigo
*/

// Keep the installer surface deliberately small. Once the application is
// running, API-specific PUT/DELETE traffic is handled by index.php; the
// installer itself never needs methods other than GET and POST.
$installMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
	? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
if (!in_array($installMethod, array('GET', 'POST'), true)) {
	header('Allow: GET, POST');
	http_response_code(405);
	exit('Method Not Allowed');
}
$installRequestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/install.php';
if (strlen($installRequestUri) > 8192 || strpos($installRequestUri, "\0") !== false ||
	preg_match('/[\x00-\x20\x7F]/', $installRequestUri) === 1 || preg_match('/%(?:00|0a|0d)/i', $installRequestUri) === 1) {
	http_response_code(400);
	exit('Bad Request');
}
$installContentLength = isset($_SERVER['CONTENT_LENGTH']) && is_scalar($_SERVER['CONTENT_LENGTH'])
	? filter_var($_SERVER['CONTENT_LENGTH'], FILTER_VALIDATE_INT) : false;
if ($installMethod === 'POST' && $installContentLength !== false && ($installContentLength < 0 || $installContentLength > 1048576)) {
	http_response_code(413);
	exit('Payload Too Large');
}

// Check PHP version
if (version_compare(phpversion(), '8.2.0', '<')) {
	$errorText = 'Trenutačna PHP verzija je ' . phpversion() . '; potrebna je verzija 8.2 ili novija.';
	error_log('[ERROR] ' . $errorText, 0);
	exit($errorText);
}

define('BRENDY', true);
define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/jezgra/pokretanje/restore-gate.php';
brendyRestoreGate(__DIR__);

$installCspNonce = bin2hex(random_bytes(24));
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// Chromium may serialize the Origin of a form POST as null under no-referrer.
// Keep same-origin form provenance available without disclosing URLs externally.
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Origin-Agent-Cluster: ?1');
header('X-Permitted-Cross-Domain-Policies: none');
header('X-Download-Options: noopen');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $installCspNonce . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

// Fail closed before any installer state is created. Brendy 1.3.x stored its
// database under brendy-content while new installations use sadrzaj. Opening
// install.php on an upgraded site must never create a second, empty site that
// hides the existing installation.
function brendyInstallMarkers()
{
    return array(
        __DIR__ . DIRECTORY_SEPARATOR . 'sadrzaj' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'site.php',
        __DIR__ . DIRECTORY_SEPARATOR . 'brendy-content' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'site.php'
    );
}

function brendyHasInstallMarker()
{
    foreach (brendyInstallMarkers() as $marker) {
        if (file_exists($marker) || is_link($marker) || is_link(dirname($marker)) || is_link(dirname($marker, 2))) return true;
    }
    return false;
}

if (brendyHasInstallMarker()) {
    // Do not expose installation state or CMS-specific details after setup.
    // The installer remains fail-closed even when the web server cannot be
    // configured to remove install.php from the public document root.
    http_response_code(404);
    exit('Not Found');
}

// Check PHP modules
$modulesRequired = array('mbstring', 'json', 'gd', 'dom', 'session', 'fileinfo');
$modulesRequiredMissing = array();
foreach ($modulesRequired as $module) {
	if (!extension_loaded($module)) {
        $modulesRequiredMissing[] = $module;
		error_log('[ERROR] Required PHP module is missing: ' . $module, 0);
	}
}
if (!empty($modulesRequiredMissing)) {
    http_response_code(500);
    echo '<!doctype html><html lang="hr"><meta charset="utf-8"><title>Brendy CMS · Nedostaju PHP moduli</title><body>';
	echo '<h1>Nedostaju obvezni PHP moduli</h1><ul>';
    foreach ($modulesRequiredMissing as $module) {
        echo '<li><strong>' . htmlspecialchars($module, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></li>';
    }
    echo '</ul><p>Omogućite navedene module na hostingu i ponovno otvorite instalaciju.</p>';
	echo '<p><a href="https://brendigo.com" target="_blank" rel="noopener noreferrer">Podrška i informacije: brendigo.com</a></p>';
    echo '</body></html>';
	exit;
}
unset($modulesRequiredMissing, $modulesRequired, $module);

// Brendy product guard.


// Directory separator


// Brendy uses one native content directory.
define('BRENDY_CONTENT_DIRECTORY', 'sadrzaj');

// PHP paths
define('PATH_ROOT',		__DIR__ . DS);
define('PATH_CONTENT',		PATH_ROOT . BRENDY_CONTENT_DIRECTORY . DS);
define('PATH_KERNEL',		PATH_ROOT . 'jezgra' . DS);
require_once(PATH_KERNEL . 'release.php');
define('PATH_LANGUAGES',	PATH_ROOT . 'jezici' . DS);
define('PATH_UPLOADS',		PATH_CONTENT . 'uploads' . DS);
define('PATH_TMP',		PATH_CONTENT . 'tmp' . DS);
define('PATH_PAGES',		PATH_CONTENT . 'pages' . DS);
define('PATH_WORKSPACES',	PATH_CONTENT . 'workspaces' . DS);
define('PATH_REVISIONS',	PATH_CONTENT . 'revisions' . DS);
define('PATH_DATABASES',	PATH_CONTENT . 'databases' . DS);
define('PATH_PLUGINS_DATABASES', PATH_CONTENT . 'databases' . DS . 'plugins' . DS);
define('DB_CORE_INTEGRITY', PATH_DATABASES . 'core-integrity.php');
define('PATH_UPLOADS_PROFILES',	PATH_UPLOADS . 'profiles' . DS);
define('PATH_UPLOADS_THUMBNAILS', PATH_UPLOADS . 'thumbnails' . DS);
define('PATH_UPLOADS_PAGES',	PATH_UPLOADS . 'pages' . DS);
define('PATH_HELPERS',		PATH_KERNEL . 'pomocnici' . DS);
define('PATH_ABSTRACT',		PATH_KERNEL . 'apstraktno' . DS);

// Protecting against Symlink attacks
define('CHECK_SYMBOLIC_LINKS', TRUE);

// Filename for pages
define('FILENAME', 'index.txt');

// Domain and protocol. HTTP_HOST is client-controlled and must not be persisted
// as the canonical URL. Use the web-server configured name instead.
$httpsValue = isset($_SERVER['HTTPS']) ? strtolower((string)$_SERVER['HTTPS']) : '';
$isHttps = ($httpsValue !== '' && $httpsValue !== 'off' && $httpsValue !== '0') ||
	(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
if ($isHttps) header('Strict-Transport-Security: max-age=63072000');
$protocol = $isHttps ? 'https://' : 'http://';
$serverName = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) ? trim($_SERVER['SERVER_NAME']) : '';
$hostParts = preg_match('/[\x00-\x20\x7F,\/\\\\]/', $serverName) ? false : parse_url('http://' . $serverName);
$host = is_array($hostParts) && isset($hostParts['host']) ? trim($hostParts['host'], '[]') : '';
if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) &&
	preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host) !== 1)) {
	http_response_code(500);
	exit('Web-poslužitelj ne pruža valjan SERVER_NAME. Ispravite konfiguraciju prije instalacije.');
}
$domain = strpos($host, ':') !== false ? '[' . $host . ']' : strtolower($host);
$serverPort = isset($_SERVER['SERVER_PORT']) && is_scalar($_SERVER['SERVER_PORT'])
	? filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT) : false;
$defaultPort = $isHttps ? 443 : 80;
if ($serverPort !== false && $serverPort >= 1 && $serverPort <= 65535 && $serverPort !== $defaultPort) {
	$domain .= ':' . $serverPort;
}
define('DOMAIN', $domain);
define('DOMAIN_NAME', $host);
define('PROTOCOL', $protocol);

// Base URL
// Change the base URL or leave it empty when Brendy should detect it.
$scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
	? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/install.php';
if ($scriptName === '' || $scriptName[0] !== '/' || strlen($scriptName) > 4096 ||
	preg_match('/[\x00-\x1F\x7F]/', $scriptName)) $scriptName = '/install.php';
$baseDirectory = dirname($scriptName);
$base = ($baseDirectory === '/' || $baseDirectory === '.' || $baseDirectory === '\\')
	? '/' : '/' . trim($baseDirectory, '/') . '/';
$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$basePrefix = rtrim($base, '/');
if ($basePrefix !== '' && $requestUri !== $basePrefix && strpos($requestUri, $basePrefix . '/') !== 0 &&
	strpos($requestUri, $basePrefix . '?') !== 0) $base = '/';

define('HTML_PATH_ROOT', $base);

// A first-install request changes the entire application state, so protect it
// against cross-site requests just like the regular administration area.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name(PROTOCOL === 'https://' ? '__Secure-BRENDY_INSTALL' : 'BRENDY_INSTALL');
session_set_cookie_params(array(
	'lifetime' => 0,
	'path' => HTML_PATH_ROOT,
	'domain' => '',
	'secure' => PROTOCOL === 'https://',
	'httponly' => true,
	'samesite' => 'Strict'
));
// The installer changes the complete application state. Do not silently run it
// with weaker PHP session settings when hosting policy refuses strict cookies.
$installerSessionIni = array(
	'session.use_strict_mode' => '1',
	'session.use_only_cookies' => '1',
	'session.use_trans_sid' => '0'
);
foreach ($installerSessionIni as $key => $expected) {
	if ((string)ini_get($key) !== $expected) {
		http_response_code(500);
		exit('PHP session sigurnosna pravila nije moguće primijeniti.');
	}
}
$installerCookie = session_get_cookie_params();
$installerSecure = PROTOCOL === 'https://';
if (!is_array($installerCookie) || empty($installerCookie['httponly']) ||
	!isset($installerCookie['samesite']) || strcasecmp((string)$installerCookie['samesite'], 'Strict') !== 0 ||
	(bool)($installerCookie['secure'] ?? false) !== $installerSecure ||
	!isset($installerCookie['path']) || (string)$installerCookie['path'] !== HTML_PATH_ROOT ||
	(isset($installerCookie['domain']) && (string)$installerCookie['domain'] !== '')) {
	http_response_code(500);
	exit('PHP session cookie sigurnosna pravila nije moguće primijeniti.');
}
unset($installerSessionIni, $installerCookie, $installerSecure, $key, $expected);
if (!session_start()) {
	http_response_code(500);
	exit('Nije moguće pokrenuti instalacijsku sesiju.');
}
if (empty($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
	$_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

function brendyInstallerPasswordPolicy($password, $email = '')
{
	if (!is_string($password) || preg_match('//u', $password) !== 1) return false;
	$length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
	if ($length < 12 || strlen($password) > 72 || preg_match('/[\x00-\x1F\x7F]/', $password)) return false;
	$lower = function_exists('mb_strtolower') ? mb_strtolower($password, 'UTF-8') : strtolower($password);
	$compact = preg_replace('/[^\pL\pN]+/u', '', $lower);
	$common = array('password', 'password123', 'password1234', '123456789012', '1234567890',
		'qwerty123456', 'qwertyuiop123', 'administrator', 'adminadmin123', 'letmein123456',
		'welcome123456', 'changeme123456', 'brendy123456', 'brendigo123456');
	if (is_string($compact) && in_array($compact, $common, true)) return false;
	$parts = array('admin');
	if (is_string($email) && strpos($email, '@') !== false) $parts[] = explode('@', strtolower($email), 2)[0];
	foreach ($parts as $identity) {
		$identity = preg_replace('/[^\pL\pN]+/u', '', (string)$identity);
		if (is_string($compact) && is_string($identity) && strlen($identity) >= 4 && strpos($compact, $identity) !== false) return false;
	}
	if ($length < 16) {
		$classes = (int)(bool)preg_match('/\p{Ll}/u', $password) + (int)(bool)preg_match('/\p{Lu}/u', $password) +
			(int)(bool)preg_match('/\pN/u', $password) + (int)(bool)preg_match('/[^\pL\pN\s]/u', $password);
		if ($classes < 3) return false;
	}
	return true;
}

function brendyInstallerPasswordHash($password)
{
	try {
		if (defined('PASSWORD_ARGON2ID')) {
			$hash = password_hash($password, PASSWORD_ARGON2ID, array('memory_cost'=>19456, 'time_cost'=>2, 'threads'=>1));
			if (is_string($hash) && $hash !== '') return $hash;
		}
		return password_hash($password, PASSWORD_DEFAULT);
	} catch (Throwable $e) {
		try { return password_hash($password, PASSWORD_DEFAULT); } catch (Throwable $fallback) { return false; }
	}
}

function brendyInstallerSameOriginRequest()
{
	if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])) {
		$fetchSite = strtolower(trim($_SERVER['HTTP_SEC_FETCH_SITE']));
		if ($fetchSite !== '' && !in_array($fetchSite, array('same-origin', 'none'), true)) return false;
	}
	$source = '';
	if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') $source = $_SERVER['HTTP_ORIGIN'];
	elseif (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') $source = $_SERVER['HTTP_REFERER'];
	if ($source === '') return true;
	if (strlen($source) > 4096 || preg_match('/[\x00-\x20\x7F]/', $source)) return false;
	$parts = parse_url($source);
	if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
	$scheme = strtolower((string)$parts['scheme']);
	$host = strtolower(trim((string)$parts['host'], '[]'));
	$port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
	$expectedScheme = PROTOCOL === 'https://' ? 'https' : 'http';
	$expectedPort = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : ($expectedScheme === 'https' ? 443 : 80);
	return $scheme === $expectedScheme && hash_equals(strtolower(DOMAIN_NAME), $host) && $port === $expectedPort;
}

// Log separator
define('LOG_SEP', ' | ');

// Database format date
define('DB_DATE_FORMAT', 'Y-m-d H:i:s');

// Charset, default UTF-8.
define('CHARSET', 'UTF-8');

// Default language file
define('DEFAULT_LANGUAGE_FILE', 'hr_HR.json');

// Set internal character encoding
mb_internal_encoding(CHARSET);

// Set HTTP output character encoding
mb_http_output(CHARSET);

// Directory permissions
define('DIR_PERMISSIONS', 0755);

// --- PHP Classes ---
include(PATH_ABSTRACT . 'brendy-json-database.class.php');
include(PATH_HELPERS . 'sanitize.class.php');
include(PATH_HELPERS . 'valid.class.php');
include(PATH_HELPERS . 'text.class.php');
include(PATH_HELPERS . 'log.class.php');
require_once(PATH_HELPERS . 'filesystem.class.php');
include(PATH_HELPERS . 'date.class.php');
include(PATH_HELPERS . 'core-integrity.class.php');
include(PATH_KERNEL . 'language.class.php');

// --- LANGUAGE and LOCALE ---
// Croatian is the explicit product default. Another language is used only
// after the visitor selects it on the installation screen.
$languageFromHTTP = 'hr_HR';
$localeFromHTTP = 'hr_HR';

$languageRequested = isset($_GET['language']) && is_string($_GET['language']) && strlen($_GET['language']) <= 20;
if ($languageRequested) {
	$languageFromHTTP = trim($_GET['language']);
}

$finalLanguage = 'hr_HR';
$languageFiles = getLanguageList();
// Skip a redundant language chooser when the package contains only one language.
if (count($languageFiles) === 1) {
	$languageRequested = true;
}
foreach ($languageFiles as $fname => $native) {
	if ($languageFromHTTP === $fname) {
		$finalLanguage = $fname;
		break;
	}
}

$L = $language = new Language($finalLanguage);
$localeFromHTTP = $L->locale();

// Try each locale advertised by the language pack. Hosts often expose only
// one of the common UTF-8 aliases, so a comma-separated string cannot be
// passed to setlocale() as a single locale name.
foreach (array_filter(array_map('trim', explode(',', $localeFromHTTP))) as $localeCandidate) {
	if (setlocale(LC_ALL, $localeCandidate) !== false) {
		break;
	}
}

// --- TIMEZONE ---

// Check if timezone is defined in php.ini
$iniDate = ini_get('date.timezone');
if (empty($iniDate)) {
	// Croatian product default when php.ini has no timezone.
	date_default_timezone_set('Europe/Zagreb');
}

// ============================================================================
// FUNCTIONS
// ============================================================================

// Returns an array with all languages
function getLanguageList()
{
	$files = glob(PATH_LANGUAGES . '*.json');
	$tmp = array();
	if (!is_array($files)) return $tmp;
	foreach (array_slice($files, 0, 1000) as $file) {
		if (!is_file($file) || is_link($file)) continue;
		$size = @filesize($file);
		if (!is_int($size) || $size < 2 || $size > 2097152) continue;
		$t = new BrendyJsonDatabase($file, false, 2097152);
		if (!isset($t->db['language-data']['native']) || !is_string($t->db['language-data']['native'])) continue;
		$native = mb_substr($t->db['language-data']['native'], 0, 200, CHARSET);
		$locale = basename($file, '.json');
		if (preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})?$/D', $locale) !== 1) continue;
		$tmp[$locale] = $native;
	}

	return $tmp;
}

// Check if Brendy is installed
function alreadyInstalled()
{
    return brendyHasInstallMarker();
}

function createDirectory($path)
{
	if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || is_link($path)) return false;
	if (is_dir($path)) return true;
	if (file_exists($path)) return false;
	return @mkdir($path, DIR_PERMISSIONS, true);
}

function writeFileAtomic($path, $data, $permissions = 0600)
{
	if (!is_string($path) || !is_string($data) || $path === '' || strpos($path, "\0") !== false ||
		!is_int($permissions) || $permissions < 0 || $permissions > 0777 ||
		is_link($path) || (file_exists($path) && !is_file($path))) return false;
	$directory = dirname($path);
	if (!createDirectory($directory) || is_link($directory)) return false;
	$realDirectory = realpath($directory);
	$basename = basename($path);
	if (!is_string($realDirectory) || !is_dir($realDirectory) || is_link($realDirectory) ||
		$basename === '' || $basename === '.' || $basename === '..' || strpos($basename, "\0") !== false) return false;
	$destination = $realDirectory . DIRECTORY_SEPARATOR . $basename;
	if (is_link($destination) || (file_exists($destination) && !is_file($destination))) return false;

	// Installer writes use the same core invariant as runtime storage: stage in
	// the destination's real directory, sync fully, then publish with one atomic
	// rename. The random exclusive filename prevents clobbering a concurrent run.
	try {
		$temporary = $realDirectory . DIRECTORY_SEPARATOR . '.brendy-install-' . bin2hex(random_bytes(12));
	} catch (Throwable $e) {
		return false;
	}
	$handle = @fopen($temporary, 'xb');
	$written = $handle === false ? false : 0;
	$dataLength = strlen($data);
	while (is_resource($handle) && $written < $dataLength) {
		$chunk = @fwrite($handle, substr($data, $written));
		if ($chunk === false || $chunk === 0) {
			$written = false;
			break;
		}
		$written += $chunk;
	}
	$flushed = false;
	$synced = true;
	if (is_resource($handle)) {
		$flushed = @fflush($handle);
		if ($flushed && function_exists('fsync')) $synced = @fsync($handle);
		@fclose($handle);
	}
	if ($written !== $dataLength || !$flushed || !$synced || !is_file($temporary) || is_link($temporary)) {
		if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
		return false;
	}
	@chmod($temporary, $permissions);
	if (!@rename($temporary, $destination)) {
		@unlink($temporary);
		return false;
	}
	return is_file($destination) && !is_link($destination);
}

function writeJsonDatabaseAtomic($path, $data, $dataHead, $permissions = 0600)
{
	if (!is_string($path) || !is_array($data) || !is_string($dataHead)) return false;
	try {
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		error_log('[ERROR] Neuspjelo JSON kodiranje instalacijske baze: ' . basename($path), 0);
		return false;
	}
	return writeFileAtomic($path, $dataHead . $json, $permissions);
}

function protectContentDirectories()
{
	$denyAll = '<IfModule mod_authz_core.c>' . PHP_EOL .
		'Require all denied' . PHP_EOL . '</IfModule>' . PHP_EOL .
		'<IfModule !mod_authz_core.c>' . PHP_EOL . 'Order allow,deny' . PHP_EOL .
		'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL;
	foreach (array(PATH_DATABASES, PATH_PAGES, PATH_TMP, PATH_WORKSPACES, PATH_REVISIONS) as $directory) {
		if (!createDirectory($directory) || !writeFileAtomic($directory . '.htaccess', $denyAll, 0644)) {
			return false;
		}
	}

	$uploads = '<IfModule mod_mime.c>' . PHP_EOL .
		'RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar .phps .inc .module .install .cgi .fcgi .pl .py .sh .shtml .html .htm .svg .svgz' . PHP_EOL .
		'RemoveType .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar .phps .inc .module .install .cgi .fcgi .pl .py .sh .shtml .html .htm .svg .svgz' . PHP_EOL .
		'</IfModule>' . PHP_EOL .
		'<FilesMatch "(?i)\\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh|shtml|svgz?|html?)(?:\\.|$)">' . PHP_EOL .
		'<IfModule mod_authz_core.c>' . PHP_EOL . 'Require all denied' . PHP_EOL . '</IfModule>' . PHP_EOL .
		'<IfModule !mod_authz_core.c>' . PHP_EOL . 'Order allow,deny' . PHP_EOL .
		'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL . '</FilesMatch>' . PHP_EOL .
		'<IfModule mod_headers.c>' . PHP_EOL .
		'Header always set X-Content-Type-Options "nosniff"' . PHP_EOL .
		'Header always set Cache-Control "public, max-age=300, must-revalidate"' . PHP_EOL .
		'Header always set Cross-Origin-Resource-Policy "same-site"' . PHP_EOL .
		'</IfModule>' . PHP_EOL;
	return createDirectory(PATH_UPLOADS) && writeFileAtomic(PATH_UPLOADS . '.htaccess', $uploads, 0644);
}

// Check write permissions and .htaccess file
function checkSystem()
{
	$output = array();

	// Try to create .htaccess
	$htaccessContent = 'AddDefaultCharset UTF-8

<IfModule mod_autoindex.c>
Options -Indexes
</IfModule>

<IfModule mod_negotiation.c>
Options -MultiViews
</IfModule>

<IfModule mod_headers.c>
Header always unset X-Powered-By
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set X-Permitted-Cross-Domain-Policies "none"
Header always set X-Download-Options "noopen"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()"
</IfModule>

<FilesMatch "(?i)(^\\.|(?:^|/)(?:php\\.ini|web\\.config)$|composer\\.(json|lock)$|package(-lock)?\\.json$|(?:SECURITY|UPGRADE|SIGURNOST|NADOGRADNJA|PROMJENE|STRUKTURA|CONTRIBUTING|DEPLOYMENT|README|API|RAZVOJ-TEMA-I-DODATAKA|AUDIT)\\.md$|BRENDY_BUILD\\.txt$|DEPENDENCIES\\.json$|SHA256SUMS\\.txt$)">
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Order allow,deny
Deny from all
</IfModule>
</FilesMatch>

<IfModule mod_rewrite.c>

# Enable rewrite rules
RewriteEngine on

# TRACE/TRACK/CONNECT are not used by Brendy.
RewriteCond %{REQUEST_METHOD} ^(?:TRACE|TRACK|CONNECT)$ [NC]
RewriteRule ^ - [R=405,L]

# Base directory
RewriteBase ' . HTML_PATH_ROOT . '

# Hide dot-paths such as .git/.env while preserving ACME and other standard .well-known endpoints.
RewriteRule (^|/)\.(?!well-known(?:/|$)) - [R=404,L,NC]

# Deny direct access to private Brendy data.
RewriteRule ^(?:sadrzaj|brendy-content)(?:$|/(?!uploads(?:/|$))) - [R=404,L,NC]

# Never execute files restored or uploaded below Brendy content.
RewriteRule ^(?:sadrzaj|brendy-content)/.*\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh|shtml|svgz?|html?)(?:\.|/|$) - [R=404,L,NC]

# PHP files in internal code directories are loaded through the front
# controller and must not be invoked directly.
RewriteRule ^(?:jezgra|dodaci|teme)/.*\\.(?:php[0-9]*|pht|phtml|phar|inc|module|install)(?:\\.|/|$) - [R=404,L,NC]

# Only the two public PHP entrypoints may execute over HTTP.
RewriteRule ^(?!(?:index|install)\\.php$).*\\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh)(?:\\.|/|$) - [R=404,L,NC]

# Developer tests are CLI-only and must never be reachable over HTTP.
RewriteRule ^tests(?:/|$) - [R=404,L,NC]
RewriteRule ^(?:docs|LICENSES)(?:/|$) - [R=404,L,NC]
RewriteRule ^(?:LICENSE|EULA\.md|THIRD-PARTY-NOTICES\.md)$ - [R=404,L,NC]


# All URL process by index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [PT,L]

</IfModule>

<IfModule mod_expires.c>
ExpiresActive On
ExpiresByType text/css "access plus 1 day"
ExpiresByType application/javascript "access plus 1 day"
ExpiresByType image/png "access plus 1 day"
ExpiresByType image/jpeg "access plus 1 day"
ExpiresByType image/webp "access plus 1 day"
ExpiresByType image/svg+xml "access plus 1 day"
ExpiresByType font/woff2 "access plus 1 day"
</IfModule>

<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json application/xml image/svg+xml
</IfModule>';

	$rootHtaccess = PATH_ROOT . '.htaccess';
	if (is_link($rootHtaccess) || (!file_exists($rootHtaccess) && !writeFileAtomic($rootHtaccess, $htaccessContent, 0644))) {
		if (!empty($_SERVER['SERVER_SOFTWARE'])) {
			$webserver = Text::lowercase($_SERVER['SERVER_SOFTWARE']);
			if (Text::stringContains($webserver, 'apache') || Text::stringContains($webserver, 'litespeed')) {
				$errorText = 'Nedostaje datoteka .htaccess; prenesite je u korijensku mapu.';
				error_log('[ERROR] ' . $errorText, 0);
				array_push($output, $errorText);
			}
		}
	}

	// Check mod_rewrite module
	if (function_exists('apache_get_modules')) {
		if (!in_array('mod_rewrite', apache_get_modules())) {
			$errorText = 'Modul mod_rewrite nije instaliran ili učitan.';
			error_log('[ERROR] ' . $errorText, 0);
			array_push($output, $errorText);
		}
	}

	// Try to create the directory content
	@mkdir(PATH_CONTENT, DIR_PERMISSIONS, true);

	// Check if the directory content is writeable.
	if (!is_writable(PATH_CONTENT)) {
		$errorText = 'Provjera zapisivanja nije uspjela; provjerite dozvole mape "sadrzaj".';
		error_log('[ERROR] ' . $errorText, 0);
		array_push($output, $errorText);
	}

	return $output;
}

// Install Brendy CMS
function install($adminPassword, $timezone, $adminEmail)
{
	global $L;
	if (!is_string($adminPassword) || !brendyInstallerPasswordPolicy($adminPassword, $adminEmail) ||
		!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true) ||
		!is_string($adminEmail) || strlen($adminEmail) > 254 || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
		return false;
	}

	if (!date_default_timezone_set($timezone)) {
		return false;
	}

	$currentDate = Date::current(DB_DATE_FORMAT);

	// ============================================================================
	// Create directories
	// ============================================================================

	// Directories for initial pages
	$pagesToInstall = array('example-page-1-slug', 'example-page-2-slug', 'example-page-3-slug', 'example-page-4-slug');
	$pageSlugs = array();
	foreach ($pagesToInstall as $page) {
		$translatedSlug = $L->get($page);
		if (!is_string($translatedSlug) || preg_match('/^[\pL\pN][\pL\pN._~-]{0,100}$/uD', $translatedSlug) !== 1 ||
			in_array($translatedSlug, $pageSlugs, true) || !createDirectory(PATH_PAGES . $translatedSlug)) {
			$errorText = 'Nije moguće izraditi sigurnu mapu početne stranice.';
			error_log('[ERROR] ' . $errorText, 0);
			return false;
		}
		$pageSlugs[$page] = $translatedSlug;
	}

	// Directories for initial plugins
	$pluginsToInstall = array('search', 'sitemap');
	foreach ($pluginsToInstall as $plugin) {
		if (!createDirectory(PATH_PLUGINS_DATABASES . $plugin)) {
			$errorText = 'Nije moguće izraditi mapu: ' . PATH_PLUGINS_DATABASES . $plugin;
			error_log('[ERROR] ' . $errorText, 0);
			return false;
		}
	}

	// Directories for upload files
	if (!createDirectory(PATH_UPLOADS_PROFILES)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_UPLOADS_PROFILES;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!createDirectory(PATH_UPLOADS_THUMBNAILS)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_UPLOADS_THUMBNAILS;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!createDirectory(PATH_TMP)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_TMP;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!createDirectory(PATH_WORKSPACES)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_WORKSPACES;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!createDirectory(PATH_REVISIONS)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_REVISIONS;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!createDirectory(PATH_UPLOADS_PAGES)) {
		$errorText = 'Nije moguće izraditi mapu: ' . PATH_UPLOADS_PAGES;
		error_log('[ERROR] ' . $errorText, 0);
		return false;
	}

	if (!protectContentDirectories()) {
		error_log('[ERROR] Nije moguće izraditi datoteke za kontrolu pristupa sadržaju.', 0);
		return false;
	}

	// ============================================================================
	// Create files
	// ============================================================================

	$dataHead = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>" . PHP_EOL;

	$data = array();
	$nextDate = $currentDate;
	foreach ($pagesToInstall as $page) {

		$slug = $page;
		$title = Text::replace('slug', 'title', $slug);
		$content = Text::replace('slug', 'content', $slug);
		$nextDate = Date::offset($nextDate, DB_DATE_FORMAT, '-1 minute');
		if (!is_string($nextDate) || $nextDate === '') return false;
		$contentText = $L->get($content);
		if (!is_string($contentText) || !writeFileAtomic(PATH_PAGES . $pageSlugs[$slug] . DS . FILENAME, $contentText, 0644)) {
			return false;
		}

		$data[$pageSlugs[$slug]] = array(
			'title' => Sanitize::html($L->get($title)),
			'description' => '',
			'username' => 'admin',
			'tags' => array(),
			'type' => (($slug === 'example-page-4-slug') ? 'static' : 'published'),
			'date' => $nextDate,
			'dateModified' => '',
			'allowComments' => true,
			'position' => 1,
			'coverImage' => '',
			'md5file' => md5($contentText),
			'contentHash' => hash('sha256', $contentText),
			'category' => 'general',
			'uuid' => bin2hex(random_bytes(16)),
			'template' => '',
			'noindex' => false,
			'nofollow' => false,
			'noarchive' => false,
			'custom' => array()
		);
	}
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'pages.php', $data, $dataHead)) {
		return false;
	}

	// File site.php

	// If Brendy is not installed inside a folder, the URL does not need a trailing slash.
	// Example (root): https://domain.com
	// Example (inside a folder): https://domain.com/folder/
	if (HTML_PATH_ROOT === '/') {
		$siteUrl = PROTOCOL . DOMAIN;
	} else {
		$siteUrl = PROTOCOL . DOMAIN . HTML_PATH_ROOT;
	}
	$data = array(
		'title' => 'Brendy CMS',
		'slogan' => Sanitize::html($L->get('welcome-to-brendy')),
		'description' => Sanitize::html($L->get('congratulations-you-have-successfully-installed-brendy')),
		'footer' => '© ' . Date::current('Y') . ' Brendigo · brendigo.com',
		'itemsPerPage' => 6,
		'language' => $L->currentLanguage(),
		'locale' => $L->locale(),
		'timezone' => $timezone,
		'theme' => 'alternativa',
		'adminTheme' => 'brendy-admin',
		'accentColor' => '#2563eb',
		'colorScheme' => 'auto',
		'contentWidth' => 'wide',
		'typographyScale' => 'comfortable',
		'cardStyle' => 'elevated',
		'animationLevel' => 'subtle',
		'roundedCards' => true,
		'showReadingProgress' => true,
		'readingProgressPosition' => 'top',
		'readingProgressHeight' => 4,
		'readingProgressColor' => '#2563eb',
		'showBackToTop' => true,
		'showPostMeta' => true,
		'showReadingTime' => true,
		'showArticleTools' => true,
		'showRelatedContent' => true,
		'enableImageLazyLoading' => true,
		'homepage' => '',
		'pageNotFound' => '',
		'uriPage' => '/',
		'uriTag' => '/oznaka/',
		'uriCategory' => '/kategorija/',
		'uriBlog' => '',
		'url' => $siteUrl,
		'emailFrom' => filter_var(DOMAIN_NAME, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? 'no-reply@' . DOMAIN_NAME : '',
		'orderBy' => 'date',
		'currentBuild' => (int) BRENDY_BUILD,
		'twitter' => '',
		'facebook' => '',
		'codepen' => '',
		'github' => '',
		'instagram' => '',
		'gitlab' => '',
		'linkedin' => '',
		'xing' => '',
		'telegram' => '',
		'mastodon' => '',
		'dribbble' => '',
		'vk' => '',
		'youtube' => '',
		'bluesky' => '',
		'dateFormat' => 'd.m.Y.',
		'timeFormat' => 'H:i',
		'extremeFriendly' => true,
		'defaultContentStatus' => 'published',
		'autosaveInterval' => 2,
		'titleFormatHomepage' => '{{site-slogan}} | {{site-title}}',
		'titleFormatPages' => '{{page-title}} | {{site-title}}',
		'titleFormatCategory' => '{{category-name}} | {{site-title}}',
		'titleFormatTag' => '{{tag-name}} | {{site-title}}',
		'seoSocialImage' => '',
		'seoFacebookAppId' => '',
		'seoTwitterSite' => '',
		'seoTwitterCard' => 'summary_large_image',
		'robotsTxt' => "User-agent: *\nAllow: /",
		'maintenanceMode' => false,
		'maintenanceMessage' => 'Web-stranica je privremeno nedostupna zbog održavanja.',
		'alternativaShowPostInformation' => false,
		'alternativaDateFormat' => 'relative',
		'fokusDarkMode' => false,
		'fokusDateFormat' => 'relative',
		'fokusShowTags' => true,
		'imageRestrict' => true,
		'imageRelativeToAbsolute' => false,
		'thumbnailEnable' => true,
		'thumbnailWidth' => 400,
		'thumbnailHeight' => 400,
		'thumbnailQuality' => 100,
		'logo' => '',
		'markdownParser' => true,
		'customFields' => '{}'
	);
	// site.php is the installation marker; write it only after every other
	// required file has been created successfully.
	$siteData = $data;

	// File users.php
	$salt = '';
	$passwordHash = brendyInstallerPasswordHash($adminPassword);
	if ($passwordHash === false) {
		return false;
	}
	$tokenAuth = bin2hex(random_bytes(32));

	$data = array(
		'admin' => array(
			'nickname' => 'Admin',
			'firstName' => Sanitize::html($L->get('Administrator')),
			'lastName' => '',
			'description' => '',
			'role' => 'admin',
			'password' => $passwordHash,
			'salt' => $salt,
			'passwordHistory' => array(),
			'passwordChangedAt' => time(),
			'lastLoginAt' => 0,
			'lastLoginMethod' => '',
			'email' => strtolower($adminEmail),
			'registered' => $currentDate,
			'tokenRemember' => '',
			'tokenRememberExpires' => 0,
			'tokenAuth' => $tokenAuth,
			'twoFactorEnabled' => false,
			'twoFactorSecret' => '',
			'twoFactorRecoveryCodes' => array(),
			'twoFactorLastCounter' => 0,
			'twitter' => '',
			'facebook' => '',
			'instagram' => '',
			'codepen' => '',
			'linkedin' => '',
			'xing' => '',
			'telegram' => '',
			'mastodon' => '',
			'vk' => '',
			'youtube' => '',
			'github' => '',
			'gitlab' => '',
			'bluesky' => ''
		)
	);
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'users.php', $data, $dataHead)) {
		return false;
	}

	// File syslog.php. Fresh installations start directly on the v2 audit
	// format with a monotonic sequence number. security.php stores the signed
	// head/tail boundary anchor separately so truncation at either retained edge is detectable.
	$auditLogHmacKey = bin2hex(random_bytes(32));
	$auditRow = array(
		'date' => $currentDate,
		'dictionaryKey' => 'welcome-to-brendy',
		'notes' => '',
		'username' => 'admin',
		'idExecution' => bin2hex(random_bytes(16)),
		'method' => 'POST',
		'sequence' => 1
	);
	$auditCanonical = json_encode($auditRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (!is_string($auditCanonical)) return false;
	$auditRow['prevHash'] = '';
	$auditRow['chainHash'] = hash_hmac('sha256', 'Brendy-Audit-v2' . "\n\n" . $auditCanonical, hex2bin($auditLogHmacKey));
	$data = array($auditRow);
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'syslog.php', $data, $dataHead)) {
		return false;
	}

	// File security.php
	$coreIntegrityHmacKey = bin2hex(random_bytes(32));
	$data = array(
		'minutesBlocked' => 5,
		'numberFailuresAllowed' => 10,
		'blackList' => array(),
		'accountFailures' => array(),
		'identityHmacKey' => bin2hex(random_bytes(32)),
		'previewTokenSecret' => bin2hex(random_bytes(32)),
		'twoFactorEncryptionKey' => bin2hex(random_bytes(32)),
		'auditLogHmacKey' => $auditLogHmacKey,
		'auditLogHeadHash' => $auditRow['chainHash'],
		'auditLogHeadSequence' => 1,
		'auditLogTailSequence' => 1,
		'auditLogTailPrevHash' => '',
		'auditLogRetainedCount' => 1,
		'auditLogAnchorVersion' => 3,
		'extensionSecurityCacheKey' => bin2hex(random_bytes(32)),
		'coreIntegrityHmacKey' => $coreIntegrityHmacKey
	);
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'security.php', $data, $dataHead)) {
		return false;
	}

	// File categories.php
	$data = array(
		'general' => array('name' => 'Općenito', 'description' => '', 'template' => '', 'list' => array_values($pageSlugs)),
		'music' => array('name' => 'Glazba', 'description' => '', 'template' => '', 'list' => array()),
		'videos' => array('name' => 'Videozapisi', 'description' => '', 'template' => '', 'list' => array())
	);
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'categories.php', $data, $dataHead)) {
		return false;
	}

	// File tags.php
	$data = array();
	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'tags.php', $data, $dataHead)) {
		return false;
	}




	// Useful local-first plugins enabled on fresh installations.
	if (!writeJsonDatabaseAtomic(PATH_PLUGINS_DATABASES . 'search' . DS . 'db.php', array(
		'label' => Sanitize::html($L->get('Search')), 'minChars' => 3, 'wordsToCachePerPage' => 800,
		'showButtonSearch' => false, 'highlightResults' => true, 'showResultCount' => true,
		'searchInTags' => true, 'searchInCategories' => true, 'excerptLength' => 200, 'position' => 1
	), $dataHead)) return false;
	if (!createDirectory(PATH_WORKSPACES . 'search')) return false;


	if (!writeJsonDatabaseAtomic(PATH_PLUGINS_DATABASES . 'sitemap' . DS . 'db.php', array('position' => 1), $dataHead)) return false;
	if (!createDirectory(PATH_WORKSPACES . 'sitemap')) return false;



	// Establish the signed core-integrity baseline before publishing site.php.
	// site.php is the installation marker checked by alreadyInstalled(), so it
	// must be the final required artifact: a failure before this point remains
	// retryable instead of leaving a half-installed CMS that only returns 404.
	$coreIntegrityPayload = CoreIntegrity::createPayload(PATH_ROOT, PATH_KERNEL, (int)BRENDY_BUILD, $coreIntegrityHmacKey);
	if (!is_array($coreIntegrityPayload) ||
		!writeJsonDatabaseAtomic(DB_CORE_INTEGRITY, $coreIntegrityPayload, $dataHead, 0600)) return false;

	if (!writeJsonDatabaseAtomic(PATH_DATABASES . 'site.php', $siteData, $dataHead)) {
		return false;
	}

	return true;
}

function redirect($url)
{
	if (!is_string($url) || $url === '' || strlen($url) > 4096 || $url[0] !== '/' ||
		strpos($url, '//') === 0 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) $url = '/';
	if (!headers_sent()) {
		header("Location: " . $url, true, 302);
		exit;
	}

	exit('<meta http-equiv="refresh" content="0; url=' . htmlspecialchars($url, ENT_QUOTES, CHARSET) . '">');
}

// ============================================================================
// MAIN
// ============================================================================

if (alreadyInstalled()) {
	// Do not disclose installation state during a concurrent/racing request.
	http_response_code(404);
	exit('Not Found');
}

// Install by POST method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrf = isset($_POST['install_csrf']) && is_string($_POST['install_csrf']) ? $_POST['install_csrf'] : '';
	$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
	$email = isset($_POST['email']) && is_string($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
	$timezone = isset($_POST['timezone']) && is_string($_POST['timezone']) ? $_POST['timezone'] : '';
	if (!brendyInstallerSameOriginRequest() || !hash_equals($_SESSION['install_csrf'], $csrf)) {
		http_response_code(403);
		$errorText = 'Nevažeći instalacijski zahtjev. Ponovno učitajte stranicu i pokušajte opet.';
	} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
		$errorText = 'Unesite valjanu administratorsku e-mail adresu.';
	} elseif (!brendyInstallerPasswordPolicy($password, $email)) {
		$errorText = 'Lozinka je preslaba. Upotrijebite jedinstvenu lozinku ili dulju zaporku (najmanje 12 znakova, najviše 72 bajta).';
		error_log('[ERROR] ' . $errorText, 0);
	} elseif (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
		$errorText = 'Nevažeća vremenska zona.';
		error_log('[ERROR] ' . $errorText, 0);
	} else {
		$installed = false;
		if (!createDirectory(PATH_CONTENT)) {
			$errorText = 'Instalacijska mapa nije dostupna.';
		} else {
			$installLockPath = PATH_CONTENT . '.install.lock';
			$installLock = Filesystem::acquireLock($installLockPath, LOCK_EX | LOCK_NB, 0600);
			if ($installLock !== false) {
				try {
					$installed = !alreadyInstalled() && install($password, $timezone, $email);
				} finally {
					Filesystem::releaseLock($installLock);
				}
			} else {
				$errorText = 'Nije moguće zaključati instalacijski postupak.';
			}
		}
		if ($installed) {
			unset($_SESSION['install_csrf']);
			session_regenerate_id(true);
			redirect(HTML_PATH_ROOT);
		}
		if (empty($errorText)) {
			$errorText = 'Instalacija nije uspjela pri zapisivanju obveznih datoteka.';
		}
	}
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	http_response_code(405);
	header('Allow: GET, POST');
	exit('Metoda nije dopuštena');
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(str_replace('_', '-', $finalLanguage), ENT_QUOTES, CHARSET) ?>">

<head>
	<title><?php echo htmlspecialchars(BRENDY_NAME . ' Installer', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
	<meta charset="<?php echo CHARSET ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="robots" content="noindex,nofollow">

	<!-- Favicon -->
	<link rel="shortcut icon" type="image/x-icon" href="jezgra/img/favicon.png?version=<?php echo BRENDY_BUILD ?>">

	<!-- CSS -->
	<link rel="stylesheet" type="text/css" href="jezgra/css/bootstrap.min.css?version=<?php echo BRENDY_BUILD ?>">
	<link rel="stylesheet" href="jezgra/css/brendy-bootstrap-compat.css?version=<?php echo BRENDY_BUILD ?>">
	<link rel="stylesheet" type="text/css" href="jezgra/administracija/teme/brendy-admin/css/brendy.css?version=<?php echo BRENDY_BUILD ?>">

	<!-- Javascript -->
	<script charset="utf-8" src="jezgra/js/jquery.min.js?version=<?php echo BRENDY_BUILD ?>"></script>
	<script charset="utf-8" src="jezgra/js/bootstrap.bundle.min.js?version=<?php echo BRENDY_BUILD ?>"></script>

	<style>
		body.login {
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			justify-content: center;
			align-items: center;
			background: linear-gradient(135deg, #1e88e5 0%, #1565c0 50%, #0d47a1 100%);
			padding: 20px;
		}

		.installer-container {
			width: 100%;
			max-width: 480px;
		}

		.installer-card {
			background: #ffffff;
			border-radius: 16px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
			padding: 40px;
			animation: fadeInUp 0.5s ease-out;
		}

		@keyframes fadeInUp {
			from {
				opacity: 0;
				transform: translateY(20px);
			}

			to {
				opacity: 1;
				transform: translateY(0);
			}
		}

		.installer-logo {
			text-align: center;
			margin-bottom: 30px;
		}

		.installer-logo .logo-icon {
			width: 70px;
			height: 70px;
			background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
			border-radius: 16px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 15px;
			box-shadow: 0 8px 20px rgba(21, 101, 192, 0.4);
		}

		.installer-logo .logo-icon img {
			width: 36px;
			height: 36px;
			filter: brightness(0) invert(1);
		}

		.installer-logo h1 {
			font-size: 1.5rem;
			font-weight: 600;
			color: #1a1a2e;
			margin: 0;
			text-transform: uppercase;
		}

		.installer-logo p {
			color: #6c757d;
			font-size: 0.9rem;
			margin-top: 5px;
		}

		.installer-card .form-control {
			border: 2px solid #e9ecef;
			border-radius: 10px;
			padding: 12px 16px;
			font-size: 0.95rem;
			transition: all 0.3s ease;
			background-color: #f8f9fa;
			line-height: 1.5;
			height: auto;
		}

		.installer-card select.form-control {
			padding: 10px 16px;
			height: 46px;
		}

		.installer-card .form-control:focus {
			border-color: #1e88e5;
			box-shadow: 0 0 0 4px rgba(30, 136, 229, 0.15);
			background-color: #fff;
		}

		.installer-card .form-control::placeholder {
			color: #adb5bd;
		}

		.installer-card .form-group {
			margin-bottom: 20px;
		}

		.installer-card .form-group label {
			font-weight: 500;
			color: #495057;
			margin-bottom: 10px;
			font-size: 0.9rem;
		}

		.installer-card .btn-primary {
			background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
			border: none;
			border-radius: 10px;
			padding: 12px 18px;
			font-size: 0.95rem;
			font-weight: 600;
			color: white;
			width: 100%;
			transition: all 0.3s ease;
			box-shadow: 0 4px 15px rgba(21, 101, 192, 0.4);
		}

		.installer-card .btn-primary:hover {
			transform: translateY(-2px);
			box-shadow: 0 6px 20px rgba(21, 101, 192, 0.5);
		}

		.installer-card .btn-primary:active {
			transform: translateY(0);
		}

		.installer-card .form-check {
			margin-bottom: 25px;
			margin-top: 15px;
		}

		.installer-card .form-check-input {
			width: 18px;
			height: 18px;
			margin-top: 0;
			border: 2px solid #dee2e6;
			border-radius: 4px;
		}

		.installer-card .form-check-input:checked {
			background-color: #1e88e5;
			border-color: #1e88e5;
		}

		.installer-card .form-check-label {
			color: #6c757d;
			font-size: 0.9rem;
			padding-left: 8px;
		}

		.alert {
			border: none;
			border-radius: 10px;
			padding: 15px 20px;
			margin-bottom: 25px;
			font-size: 0.9rem;
		}

		.alert-danger {
			background-color: #fee;
			color: #c62828;
		}

		.error-table {
			background-color: #fff3cd;
			border: 2px solid #ffc107;
			border-radius: 10px;
			padding: 20px;
			margin-bottom: 20px;
		}

		.error-table th {
			color: #856404;
			font-weight: 500;
			font-size: 0.9rem;
		}

		.installer-description {
			color: #6c757d;
			font-size: 0.9rem;
			margin-bottom: 25px;
			text-align: center;
		}


		.btn-generate {
			background: #f8f9fa;
			border: 2px solid #e9ecef;
			border-left: none;
			border-radius: 0 10px 10px 0;
			color: #495057;
			padding: 12px 16px;
			transition: all 0.3s ease;
			font-size: 0.9rem;
			white-space: nowrap;
		}

		.btn-generate:hover {
			background: #e9ecef;
			border-color: #dee2e6;
			color: #212529;
		}

		.btn-generate:focus {
			outline: none;
			box-shadow: 0 0 0 4px rgba(30, 136, 229, 0.15);
			border-color: #1e88e5;
		}

		.input-group .form-control {
			border-radius: 10px 0 0 10px;
		}

		.input-group {
			display: flex;
		}
	</style>
</head>

<body class="login">
	<div class="installer-container">
		<div class="installer-card">
			<div class="installer-logo">
				<div class="logo-icon">
					<img src="jezgra/img/logo.svg" alt="Brendy CMS">
				</div>
				<h1><?php echo htmlspecialchars(BRENDY_NAME . ' Installer', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
			</div>

			<?php
			$system = checkSystem();
			if (!empty($system)) {
				foreach ($system as $error) {
					echo '<div class="error-table"><strong>' . $error . '</strong></div>';
				}
			} elseif ($languageRequested) {
			?>
				<p class="installer-description"><?php echo $L->get('choose-a-password-for-the-user-admin') ?></p>

				<?php if (!empty($errorText)) : ?>
					<div class="alert alert-danger"><?php echo htmlspecialchars($errorText, ENT_QUOTES, CHARSET) ?></div>
				<?php endif ?>

				<?php $installerEmailValue = isset($_POST['email']) && is_string($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, CHARSET) : ''; ?>
				<form id="jsformInstaller" method="post" action="" autocomplete="off">
					<input type="hidden" name="install_csrf" value="<?php echo htmlspecialchars($_SESSION['install_csrf'], ENT_QUOTES, CHARSET) ?>">
					<input type="hidden" name="timezone" id="jstimezone" value="Europe/Zagreb">

					<div class="form-group">
						<label for="jsemail"><?php $L->p('Administrator email') ?></label>
						<input type="email" dir="auto" value="<?php echo $installerEmailValue ?>" class="form-control" id="jsemail" name="email" maxlength="254" autocomplete="email" required placeholder="admin@example.com">
						<small class="form-text text-muted"><?php $L->p('Use this email to sign in to Brendy') ?></small>
					</div>

					<div class="form-group">
						<label for="jspassword"><?php $L->p('Password') ?></label>
						<div class="input-group">
							<input type="password" class="form-control" id="jspassword" name="password" autocomplete="new-password" minlength="12" maxlength="72" required placeholder="<?php $L->p('Password') ?>">
							<button type="button" class="btn btn-generate" id="jsgeneratePassword" title="<?php $L->p('Generate secure password') ?>"><?php $L->p('Generate') ?></button>
						</div>
					</div>

					<div class="form-check">
						<input role="button" class="form-check-input" type="checkbox" value="" id="jsshowPassword">
						<label class="form-check-label" for="jsshowPassword"><?php $L->p('Show password') ?></label>
					</div>

					<div class="form-group mt-4">
						<button type="submit" class="btn btn-primary" name="install"><?php $L->p('Install') ?></button>
					</div>
				</form>
			<?php
			} else {
			?>
				<form id="jsformLanguage" method="get" action="" autocomplete="off">
					<div class="form-group">
						<label for="jslanguage"><?php echo $L->get('Choose your language') ?></label>
						<select id="jslanguage" name="language" class="form-control">
							<?php
							$htmlOptions = getLanguageList();
							foreach ($htmlOptions as $fname => $native) {
								echo '<option value="' . htmlspecialchars($fname, ENT_QUOTES, CHARSET) . '"' . (($finalLanguage === $fname) ? ' selected="selected"' : '') . '>' . htmlspecialchars($native, ENT_QUOTES, CHARSET) . '</option>';
							}
							?>
						</select>
					</div>

					<div class="form-group mt-4">
						<button type="submit" class="btn btn-primary"><?php $L->p('Next') ?></button>
					</div>
				</form>
			<?php
			}
			?>
		</div>
	</div>

	<script nonce="<?php echo htmlspecialchars($installCspNonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
		$(function() {
			// Modern browsers expose an IANA timezone directly. Keep Europe/Zagreb
			// as the validated Croatian fallback and avoid a separate legacy library.
			try {
				var detectedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
				if (typeof detectedTimezone === "string" && detectedTimezone.length > 0 && detectedTimezone.length <= 100) {
					$("#jstimezone").val(detectedTimezone);
				}
			} catch (error) {
				// The hidden field already contains the safe default.
			}

			// Show password
			$("#jsshowPassword").on("click", function() {
				var input = document.getElementById("jspassword");

				if (!$(this).is(':checked')) {
					input.setAttribute("type", "password");
				} else {
					input.setAttribute("type", "text");
				}
			});

			// Generate password
			$("#jsgeneratePassword").on("click", function() {
				var length = 20;
				var charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=";
				var password = "";
				var crypto = window.crypto || window.msCrypto;

				if (!crypto || !crypto.getRandomValues) {
					alert(<?php echo json_encode($L->get('Secure random password generation is not supported by this browser'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
					return;
				}
				var limit = 256 - (256 % charset.length);
				while (password.length < length) {
					var values = new Uint8Array(length);
					crypto.getRandomValues(values);
					for (var i = 0; i < values.length && password.length < length; i++) {
						if (values[i] < limit) password += charset[values[i] % charset.length];
					}
				}

				$("#jspassword").val(password);
				$("#jspassword").attr("type", "text");
				$("#jsshowPassword").prop("checked", true);
			});
		});
	</script>

</body>

</html>
