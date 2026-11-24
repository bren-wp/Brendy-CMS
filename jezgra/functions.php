<?php defined('BRENDY') or die('Brendy CMS.');

// Per-request CSP nonce used by trusted administrator and public inline code. Keeping the
// nonce in process memory only means it is never persisted to JSON, cookies or
// logs. Hex is a valid CSP nonce-source value and is safe inside HTML attrs.
function brendyCspNonce()
{
  if (isset($GLOBALS['_brendyCspNonce']) && is_string($GLOBALS['_brendyCspNonce']) &&
      preg_match('/^[a-f0-9]{48}$/D', $GLOBALS['_brendyCspNonce']) === 1) {
    return $GLOBALS['_brendyCspNonce'];
  }
  $GLOBALS['_brendyCspNonce'] = bin2hex(random_bytes(24));
  return $GLOBALS['_brendyCspNonce'];
}

function brendyCspNonceAttribute()
{
  return ' nonce="' . htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"';
}


/**
 * Strict boolean normalization shared by core models, settings and APIs.
 * Returns only true/false or the caller-provided default; arrays/objects and
 * ambiguous strings are never coerced using PHP truthiness.
 */
function brendyBooleanValue($value, $default = null)
{
  if (is_bool($value)) return $value;
  if (is_int($value) && ($value === 0 || $value === 1)) return (bool)$value;
  if (!is_string($value) || strlen($value) > 16 || preg_match('/[\x00-\x1F\x7F]/', $value)) return $default;
  $normalized = strtolower(trim($value));
  if (in_array($normalized, array('true', '1', 'yes', 'on'), true)) return true;
  if (in_array($normalized, array('false', '0', 'no', 'off'), true)) return false;
  return $default;
}

/**
 * Read one boolean field from a request/config array without PHP truthiness.
 * Missing keys use the explicit boolean default; malformed present values
 * return null so the caller can reject the request instead of broadening it.
 */
function brendyBooleanParameter($source, $key, $default = false)
{
  if (!is_array($source) || !is_string($key) || $key === '' || !is_bool($default)) return null;
  if (!array_key_exists($key, $source)) return $default;
  return brendyBooleanValue($source[$key], null);
}

/**
 * Strict integer normalization shared by request and settings code. Unlike a
 * direct PHP (int) cast, malformed strings such as "12x" or "banana" are
 * rejected instead of silently becoming 12/0. Bounds are part of the contract.
 */
function brendyIntegerValue($value, $default = null, $minimum = null, $maximum = null)
{
  if (($minimum !== null && !is_int($minimum)) || ($maximum !== null && !is_int($maximum)) ||
      ($minimum !== null && $maximum !== null && $minimum > $maximum)) return $default;
  if (is_bool($value) || is_array($value) || is_object($value) || is_resource($value)) return $default;
  if (is_string($value)) {
    if ($value === '' || strlen($value) > 32 || preg_match('/[\x00-\x20\x7F]/', $value)) return $default;
  }
  $integer = filter_var($value, FILTER_VALIDATE_INT);
  if ($integer === false) return $default;
  $integer = (int)$integer;
  if ($minimum !== null && $integer < $minimum) return $default;
  if ($maximum !== null && $integer > $maximum) return $default;
  return $integer;
}

/**
 * Read one bounded integer from an input array. Missing values use the caller's
 * default; malformed present values return null so mutation endpoints can reject
 * them rather than relying on PHP's permissive numeric casts.
 */
function brendyIntegerParameter($source, $key, $default = null, $minimum = null, $maximum = null)
{
  if (!is_array($source) || !is_string($key) || $key === '' ||
      ($default !== null && !is_int($default))) return null;
  if (!array_key_exists($key, $source)) return $default;
  return brendyIntegerValue($source[$key], null, $minimum, $maximum);
}

// Atomically maintain the application-owned web-server protection files. The
// function refuses symlinks and non-regular destinations so an attacker cannot
// redirect a privileged dashboard request into an arbitrary file.
function writeBrendyProtectionFile($path, $contents)
{
  if (!is_string($path) || !is_string($contents) || $path === '' || strpos($path, "\0") !== false ||
      is_link($path) || (file_exists($path) && !is_file($path))) return false;
  $directory = dirname($path);
  if (!is_dir($directory) || is_link($directory)) return false;

  if (is_file($path)) {
    $existing = Filesystem::readFileBounded($path, 1048576, 0);
    if (is_string($existing) && hash_equals($existing, $contents)) return true;
  }
  return Filesystem::atomicWrite($path, $contents, 0644);
}

// Defense in depth for upgraded installations. The root rewrite rules already
// block these locations on Apache; nested files keep the data protected when a
// site is moved, the root file is temporarily absent, or content is replaced.
function brendyServeCoreRobotsTxt()
{
  global $site;
  if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI']) || strlen($_SERVER['REQUEST_URI']) > 8192) return false;
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (!is_string($path)) return false;
  $root = defined('HTML_PATH_ROOT') && is_string(HTML_PATH_ROOT) ? '/' . trim(HTML_PATH_ROOT, '/') : '';
  if ($root === '/') $root = '';
  if ($path !== $root . '/robots.txt') return false;
  $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper(trim($_SERVER['REQUEST_METHOD'])) : 'GET';
  if (!in_array($method, array('GET', 'HEAD'), true)) {
    if (!headers_sent()) { header('Allow: GET, HEAD'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); }
    http_response_code(405); exit(0);
  }
  if (!headers_sent()) {
    header('Content-Type: text/plain; charset=' . CHARSET);
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
  }
  if ($method === 'HEAD') exit(0);
  if (function_exists('pluginActivated') && pluginActivated('BrendyPluginSitemap')) echo 'Sitemap: ' . DOMAIN_BASE . 'sitemap.xml' . PHP_EOL;
  $robots = is_object($site) && method_exists($site, 'robotsTxt') ? $site->robotsTxt() : "User-agent: *\nAllow: /";
  echo is_string($robots) ? $robots : '';
  exit(0);
}

function brendyServeMaintenanceMode()
{
  global $site, $L;
  if (!is_object($site) || !method_exists($site, 'maintenanceMode') || !$site->maintenanceMode()) return false;
  $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper(trim($_SERVER['REQUEST_METHOD'])) : 'GET';
  if (!headers_sent()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=' . CHARSET);
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Retry-After: 3600');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('X-Content-Type-Options: nosniff');
  }
  if ($method === 'HEAD') exit(0);
  $message = method_exists($site, 'maintenanceMessage') ? $site->maintenanceMessage() : 'Web-stranica je privremeno nedostupna zbog održavanja.';
  if (!is_string($message)) $message = 'Web-stranica je privremeno nedostupna zbog održavanja.';
  $title = is_object($L) && method_exists($L, 'g') ? $L->g('Maintenance mode') : 'Maintenance mode';
  $lang = method_exists($site, 'languageShortVersion') ? $site->languageShortVersion() : 'hr';
  if (!is_string($lang) || preg_match('/^[A-Za-z]{2,3}$/D', $lang) !== 1) $lang = 'hr';
  $escape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); };
  echo '<!doctype html><html lang="' . $escape(strtolower($lang)) . '"><head><meta charset="' . $escape(CHARSET) . '"><meta name="viewport" content="width=device-width,initial-scale=1">' .
    '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><title>' . $escape($title) . '</title></head><body><main><h1>' .
    $escape($title) . '</h1><p>' . $escape($message) . '</p></main></body></html>';
  exit(0);
}

function protectContentDirectories()
{
  $denyAll = '<IfModule mod_authz_core.c>' . PHP_EOL .
    'Require all denied' . PHP_EOL . '</IfModule>' . PHP_EOL .
    '<IfModule !mod_authz_core.c>' . PHP_EOL . 'Order allow,deny' . PHP_EOL .
    'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL;

  foreach (array(PATH_DATABASES, PATH_PAGES, PATH_TMP, PATH_WORKSPACES, PATH_REVISIONS) as $directory) {
    if ((!is_dir($directory) && !Filesystem::mkdir($directory, true)) || is_link($directory) ||
        !writeBrendyProtectionFile($directory . '.htaccess', $denyAll)) return false;
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

  return ((!is_dir(PATH_UPLOADS) && !Filesystem::mkdir(PATH_UPLOADS, true)) || is_link(PATH_UPLOADS))
    ? false : writeBrendyProtectionFile(PATH_UPLOADS . '.htaccess', $uploads);
}

// Build or refresh the HMAC-authenticated baseline of Brendy's own runtime
// code. This is called only on a trusted install/update boundary; ordinary
// requests never silently bless changed core files.
function writeBrendyCoreIntegrityBaseline()
{
  global $security;
  if (!defined('DB_CORE_INTEGRITY') || !isset($security) || !is_object($security) ||
      !method_exists($security, 'coreIntegrityHmacKey')) return false;
  $key = $security->coreIntegrityHmacKey();
  if (!is_string($key)) return false;
  $payload = CoreIntegrity::createPayload(PATH_ROOT, PATH_KERNEL, (int)BRENDY_BUILD, $key);
  if (!is_array($payload)) return false;
  if (is_link(DB_CORE_INTEGRITY) || (file_exists(DB_CORE_INTEGRITY) && !is_file(DB_CORE_INTEGRITY))) return false;
  $store = new BrendyJsonDatabase(DB_CORE_INTEGRITY);
  $store->db = $payload;
  return $store->save();
}

function verifyBrendyCoreIntegrityBaseline()
{
  global $security;
  $failure = array('ok'=>false, 'authentic'=>false, 'build'=>0, 'fileCount'=>0,
    'modified'=>array(), 'missing'=>array(), 'unexpected'=>array(), 'error'=>'baseline-unavailable');
  if (!defined('DB_CORE_INTEGRITY') || !is_file(DB_CORE_INTEGRITY) || is_link(DB_CORE_INTEGRITY) ||
      !isset($security) || !is_object($security) || !method_exists($security, 'coreIntegrityHmacKey')) return $failure;
  $inspection = BrendyJsonDatabase::inspectFile(DB_CORE_INTEGRITY, true, 2097152);
  if (!is_array($inspection) || empty($inspection['ok'])) {
    $failure['error'] = 'baseline-storage';
    return $failure;
  }
  $key = $security->coreIntegrityHmacKey();
  if (!is_string($key)) {
    $failure['error'] = 'baseline-key';
    return $failure;
  }
  $store = new BrendyJsonDatabase(DB_CORE_INTEGRITY);
  return CoreIntegrity::verifyPayload($store->db, PATH_ROOT, PATH_KERNEL, $key);
}

// Core-integrity state belongs to the deployed application code, not to a
// content snapshot. Backup/restore operations preserve this authenticated
// deployment state instead of importing an obsolete baseline/key from the
// restored content tree.
function captureBrendyDeploymentIntegrityState()
{
  global $security;
  if (!defined('DB_CORE_INTEGRITY') || !is_file(DB_CORE_INTEGRITY) || is_link(DB_CORE_INTEGRITY) ||
      !isset($security) || !is_object($security) || !method_exists($security, 'coreIntegrityHmacKey')) return false;
  $key = $security->coreIntegrityHmacKey();
  if (!is_string($key)) return false;
  $inspection = BrendyJsonDatabase::inspectFile(DB_CORE_INTEGRITY, true, 2097152);
  if (!is_array($inspection) || empty($inspection['ok'])) return false;
  $store = new BrendyJsonDatabase(DB_CORE_INTEGRITY);
  $verification = CoreIntegrity::verifyPayload($store->db, PATH_ROOT, PATH_KERNEL, $key);
  if (!is_array($verification) || empty($verification['authentic'])) return false;
  return array('key' => $key, 'baseline' => $store->db);
}

function restoreBrendyDeploymentIntegrityState($state)
{
  if (!is_array($state) || !isset($state['key'], $state['baseline']) || !is_string($state['key']) ||
      preg_match('/^[a-f0-9]{64}$/D', strtolower($state['key'])) !== 1 || !is_array($state['baseline'])) return false;
  $key = strtolower($state['key']);
  $verification = CoreIntegrity::verifyPayload($state['baseline'], PATH_ROOT, PATH_KERNEL, $key);
  if (!is_array($verification) || empty($verification['authentic'])) return false;
  $securityInspection = BrendyJsonDatabase::inspectFile(DB_SECURITY, true, 2097152);
  if (!is_array($securityInspection) || empty($securityInspection['ok'])) return false;
  $securityStore = new BrendyJsonDatabase(DB_SECURITY);
  if (!is_array($securityStore->db)) return false;
  $securityStore->db['coreIntegrityHmacKey'] = $key;
  if (!$securityStore->save()) return false;

  if (is_link(DB_CORE_INTEGRITY) || (file_exists(DB_CORE_INTEGRITY) && !is_file(DB_CORE_INTEGRITY))) return false;
  try {
    $json = json_encode($state['baseline'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    return false;
  }
  $guard = "<?php defined('BRENDY') or die('Brendy CMS.'); ?>" . PHP_EOL;
  return Filesystem::atomicWrite(DB_CORE_INTEGRITY, $guard . $json, 0640);
}

function contentMutationLockDepth()
{
  return isset($GLOBALS['_brendyContentMutationDepth']) ? (int)$GLOBALS['_brendyContentMutationDepth'] : 0;
}

// Serialize operations that change both page files and JSON indexes. The lock
// is request-reentrant because successful create/edit operations may remove an
// autosave through the same public helper.
function acquireContentMutationLock()
{
  $depth = contentMutationLockDepth();
  if ($depth > 0 && isset($GLOBALS['_brendyContentMutationHandle']) &&
      is_resource($GLOBALS['_brendyContentMutationHandle'])) {
    $GLOBALS['_brendyContentMutationDepth'] = $depth + 1;
    return true;
  }
  if ((!Filesystem::directoryExists(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) || is_link(PATH_TMP)) return false;
  $path = PATH_TMP . 'sadrzaj-mutation.lock';
  $handle = Filesystem::acquireLock($path, LOCK_EX, 0600);
  if ($handle === false) return false;
  $GLOBALS['_brendyContentMutationHandle'] = $handle;
  $GLOBALS['_brendyContentMutationDepth'] = 1;
  return true;
}

function releaseContentMutationLock()
{
  $depth = contentMutationLockDepth();
  if ($depth > 1) {
    $GLOBALS['_brendyContentMutationDepth'] = $depth - 1;
    return true;
  }
  $handle = isset($GLOBALS['_brendyContentMutationHandle']) ? $GLOBALS['_brendyContentMutationHandle'] : null;
  if (is_resource($handle)) Filesystem::releaseLock($handle);
  unset($GLOBALS['_brendyContentMutationHandle'], $GLOBALS['_brendyContentMutationDepth']);
  return true;
}

// A request may have waited for another writer after loading its databases.
// Refresh the shared objects while holding the outermost mutation lock so the
// operation starts from the latest committed state.
function refreshContentMutationState()
{
  global $pages, $categories, $tags, $users;
  if (contentMutationLockDepth() !== 1) return true;
  $pages = new Pages();
  $categories = new Categories();
  $tags = new Tags();
  $users = new Users();
  return true;
}


// Stable fingerprint for optimistic editing. The value is not an authorization
// token; it only detects that an editor form was opened before a newer save.
function pageVersionFingerprint($key, $row)
{
  if (!is_string($key) || !is_array($row)) return '';
  $parts = array(
    $key,
    isset($row['uuid']) && is_string($row['uuid']) ? $row['uuid'] : '',
    isset($row['dateModified']) && is_string($row['dateModified']) ? $row['dateModified'] : '',
    isset($row['contentHash']) && is_string($row['contentHash']) && preg_match('/^[a-f0-9]{64}$/iD', $row['contentHash']) === 1
      ? strtolower($row['contentHash'])
      : (isset($row['md5file']) && is_string($row['md5file']) ? $row['md5file'] : ''),
    isset($row['type']) && is_string($row['type']) ? $row['type'] : ''
  );
  return hash('sha256', implode("\n", $parts));
}



// Upgrade legacy page checksums to SHA-256. The legacy md5file field remains
// for backwards compatibility with older extensions, while contentHash is the
// canonical integrity value used by new Brendy releases.
function migratePageContentHashes()
{
  global $pages;
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    if (!isset($pages) || !$pages instanceof Pages || !is_array($pages->db)) return false;
    $changed = false;
    $checked = 0;
    foreach ($pages->db as $key => $row) {
      if (++$checked > 50000 || !is_string($key) || !is_array($row)) return false;
      $existing = isset($row['contentHash']) && is_string($row['contentHash']) ? strtolower($row['contentHash']) : '';
      if (preg_match('/^[a-f0-9]{64}$/D', $existing) === 1) continue;
      if (!isValidPageKey($key)) return false;
      $path = PATH_PAGES . $key . DS . FILENAME;
      if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
      $size = @filesize($path);
      if (!is_int($size) || $size < 0 || $size > BRENDY_MAX_CONTENT_BYTES) return false;
      $hash = Filesystem::hashFileBounded($path, 'sha256', BRENDY_MAX_CONTENT_BYTES, 0, $size);
      if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
      $pages->db[$key]['contentHash'] = $hash;
      $changed = true;
    }
    if (!$changed) return true;
    return $pages->save();
  } finally {
    releaseContentMutationLock();
  }
}

function ensureRevisionStorage()
{
  if ((!is_dir(PATH_REVISIONS) && !Filesystem::mkdir(PATH_REVISIONS, true)) || is_link(PATH_REVISIONS)) return false;
  $denyAll = '<IfModule mod_authz_core.c>' . PHP_EOL . 'Require all denied' . PHP_EOL . '</IfModule>' . PHP_EOL .
    '<IfModule !mod_authz_core.c>' . PHP_EOL . 'Order allow,deny' . PHP_EOL . 'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL;
  return writeBrendyProtectionFile(PATH_REVISIONS . '.htaccess', $denyAll);
}

function createPageRevision($key, $actorUsername = null, $reason = 'edit')
{
  global $pages;
  if (!is_string($key) || $key === '' || !is_string($reason) || strlen($reason) > 40 ||
      ($actorUsername !== null && !is_string($actorUsername))) return false;
  $row = $pages->getPageDB($key);
  if (!is_array($row) || !isset($row['uuid'], $row['type']) || !is_string($row['uuid']) ||
      !is_string($row['type']) || $row['type'] === 'autosave' ||
      preg_match('/^[a-f0-9]{32}$/iD', $row['uuid']) !== 1) return false;
  if (!ensureRevisionStorage()) return false;

  $contentPath = PATH_PAGES . $key . DS . FILENAME;
  $content = Filesystem::readFileBounded($contentPath, BRENDY_MAX_CONTENT_BYTES, 0);
  if (!is_string($content)) return false;

  $uuidDirectory = PATH_REVISIONS . strtolower($row['uuid']) . DS;
  if ((!is_dir($uuidDirectory) && !Filesystem::mkdir($uuidDirectory, true)) || is_link($uuidDirectory)) return false;
  $id = Date::current('YmdHis') . '-' . bin2hex(random_bytes(6));
  $stage = $uuidDirectory . '.stage-' . bin2hex(random_bytes(8));
  $final = $uuidDirectory . $id;
  if (file_exists($final) || is_link($final) || !Filesystem::mkdir($stage, true)) return false;

  $safeFields = array('title','description','tags','type','date','position','coverImage','category','allowComments',
    'template','noindex','nofollow','noarchive','custom');
  $fields = array();
  foreach ($safeFields as $field) {
    if (array_key_exists($field, $row)) $fields[$field] = $row[$field];
  }
  $metadata = array(
    'id' => $id,
    'pageKey' => $key,
    'uuid' => strtolower($row['uuid']),
    'title' => isset($row['title']) && is_string($row['title']) ? $row['title'] : $key,
    'createdAt' => Date::current(DB_DATE_FORMAT),
    'actor' => is_string($actorUsername) && $actorUsername !== '' ? $actorUsername : (string)Session::get('username'),
    'reason' => preg_replace('/[^a-z0-9_-]/i', '', $reason),
    'contentBytes' => strlen($content),
    'contentSha256' => hash('sha256', $content),
    'fields' => $fields
  );
  try {
    $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    Filesystem::deleteRecursive($stage);
    return false;
  }
  if (!Filesystem::atomicWrite($stage . DS . 'content.txt', $content, 0600) ||
      !Filesystem::atomicWrite($stage . DS . 'metadata.json', $metadataJson, 0600) ||
      !Filesystem::mv($stage, $final)) {
    if (is_dir($stage)) Filesystem::deleteRecursive($stage);
    return false;
  }
  @chmod($final, 0700);

  // Keep only the newest revisions for this page.
  $entries = glob($uuidDirectory . '*', GLOB_ONLYDIR);
  if (is_array($entries)) {
    $entries = array_values(array_filter($entries, static function ($path) use ($uuidDirectory) {
      return is_string($path) && !is_link($path) && strpos(basename($path), '.stage-') !== 0 &&
        preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', basename($path)) === 1;
    }));
    rsort($entries, SORT_STRING);
    foreach (array_slice($entries, max(1, (int)CONTENT_REVISION_RETENTION)) as $old) {
      Filesystem::deleteRecursive($old);
    }
  }
  return $id;
}

function listPageRevisions($uuid, $limit = 20)
{
  if (!is_string($uuid) || preg_match('/^[a-f0-9]{32}$/iD', $uuid) !== 1) return array();
  $limit = max(1, min((int)CONTENT_REVISION_RETENTION, (int)$limit));
  $directory = PATH_REVISIONS . strtolower($uuid) . DS;
  if (!is_dir($directory) || is_link($directory)) return array();
  $entries = glob($directory . '*', GLOB_ONLYDIR);
  if (!is_array($entries)) return array();
  rsort($entries, SORT_STRING);
  $result = array();
  foreach ($entries as $path) {
    $id = basename($path);
    if (count($result) >= $limit || is_link($path) || preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $id) !== 1) continue;
    $metaPath = $path . DS . 'metadata.json';
    $raw = Filesystem::readFileBounded($metaPath, 262144, 2);
    if (!is_string($raw)) continue;
    try { $meta = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException $e) { continue; }
    if (!is_array($meta) || !isset($meta['id'], $meta['uuid']) || !is_string($meta['id']) ||
        !is_string($meta['uuid']) || !hash_equals(strtolower($uuid), strtolower($meta['uuid'])) ||
        !hash_equals($id, $meta['id'])) continue;
    $result[] = $meta;
  }
  return $result;
}

function loadPageRevision($uuid, $revisionId)
{
  if (!is_string($uuid) || preg_match('/^[a-f0-9]{32}$/iD', $uuid) !== 1 || !is_string($revisionId) ||
      preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $revisionId) !== 1) return false;
  $directory = PATH_REVISIONS . strtolower($uuid) . DS . $revisionId;
  if (!is_dir($directory) || is_link($directory)) return false;
  $metaPath = $directory . DS . 'metadata.json';
  $contentPath = $directory . DS . 'content.txt';
  $raw = Filesystem::readFileBounded($metaPath, 262144, 2);
  $content = Filesystem::readFileBounded($contentPath, BRENDY_MAX_CONTENT_BYTES, 0);
  if (!is_string($raw) || !is_string($content)) return false;
  try { $meta = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException $e) { return false; }
  if (!is_array($meta) || !isset($meta['id'], $meta['uuid'], $meta['contentSha256'], $meta['fields']) ||
      !is_string($meta['id']) || !hash_equals($revisionId, $meta['id']) || !is_string($meta['uuid']) ||
      !hash_equals(strtolower($uuid), strtolower($meta['uuid'])) || !is_string($meta['contentSha256']) ||
      !hash_equals($meta['contentSha256'], hash('sha256', $content)) || !is_array($meta['fields'])) return false;
  return array('metadata' => $meta, 'content' => $content);
}

function restorePageRevision($key, $revisionId, $actorUsername = null)
{
  global $pages, $syslog;
  if (!is_string($key) || $key === '' || !is_string($revisionId)) return false;
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    $row = $pages->getPageDB($key);
    if (!is_array($row) || !isset($row['uuid']) || !is_string($row['uuid'])) return false;
    $revision = loadPageRevision($row['uuid'], $revisionId);
    if (!is_array($revision)) return false;
    $fields = $revision['metadata']['fields'];
    $args = array('key' => $key, 'uuid' => $row['uuid'], 'content' => $revision['content']);
    foreach (array('title','description','tags','type','date','position','coverImage','category','allowComments',
      'template','noindex','nofollow','noarchive','custom') as $field) {
      if (!array_key_exists($field, $fields)) continue;
      if ($field === 'tags' && is_array($fields[$field])) {
        $args[$field] = implode(',', array_values(array_filter($fields[$field], 'is_string')));
      } else {
        $args[$field] = $fields[$field];
      }
    }
    // Save the current state before replacing it, then avoid a duplicate snapshot
    // inside editPageUnlocked().
    if (createPageRevision($key, $actorUsername, 'before-restore') === false) {
      Log::set('Function restorePageRevision()' . LOG_SEP . 'Unable to preserve current version before restore.', LOG_TYPE_ERROR);
      return false;
    }
    $GLOBALS['_brendySkipRevisionCapture'] = true;
    try {
      $result = editPageUnlocked($args, $actorUsername);
    } finally {
      unset($GLOBALS['_brendySkipRevisionCapture']);
    }
    if ($result !== false) {
      $syslog->add(array('dictionaryKey' => 'revision-restored', 'notes' => $revisionId, 'username' => $actorUsername));
    }
    return $result;
  } finally {
    releaseContentMutationLock();
  }
}

// Re-index database of categories
// If you create/edit/remove a page is necessary regenerate the database of categories
function reindexCategories()
{
  global $categories;
  return $categories->reindex();
}

// Re-index database of tags
// If you create/edit/remove a page is necessary regenerate the database of tags
function reindexTags()
{
  global $tags;
  return $tags->reindex();
}

// Keep generated plugin artifacts and taxonomy indexes synchronized after one
// committed page mutation. Lifecycle hooks are isolated by Theme::plugins(), so
// a misbehaving extension cannot inject output into redirects/AJAX responses.
function refreshContentDerivedState($pluginHook, $pageKey = null)
{
  if (!is_string($pluginHook) || !in_array($pluginHook, array('afterPageCreate', 'afterPageModify', 'afterPageDelete'), true) ||
      ($pageKey !== null && (!is_string($pageKey) || $pageKey === ''))) return false;

  // Taxonomy indexes are dependencies of generated public artifacts (notably
  // Sitemap). Rebuild them first so lifecycle plugins never regenerate from the
  // previous category/tag membership after the page row is already committed.
  $categoriesOk = reindexCategories();
  $tagsOk = reindexTags();
  if (!$categoriesOk || !$tagsOk) {
    Log::set(__METHOD__ . LOG_SEP . 'Content committed, but taxonomy indexes need rebuilding.', LOG_TYPE_ERROR);
  }
  $hookOk = Theme::plugins($pluginHook, $pageKey === null ? array() : array($pageKey));
  return $hookOk !== false && $categoriesOk && $tagsOk;
}

// Publish due scheduled pages under the same transaction boundary as ordinary
// create/edit/delete operations. Without this lock a scheduler request could
// regenerate Search/Sitemap from a stale Pages object after a newer edit had
// already committed. The read-only preflight avoids locking normal requests.
function publishScheduledContent()
{
  global $pages, $syslog;
  if (!is_object($pages) || !method_exists($pages, 'hasPublishableScheduledPages') ||
      !$pages->hasPublishableScheduledPages()) return false;

  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    if (!$pages->hasPublishableScheduledPages() || !$pages->scheduler()) return false;

    refreshContentDerivedState('afterPageCreate');
    if (isset($syslog) && is_object($syslog)) {
      $syslog->add(array(
        'dictionaryKey' => 'content-published-from-scheduler',
        'notes' => ''
      ));
    }
    return true;
  } finally {
    releaseContentMutationLock();
  }
}

// Change the public/content type of a complete page subtree under the same
// mutation lock used by create/edit/delete operations. This closes the gap where
// the AJAX type switch updated DB_PAGES but left Search/Sitemap/taxonomies stale.
function changePageType($key, $newType, $actorUsername = null)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return changePageTypeUnlocked($key, $newType, $actorUsername);
  } finally {
    releaseContentMutationLock();
  }
}

function changePageTypeUnlocked($key, $newType, $actorUsername = null)
{
  global $pages, $users, $syslog;
  $allowed = array('published', 'sticky', 'static', 'draft');
  if (!is_string($key) || !is_string($newType) || !isValidPageKey($key) || !in_array($newType, $allowed, true) ||
      ($actorUsername !== null && (!is_string($actorUsername) || $actorUsername === ''))) return false;

  $resolvedUsername = $actorUsername !== null ? $actorUsername : Session::get('username');
  if (!is_string($resolvedUsername) || $resolvedUsername === '') return false;
  $actor = $users->getUserDB($resolvedUsername);
  $role = is_array($actor) && isset($actor['role']) && is_string($actor['role']) ? $actor['role'] : '';
  if (!in_array($role, array('admin', 'editor', 'author'), true) || !$pages->exists($key)) return false;

  $row = $pages->getPageDB($key);
  $currentType = is_array($row) && isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
  if (!in_array($currentType, $allowed, true)) return false;
  if ($currentType === $newType) return 0;

  $subtree = array_merge(array($key), $pages->getDescendants($key));
  if ($role === 'author') {
    foreach ($subtree as $affectedKey) {
      $affectedRow = $pages->getPageDB($affectedKey);
      if (!is_array($affectedRow) || !isset($affectedRow['username']) || !is_string($affectedRow['username']) ||
          $affectedRow['username'] !== $resolvedUsername) return false;
    }
  }

  $affected = $pages->setTypeForSubtree($key, $newType);
  if ($affected === false) return false;

  refreshContentDerivedState('afterPageModify', $key);
  if (!brendyReconcileSitePageReferences()) {
    Log::set(__METHOD__ . LOG_SEP . 'Page type changed but Homepage/404 references could not be reconciled.', LOG_TYPE_ERROR);
  }
  if (isset($syslog) && is_object($syslog)) {
    $syslog->add(array(
      'dictionaryKey' => 'content-edited',
      'notes' => $key . ' [' . $newType . ']',
      'username' => $actorUsername
    ));
  }
  return (int)$affected;
}

// Generate the page 404 Not found
function buildErrorPage()
{
  global $site;
  global $L;
  global $pages;

  try {
    $pageNotFoundKey = $site->pageNotFound();
    if (!is_string($pageNotFoundKey) || !$pages->isPubliclyAccessible($pageNotFoundKey)) {
      throw new RuntimeException('Configured 404 page is not publicly accessible.');
    }
    $pageNotFound = new Page($pageNotFoundKey);
  } catch (Throwable $e) {
    $pageNotFound = new Page(false);
    $pageNotFound->setField('title',   $L->get('page-not-found'));
    $pageNotFound->setField('content',   $L->get('page-not-found-content'));
    $pageNotFound->setField('username',   'admin');
  }

  return $pageNotFound;
}

// This function is only used from the rule 69.pages.php, DO NOT use this function!
// This function generate a particular page from the current slug of the url
// If the slug has not a page associated returns FALSE and set not-found as true
function buildThePage()
{
  global $url;
  global $security;
  global $pages;

  try {
    $pageKey = $url->slug();
    $page = new Page($pageKey);
  } catch (Throwable $e) {
    $url->setNotFound();
    return false;
  }

  // Visibility is inherited through the URL hierarchy. A child marked
  // "published" must still stay private while any ancestor is non-public.
  // Authenticated preview remains available through the child's signed token.
  if (!$pages->isPubliclyAccessible($pageKey)) {
    $preview = $url->parameter('preview');
    if (!$security->validatePreviewToken($page->uuid(), $preview)) {
      $url->setNotFound();
      return false;
    }
    // Mark only successfully authenticated non-public page previews. SEO and
    // cache layers use this flag to ensure preview responses can never look
    // like indexable public documents.
    $GLOBALS['BRENDY_PREVIEW_MODE'] = true;
  }

  return $page;
}

// This function is only used from the rule 69.pages.php, DO NOT use this function!
function buildPagesForHome()
{
  return buildPagesFor('home');
}

// This function is only used from the rule 69.pages.php, DO NOT use this function!
function buildPagesByCategory()
{
  global $url;

  $categoryKey = $url->slug();
  return buildPagesFor('category', $categoryKey, false);
}

// This function is only used from the rule 69.pages.php, DO NOT use this function!
function buildPagesByTag()
{
  global $url;

  $tagKey = $url->slug();
  return buildPagesFor('tag', false, $tagKey);
}

// This function is only used from the rule 69.pages.php, DO NOT use this function!
// Generate the global variables $content / $content, defined on 69.pages.php
// This function is use for buildPagesForHome(), buildPagesByCategory(), buildPagesByTag()
function buildPagesFor($for, $categoryKey = false, $tagKey = false)
{
  global $pages;
  global $categories;
  global $tags;
  global $site;
  global $url;

  // Get the page number from URL
  if (!is_string($for) || !in_array($for, array('home', 'category', 'tag'), true)) return false;
  $pageNumber = $url->pageNumber();
  $list = false;

  if ($for === 'home') {
    $onlyPublished = true;
    $numberOfItems = $site->itemsPerPage();
    $list = $pages->getPublicList($pageNumber, $numberOfItems, $onlyPublished);

    // Include sticky pages only in the first page
    if ($pageNumber === 1) {
      $sticky = $pages->getPublicList(1, -1, false, false, true);
      $list = array_merge(is_array($sticky) ? $sticky : array(), is_array($list) ? $list : array());
    }
  } elseif ($for === 'category') {
    $numberOfItems = $site->itemsPerPage();
    $list = $categories->getList($categoryKey, $pageNumber, $numberOfItems);
  } elseif ($for === 'tag') {
    $numberOfItems = $site->itemsPerPage();
    $list = $tags->getList($tagKey, $pageNumber, $numberOfItems);
  }

  // There are no items, invalid tag, invalid category, out of range, etc...
  if ($list === false) {
    $url->setNotFound();
    return false;
  }

  $content = array();
  $invalidPageFound = false;
  foreach ($list as $pageKey) {
    if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
    try {
      $page = new Page($pageKey);
      if (in_array($page->type(), array('published', 'sticky', 'static'), true)) {
        $content[] = $page;
      }
    } catch (Throwable $e) {
      $invalidPageFound = true;
      // continue
    }
  }

  if (($for === 'category' || $for === 'tag') && (!empty($list)) && (empty($content) || $invalidPageFound)) {
    if ($for === 'category') {
      reindexCategories();
      $list = $categories->getList($categoryKey, $pageNumber, $numberOfItems);
    } else {
      reindexTags();
      $list = $tags->getList($tagKey, $pageNumber, $numberOfItems);
    }

    if (is_array($list)) {
      $content = array();
      foreach ($list as $pageKey) {
        if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
        try {
          $page = new Page($pageKey);
          if (in_array($page->type(), array('published', 'sticky', 'static'), true)) {
            $content[] = $page;
          }
        } catch (Throwable $e) {
          // continue
        }
      }
    }
  }

  return $content;
}

// Returns an array with all the static pages as Page-Object
// The static pages are order by position all the time
function buildStaticPages()
{
  global $pages;

  $list = array();
  $pagesKey = $pages->getStaticDB();
  foreach ($pagesKey as $pageKey) {
    if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
    try {
      $page = new Page($pageKey);
      array_push($list, $page);
    } catch (Throwable $e) {
      // continue
    }
  }

  return $list;
}

// Returns the Page-Object if exists, FALSE otherwise
function buildPage($pageKey)
{
  try {
    $page = new Page($pageKey);
    return $page;
  } catch (Throwable $e) {
    return false;
  }
}

// Returns an array with all the parent pages as Page-Object
// The pages are order by the settings on the system
function buildParentPages()
{
  global $pages;

  $list = array();
  $pagesKey = $pages->getPublishedDB();
  foreach ($pagesKey as $pageKey) {
    if (!is_string($pageKey) || !$pages->isPubliclyAccessible($pageKey)) continue;
    try {
      $page = new Page($pageKey);
      if ($page->isParent()) {
        array_push($list, $page);
      }
    } catch (Throwable $e) {
      // continue
    }
  }

  return $list;
}

/**
 * Canonical Brendy plugin class names and identifiers kept for upgrades from
 * older installations. The aliases are resolved at the boundary instead of
 * registering duplicate plugin objects and hooks.
 *
 * @return array<string, string>
 */
function brendyRetiredBundledPluginMap()
{
  // Bundled extensions retired across Brendy releases. Mapping the historical
  // class name lets upgrade cleanup distinguish our own old source from unrelated
  // administrator files before removing anything.
  return array(
    'about' => 'BrendyPluginAbout',
    'disqus' => 'BrendyPluginDisqus',
    'custom-fields-parser' => 'BrendyPluginCustomFieldsParser',
    'domain-migrator' => 'BrendyPluginDomainMigrator',
    'html-code' => 'BrendyPluginHTMLCode',
    'links' => 'BrendyPluginLinks',
    'remote-content' => 'BrendyPluginRemoteContent',
    'rss' => 'BrendyPluginRSS',
    'static-pages' => 'BrendyPluginStaticPages',
    'timemachine-x' => 'BrendyPluginTimeMachine',
    'visits-stats' => 'BrendyPluginVisitsStats'
  );
}

function brendyRetiredBundledPluginDirectories()
{
  return array_keys(brendyRetiredBundledPluginMap());
}

function brendyIsRetiredBundledPluginDirectory($directory)
{
  return is_string($directory) && in_array(strtolower($directory), brendyRetiredBundledPluginDirectories(), true);
}

function brendyCleanupRetiredBundledPluginResidue()
{
  $ok = true;
  $stateCleanupAllowed = array();
  $pluginsRoot = realpath(PATH_PLUGINS);
  if ($pluginsRoot !== false && !is_link(PATH_PLUGINS)) {
    foreach (brendyRetiredBundledPluginMap() as $directory => $className) {
      $path = PATH_PLUGINS . $directory;
      // When source is already absent (normal clean 2.20 package), its known
      // generated/settings state can be retired safely. If source exists, state
      // cleanup is allowed only after the source is positively identified as our
      // historical bundled extension and removed successfully.
      if (!file_exists($path) && !is_link($path)) {
        $stateCleanupAllowed[$directory] = true;
        continue;
      }
      $stateCleanupAllowed[$directory] = false;
      if (is_link($path) || !is_dir($path)) { $ok = false; continue; }
      $real = realpath($path);
      $pluginFile = $path . DS . 'plugin.php';
      if ($real === false || strpos($real, rtrim($pluginsRoot, DS) . DS) !== 0 ||
          !is_file($pluginFile) || is_link($pluginFile) ||
          brendyPluginClassNameFromSource($pluginFile) !== $className) {
        // Never delete database/workspace state for an unverified directory. A
        // third-party extension may happen to reuse a retired bundled basename.
        $ok = false;
        continue;
      }
      if (!Filesystem::deleteRecursive($path)) { $ok = false; continue; }
      $stateCleanupAllowed[$directory] = true;
    }
  } else {
    // If the plugin root itself cannot be verified, no extension state should be
    // destructively cleaned in this request.
    foreach (brendyRetiredBundledPluginDirectories() as $directory) {
      $stateCleanupAllowed[$directory] = false;
    }
    $ok = false;
  }

  // State belonging to fully removed bundled extensions is obsolete as well,
  // but only after the corresponding source directory is known to be absent or
  // was positively identified and deleted above.
  foreach (brendyRetiredBundledPluginStateDirectories() as $directory) {
    if (empty($stateCleanupAllowed[$directory])) continue;
    foreach (array(PATH_PLUGINS_DATABASES . $directory, PATH_WORKSPACES . $directory) as $path) {
      if (!file_exists($path) && !is_link($path)) continue;
      if (is_link($path) || !Filesystem::deleteRecursive($path)) $ok = false;
    }
  }
  return $ok;
}

function brendyRetiredBundledPluginStateDirectories()
{
  return array('custom-fields-parser', 'domain-migrator', 'html-code', 'rss', 'static-pages', 'visits-stats');
}

/**
 * Preserve the useful Static Pages sidebar behaviour before its retired plugin
 * state is removed. Navigation becomes the single owner of page-tree sidebar
 * rendering in Brendy 2.20.
 */
function brendyMigrateRetiredStaticPagesToNavigation()
{
  $legacyFile = PATH_PLUGINS_DATABASES . 'static-pages' . DS . 'db.php';
  if (!is_file($legacyFile) || is_link($legacyFile)) return true;

  // If a source directory still exists, migrate only when it is positively
  // identified as Brendy's historical Static Pages extension. An unrelated
  // third-party directory reusing the old basename must not influence current
  // Navigation settings merely because its database has similarly named keys.
  $legacySource = PATH_PLUGINS . 'static-pages';
  if (file_exists($legacySource) || is_link($legacySource)) {
    $pluginFile = $legacySource . DS . 'plugin.php';
    if (is_link($legacySource) || !is_dir($legacySource) || !is_file($pluginFile) || is_link($pluginFile) ||
        brendyPluginClassNameFromSource($pluginFile) !== 'BrendyPluginStaticPages') {
      return true;
    }
  }

  global $plugins;
  if (!isset($plugins['all']['BrendyPluginNavigation']) ||
      !($plugins['all']['BrendyPluginNavigation'] instanceof BrendyPlugin)) return false;
  $navigation = $plugins['all']['BrendyPluginNavigation'];

  $legacyStore = new BrendyJsonDatabase($legacyFile);
  if (!$legacyStore->loadedSuccessfully() || !is_array($legacyStore->db)) return false;
  $legacy = $legacyStore->db;

  if ($navigation->installed()) {
    return $navigation->setFields(array('showStaticPages' => true));
  }

  $position = isset($legacy['position']) ? brendyIntegerValue($legacy['position'], 1, 0, 10000) : 1;
  if ($position === null) $position = 1;
  if (!$navigation->install($position)) return false;

  $label = isset($legacy['label']) && is_string($legacy['label'])
    ? trim(Sanitize::htmlDecode($legacy['label'])) : '';
  if ($label === '' || Text::length($label) > 200) $label = 'Static Pages';
  $homeLink = array_key_exists('homeLink', $legacy) ? brendyBooleanValue($legacy['homeLink'], true) : true;
  if ($homeLink === null) $homeLink = true;

  if (!$navigation->setFields(array(
    'label' => $label,
    'homeLink' => $homeLink,
    'showPublishedPages' => false,
    'showStaticPages' => true
  ))) {
    $navigation->uninstall();
    return false;
  }
  return true;
}

function brendyPluginClassAliases()
{
  static $aliases = array(
    'pluginAPI' => 'BrendyPluginAPI',
    'pluginBackupManager' => 'BrendyPluginBackupManager',
    'pluginCategories' => 'BrendyPluginCategories',
    'pluginComments' => 'BrendyPluginComments',
    'pluginGallery' => 'BrendyPluginGallery',
    'pluginNavigation' => 'BrendyPluginNavigation',
    'pluginSearch' => 'BrendyPluginSearch',
    'pluginSitemap' => 'BrendyPluginSitemap',
    'pluginTags' => 'BrendyPluginTags',
  );

  return $aliases;
}

function normalizeBrendyPluginClassName($pluginClassName)
{
  if (!is_string($pluginClassName)) return '';

  $pluginClassName = trim($pluginClassName);
  if ($pluginClassName === '' || strlen($pluginClassName) > 200) return '';

  static $lookup = null;
  if ($lookup === null) {
    $lookup = array();
    foreach (brendyPluginClassAliases() as $alias => $canonical) {
      $lookup[strtolower($alias)] = $canonical;
      $lookup[strtolower($canonical)] = $canonical;
    }
  }

  $key = strtolower($pluginClassName);
  return isset($lookup[$key]) ? $lookup[$key] : $pluginClassName;
}

/**
 * Resolve a plugin class from its source directory instead of assuming that a
 * directory name is itself a PHP class name. This is required by legacy/theme
 * companion plugins: runtime plugin registries are keyed by BrendyPlugin* class,
 * while themes naturally refer to their companion by a shared directory slug.
 *
 * Source lookup is opt-in and is used only on explicit extension-management
 * surfaces before an inactive companion has been loaded. Normal runtime calls
 * reuse the already-audited catalog/loaded plugin objects.
 */
function brendyPluginClassNameForDirectory($directoryName, $allowSourceLookup = false)
{
  global $plugins, $pluginsCatalog;

  if (!is_string($directoryName) || !brendyIsSafeExtensionDirectoryName($directoryName)) return '';

  if (isset($plugins['all']) && is_array($plugins['all'])) {
    foreach ($plugins['all'] as $className => $plugin) {
      if (!is_string($className) || !is_object($plugin) || !method_exists($plugin, 'directoryName')) continue;
      $pluginDirectory = $plugin->directoryName();
      if (is_string($pluginDirectory) && hash_equals($directoryName, $pluginDirectory)) {
        return normalizeBrendyPluginClassName($className);
      }
    }
  }

  if (isset($pluginsCatalog) && is_array($pluginsCatalog)) {
    foreach ($pluginsCatalog as $catalogEntry) {
      if (!is_array($catalogEntry) || !isset($catalogEntry['directory'], $catalogEntry['className']) ||
          !is_string($catalogEntry['directory']) || !is_string($catalogEntry['className']) ||
          !hash_equals($directoryName, $catalogEntry['directory'])) continue;
      $className = normalizeBrendyPluginClassName($catalogEntry['className']);
      if (preg_match('/^BrendyPlugin[A-Za-z0-9_]{1,120}$/D', $className) === 1) return $className;
    }
  }

  if ($allowSourceLookup !== true || !defined('PATH_PLUGINS')) return '';
  $pluginsRoot = realpath(PATH_PLUGINS);
  $pluginDirectory = realpath(PATH_PLUGINS . $directoryName);
  if ($pluginsRoot === false || $pluginDirectory === false || is_link(PATH_PLUGINS . $directoryName) ||
      strpos($pluginDirectory, $pluginsRoot . DS) !== 0) return '';
  $pluginFile = $pluginDirectory . DS . 'plugin.php';
  if (!is_file($pluginFile) || is_link($pluginFile) || !is_readable($pluginFile)) return '';
  $className = brendyPluginClassNameFromSource($pluginFile);
  return preg_match('/^BrendyPlugin[A-Za-z0-9_]{1,120}$/D', $className) === 1 ? $className : '';
}

function getPluginByDirectory($directoryName)
{
  $className = brendyPluginClassNameForDirectory($directoryName, false);
  return $className !== '' ? getPlugin($className) : false;
}

// Returns the Plugin-Object if is enabled and installed, FALSE otherwise
function getPlugin($pluginClassName)
{
  global $plugins;

  $pluginClassName = normalizeBrendyPluginClassName($pluginClassName);

  if (pluginActivated($pluginClassName)) {
    return $plugins['all'][$pluginClassName];
  }
  return false;
}

// Returns TRUE if the plugin is activaed / installed, FALSE otherwise
function pluginActivated($pluginClassName)
{
  global $plugins;

  $pluginClassName = normalizeBrendyPluginClassName($pluginClassName);

  if (isset($plugins['all'][$pluginClassName])) {
    $plugin = $plugins['all'][$pluginClassName];
    return method_exists($plugin, 'runtimeReady') ? $plugin->runtimeReady() : $plugin->installed();
  }
  return false;
}

/**
 * Serialize extension lifecycle mutations (install/activate/deactivate/delete)
 * across concurrent administrator requests. The lock is request-reentrant so a
 * theme activation can safely activate/deactivate its companion plugin without
 * deadlocking on the same process-local lifecycle boundary.
 */
function brendyAcquireExtensionMutationLock()
{
  if (isset($GLOBALS['_brendyExtensionMutationLockHandle'], $GLOBALS['_brendyExtensionMutationLockDepth']) &&
      is_resource($GLOBALS['_brendyExtensionMutationLockHandle']) &&
      is_int($GLOBALS['_brendyExtensionMutationLockDepth']) && $GLOBALS['_brendyExtensionMutationLockDepth'] > 0) {
    $GLOBALS['_brendyExtensionMutationLockDepth']++;
    return true;
  }
  if (!defined('PATH_TMP') || !is_string(PATH_TMP) || PATH_TMP === '' || is_link(PATH_TMP)) return false;
  if (!is_dir(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) return false;
  if (!is_dir(PATH_TMP) || is_link(PATH_TMP)) return false;

  $handle = Filesystem::acquireLock(PATH_TMP . 'extension-mutation.lock', LOCK_EX | LOCK_NB, 0600);
  if ($handle === false) return false;
  $GLOBALS['_brendyExtensionMutationLockHandle'] = $handle;
  $GLOBALS['_brendyExtensionMutationLockDepth'] = 1;
  return true;
}

function brendyReleaseExtensionMutationLock()
{
  if (!isset($GLOBALS['_brendyExtensionMutationLockHandle'], $GLOBALS['_brendyExtensionMutationLockDepth']) ||
      !is_resource($GLOBALS['_brendyExtensionMutationLockHandle']) ||
      !is_int($GLOBALS['_brendyExtensionMutationLockDepth']) || $GLOBALS['_brendyExtensionMutationLockDepth'] < 1) return false;

  $GLOBALS['_brendyExtensionMutationLockDepth']--;
  if ($GLOBALS['_brendyExtensionMutationLockDepth'] > 0) return true;
  $handle = $GLOBALS['_brendyExtensionMutationLockHandle'];
  unset($GLOBALS['_brendyExtensionMutationLockHandle'], $GLOBALS['_brendyExtensionMutationLockDepth']);
  return Filesystem::releaseLock($handle);
}

function activatePlugin($pluginClassName)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyActivatePluginUnlocked($pluginClassName);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyActivatePluginUnlocked($pluginClassName)
{
  global $plugins;
  global $syslog;
  global $L;

  $pluginClassName = normalizeBrendyPluginClassName($pluginClassName);

  // Check if the plugin exists
  if (isset($plugins['all'][$pluginClassName])) {
    $plugin = $plugins['all'][$pluginClassName];
    if (!method_exists($plugin, 'isCompatible') || !$plugin->isCompatible()) return false;
    if (method_exists($plugin, 'healthStatus')) {
      $health = $plugin->healthStatus();
      if (!is_array($health) || ($health['status'] ?? 'error') === 'error') return false;
    }
    if ($plugin->install()) {
      // Add to syslog
      $syslog->add(array(
        'dictionaryKey' => 'plugin-activated',
        'notes' => $plugin->name()
      ));

      // Create an alert
      Alert::set($L->g('plugin-activated'));
      return true;
    }
  }
  return false;
}

function removeBrendyPluginState($directoryName)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyRemovePluginStateUnlocked($directoryName);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyRemovePluginStateUnlocked($directoryName)
{
  if (!brendyIsSafeExtensionDirectoryName($directoryName)) return false;
  $databaseDirectory = PATH_PLUGINS_DATABASES . $directoryName;
  $workspace = PATH_WORKSPACES . $directoryName . DS;

  // Never follow or silently remove anomalous plugin state nodes. A symlink,
  // regular file or other non-directory object in a state location requires
  // explicit filesystem intervention before the extension can be trusted.
  $databaseNodeExists = file_exists($databaseDirectory) || is_link($databaseDirectory);
  $workspaceNodeExists = file_exists($workspace) || is_link($workspace);
  if (($databaseNodeExists && (is_link($databaseDirectory) || !is_dir($databaseDirectory))) ||
      ($workspaceNodeExists && (is_link($workspace) || !is_dir($workspace)))) {
    Log::set(__FUNCTION__ . LOG_SEP . 'Refusing anomalous plugin state node for ' . $directoryName, LOG_TYPE_ERROR);
    return false;
  }

  $hasDatabase = Filesystem::directoryExists($databaseDirectory);
  $hasWorkspace = Filesystem::directoryExists($workspace);
  if (!$hasDatabase && !$hasWorkspace) return true;

  try {
    $suffix = bin2hex(random_bytes(12));
  } catch (Throwable $e) {
    return false;
  }
  $databaseStage = PATH_PLUGINS_DATABASES . '.uklanjanje-' . $directoryName . '-' . $suffix;
  $workspaceStage = PATH_WORKSPACES . '.uklanjanje-' . $directoryName . '-' . $suffix;
  $databaseStaged = false;
  $workspaceStaged = false;

  if ($hasWorkspace) {
    if (!Filesystem::mv($workspace, $workspaceStage)) return false;
    $workspaceStaged = true;
  }
  if ($hasDatabase) {
    if (!Filesystem::mv($databaseDirectory, $databaseStage)) {
      if ($workspaceStaged && !Filesystem::mv($workspaceStage, $workspace)) {
        Log::set(__FUNCTION__ . LOG_SEP . 'Unable to roll back plugin workspace for ' . $directoryName, LOG_TYPE_ERROR);
      }
      return false;
    }
    $databaseStaged = true;
  }

  if ($databaseStaged && !Filesystem::deleteRecursive($databaseStage)) {
    Log::set(__FUNCTION__ . LOG_SEP . 'Unable to remove staged plugin database for ' . $directoryName, LOG_TYPE_ERROR);
  }
  if ($workspaceStaged && !Filesystem::deleteRecursive($workspaceStage)) {
    Log::set(__FUNCTION__ . LOG_SEP . 'Unable to remove staged plugin workspace for ' . $directoryName, LOG_TYPE_ERROR);
  }
  return true;
}

function deactivatePlugin($pluginClassName)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyDeactivatePluginUnlocked($pluginClassName);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyDeactivatePluginUnlocked($pluginClassName)
{
  global $plugins;
  global $syslog;
  global $L;

  $pluginClassName = normalizeBrendyPluginClassName($pluginClassName);

  // Check if the plugin exists
  if (isset($plugins['all'][$pluginClassName])) {
    $plugin = $plugins['all'][$pluginClassName];

    if ($plugin->uninstall()) {
      // Add to syslog
      $syslog->add(array(
        'dictionaryKey' => 'plugin-deactivated',
        'notes' => $plugin->name()
      ));

      // Create an alert
      Alert::set($L->g('plugin-deactivated'));
      return true;
    }
  }
  return false;
}

function deactivateAllPlugin()
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyDeactivateAllPluginUnlocked();
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyDeactivateAllPluginUnlocked()
{
  global $plugins;
  global $syslog;

  $success = true;
  foreach ($plugins['all'] as $plugin) {
    // Do not report already inactive plugins as newly deactivated.
    if (!$plugin->installed()) continue;

    if ($plugin->uninstall()) {
      $syslog->add(array(
        'dictionaryKey' => 'plugin-deactivated',
        'notes' => $plugin->name()
      ));
    } else {
      $success = false;
      Log::set(__METHOD__ . LOG_SEP . 'Unable to deactivate plugin: ' . $plugin->directoryName(), LOG_TYPE_ERROR);
    }
  }
  return $success;
}

function changePluginsPosition($pluginClassList)
{
  global $plugins;
  global $syslog;
  global $L;

  if (!is_array($pluginClassList) || count($pluginClassList) > 500 ||
      !checkRole(array('admin'), false)) return false;

  $validated = array();
  $canonicalList = array();
  foreach ($pluginClassList as $pluginClassName) {
    $pluginClassName = normalizeBrendyPluginClassName($pluginClassName);
    if ($pluginClassName === '' ||
        !isset($plugins['all'][$pluginClassName]) || isset($validated[$pluginClassName])) {
      Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
      return false;
    }
    $validated[$pluginClassName] = true;
    $canonicalList[] = $pluginClassName;
  }

  // Plugin positions live in independent JSON files, so treat the sequence as
  // a small transaction: remember the original values and roll back every
  // successful write if a later plugin cannot be saved.
  $originalPositions = array();
  $changedPlugins = array();
  foreach ($canonicalList as $pluginClassName) {
    $position = $plugins['all'][$pluginClassName]->getValue('position');
    $originalPositions[$pluginClassName] = is_numeric($position) ? (int)$position : 0;
  }

  foreach ($canonicalList as $position => $pluginClassName) {
    $newPosition = $position + 1;
    if ($originalPositions[$pluginClassName] === $newPosition) continue;

    $plugin = $plugins['all'][$pluginClassName];
    if (!$plugin->setPosition($newPosition)) {
      $rollbackFailed = false;
      foreach (array_reverse($changedPlugins) as $changedClassName) {
        if (!$plugins['all'][$changedClassName]->setPosition($originalPositions[$changedClassName])) {
          $rollbackFailed = true;
          Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back plugin position: ' . $changedClassName, LOG_TYPE_ERROR);
        }
      }
      if ($rollbackFailed) {
        Log::set(__METHOD__ . LOG_SEP . 'Plugin ordering rollback was incomplete.', LOG_TYPE_ERROR);
      }
      Alert::set($L->g('The changes could not be saved'), ALERT_STATUS_FAIL);
      return false;
    }
    $changedPlugins[] = $pluginClassName;
  }

  // Add to syslog
  $syslog->add(array(
    'dictionaryKey' => 'plugins-sorted',
    'notes' => ''
  ));

  Alert::set($L->g('The changes have been saved'));
  return true;
}

/*
	Create a new page

	The array $args support all the keys from variable $dbFields of the class pages.class.php
	If you don't pass all the keys, the default values are used, the default values are from $dbFields in the class pages.class.php
*/
function createPage($args, $actorUsername = null)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return createPageUnlocked($args, $actorUsername);
  } finally {
    releaseContentMutationLock();
  }
}

function createPageUnlocked($args, $actorUsername = null)
{
  global $pages;
	global $users;
  global $syslog;
  global $L;
	if (!is_array($args)) {
		return false;
	}
	if ($actorUsername !== null && (!is_string($actorUsername) || $actorUsername === '')) return false;
	$resolvedUsername = $actorUsername !== null ? $actorUsername : Session::get('username');
	if (!is_string($resolvedUsername) || $resolvedUsername === '') {
		Log::set('Function createPage()' . LOG_SEP . 'Empty username.', LOG_TYPE_ERROR);
		return false;
	}
	$actor = $users->getUserDB($resolvedUsername);
	$actorRole = is_array($actor) && isset($actor['role']) && is_string($actor['role']) ? $actor['role'] : '';
	if (!in_array($actorRole, array('admin', 'editor', 'author'), true)) return false;
	if ($actorRole === 'author' && isset($args['parent']) && is_string($args['parent']) && $args['parent'] !== '') {
		$parentRow = $pages->getPageDB($args['parent']);
		if (!is_array($parentRow) || !isset($parentRow['username']) ||
			!is_string($parentRow['username']) || $parentRow['username'] !== $resolvedUsername) return false;
	}
	$autosaveKeyToDelete = false;
	$isAutosaveMutation = isset($args['type']) && $args['type'] === 'autosave';

	// Check whether an owned autosave exists. Keep it until the permanent page
	// has been saved successfully so a storage failure cannot destroy recovery
	// content.
  if (isset($args['uuid'])) {
		if (!is_string($args['uuid'])) {
			return false;
		}
		if ($isAutosaveMutation) {
			if (preg_match('/^autosave-([a-f0-9]{32})$/iD', $args['uuid'], $matches) !== 1) {
				return false;
			}
			if ($actorUsername === null && !isIssuedNewPageUuid($matches[1])) {
				$originalKey = $pages->getByUUID($matches[1]);
				$originalRow = $originalKey !== false ? $pages->getPageDB($originalKey) : false;
				$sessionRole = Session::get('role');
				if (!is_array($originalRow) ||
					(!in_array($sessionRole, array('admin', 'editor'), true) &&
					 (!isset($originalRow['username']) || $originalRow['username'] !== $resolvedUsername))) {
					return false;
				}
			}
		} else {
			if (!isValidPageUuid($args['uuid']) || ($actorUsername === null && !isIssuedNewPageUuid($args['uuid']))) {
				return false;
			}
			$autosaveKey = $pages->getByUUID('autosave-' . $args['uuid']);
			if ($autosaveKey !== false) {
				$autosaveRow = $pages->getPageDB($autosaveKey);
				if (!is_array($autosaveRow) || !isset($autosaveRow['username']) ||
					$autosaveRow['username'] !== $resolvedUsername) {
					return false;
				}
				$autosaveKeyToDelete = $autosaveKey;
			}
		}
  }

  // The user is always the one logged
	$args['username'] = $resolvedUsername;

  $key = $pages->add($args);
  if ($key !== false && $key !== '') {
	if ($autosaveKeyToDelete !== false) {
		if (deletePage($autosaveKeyToDelete, $resolvedUsername)) {
			Log::set('Function createPage()' . LOG_SEP . 'Owned autosave deleted after page creation.', LOG_TYPE_INFO);
		} else {
			Log::set('Function createPage()' . LOG_SEP . 'Page created, but the old autosave could not be removed.', LOG_TYPE_ERROR);
		}
	}
    if (!$isAutosaveMutation) {
      refreshContentDerivedState('afterPageCreate', $key);

      // Autosave is private recovery state, not a public content event.
      $syslog->add(array(
        'dictionaryKey' => 'new-content-created',
        'notes' => (isset($args['title']) && is_string($args['title']) && $args['title'] !== '' ? $args['title'] : $key),
        'username' => $actorUsername
      ));
    }

    return $key;
  }

  Log::set('Function createNewPage()' . LOG_SEP . 'Error occurred when trying to create the page', LOG_TYPE_ERROR);
  return false;
}

function brendyReconcileSitePageReferences()
{
  global $site, $pages;
  if (!isset($site, $pages) || !is_object($site) || !is_object($pages)) return false;
  $changes = array();
  $homepage = $site->homepage();
  if ($homepage !== false && !$pages->isPubliclyAccessible($homepage)) $changes['homepage'] = '';
  $notFound = $site->pageNotFound();
  if (is_string($notFound) && $notFound !== '' && !$pages->isPubliclyAccessible($notFound)) $changes['pageNotFound'] = '';
  return empty($changes) ? true : $site->set($changes);
}

function brendyRemapPageReference($reference, $oldRoot, $newRoot)
{
  if (!is_string($reference) || !is_string($oldRoot) || !is_string($newRoot) ||
      $reference === '' || $oldRoot === '' || $newRoot === '') return $reference;
  if ($reference === $oldRoot) return $newRoot;
  $prefix = $oldRoot . '/';
  if (Text::startsWith($reference, $prefix)) return $newRoot . substr($reference, strlen($oldRoot));
  return $reference;
}

function editPage($args, $actorUsername = null)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return editPageUnlocked($args, $actorUsername);
  } finally {
    releaseContentMutationLock();
  }
}

function editPageUnlocked($args, $actorUsername = null)
{
  global $pages;
  global $users;
  global $site;
  global $syslog;
	if (!is_array($args) || !isset($args['key']) || !is_string($args['key'])) {
		return false;
	}
	if ($actorUsername !== null && (!is_string($actorUsername) || $actorUsername === '')) return false;
	$resolvedUsername = $actorUsername !== null ? $actorUsername : Session::get('username');
	if (!is_string($resolvedUsername) || $resolvedUsername === '') return false;
	$actor = $users->getUserDB($resolvedUsername);
	$actorRole = is_array($actor) && isset($actor['role']) && is_string($actor['role']) ? $actor['role'] : '';
	if (!in_array($actorRole, array('admin', 'editor', 'author'), true)) return false;
	$autosaveKeyToDelete = false;

  // Check if the key is not empty
  if ($args['key'] === '') {
    Log::set('Function editPage()' . LOG_SEP . 'Empty key.', LOG_TYPE_ERROR);
    return false;
  }

  // Check if the page key exist
  if (!$pages->exists($args['key'])) {
    Log::set('Function editPage()' . LOG_SEP . 'Page key does not exist, ' . $args['key'], LOG_TYPE_ERROR);
    return false;
  }
	$currentRow = $pages->getPageDB($args['key']);
	if (!is_array($currentRow) || !isset($currentRow['username']) || !is_string($currentRow['username']) ||
		($actorRole === 'author' && $currentRow['username'] !== $resolvedUsername)) return false;
	$currentType = isset($currentRow['type']) && is_string($currentRow['type']) ? $currentRow['type'] : '';
	$isAutosaveMutation = $currentType === 'autosave';
	if (isset($args['contentVersion']) && !$isAutosaveMutation) {
		if (!is_string($args['contentVersion']) || preg_match('/^[a-f0-9]{64}$/iD', $args['contentVersion']) !== 1 ||
			!hash_equals(pageVersionFingerprint($args['key'], $currentRow), strtolower($args['contentVersion']))) {
			global $L;
			Alert::set($L->g('This content was changed in another session. Reload the page before saving again.'), ALERT_STATUS_FAIL);
			return false;
		}
	}
	if ($actorRole === 'author' && isset($args['parent']) && is_string($args['parent']) && $args['parent'] !== '') {
		$parentRow = $pages->getPageDB($args['parent']);
		if (!is_array($parentRow) || !isset($parentRow['username']) ||
			!is_string($parentRow['username']) || $parentRow['username'] !== $resolvedUsername) return false;
	}
	// Page ownership is immutable through the general edit endpoint. Dedicated
	// user-transfer operations are the only supported way to reassign content.
	$args['username'] = $currentRow['username'];

  // Validate the submitted UUID against the target before deleting an
  // autosave. Previously a crafted edit could delete another page's preview
  // even though the subsequent page edit itself failed.
  if (isset($args['uuid'])) {
	if (!is_string($args['uuid'])) return false;
	if (!is_array($currentRow) || !isset($currentRow['uuid']) ||
		!is_string($currentRow['uuid']) || !hash_equals($currentRow['uuid'], $args['uuid'])) {
		return false;
	}
	if (strpos($args['uuid'], 'autosave-') !== 0) {
		$autosaveKey = $pages->getByUUID('autosave-' . $args['uuid']);
		if ($autosaveKey !== false) {
			$autosaveRow = $pages->getPageDB($autosaveKey);
			if (!is_array($autosaveRow) || !isset($autosaveRow['username']) ||
				$autosaveRow['username'] !== $resolvedUsername) {
				return false;
			}
			$autosaveKeyToDelete = $autosaveKey;
		}
	}
  }

  // Predict a possible root/subtree key change before committing the page edit.
  // Homepage and custom-404 references are stored by page key, so a rename or
  // move must update those references in the same mutation workflow.
  $oldKey = $args['key'];
  $slash = strrpos($oldKey, '/');
  $currentParent = $slash === false ? '' : substr($oldKey, 0, $slash);
  $currentSlug = $slash === false ? $oldKey : substr($oldKey, $slash + 1);
  $requestedParent = array_key_exists('parent', $args) ? $args['parent'] : $currentParent;
  $requestedSlug = (!array_key_exists('slug', $args) || $args['slug'] === '') ? $currentSlug : $args['slug'];
  if (!is_string($requestedParent) || !is_string($requestedSlug)) return false;
  $predictedKey = $pages->generateKey($requestedSlug, $requestedParent, false, $oldKey);
  if (!is_string($predictedKey) || $predictedKey === '') return false;

  $siteBeforeEdit = $site->db;
  $siteChangesForRename = array();
  if ($predictedKey !== $oldKey) {
    $homepage = $site->homepage();
    if ($homepage !== false) {
      $mapped = brendyRemapPageReference($homepage, $oldKey, $predictedKey);
      if ($mapped !== $homepage) $siteChangesForRename['homepage'] = $mapped;
    }
    $notFound = $site->pageNotFound();
    if (is_string($notFound) && $notFound !== '') {
      $mapped = brendyRemapPageReference($notFound, $oldKey, $predictedKey);
      if ($mapped !== $notFound) $siteChangesForRename['pageNotFound'] = $mapped;
    }
    if (!empty($siteChangesForRename) && !$site->set($siteChangesForRename)) return false;
  }

  if ($currentType !== 'autosave' && empty($GLOBALS['_brendySkipRevisionCapture'])) {
	if (createPageRevision($args['key'], $resolvedUsername, 'edit') === false) {
		Log::set('Function editPage()' . LOG_SEP . 'Unable to create a pre-edit revision for ' . $args['key'], LOG_TYPE_WARN);
	}
  }

  $key = $pages->edit($args);
  if ($key !== false && $key !== '') {
    if ($key !== $predictedKey) {
      // Defensive recovery: generateKey() should be deterministic under the
      // content lock. If this invariant is ever broken, repair references to
      // the actually committed key rather than leaving stale configuration.
      $actualChanges = array();
      $homepage = isset($siteChangesForRename['homepage']) ? $siteChangesForRename['homepage'] : $site->homepage();
      if (is_string($homepage) && $homepage !== '') {
        $mapped = brendyRemapPageReference($homepage, $predictedKey, $key);
        if ($mapped !== $homepage) $actualChanges['homepage'] = $mapped;
      }
      $notFound = isset($siteChangesForRename['pageNotFound']) ? $siteChangesForRename['pageNotFound'] : $site->pageNotFound();
      if (is_string($notFound) && $notFound !== '') {
        $mapped = brendyRemapPageReference($notFound, $predictedKey, $key);
        if ($mapped !== $notFound) $actualChanges['pageNotFound'] = $mapped;
      }
      if (!empty($actualChanges) && !$site->set($actualChanges)) {
        Log::set(__METHOD__ . LOG_SEP . 'Page key changed unexpectedly and site references could not be repaired.', LOG_TYPE_ERROR);
      }
    }
	if ($autosaveKeyToDelete !== false) {
		if (deletePage($autosaveKeyToDelete, $resolvedUsername)) {
			Log::set('Function editPage()' . LOG_SEP . 'Owned autosave deleted after editing ' . $key, LOG_TYPE_INFO);
		} else {
			Log::set('Function editPage()' . LOG_SEP . 'Page edited, but the old autosave could not be removed.', LOG_TYPE_ERROR);
		}
	}
    if (!$isAutosaveMutation) {
      refreshContentDerivedState('afterPageModify', $key);
      if (!brendyReconcileSitePageReferences()) {
        Log::set(__METHOD__ . LOG_SEP . 'Page edited but Homepage/404 references could not be reconciled.', LOG_TYPE_ERROR);
      }

      $syslog->add(array(
        'dictionaryKey' => 'content-edited',
        'notes' => isset($args['title']) && is_string($args['title']) && $args['title'] !== '' ? $args['title'] : $key,
        'username' => $actorUsername
      ));
    }

    return $key;
  }

  if (!empty($siteChangesForRename) && !$site->set($siteBeforeEdit)) {
    Log::set(__METHOD__ . LOG_SEP . 'Unable to restore site page references after page edit failed.', LOG_TYPE_ERROR);
  }
  Log::set('Function editPage()' . LOG_SEP . 'Something happen when try to edit the page.', LOG_TYPE_ERROR);
  return false;
}

function deletePage($key, $actorUsername = null)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return deletePageUnlocked($key, $actorUsername);
  } finally {
    releaseContentMutationLock();
  }
}

function deletePageUnlocked($key, $actorUsername = null)
{
  global $pages;
  global $users;
  global $site;
  global $syslog;

  if (!is_string($key) || $key === '' ||
      ($actorUsername !== null && (!is_string($actorUsername) || $actorUsername === ''))) {
    return false;
  }
  $resolvedUsername = $actorUsername !== null ? $actorUsername : Session::get('username');
  if (!is_string($resolvedUsername) || $resolvedUsername === '') return false;
  $actor = $users->getUserDB($resolvedUsername);
  $actorRole = is_array($actor) && isset($actor['role']) && is_string($actor['role']) ? $actor['role'] : '';
  $row = $pages->getPageDB($key);
  $isAutosaveMutation = is_array($row) && isset($row['type']) && $row['type'] === 'autosave';
  if (!in_array($actorRole, array('admin', 'editor', 'author'), true) || !is_array($row) ||
      !isset($row['username']) || !is_string($row['username']) ||
      ($actorRole === 'author' && $row['username'] !== $resolvedUsername)) return false;

  // Site settings must never keep a dangling reference to a deleted page. Apply
  // the small settings mutation first and roll it back if the page transaction
  // itself fails; both operations run under the content mutation lock.
  $siteBefore = $site->db;
  $siteChanges = array();
  if ($site->homepage() === $key) $siteChanges['homepage'] = '';
  if ($site->pageNotFound() === $key) $siteChanges['pageNotFound'] = '';
  if (!empty($siteChanges) && !$site->set($siteChanges)) return false;

  if ($pages->delete($key)) {
    if (!$isAutosaveMutation) {
      refreshContentDerivedState('afterPageDelete', $key);

      $syslog->add(array(
        'dictionaryKey' => 'content-deleted',
        'notes' => $key,
        'username' => $actorUsername
      ));
    }

    return true;
  }

  if (!empty($siteChanges) && !$site->set($siteBefore)) {
    Log::set(__METHOD__ . LOG_SEP . 'Unable to restore site page references after page deletion failed.', LOG_TYPE_ERROR);
  }
  return false;
}

function editUser($args)
{
  global $users;
  global $login;
  global $syslog;

  if (!is_array($args) || !isset($args['username']) || !is_string($args['username']) ||
      !$users->exists($args['username'])) {
    return false;
  }
  $username = $args['username'];
  if ($login->role() !== 'admin' && $login->username() !== $username) {
    return false;
  }
  if (isset($args['role'])) {
    if ($login->role() !== 'admin' || !is_string($args['role']) ||
        !in_array($args['role'], array('admin', 'editor', 'author'), true) ||
        ($username === 'admin' && $args['role'] !== 'admin')) {
      return false;
    }
  }
  if (isset($args['email']) && $args['email'] !== '' &&
      (!is_string($args['email']) || !filter_var($args['email'], FILTER_VALIDATE_EMAIL))) {
    return false;
  }

  if ($users->set($args)) {
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'user-edited',
      'notes' => $args['username']
    ));

    return true;
  }

  return false;
}

// Rotate an authenticated session after a credential or privilege boundary.
// The session identifier and CSRF token move together so a copied pre-change
// session cannot inherit newly-authorized state. Failure-bucket cleanup is
// best-effort and never weakens an otherwise successful session rotation.
function brendyRotateAuthenticatedSession($newToken = null, $username = null)
{
  global $security;

  if ($newToken !== null) {
    if (!is_string($newToken) || $newToken === '' || !Session::set('tokenAuth', $newToken)) return false;
  }
  if (!Session::regenerate(true)) return false;
  if (!Session::set('sessionRotated', time())) return false;

  if (!isset($security) || !is_object($security) || !method_exists($security, 'generateTokenCSRF')) return false;
  $csrf = $security->generateTokenCSRF();
  if (!is_string($csrf) || preg_match('/^[a-f0-9]{64}$/D', $csrf) !== 1) return false;
  if (is_string($username) && $username !== '' && method_exists($security, 'clearLoginFailures') &&
      !$security->clearLoginFailures(null, $username)) {
    Log::set(__METHOD__ . LOG_SEP . 'Session rotated but the authentication failure bucket could not be cleared.', LOG_TYPE_WARN);
  }
  return true;
}

function regenerateUserAuthToken($args)
{
  global $users;
  global $login;
  global $syslog;

  if (!is_array($args) || !isset($args['username']) || !is_string($args['username']) ||
      !$users->exists($args['username'])) {
    return false;
  }
  $username = $args['username'];
  if ($login->role() !== 'admin' && $login->username() !== $username) {
    return false;
  }

  $token = $users->rotateAuthToken($username);
  if (!is_string($token)) {
    return false;
  }

  if ($login->username() === $username) {
    // Token-bound sessions would otherwise revoke the user who requested the
    // rotation. Rotate both the PHP session ID and CSRF token at the boundary.
    if (!brendyRotateAuthenticatedSession($token, $username)) {
      Session::destroy();
      return false;
    }
    $login->invalidateRememberMe(false);
  }

  $syslog->add(array(
    'dictionaryKey' => 'authentication-token-regenerated',
    'notes' => $username
  ));
  return true;
}

function disableUser($args)
{
  global $users;
  global $login;
  global $syslog;

	if (!is_array($args) || !isset($args['username']) || !is_string($args['username'])) {
		return false;
	}

  // Arguments
  $username = $args['username'];

  // Only administrators can disable users
  if ($login->role() !== 'admin') {
    return false;
  }

  // Never allow the primary administrator or the current account to disable
  // itself. This prevents accidental and CSRF-assisted administrative lockout.
  if ($username === 'admin' || $username === $login->username()) {
    return false;
  }

  // Check if the username exists
  if (!$users->exists($username)) {
    return false;
  }

  // Disable the user
  if ($users->disableUser($username)) {
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'user-disabled',
      'notes' => $username
    ));

    return true;
  }

  return false;
}

function enableUser($args)
{
  global $users;
  global $login;
  global $syslog;

  if (!is_array($args) || !isset($args['username']) || !is_string($args['username'])) {
    return false;
  }
  $username = $args['username'];
  if ($login->role() !== 'admin' || $username === 'admin' || !$users->exists($username)) {
    return false;
  }
  if ($users->enableUser($username)) {
    $syslog->add(array(
      'dictionaryKey' => 'user-enabled',
      'notes' => $username
    ));
    return true;
  }
  return false;
}

function deleteUser($args)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return deleteUserUnlocked($args);
  } finally {
    releaseContentMutationLock();
  }
}

function deleteUserUnlocked($args)
{
  global $users, $pages, $site;
  global $login;
  global $syslog;

	if (!is_array($args) || !isset($args['username']) || !is_string($args['username'])) {
		return false;
	}

  // Arguments
  $username = $args['username'];
  $deleteContent = isset($args['deleteContent']) ? $args['deleteContent'] : false;
  if (!is_bool($deleteContent)) return false;

  // Only administrators can delete users
  if ($login->role() !== 'admin') {
    return false;
  }

  // The user admin cannot be deleted
  if ($username === 'admin') {
    return false;
  }

  // Check if the username exists
  if (!$users->exists($username)) {
    return false;
  }

  // Commit the user removal first, then mutate page ownership/content. If the
  // page operation fails, restore the user database so the account does not
  // disappear while its content remains in an inconsistent state. When content
  // is deleted, clear Homepage/404 references to pages owned by that account as
  // part of the same mutation workflow.
  $usersBefore = $users->db;
  $siteBefore = $site->db;
  if (!$users->delete($username)) return false;

  $siteChanges = array();
  if ($deleteContent) {
    $homepage = $site->homepage();
    if ($homepage !== false) {
      $row = $pages->getPageDB($homepage);
      if (is_array($row) && isset($row['username']) && $row['username'] === $username) $siteChanges['homepage'] = '';
    }
    $notFound = $site->pageNotFound();
    if (is_string($notFound) && $notFound !== '') {
      $row = $pages->getPageDB($notFound);
      if (is_array($row) && isset($row['username']) && $row['username'] === $username) $siteChanges['pageNotFound'] = '';
    }
    if (!empty($siteChanges) && !$site->set($siteChanges)) {
      $users->db = $usersBefore;
      if (!$users->save()) {
        Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back user deletion after site-reference update failure.', LOG_TYPE_ERROR);
        $users->recoverPersistedState();
      }
      return false;
    }
  }

  $pagesChanged = $deleteContent
    ? $pages->deletePagesByUser(array('username' => $username))
    : $pages->transferPages(array('oldUsername' => $username));
  if (!$pagesChanged) {
    if (!empty($siteChanges) && !$site->set($siteBefore)) {
      Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back site references after user-content deletion failure.', LOG_TYPE_ERROR);
      $site->recoverPersistedState();
    }
    $users->db = $usersBefore;
    if (!$users->save()) {
      Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back user deletion after page mutation failure.', LOG_TYPE_ERROR);
      $users->recoverPersistedState();
    }
    return false;
  }

  $syslog->add(array(
    'dictionaryKey' => 'user-deleted',
    'notes' => $username
  ));
  return true;
}

function createUser($args)
{
  global $users;
  global $L;
  global $syslog;

	if (!checkRole(array('admin'), false)) return false;

	if (!is_array($args) || !isset($args['new_username'], $args['new_password'], $args['confirm_password'], $args['role'], $args['email']) ||
		!is_string($args['new_username']) || !is_string($args['new_password']) ||
		!is_string($args['confirm_password']) || !is_string($args['role']) || !is_string($args['email'])) {
		return false;
	}
	if (!in_array($args['role'], array('admin', 'editor', 'author'), true) ||
		($args['email'] !== '' && !filter_var($args['email'], FILTER_VALIDATE_EMAIL))) {
		return false;
	}

	$args['new_username'] = Text::removeSpecialCharacters($args['new_username']);

  // Check empty username
  if (Text::isEmpty($args['new_username'])) {
    Alert::set($L->g('username-field-is-empty'), ALERT_STATUS_FAIL);
    return false;
  }

  // Check already exist username
  if ($users->exists($args['new_username'])) {
    Alert::set($L->g('username-already-exists'), ALERT_STATUS_FAIL);
    return false;
  }

  // Password length
  if (Text::length($args['new_password']) < PASSWORD_LENGTH) {
	Alert::set(sprintf($L->g('Password must be at least %d characters long'), PASSWORD_LENGTH), ALERT_STATUS_FAIL);
    return false;
  }

  if (strlen($args['new_password']) > PASSWORD_MAX_LENGTH) {
	Alert::set(sprintf($L->g('Password must be at most %d bytes long'), PASSWORD_MAX_LENGTH), ALERT_STATUS_FAIL);
    return false;
  }

  if ($users->passwordPolicyStatus($args['new_password'], $args['new_username'], $args['email']) !== 'ok') {
    Alert::set($L->g('Password is too weak or too easy to guess'), ALERT_STATUS_FAIL);
    return false;
  }

  // Check new password and confirm password are equal
  if ($args['new_password'] !== $args['confirm_password']) {
    Alert::set($L->g('The password and confirmation password do not match'), ALERT_STATUS_FAIL);
    return false;
  }

  // Filter form fields
  $tmp = array();
  $tmp['username'] = $args['new_username'];
  $tmp['password'] = $args['new_password'];
  $tmp['role']   = $args['role'];
  $tmp['email']   = $args['email'];

  // Add the user to the database
  if ($users->add($tmp)) {
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'new-user-created',
      'notes' => $tmp['username']
    ));

    return true;
  }

  return false;
}

function editSettings($args, $actorUsername = null)
{
  global $site;
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) {
      refreshContentMutationState();
      $site = new Site();
    }
    return editSettingsUnlocked($args, $actorUsername);
  } finally {
    releaseContentMutationLock();
  }
}

function editSettingsUnlocked($args, $actorUsername = null)
{
  global $site;
  global $syslog;
  global $L;
  global $pages;
	global $users;
	if (!is_array($args)) {
		return false;
	}
	$resolvedUsername = $actorUsername !== null ? $actorUsername : Session::get('username');
	$actor = is_string($resolvedUsername) ? $users->getUserDB($resolvedUsername) : false;
	if (!is_array($actor) || !isset($actor['role']) || $actor['role'] !== 'admin') return false;
	$siteDatabaseBefore = $site->db;
	$pagesDatabaseBefore = $pages->db;
	$pagesChanged = false;
	if ($actorUsername !== null && (!is_string($actorUsername) || $actorUsername === '')) return false;
	foreach (array('language', 'timezone', 'url', 'emailFrom', 'homepage', 'pageNotFound',
		'uriPage', 'uriTag', 'uriCategory', 'uriBlog', 'customFields', 'accentColor',
		'colorScheme', 'contentWidth', 'typographyScale', 'cardStyle', 'animationLevel', 'seoSocialImage',
		'seoFacebookAppId', 'seoTwitterSite', 'seoTwitterCard', 'alternativaDateFormat', 'fokusDateFormat',
		'readingProgressPosition', 'readingProgressColor', 'robotsTxt', 'maintenanceMessage') as $stringField) {
		if (array_key_exists($stringField, $args) && !is_string($args[$stringField])) {
			return false;
		}
	}

  if (isset($args['language'])) {
    $language = (string)$args['language'];
    if (preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})?$/D', $language) !== 1 ||
		!is_file(PATH_LANGUAGES . $language . '.json') || is_link(PATH_LANGUAGES . $language . '.json') ||
		!Sanitize::pathFile(PATH_LANGUAGES, $language . '.json')) {
      return false;
    }
  }

  if (isset($args['timezone']) && !in_array($args['timezone'], DateTimeZone::listIdentifiers(), true)) {
    return false;
  }

  if (isset($args['url']) && $args['url'] !== '') {
    $urlParts = parse_url($args['url']);
    if (strlen($args['url']) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $args['url']) ||
		!filter_var($args['url'], FILTER_VALIDATE_URL) || !is_array($urlParts) ||
		!isset($urlParts['scheme'], $urlParts['host']) || !in_array(strtolower($urlParts['scheme']), array('http', 'https'), true) ||
		isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
      return false;
    }
  }

  if (isset($args['emailFrom']) && $args['emailFrom'] !== '' &&
      !filter_var($args['emailFrom'], FILTER_VALIDATE_EMAIL)) {
    return false;
  }

  if (isset($args['language'])) {
    if ($args['language'] !== $site->language()) {
      $tmp = new BrendyJsonDatabase(PATH_LANGUAGES . $args['language'] . '.json', false, 2097152);
      if (isset($tmp->db['language-data']['locale']) && is_string($tmp->db['language-data']['locale'])) {
        $args['locale'] = $tmp->db['language-data']['locale'];
      } else {
        $args['locale'] = $args['language'];
      }
    }
  }

  if (array_key_exists('homepage', $args)) {
	if ($args['homepage'] !== '' && !$pages->isPubliclyAccessible($args['homepage'])) return false;
	if ($args['homepage'] === '') {
		$args['homepage'] = '';
		if (!array_key_exists('uriBlog', $args)) $args['uriBlog'] = '';
	}
  }

  if (array_key_exists('pageNotFound', $args)) {
	if ($args['pageNotFound'] !== '' && !$pages->isPubliclyAccessible($args['pageNotFound'])) return false;
	if ($args['pageNotFound'] === '') $args['pageNotFound'] = '';
  }

  if (isset($args['uriPage'])) {
    $args['uriPage'] = Text::addSlashes($args['uriPage']);
  }

  if (isset($args['uriTag'])) {
    $args['uriTag'] = Text::addSlashes($args['uriTag']);
  }

  if (isset($args['uriCategory'])) {
    $args['uriCategory'] = Text::addSlashes($args['uriCategory']);
  }

  if (array_key_exists('uriBlog', $args)) {
	if ($args['uriBlog'] !== '') {
		$args['uriBlog'] = Text::addSlashes($args['uriBlog']);
	} elseif (isset($args['homepage']) && $args['homepage'] !== '') {
		$args['uriBlog'] = '/blog/';
	} else {
		$args['uriBlog'] = '';
	}
  }

  if (array_key_exists('extremeFriendly', $args)) {
    $extremeFriendly = brendyBooleanValue($args['extremeFriendly'], null);
    if ($extremeFriendly === null) return false;
    $args['extremeFriendly'] = $extremeFriendly;
  }

  if (isset($args['customFields'])) {
	if (strlen($args['customFields']) > 100000) {
		return false;
	}
	$customDefinitions = json_decode($args['customFields'], true, 64);
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($customDefinitions) || count($customDefinitions) > 100) {
		return false;
	}
	$normalizedDefinitions = array();
	foreach ($customDefinitions as $field => $options) {
		if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $field) !== 1 ||
			!is_array($options) || !isset($options['type']) ||
			!in_array($options['type'], array('string', 'bool'), true)) {
			return false;
		}
		$normalized = array('type' => $options['type']);
		foreach (array('label', 'tip', 'placeholder') as $optionName) {
			if (isset($options[$optionName])) {
				if (!is_string($options[$optionName])) return false;
				$normalized[$optionName] = mb_substr(Sanitize::removeTags($options[$optionName]), 0, 1000);
			}
		}
		if (array_key_exists('default', $options)) {
			if ($options['type'] === 'bool') {
				$default = brendyBooleanValue($options['default'], null);
				if ($default === null) return false;
				$normalized['default'] = $default;
			} else {
				if (!is_scalar($options['default'])) return false;
				$normalized['default'] = mb_substr(Sanitize::removeTags((string)$options['default']), 0, 1000);
			}
		}
		if (isset($options['position']) && in_array($options['position'], array('top', 'bottom'), true)) {
			$normalized['position'] = $options['position'];
		}
		if (array_key_exists('checked', $options)) {
			$checked = brendyBooleanValue($options['checked'], null);
			if ($checked === null || $options['type'] !== 'bool') return false;
			$normalized['checked'] = $checked;
		}
		$normalizedDefinitions[$field] = $normalized;
	}
	$args['customFields'] = json_encode($normalizedDefinitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($args['customFields']) || !$pages->setCustomFields($args['customFields'])) {
		$pages->db = $pagesDatabaseBefore;
		return false;
	}
	$pagesChanged = true;
  }

  if ($site->set($args)) {
    // Check current order-by if changed it reorder the content
    if ($site->orderBy() !== ORDER_BY) {
      if ($site->orderBy() === 'date') {
        $pages->sortByDate();
      } else {
        $pages->sortByPosition();
      }
	  if (!$pages->save()) {
		$site->db = $siteDatabaseBefore;
		$pages->db = $pagesDatabaseBefore;
		$site->save();
		$pages->save();
		return false;
	  }
	  $pagesChanged = true;
    }

    // Add syslog
    $syslog->add(array(
      'dictionaryKey' => 'settings-changes',
      'notes' => '',
      'username' => $actorUsername
    ));

    // Notify installed plugins only after the settings transaction has fully
    // committed. Generated artifacts can invalidate themselves here and rebuild
    // on the next request, when URL/locale bootstrap constants reflect new values.
    Theme::plugins('afterSettingsModify', array($args));

    // Create alert
    Alert::set($L->g('The changes have been saved'));
    return true;
  }

	if ($pagesChanged) {
		$pages->db = $pagesDatabaseBefore;
		$pages->save();
	}
	return false;
}

function changeUserPassword($args)
{
  global $users;
  global $login;
  global $security;
  global $L;
  global $syslog;

	if (!is_array($args) || !isset($args['username'], $args['newPassword'], $args['confirmPassword']) ||
		!is_string($args['username']) || !is_string($args['newPassword']) || !is_string($args['confirmPassword']) ||
		!$users->exists($args['username'])) {
		return false;
	}

  // Arguments
  $username = $args['username'];

  // Authorization: only admins or the user themselves can change a password
  if ($login->role() !== 'admin' && $login->username() !== $username) {
    Alert::set($L->g('You do not have permissions to access this page'), ALERT_STATUS_FAIL);
    return false;
  }
  $newPassword = $args['newPassword'];
  $confirmPassword = $args['confirmPassword'];
	$isSelfChange = $login->username() === $username;
	if ($isSelfChange) {
		if (!isset($security) || !is_object($security) || !$security->loginProtectionAvailable()) {
			http_response_code(503);
			Alert::set($L->g('Login protection storage is unavailable. Try again after the server storage is repaired.'), ALERT_STATUS_FAIL);
			return false;
		}
		if ($security->isLoginBlocked($username)) {
			$retryAfter = $security->retryAfterSeconds($username);
			http_response_code(429);
			if ($retryAfter > 0 && !headers_sent()) header('Retry-After: ' . $retryAfter);
			Alert::set($L->g('Too many login attempts. Try again later.'), ALERT_STATUS_FAIL);
			return false;
		}
		$currentPassword = isset($args['currentPassword']) && is_string($args['currentPassword'])
			? $args['currentPassword'] : '';
		if ($currentPassword === '' || strlen($currentPassword) > 4096 ||
			!$users->verifyPassword($username, $currentPassword)) {
			$security->addLoginFailure($username);
			Alert::set($L->g('Current password is incorrect'), ALERT_STATUS_FAIL);
			return false;
		}
	}

  // Password length
  if (Text::length($newPassword) < PASSWORD_LENGTH) {
	Alert::set(sprintf($L->g('Password must be at least %d characters long'), PASSWORD_LENGTH), ALERT_STATUS_FAIL);
    return false;
  }

  if (strlen($newPassword) > PASSWORD_MAX_LENGTH) {
	Alert::set(sprintf($L->g('Password must be at most %d bytes long'), PASSWORD_MAX_LENGTH), ALERT_STATUS_FAIL);
    return false;
  }

  $passwordUser = $users->getUserDB($username);
  $passwordEmail = is_array($passwordUser) && isset($passwordUser['email']) && is_string($passwordUser['email'])
    ? Sanitize::htmlDecode($passwordUser['email']) : '';
  $passwordPolicyStatus = $users->passwordPolicyStatus($newPassword, $username, $passwordEmail);
  if ($passwordPolicyStatus !== 'ok') {
    Alert::set(
      $passwordPolicyStatus === 'history'
        ? sprintf($L->g('You cannot reuse any of your last %d passwords'), PASSWORD_HISTORY_LIMIT + 1)
        : $L->g('Password is too weak or too easy to guess'),
      ALERT_STATUS_FAIL
    );
    return false;
  }

  if ($newPassword !== $confirmPassword) {
    Alert::set($L->g('The password and confirmation password do not match'), ALERT_STATUS_FAIL);
    return false;
  }

  if ($users->setPassword(array('username' => $username, 'password' => $newPassword))) {
	if ($isSelfChange) {
		$updatedUser = $users->getUserDB($username);
		if (!is_array($updatedUser) || !isset($updatedUser['tokenAuth']) || !is_string($updatedUser['tokenAuth']) ||
			!brendyRotateAuthenticatedSession($updatedUser['tokenAuth'], $username)) {
			Log::set(__METHOD__ . LOG_SEP . 'Password changed but the current session could not be securely rotated.', LOG_TYPE_ERROR);
			Session::destroy();
			return false;
		}
		$login->invalidateRememberMe(false);
	}
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'user-password-changed',
      'notes' => $username
    ));

    Alert::set($L->g('The changes have been saved'), ALERT_STATUS_OK);
    return true;
  }

  return false;
}

// Returns true if the user is allowed to proceed
function brendyRequireHttpMethod($allowedMethods)
{
  $allowedMethods = is_array($allowedMethods) ? $allowedMethods : array($allowedMethods);
  $normalized = array();
  foreach (array_slice($allowedMethods, 0, 8) as $method) {
    if (!is_string($method)) continue;
    $method = strtoupper(trim($method));
    if (preg_match('/^[A-Z]{3,10}$/D', $method) === 1) $normalized[$method] = true;
  }
  if (empty($normalized)) $normalized = array('GET' => true);
  $requestMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
  if (isset($normalized[$requestMethod])) return true;
  if (!headers_sent()) header('Allow: ' . implode(', ', array_keys($normalized)));
  http_response_code(405);
  exit('Method Not Allowed');
}

function brendyForceLogout()
{
  global $login;
  if (isset($login) && is_object($login) && method_exists($login, 'logout')) {
    $login->logout();
  } else {
    Session::destroy();
  }
  Redirect::admin();
}

function checkRole($allowRoles, $redirect = true)
{
  global $login;
  global $L;
  global $syslog;

  $userRole = $login->role();
  if (in_array($userRole, $allowRoles, true)) {
    return true;
  }

  if ($redirect) {
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'access-denied',
      'notes' => $login->username()
    ));

    Alert::set($L->g('You do not have sufficient permissions'));
    Redirect::page('dashboard');
  }
  return false;
}

// Sensitive administrator operations require proof that the primary
// credential was presented recently. A long-lived Remember-me session cannot
// satisfy this boundary. The return target is constrained to an internal admin
// route so re-authentication cannot become an open redirect.
function brendySafeAdminReturnRoute($route)
{
  if (!is_string($route)) return false;
  $route = trim($route);
  if ($route === '' || strlen($route) > 512 || preg_match('/[\x00-\x20\x7F\\?&=]/', $route)) return false;
  if (strpos($route, '%') !== false && preg_match('/%(?![A-Fa-f0-9]{2})/', $route)) return false;
  if (preg_match('/^[A-Za-z0-9%][A-Za-z0-9_%.\/-]{0,399}(?:#[A-Za-z0-9%][A-Za-z0-9%_.:-]{0,99})?$/D', $route) !== 1) return false;

  $parts = explode('#', $route, 2);
  $encodedPath = $parts[0];
  $decodedPath = rawurldecode($encodedPath);
  if ($decodedPath === '' || strlen($decodedPath) > 400 ||
      preg_match('/[\x00-\x20\x7F\\?#&=]/', $decodedPath)) return false;
  foreach (explode('/', $decodedPath) as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..') return false;
  }
  // Fragments are never sent to the server, but keep them constrained as UI
  // anchors only. Encoded fragment data is decoded once to reject hidden
  // separators/control characters before the Location header is constructed.
  if (isset($parts[1])) {
    $fragment = rawurldecode($parts[1]);
    if ($fragment === '' || $fragment === '.' || $fragment === '..' || strlen($fragment) > 100 || preg_match('/[^A-Za-z0-9_.:-]/D', $fragment)) return false;
  }
  return $route;
}

function brendyRequireRecentAuthentication($returnRoute)
{
  global $login, $L, $syslog;
  if (!isset($login) || !is_object($login)) Redirect::page('login');
  if (!$login->isLogged()) Redirect::page('login');

  // Sensitive mutations must remain auditable. If the HMAC chain or either
  // retained boundary cannot be verified, fail closed before a destructive
  // controller or high-risk plugin can execute. System Health itself is not
  // gated, so the administrator can still inspect the incident safely.
  if (!isset($syslog) || !is_object($syslog) || !method_exists($syslog, 'verifyChain')) {
    http_response_code(503);
    Alert::set($L->g('Sensitive action blocked because the activity log integrity could not be verified'), ALERT_STATUS_FAIL);
    Redirect::page('system-health');
  }
  $auditState = $syslog->verifyChain();
  if (!is_array($auditState) || empty($auditState['ok'])) {
    http_response_code(503);
    Alert::set($L->g('Sensitive action blocked because the activity log integrity could not be verified'), ALERT_STATUS_FAIL);
    Redirect::page('system-health');
  }

  // recentAuthenticationValid() revalidates the session fingerprint and
  // refuses Remember-me sessions, so this is the only freshness check needed.
  if ($login->recentAuthenticationValid()) return true;

  $safeReturn = brendySafeAdminReturnRoute($returnRoute);
  if ($safeReturn === false) $safeReturn = 'dashboard';
  Session::set('stepUpReturnTo', $safeReturn);
  Session::set('stepUpReturnExpires', time() + STEP_UP_RETURN_TTL);
  if (isset($syslog) && is_object($syslog)) {
    $syslog->add(array(
      'dictionaryKey' => 'recent-authentication-required',
      'notes' => $safeReturn,
      'username' => $login->username()
    ));
  }
  Alert::set($L->g('Confirm your identity to continue with this sensitive action'), ALERT_STATUS_FAIL);
  Redirect::page('reauth');
}

function brendyConsumeRecentAuthenticationReturnRoute()
{
  $route = Session::get('stepUpReturnTo');
  $expires = (int)Session::get('stepUpReturnExpires');
  Session::remove('stepUpReturnTo');
  Session::remove('stepUpReturnExpires');
  if ($expires <= time() || $expires > time() + STEP_UP_RETURN_TTL + 60) return 'dashboard';
  $route = brendySafeAdminReturnRoute($route);
  return $route === false ? 'dashboard' : $route;
}

function isValidPageUuid($uuid)
{
  return is_string($uuid) && preg_match('/^[a-f0-9]{32}$/iD', $uuid) === 1;
}

function isValidPageKey($key)
{
  return is_string($key) && $key !== '' && strlen($key) <= 200 &&
    strpos($key, "\0") === false && strpos($key, '\\') === false &&
    preg_match('#^(?!.*(?:^|/)\.{1,2}(?:/|$))[\pL\pN._~-]+(?:/[\pL\pN._~-]+)*$#uD', $key) === 1;
}

// Encode a validated page key for use as a URL path while preserving hierarchy
// separators. Encoding the whole key with rawurlencode() would turn '/' into
// '%2F', which Brendy intentionally rejects at the router boundary.
function brendyPageKeyUrlPath($key)
{
  if (!isValidPageKey($key)) return false;
  return implode('/', array_map('rawurlencode', explode('/', $key)));
}

// Resolve the physical filesystem location of a legacy page-key upload alias.
// Nested aliases cannot be created by traversing a parent alias symlink because
// Filesystem::symlink() intentionally rejects symlinked destination parents.
// Instead, each child alias lives directly inside its parent's canonical UUID
// upload directory. This preserves old /uploads/pages/<page-key>/ URLs without
// weakening the generic filesystem symlink policy.
function brendyPageUploadAliasPath($pageKey, $database)
{
  if (!defined('PATH_UPLOADS_PAGES') || !isValidPageKey($pageKey) || !is_array($database) ||
      !isset($database[$pageKey]) || !is_array($database[$pageKey])) return false;

  $segments = explode('/', $pageKey);
  $aliasName = array_pop($segments);
  if (!is_string($aliasName) || $aliasName === '' || $aliasName !== basename($aliasName)) return false;

  if (empty($segments)) {
    $parentDirectory = PATH_UPLOADS_PAGES;
  } else {
    $parentKey = implode('/', $segments);
    if (!isset($database[$parentKey]) || !is_array($database[$parentKey]) ||
        !isset($database[$parentKey]['uuid']) || !is_string($database[$parentKey]['uuid']) ||
        preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $database[$parentKey]['uuid']) !== 1) return false;
    $parentDirectory = PATH_UPLOADS_PAGES . $database[$parentKey]['uuid'] . DS;
  }

  if (!is_dir($parentDirectory) || is_link($parentDirectory)) return false;
  $root = realpath(PATH_UPLOADS_PAGES);
  $parent = realpath($parentDirectory);
  if (!is_string($root) || !is_string($parent) ||
      ($parent !== $root && strpos($parent . DS, rtrim($root, DS) . DS) !== 0)) return false;

  return rtrim($parent, DS) . DS . $aliasName;
}

function brendyEnsurePageUploadAlias($pageKey, $database, &$created = null)
{
  $created = false;
  if (!is_array($database) || !isset($database[$pageKey]) || !is_array($database[$pageKey]) ||
      !isset($database[$pageKey]['uuid']) || !is_string($database[$pageKey]['uuid']) ||
      preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $database[$pageKey]['uuid']) !== 1) return false;

  $alias = brendyPageUploadAliasPath($pageKey, $database);
  if (!is_string($alias)) return false;
  $source = PATH_UPLOADS_PAGES . $database[$pageKey]['uuid'];
  if (!is_dir($source) || is_link($source)) return false;
  $sourceReal = realpath($source);
  $rootReal = realpath(PATH_UPLOADS_PAGES);
  if (!is_string($sourceReal) || !is_string($rootReal) ||
      strpos($sourceReal . DS, rtrim($rootReal, DS) . DS) !== 0) return false;

  // A top-level page key may theoretically equal its canonical UUID. In that
  // case the compatibility URL already resolves to the canonical directory and
  // no symlink is required. Never replace or unlink that real directory.
  if (!is_link($alias) && is_dir($alias)) {
    $existingReal = realpath($alias);
    if (is_string($existingReal) && hash_equals($sourceReal, $existingReal) &&
        hash_equals(rtrim($sourceReal, DS), rtrim($alias, DS))) return true;
    return false;
  }
  if (is_link($alias)) {
    $target = realpath($alias);
    return is_string($target) && hash_equals($sourceReal, $target);
  }
  if (file_exists($alias)) return false;
  if (!Filesystem::symlink($sourceReal, $alias)) return false;
  $created = true;
  $target = realpath($alias);
  if (!is_link($alias) || !is_string($target) || !hash_equals($sourceReal, $target)) {
    if (is_link($alias)) @unlink($alias);
    $created = false;
    return false;
  }
  return true;
}

function brendyRemovePageUploadAlias($pageKey, $database)
{
  $alias = brendyPageUploadAliasPath($pageKey, $database);
  if (!is_string($alias)) return false;
  if (!file_exists($alias) && !is_link($alias)) return true;
  if (!is_link($alias)) return false;
  return @unlink($alias);
}

// Rebuild all compatibility page-key media aliases using the same hardened
// resolver used by normal page mutations. Callers receive compact statistics so
// upgrades/restores can warn without treating aliases as canonical content.
function brendyRebuildPageUploadAliases($database, &$stats = null)
{
  $stats = array('checked' => 0, 'created' => 0, 'failed' => 0, 'skipped' => 0);
  if (!is_array($database) || !defined('PATH_UPLOADS_PAGES') || !is_dir(PATH_UPLOADS_PAGES) || is_link(PATH_UPLOADS_PAGES)) {
    $stats['failed']++;
    return false;
  }
  $keys = array_keys($database);
  usort($keys, static function ($a, $b) {
    $depth = substr_count((string)$a, '/') <=> substr_count((string)$b, '/');
    return $depth !== 0 ? $depth : strcmp((string)$a, (string)$b);
  });
  foreach ($keys as $key) {
    $row = isset($database[$key]) ? $database[$key] : null;
    if (!is_string($key) || !is_array($row) || !isset($row['uuid']) || !is_string($row['uuid']) ||
        preg_match('/^(?:autosave-)?[a-f0-9]{32}$/iD', $row['uuid']) !== 1) {
      $stats['skipped']++;
      continue;
    }
    $stats['checked']++;
    $source = PATH_UPLOADS_PAGES . $row['uuid'];
    if (!is_dir($source) || is_link($source)) {
      $stats['failed']++;
      continue;
    }
    $created = false;
    if (!brendyEnsurePageUploadAlias($key, $database, $created)) {
      $stats['failed']++;
      continue;
    }
    if ($created) $stats['created']++;
  }
  return $stats['failed'] === 0;
}

// Define the editor/media URLs in one place so new-content and edit-content
// cannot drift apart when upload configuration changes.
function definePageMediaConstants($uuid)
{
  if (!isValidPageUuid($uuid)) return false;
  $required = array(
    'PAGE_IMAGES_KEY', 'PAGE_IMAGES_DIRECTORY', 'PAGE_IMAGES_URL',
    'PAGE_THUMBNAILS_DIRECTORY', 'PAGE_THUMBNAILS_HTML', 'PAGE_THUMBNAILS_URL'
  );
  foreach ($required as $constant) {
    if (defined($constant)) return false;
  }

  define('PAGE_IMAGES_KEY', $uuid);
  if (IMAGE_RESTRICT) {
    define('PAGE_IMAGES_DIRECTORY', IMAGE_RELATIVE_TO_ABSOLUTE ? '' : HTML_PATH_UPLOADS_PAGES . PAGE_IMAGES_KEY . '/');
    define('PAGE_IMAGES_URL', IMAGE_RELATIVE_TO_ABSOLUTE ? '' : DOMAIN_UPLOADS_PAGES . PAGE_IMAGES_KEY . '/');
    define('PAGE_THUMBNAILS_DIRECTORY', PATH_UPLOADS_PAGES . PAGE_IMAGES_KEY . DS . 'thumbnails' . DS);
    define('PAGE_THUMBNAILS_HTML', HTML_PATH_UPLOADS_PAGES . PAGE_IMAGES_KEY . '/thumbnails/');
    define('PAGE_THUMBNAILS_URL', DOMAIN_UPLOADS_PAGES . PAGE_IMAGES_KEY . '/thumbnails/');
  } else {
    define('PAGE_IMAGES_DIRECTORY', IMAGE_RELATIVE_TO_ABSOLUTE ? '' : HTML_PATH_UPLOADS);
    define('PAGE_IMAGES_URL', IMAGE_RELATIVE_TO_ABSOLUTE ? '' : DOMAIN_UPLOADS);
    define('PAGE_THUMBNAILS_DIRECTORY', PATH_UPLOADS_THUMBNAILS);
    define('PAGE_THUMBNAILS_HTML', HTML_PATH_UPLOADS_THUMBNAILS);
    define('PAGE_THUMBNAILS_URL', DOMAIN_UPLOADS_THUMBNAILS);
  }
  return true;
}

// Bind UUIDs prepared by the new-content screen to the authenticated session.
// Without this, an author could use arbitrary, unassigned UUIDs to create
// orphan media directories and consume disk space.
function registerNewPageUuid($uuid)
{
  if (!isValidPageUuid($uuid) || !Session::started()) return false;
  $now = time();
  $issued = Session::get('newPageUuids');
  $issued = is_array($issued) ? $issued : array();
  foreach ($issued as $key => $createdAt) {
    if (!isValidPageUuid($key) || !is_int($createdAt) || ($now - $createdAt) > 7200) {
      unset($issued[$key]);
    }
  }
  while (count($issued) >= 20) {
    array_shift($issued);
  }
  $issued[$uuid] = $now;
  return Session::set('newPageUuids', $issued);
}

function consumeNewPageUuid($uuid)
{
  if (!isValidPageUuid($uuid) || !Session::started()) return false;
  $issued = Session::get('newPageUuids');
  if (!is_array($issued) || !array_key_exists($uuid, $issued)) return false;
  unset($issued[$uuid]);
  return Session::set('newPageUuids', $issued);
}

function isIssuedNewPageUuid($uuid)
{
  if (!isValidPageUuid($uuid) || !Session::started()) return false;
  $issued = Session::get('newPageUuids');
  if (!is_array($issued) || !isset($issued[$uuid]) || !is_int($issued[$uuid])) return false;
  return (time() - $issued[$uuid]) <= 7200;
}

// Editors and administrators can manage all page media. Authors can manage
// their own page or an as-yet unassigned UUID generated for a new page.
function canManagePageMedia($uuid)
{
  global $login;
  global $pages;


  if (!isValidPageUuid($uuid)) {
    return false;
  }
  $pageKey = $pages->getByUUID($uuid);
  if ($pageKey === false) {
    return isIssuedNewPageUuid($uuid);
  }
  if (checkRole(array('admin', 'editor'), false)) {
    return true;
  }
  if (!checkRole(array('author'), false)) {
    return false;
  }
  return isset($pages->db[$pageKey]['username']) &&
    $pages->db[$pageKey]['username'] === $login->username();
}

function brendyIsServerControlFilename($filename)
{
  if (!is_string($filename) || $filename === '' || basename($filename) !== $filename) return true;
  return in_array(strtolower($filename), array('.htaccess', '.user.ini', 'php.ini', 'web.config'), true);
}

function brendyHasDangerousExecutableFilename($filename)
{
  if (!is_string($filename) || $filename === '' || strlen($filename) > 255 ||
      basename($filename) !== $filename || strpos($filename, "\0") !== false ||
      preg_match('/[\x00-\x1F\x7F]/', $filename)) return true;
  // Apache mod_mime can associate handlers/types with any extension segment,
  // not only the final suffix. Reject executable/renderable segments anywhere.
  return preg_match('/(?:^|\.)(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh|shtml|html?|svgz?)(?:\.|$)/iD', $filename) === 1;
}

function brendySanitizeUploadedFilename($filename, $fallbackStem = 'file', $maxStemLength = 150)
{
  if (!is_string($filename) || strlen($filename) < 1 || strlen($filename) > 255 ||
      basename($filename) !== $filename || strpos($filename, '/') !== false ||
      strpos($filename, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $filename) ||
      brendyHasDangerousExecutableFilename($filename)) {
    return false;
  }

  $extension = Text::lowercase(Filesystem::extension($filename));
  if ($extension === '' || strlen($extension) > 20 || preg_match('/^[a-z0-9]+$/D', $extension) !== 1) return false;

  $fallbackStem = is_string($fallbackStem) ? strtolower(trim($fallbackStem)) : 'file';
  if ($fallbackStem === '' || strlen($fallbackStem) > 32 || preg_match('/^[a-z0-9_-]+$/D', $fallbackStem) !== 1) {
    $fallbackStem = 'file';
  }
  $maxStemLength = max(1, min(180, (int)$maxStemLength));

  $stem = Text::removeSpecialCharacters(pathinfo($filename, PATHINFO_FILENAME), '-');
  $stem = Text::removeSpaces(Text::removeQuotes($stem), '-');
  $stem = trim($stem, " .-_\t\n\r\0\x0B");
  $stem = mb_substr($stem, 0, $maxStemLength, CHARSET);
  $stem = rtrim($stem, " .-_\t\n\r\0\x0B");
  if ($stem === '') $stem = $fallbackStem;

  $safe = $stem . '.' . $extension;
  if (strlen($safe) > 220 || $safe[0] === '.' || basename($safe) !== $safe ||
      strpos($safe, '/') !== false || strpos($safe, '\\') !== false ||
      brendyHasDangerousExecutableFilename($safe)) {
    return false;
  }
  return $safe;
}

function brendyAllowedUploadDirectory($directory)
{
  if (!is_string($directory) || $directory === '' || strpos($directory, "\0") !== false ||
      !is_dir($directory) || is_link($directory)) return false;
  $parent = realpath($directory);
  if ($parent === false || !is_dir($parent)) return false;
  foreach (array(PATH_TMP, PATH_UPLOADS) as $root) {
    $realRoot = realpath($root);
    if ($realRoot !== false && ($parent === $realRoot || strpos($parent, $realRoot . DS) === 0)) {
      return $parent;
    }
  }
  return false;
}

function moveUploadedFileSafely($temporaryFile, $destination)
{
  if (!is_string($temporaryFile) || !is_string($destination) || $destination === '' ||
      strpos($destination, "\0") !== false || !is_uploaded_file($temporaryFile) ||
      file_exists($destination) || is_link($destination)) {
    return false;
  }
  if (brendyHasDangerousExecutableFilename(basename($destination))) return false;
  $parent = brendyAllowedUploadDirectory(dirname($destination));
  if ($parent === false) return false;

  // nextFilename() is advisory only: another request can create the same target
  // between its existence check and this commit. move_uploaded_file() overwrites
  // an existing destination, so publishing directly could silently replace a
  // concurrent upload. First move the PHP upload into an unpredictable file in
  // the final directory, then promote it through Filesystem::mv(), whose regular
  // file path is explicitly no-clobber (hard-link or exclusive-copy fallback).
  $staging = Filesystem::temporaryFile($parent, '.brendy-upload-', 0600);
  if ($staging === false) return false;
  if (!@move_uploaded_file($temporaryFile, $staging)) {
    Filesystem::rmfile($staging);
    return false;
  }
  if (!is_file($staging) || is_link($staging) || !Filesystem::mv($staging, $destination)) {
    if (is_file($staging) && !is_link($staging)) Filesystem::rmfile($staging);
    return false;
  }
  return is_file($destination) && !is_link($destination);
}

/**
 * Store a PHP upload under the next available safe name. The uploaded bytes are
 * moved into one unpredictable same-directory staging file once, then a bounded
 * no-clobber retry chooses the final filename without reopening PHP's temp file.
 *
 * @return string|false Final basename, or false on failure.
 */
function moveUploadedFileUniquely($temporaryFile, $preferredFilename, $directory, $maxAttempts = 16)
{
  if (!is_string($temporaryFile) || !is_string($preferredFilename) || !is_int($maxAttempts) ||
      $maxAttempts < 1 || $maxAttempts > 128 || basename($preferredFilename) !== $preferredFilename ||
      strlen($preferredFilename) < 1 || strlen($preferredFilename) > 255 ||
      strpos($preferredFilename, "\0") !== false || !is_uploaded_file($temporaryFile) ||
      brendyHasDangerousExecutableFilename($preferredFilename)) return false;

  $parent = brendyAllowedUploadDirectory($directory);
  if ($parent === false) return false;
  $staging = Filesystem::temporaryFile($parent, '.brendy-upload-', 0600);
  if ($staging === false) return false;
  if (!@move_uploaded_file($temporaryFile, $staging)) {
    Filesystem::rmfile($staging);
    return false;
  }

  $committed = Filesystem::moveToUniqueFilename($staging, $preferredFilename, $parent, $maxAttempts);
  if (!is_string($committed)) {
    if (is_file($staging) && !is_link($staging)) Filesystem::rmfile($staging);
    return false;
  }
  return basename($committed);
}

// Add a new category to the system
// Returns TRUE is successfully added, FALSE otherwise
function createCategory($args)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return createCategoryUnlocked($args);
  } finally {
    releaseContentMutationLock();
  }
}

function createCategoryUnlocked($args)
{
  global $categories;
  global $L;
  global $syslog;

  if (!checkRole(array('admin'), false)) return false;

  if (!is_array($args) || !isset($args['name']) || !is_string($args['name']) ||
      (isset($args['description']) && !is_string($args['description'])) ||
      mb_strlen($args['name'], CHARSET) > 200 ||
      (isset($args['description']) && mb_strlen($args['description'], CHARSET) > 5000) ||
      Text::isEmpty($args['name'])) {
    Alert::set($L->g('Category name is empty'), ALERT_STATUS_FAIL);
    return false;
  }

  $description = isset($args['description']) ? $args['description'] : '';
  $categoryKey = $categories->add(array('name' => $args['name'], 'description' => $description));
  if ($categoryKey !== false) {
    Theme::plugins('afterCategoryCreate', array($categoryKey));
    // Add to syslog
    $syslog->add(array(
      'dictionaryKey' => 'new-category-created',
      'notes' => $args['name']
    ));

    Alert::set($L->g('Category added'), ALERT_STATUS_OK);
    return true;
  }

  Alert::set($L->g('The category already exists'), ALERT_STATUS_FAIL);
  return false;
}

function editCategory($args)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return editCategoryUnlocked($args);
  } finally {
    releaseContentMutationLock();
  }
}

function editCategoryUnlocked($args)
{
  global $L;
  global $pages;
  global $categories;
  global $syslog;

  if (!checkRole(array('admin'), false)) return false;

  foreach (array('name', 'newKey', 'oldKey') as $field) {
    if (!is_array($args) || !isset($args[$field]) || !is_string($args[$field])) return false;
  }
  foreach (array('description', 'template') as $field) {
    if (isset($args[$field]) && !is_string($args[$field])) return false;
  }
  if (!$categories->exists($args['oldKey']) || mb_strlen($args['name'], CHARSET) > 200 ||
      strlen($args['newKey']) > 200 ||
      (isset($args['description']) && mb_strlen($args['description'], CHARSET) > 5000) ||
      (isset($args['template']) && strlen($args['template']) > 200) ||
      Text::isEmpty($args['name']) || Text::isEmpty($args['newKey'])) {
    Alert::set($L->g('Empty fields'));
    return false;
  }

  $categoriesBefore = $categories->db;
  $pagesBefore = $pages->db;
  $newCategoryKey = $categories->edit($args);

  if ($newCategoryKey === false) {
    Alert::set($L->g('The category already exists'));
    return false;
  }

  // Change the category key in the pages database. The category row was
  // already committed, so a page-DB failure requires an explicit category
  // rollback. A failed/stale page save is recovered from disk instead of
  // leaving this request with an in-memory snapshot that was never persisted.
  if (!$pages->changeCategory($args['oldKey'], $newCategoryKey)) {
    $categories->db = $categoriesBefore;
    if (!$categories->save()) {
      Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back category metadata after page remap failure.', LOG_TYPE_ERROR);
      $categories->recoverPersistedState();
    }
    $pages->db = $pagesBefore;
    if (!$pages->recoverPersistedState()) {
      Log::set(__METHOD__ . LOG_SEP . 'Unable to reload page state after category remap failure.', LOG_TYPE_ERROR);
    }
    return false;
  }

  Theme::plugins('afterCategoryModify', array($args['oldKey'], $newCategoryKey));

  // Add to syslog
  $syslog->add(array(
    'dictionaryKey' => 'category-edited',
    'notes' => $newCategoryKey
  ));

  Alert::set($L->g('The changes have been saved'));
  return true;
}

function deleteCategory($args)
{
  $outermost = contentMutationLockDepth() === 0;
  if (!acquireContentMutationLock()) return false;
  try {
    if ($outermost) refreshContentMutationState();
    return deleteCategoryUnlocked($args);
  } finally {
    releaseContentMutationLock();
  }
}

function deleteCategoryUnlocked($args)
{
  global $L;
  global $categories;
  global $pages;
  global $syslog;

  if (!checkRole(array('admin'), false)) return false;

  if (!is_array($args) || !isset($args['oldKey']) || !is_string($args['oldKey']) ||
      !$categories->exists($args['oldKey'])) return false;

  // Do not leave pages pointing at a category that no longer exists. Clear
  // those references first and roll them back if deleting the category fails.
  $pagesBefore = $pages->db;
  if (!$pages->changeCategory($args['oldKey'], '')) return false;
  if (!$categories->remove($args['oldKey'])) {
    // remove() restores its own in-memory snapshot, but an optimistic conflict
    // may mean a newer category file exists on disk. Refresh it before this
    // request continues. The page references *were* committed, so attempt to
    // restore those and recover the newest persisted state if rollback loses a
    // race or storage becomes unavailable.
    $categories->recoverPersistedState();
    $pages->db = $pagesBefore;
    if (!$pages->save()) {
      Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back page category references.', LOG_TYPE_ERROR);
      $pages->recoverPersistedState();
    }
    return false;
  }

  Theme::plugins('afterCategoryDelete', array($args['oldKey']));

  // Add to syslog
  $syslog->add(array(
    'dictionaryKey' => 'category-deleted',
    'notes' => $args['oldKey']
  ));

  Alert::set($L->g('The changes have been saved'));
  return true;
}

// Returns an array with all the categories
// By default, the database of categories is alphanumeric sorted
function getCategories()
{
  global $categories;

  $list = array();
  foreach ($categories->keys() as $key) {
    try {
      $list[] = new Category($key);
    } catch (Throwable $e) {
      // Corrupted legacy rows must not take down taxonomy listings.
    }
  }
  return $list;
}

// Returns the object category if the category exists, FALSE otherwise
function getCategory($key)
{
  try {
    return new Category($key);
  } catch (Throwable $e) {
    return false;
  }
}

// Returns an array with all the tags
// By default, the database of tags is alphanumeric sorted
function getTags()
{
  global $tags;

  $list = array();
  foreach ($tags->db as $key => $fields) {
    try {
      $list[] = new Tag($key);
    } catch (Throwable $e) {
      // Corrupted legacy rows must not take down taxonomy listings.
    }
  }
  return $list;
}

// Returns the object tag if the tag exists, FALSE otherwise
function getTag($key)
{
  try {
    return new Tag($key);
  } catch (Throwable $e) {
    return false;
  }
}


/*
| Validate uploaded extension PHP without executing it. TOKEN_PARSE asks PHP's
| own parser to reject syntax errors before any code is moved into dodaci/teme.
*/
function brendyParsePhpSourceFile($filename, $maxBytes = 8388608)
{
  if (!is_string($filename) || !is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 16777216) return false;
  $source = Filesystem::readFileBounded($filename, $maxBytes, 1);
  if (!is_string($source) || strpos($source, "\0") !== false) return false;
  try {
    $tokens = token_get_all($source, TOKEN_PARSE);
  } catch (ParseError $e) {
    return false;
  }
  return array('source' => $source, 'tokens' => $tokens);
}

function brendyValidatePhpSourceFile($filename)
{
  return is_array(brendyParsePhpSourceFile($filename));
}

function brendyValidateExtensionPhpSecurity($filename, $maxBytes = 8388608, $expectedSha256 = null)
{
  $parsed = brendyParsePhpSourceFile($filename, $maxBytes);
  if (!is_array($parsed) || !isset($parsed['source'], $parsed['tokens']) ||
      !is_string($parsed['source']) || !is_array($parsed['tokens'])) return false;
  $source = $parsed['source'];
  $tokens = $parsed['tokens'];
  if ($expectedSha256 !== null) {
    if (!is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1 ||
        !hash_equals($expectedSha256, hash('sha256', $source))) return false;
  }

  // Uploaded PHP must *enforce* a Brendy bootstrap guard as its first
  // executable statement. Merely mentioning defined('BRENDY') is insufficient:
  // a malicious archive could otherwise satisfy the scanner without stopping
  // direct execution. Accept the canonical `defined(...) or die/exit` form and
  // the equivalent `if (!defined(...)) exit/die` form.
  $significant = array();
  foreach ($tokens as $token) {
    if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG), true)) continue;
    $significant[] = $token;
    if (count($significant) >= 32) break;
  }
  $tokenId = function ($token) { return is_array($token) ? $token[0] : null; };
  $tokenText = function ($token) { return is_array($token) ? $token[1] : $token; };
  $isBrendyLiteral = function ($token) use ($tokenId, $tokenText) {
    return $tokenId($token) === T_CONSTANT_ENCAPSED_STRING && trim((string)$tokenText($token), "'\"") === 'BRENDY';
  };
  $guardOk = false;
  if (count($significant) >= 6 && $tokenId($significant[0]) === T_STRING &&
      strtolower((string)$tokenText($significant[0])) === 'defined' && $significant[1] === '(' &&
      $isBrendyLiteral($significant[2]) && $significant[3] === ')' &&
      in_array($tokenId($significant[4]), array(T_LOGICAL_OR, T_BOOLEAN_OR), true) &&
      $tokenId($significant[5]) === T_EXIT) {
    $guardOk = true;
  } elseif (count($significant) >= 10 && $tokenId($significant[0]) === T_IF && $significant[1] === '(' &&
      $significant[2] === '!' && $tokenId($significant[3]) === T_STRING &&
      strtolower((string)$tokenText($significant[3])) === 'defined' && $significant[4] === '(' &&
      $isBrendyLiteral($significant[5]) && $significant[6] === ')' && $significant[7] === ')') {
    // The exit must be the immediate body, not a nested conditional.
    $g = $significant[8] === '{' ? 9 : 8;
    $guardOk = isset($significant[$g]) && $tokenId($significant[$g]) === T_EXIT;
  }
  if (!$guardOk) return false;
  $blockedFunctions = array(
    'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
    'dl', 'assert', 'create_function', 'unserialize',
    // Prevent common indirection paths that can trivially bypass a scanner by
    // passing a blocked function name as data. Bundled extensions do not rely
    // on these dynamic invocation primitives.
    'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
    'reflectionfunction', 'reflectionmethod'
  );
  // PHP 8 tokenizes namespaced/fully-qualified calls such as \exec() as
  // T_NAME_FULLY_QUALIFIED rather than T_STRING. Treat every callable-name
  // token form consistently or a leading backslash becomes a scanner bypass.
  $callableNameTokens = array(T_STRING);
  foreach (array('T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE') as $tokenConstant) {
    if (defined($tokenConstant)) $callableNameTokens[] = constant($tokenConstant);
  }
  $normalizeCallableName = static function ($raw) {
    if (!is_string($raw) || $raw === '') return '';
    $raw = strtolower(trim($raw, "\\"));
    $pos = strrpos($raw, '\\');
    return $pos === false ? $raw : substr($raw, $pos + 1);
  };
  $decodeStaticString = static function ($raw) {
    if (!is_string($raw) || strlen($raw) < 2) return false;
    $quote = $raw[0];
    if (($quote !== "'" && $quote !== '"') || substr($raw, -1) !== $quote) return false;
    $value = substr($raw, 1, -1);
    if ($quote === "'") return str_replace(array('\\\\', "\\'"), array('\\', "'"), $value);
    return stripcslashes($value);
  };
  $isIgnorableToken = static function ($token) {
    return is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
  };
  $count = count($tokens);
  $nextSignificantIndex = static function ($tokens, $start, $count) use ($isIgnorableToken) {
    for ($index = $start; $index < $count; $index++) {
      if (!$isIgnorableToken($tokens[$index])) return $index;
    }
    return false;
  };
  // PHP control-flow blocks do not create variable scopes. Track static
  // blocked-callable assignments per function/method/closure scope instead of
  // per brace depth, otherwise an `if (...) { $fn = 'exec'; } $fn();` pattern
  // could lose its taint when the block closes. Once tainted, a variable stays
  // tainted for that function scope: branch-sensitive proof of a safe
  // reassignment is deliberately outside this lightweight scanner.
  $braceDepth = 0;
  $pendingFunctionScope = false;
  $functionScopes = array(array('depth' => 0, 'tainted' => array()));
  for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];
    if (is_array($token) && $token[0] === T_FUNCTION) $pendingFunctionScope = true;
    if ($token === '{') {
      $braceDepth++;
      if ($pendingFunctionScope) {
        $functionScopes[] = array('depth' => $braceDepth, 'tainted' => array());
        $pendingFunctionScope = false;
      }
      continue;
    }
    if ($token === '}') {
      $last = count($functionScopes) - 1;
      if ($last > 0 && $functionScopes[$last]['depth'] === $braceDepth) array_pop($functionScopes);
      $braceDepth = max(0, $braceDepth - 1);
      continue;
    }
    if ($token === '`') return false;
    if (!is_array($token)) continue;

    // PHP allows strings and variables to be invoked directly as callables. A
    // blocked function name must not become executable merely by moving it from
    // a name token into a literal/variable (e.g. "exec"(...) or
    // $fn = "exec"; $fn(...)). Track only static assignments within the
    // current brace scope so ordinary closure variables remain compatible.
    if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
      $literal = $decodeStaticString($token[1]);
      $literalName = is_string($literal) ? $normalizeCallableName($literal) : '';
      if ($literalName !== '' && in_array($literalName, $blockedFunctions, true)) {
        $nextIndex = $nextSignificantIndex($tokens, $i + 1, $count);
        if ($nextIndex !== false && $tokens[$nextIndex] === '(') return false;
        if ($nextIndex !== false && $tokens[$nextIndex] === ')') {
          $afterParen = $nextSignificantIndex($tokens, $nextIndex + 1, $count);
          if ($afterParen !== false && $tokens[$afterParen] === '(') return false;
        }
      }
    }

    if ($token[0] === T_VARIABLE) {
      $variable = $token[1];
      $nextIndex = $nextSignificantIndex($tokens, $i + 1, $count);
      if ($nextIndex !== false && $tokens[$nextIndex] === '=') {
        $valueIndex = $nextSignificantIndex($tokens, $nextIndex + 1, $count);
        $tainted = false;
        if ($valueIndex !== false && is_array($tokens[$valueIndex])) {
          $valueToken = $tokens[$valueIndex];
          if ($valueToken[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = $decodeStaticString($valueToken[1]);
            $literalName = is_string($literal) ? $normalizeCallableName($literal) : '';
            $tainted = $literalName !== '' && in_array($literalName, $blockedFunctions, true);
          } elseif ($valueToken[0] === T_VARIABLE) {
            $scopeIndex = count($functionScopes) - 1;
            $tainted = !empty($functionScopes[$scopeIndex]['tainted'][$valueToken[1]]);
          }
        }
        if ($tainted) {
          $scopeIndex = count($functionScopes) - 1;
          $functionScopes[$scopeIndex]['tainted'][$variable] = true;
        }
      } elseif ($nextIndex !== false && $tokens[$nextIndex] === '(') {
        $scopeIndex = count($functionScopes) - 1;
        if (!empty($functionScopes[$scopeIndex]['tainted'][$variable])) return false;
      }
    }
    if (defined('T_EVAL') && $token[0] === T_EVAL) return false;

    // Import aliases can hide a blocked global/class name from the later call
    // site (for example `use function exec as run; run(...)`). Reject imports
    // that mention a blocked callable name so aliases cannot bypass scanning.
    if ($token[0] === T_USE) {
      for ($j = $i + 1; $j < $count; $j++) {
        $next = $tokens[$j];
        if ($next === ';') break;
        if (!is_array($next) || !in_array($next[0], $callableNameTokens, true)) continue;
        $importedName = $normalizeCallableName($next[1]);
        if (in_array($importedName, $blockedFunctions, true)) return false;
      }
    }

    // Extension includes are deliberately narrower than normal PHP includes.
    // A package may only include a path anchored to its theme directory or to
    // the current source directory and completed with static path literals.
    // Reject variables, function calls, stream wrappers and traversal instead
    // of assuming that a later runtime boundary will contain them.
    if (in_array($token[0], array(T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE), true)) {
      $includeAnchor = false;
      $includeLiteral = false;
      $includeSuffix = '';
      $includeValid = true;
      $includeEnded = false;
      for ($j = $i + 1; $j < $count; $j++) {
        $next = $tokens[$j];
        if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
        if ($next === ';' || (is_array($next) && $next[0] === T_CLOSE_TAG)) {
          $includeEnded = true;
          break;
        }
        if ($next === '(' || $next === ')' || $next === '.') continue;
        if (is_array($next) && defined('T_DIR') && $next[0] === T_DIR) {
          if ($includeAnchor || $includeLiteral) { $includeValid = false; break; }
          $includeAnchor = true;
          continue;
        }
        if (is_array($next) && $next[0] === T_STRING) {
          $identifier = strtoupper($next[1]);
          if ($identifier === 'THEME_DIR_PHP') {
            if ($includeAnchor || $includeLiteral) { $includeValid = false; break; }
            $includeAnchor = true;
            continue;
          }
          if ($includeAnchor && in_array($identifier, array('DS', 'DIRECTORY_SEPARATOR'), true)) {
            $includeSuffix .= '/';
            continue;
          }
          $includeValid = false;
          break;
        }
        if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
          if (!$includeAnchor || strlen($next[1]) < 2) { $includeValid = false; break; }
          $quote = $next[1][0];
          if (($quote !== "'" && $quote !== '"') || substr($next[1], -1) !== $quote) { $includeValid = false; break; }
          $literal = substr($next[1], 1, -1);
          // Backslash escapes would require evaluating PHP to know the final
          // path. They are unnecessary for local extension file names, so fail
          // closed and keep the scanner deterministic.
          if ($literal === '' || strpos($literal, '\\') !== false ||
              preg_match('/[\x00-\x1F\x7F]/', $literal) === 1 ||
              preg_match('~(?:^|[\\/])\.\.(?:[\\/]|$)~', $literal) === 1 ||
              preg_match('~(?:https?|ftp|ftps|data|phar|zip|expect|glob):~i', $literal) === 1) {
            $includeValid = false;
            break;
          }
          $includeLiteral = true;
          $includeSuffix .= str_replace('\\', '/', $literal);
          if (strlen($includeSuffix) > 1024) { $includeValid = false; break; }
          continue;
        }
        $includeValid = false;
        break;
      }
      if (!$includeValid || !$includeEnded || !$includeAnchor || !$includeLiteral ||
          preg_match('~(?:^|/)\.\.(?:/|$)~', str_replace('\\', '/', $includeSuffix)) === 1) return false;
      continue;
    }

    if (!in_array($token[0], $callableNameTokens, true)) continue;
    $name = $normalizeCallableName($token[1]);
    if (!in_array($name, $blockedFunctions, true)) continue;
    // Only treat it as a call when the next significant token opens args.
    for ($j = $i + 1; $j < $count; $j++) {
      $next = $tokens[$j];
      if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
      if ($next === '(') return false;
      break;
    }
  }
  return true;
}


/**
 * Validate every executable-looking PHP source file in an installed extension
 * before the extension is executed. This closes the post-install tampering gap:
 * replacing a secondary .inc/.phtml file on disk cannot bypass the installer
 * scanner and become active on the next request.
 */
function brendyExtensionSecurityCacheKeyBinary()
{
  if (!isset($GLOBALS['security']) || !is_object($GLOBALS['security']) ||
      !method_exists($GLOBALS['security'], 'extensionSecurityCacheKey')) return false;
  $keyHex = $GLOBALS['security']->extensionSecurityCacheKey();
  if (!is_string($keyHex) || preg_match('/^[a-f0-9]{64}$/D', $keyHex) !== 1) return false;
  $key = hex2bin($keyHex);
  return is_string($key) && strlen($key) === 32 ? $key : false;
}

function brendyExtensionSecurityCachePath()
{
  return defined('PATH_TMP') ? PATH_TMP . 'extension-security-cache.json' : false;
}

function brendyExtensionSecurityCacheCanonical($entries)
{
  if (!is_array($entries) || count($entries) > 500) return false;
  ksort($entries, SORT_STRING);
  try {
    return json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    return false;
  }
}

function brendyExtensionSecurityPolicyVersion()
{
  // Increment whenever source-scanner semantics become stricter. This keeps
  // cache invalidation explicit even for an emergency rebuild that reuses the
  // same public version/build identifiers.
  return 5;
}

function brendyExtensionSecurityCacheContext()
{
  return array(
    'version' => 2,
    'core' => (string)BRENDY_EXTENSION_API,
    'policy' => brendyExtensionSecurityPolicyVersion(),
    // Scanner policy changes can happen in a patch release without changing
    // Extension API compatibility. Binding cache trust to the concrete build
    // guarantees that a result produced by an older scanner is never reused
    // after an upgrade with stricter source rules.
    'build' => defined('BRENDY_BUILD') ? (int)BRENDY_BUILD : 0
  );
}

function brendyExtensionSecurityCacheSignature($entries, $key)
{
  if (!is_string($key) || strlen($key) !== 32) return false;
  $canonical = brendyExtensionSecurityCacheCanonical($entries);
  if (!is_string($canonical)) return false;
  $context = brendyExtensionSecurityCacheContext();
  if ($context['build'] <= 0 || $context['policy'] < 1) return false;
  return hash_hmac(
    'sha256',
    "Brendy-Extension-Scan-Cache-v2\n" . $context['core'] . "\n" . $context['build'] . "\n" . $context['policy'] . "\n" . $canonical,
    $key
  );
}

/**
 * Validate an authenticated extension-scan cache payload independently from
 * filesystem I/O. Returning FALSE means the caller must perform a fresh live
 * scan; an empty array is a valid authenticated cache with no entries.
 */
function brendyValidateExtensionSecurityCachePayload($payload, $key)
{
  $context = brendyExtensionSecurityCacheContext();
  if (!is_array($payload) || !is_string($key) || strlen($key) !== 32 ||
      ($payload['version'] ?? null) !== $context['version'] ||
      !isset($payload['core'], $payload['build'], $payload['policy'], $payload['entries'], $payload['hmac']) ||
      !is_string($payload['core']) || !hash_equals($context['core'], $payload['core']) ||
      !is_int($payload['build']) || $payload['build'] !== $context['build'] ||
      !is_int($payload['policy']) || $payload['policy'] !== $context['policy'] ||
      !is_array($payload['entries']) || count($payload['entries']) > 500 ||
      !is_string($payload['hmac']) || preg_match('/^[a-f0-9]{64}$/D', $payload['hmac']) !== 1) return false;

  $entries = array();
  foreach ($payload['entries'] as $cacheId => $entry) {
    if (!is_string($cacheId) || preg_match('/^[a-f0-9]{64}$/D', $cacheId) !== 1 || !is_array($entry) ||
        !isset($entry['treeHash'], $entry['files'], $entry['bytes'], $entry['checkedAt']) ||
        !is_string($entry['treeHash']) || preg_match('/^[a-f0-9]{64}$/D', $entry['treeHash']) !== 1 ||
        !is_int($entry['files']) || $entry['files'] < 1 || $entry['files'] > 1000 ||
        !is_int($entry['bytes']) || $entry['bytes'] < 1 || $entry['bytes'] > 67108864 ||
        !is_int($entry['checkedAt']) || $entry['checkedAt'] < 0 || $entry['checkedAt'] > time() + 300) continue;
    $entries[$cacheId] = $entry;
  }

  $expected = brendyExtensionSecurityCacheSignature($entries, $key);
  if (!is_string($expected) || !hash_equals($expected, $payload['hmac'])) return false;
  return $entries;
}

function brendyLoadExtensionSecurityCache()
{
  $path = brendyExtensionSecurityCachePath();
  $key = brendyExtensionSecurityCacheKeyBinary();
  if (!is_string($path) || !is_string($key) || !is_file($path) || is_link($path)) return array();
  $raw = Filesystem::readFileBounded($path, 2097152, 10);
  if (!is_string($raw)) return array();
  try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    return array();
  }
  $entries = brendyValidateExtensionSecurityCachePayload($payload, $key);
  return is_array($entries) ? $entries : array();
}

function brendySaveExtensionSecurityCache($entries)
{
  $path = brendyExtensionSecurityCachePath();
  $key = brendyExtensionSecurityCacheKeyBinary();
  if (!is_string($path) || !is_string($key) || !is_array($entries)) return false;
  if ((!is_dir(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) || is_link(PATH_TMP)) return false;

  $lockPath = $path . '.lock';
  $lock = Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
  if ($lock === false) return false;

  try {
    // Another request can complete a live scan while this request is waiting for
    // the lock. Merge only entries authenticated for this exact scanner build;
    // stale cache generations intentionally load as empty and are replaced.
    $diskEntries = brendyLoadExtensionSecurityCache();
    foreach ($diskEntries as $cacheId => $entry) {
      if (!isset($entries[$cacheId]) ||
          (int)($entry['checkedAt'] ?? 0) > (int)($entries[$cacheId]['checkedAt'] ?? 0)) {
        $entries[$cacheId] = $entry;
      }
    }

    uasort($entries, static function ($a, $b) {
      return ((int)($b['checkedAt'] ?? 0)) <=> ((int)($a['checkedAt'] ?? 0));
    });
    $entries = array_slice($entries, 0, 500, true);
    $context = brendyExtensionSecurityCacheContext();
    $signature = brendyExtensionSecurityCacheSignature($entries, $key);
    if (!is_string($signature)) return false;
    $payload = array(
      'version' => $context['version'],
      'core' => $context['core'],
      'build' => $context['build'],
      'policy' => $context['policy'],
      'entries' => $entries,
      'hmac' => $signature
    );
    try {
      $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      return false;
    }

    return Filesystem::atomicWrite($path, $json, 0600);
  } finally {
    Filesystem::releaseLock($lock);
  }
}

/**
 * Validate every executable-looking PHP source file in an installed extension
 * before the extension is executed. Brendy computes a SHA-256 manifest on
 * every request and reuses a HMAC-authenticated parser result only when every
 * executable byte is unchanged. This removes repeated token parsing on shared
 * hosting without trusting mtimes, filenames or a writable cache file.
 */
function brendyValidateExtensionPhpTreeSecurity($directory, $maxFiles = 250, $maxBytes = 8388608)
{
  if (!is_string($directory) || !is_dir($directory) || is_link($directory) ||
      !is_int($maxFiles) || $maxFiles < 1 || $maxFiles > 1000 ||
      !is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 67108864) return false;
  $root = realpath($directory);
  if (!is_string($root)) return false;

  $files = array();
  $bytes = 0;
  $entries = 0;
  try {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
      if (++$entries > 10000 || $item->isLink()) return false;
      $relativePath = substr($item->getPathname(), strlen($root) + 1);
      foreach (explode(DS, $relativePath) as $segment) {
        if ($segment === '' || $segment[0] === '.' || rtrim($segment, ' .') !== $segment || brendyIsServerControlFilename($segment)) return false;
      }
      $real = $item->getRealPath();
      if (!is_string($real) || strpos($real, $root . DS) !== 0) return false;
      if ($item->isDir()) continue;
      if (!$item->isFile()) return false;
      $name = $item->getFilename();
      // Reject executable-looking alternate or compound names, even when the
      // extension was copied over FTP instead of passing the ZIP installer.
      if (preg_match('/\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh|exe|dll|so|dylib|bin|cmd|bat|ps1|com|scr|jar|class)(?:\.|$)/iD', $name) !== 1) continue;
      if (preg_match('/\.php$/iD', $name) !== 1 ||
          preg_match('/\.(?:php[0-9]*|pht|phtml|phar|phps|cgi|fcgi)\./i', $name)) return false;
      $size = $item->getSize();
      if (!is_int($size) || $size < 1 || count($files) >= $maxFiles) return false;
      $bytes += $size;
      if ($bytes > $maxBytes) return false;
      $hash = Filesystem::hashFileBounded($real, 'sha256', $maxBytes, 1, $size);
      if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
      $relative = substr($real, strlen($root) + 1);
      if (!is_string($relative) || $relative === '' || strpos($relative, "\0") !== false) return false;
      $files[$relative] = array('path' => $real, 'size' => $size, 'hash' => $hash);
    }
  } catch (Throwable $e) {
    return false;
  }
  if (empty($files)) return false;
  ksort($files, SORT_STRING);

  $ctx = hash_init('sha256');
  foreach ($files as $relative => $file) {
    hash_update($ctx, $relative . "\0" . $file['size'] . "\0" . $file['hash'] . "\n");
  }
  $treeHash = hash_final($ctx);
  $cacheId = hash('sha256', $root);
  $cache = brendyLoadExtensionSecurityCache();
  if (isset($cache[$cacheId]) && is_array($cache[$cacheId]) &&
      isset($cache[$cacheId]['treeHash'], $cache[$cacheId]['files'], $cache[$cacheId]['bytes']) &&
      is_string($cache[$cacheId]['treeHash']) && hash_equals($treeHash, $cache[$cacheId]['treeHash']) &&
      (int)$cache[$cacheId]['files'] === count($files) && (int)$cache[$cacheId]['bytes'] === $bytes) {
    return true;
  }

  foreach ($files as $file) {
    // Re-check the SHA-256 immediately before parsing. A mid-request file change
    // is treated as unsafe instead of allowing a stale manifest decision.
    if (!brendyValidateExtensionPhpSecurity($file['path'], 8388608, $file['hash'])) return false;
  }

  $cache[$cacheId] = array(
    'treeHash' => $treeHash,
    'files' => count($files),
    'bytes' => $bytes,
    'checkedAt' => time()
  );
  // Cache persistence is an optimization only. A successful live scan remains
  // authoritative even when a read-only hosting account cannot write the cache.
  brendySaveExtensionSecurityCache($cache);
  return true;
}

function brendyIsSafeExtensionDirectoryName($name)
{
  if (!is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $name) !== 1 ||
      rtrim($name, '. ') !== $name) return false;

  // Portable extension names avoid ambiguous/reserved paths on common hosting
  // filesystems and make install/delete operations deterministic.
  $stem = strtolower((string)strtok($name, '.'));
  if (preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/D', $stem) === 1) return false;
  return true;
}

function brendyReadThemeLanguageFile($filename)
{
  if (!is_string($filename) || $filename === '' || is_link($filename) || !is_file($filename) || !is_readable($filename)) return false;
  $raw = Filesystem::readFileBounded($filename, 2097152, 2);
  if (!is_string($raw)) return false;
  try {
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    return false;
  }
  if (!is_array($data) || !isset($data['theme-data']) || !is_array($data['theme-data'])) return false;
  foreach (array('name' => 500, 'description' => 10000) as $field => $limit) {
    if (!array_key_exists($field, $data['theme-data'])) continue;
    $value = $data['theme-data'][$field];
    if (!is_scalar($value) || strlen((string)$value) > $limit) return false;
  }
  return $data;
}

function brendyExtensionMetadataCompatible($metadata)
{
  if (!is_array($metadata) || !isset($metadata['compatible']) || !is_string($metadata['compatible']) ||
      strlen($metadata['compatible']) > 1000) return false;
  $core = explode('.', BRENDY_CORE_COMPATIBILITY);
  if (count($core) < 2 || preg_match('/^[0-9]+$/D', (string)$core[0]) !== 1 ||
      preg_match('/^[0-9]+$/D', (string)$core[1]) !== 1) return false;
  $declaredVersions = array_slice(explode(',', $metadata['compatible']), 0, 20);
  if (empty($declaredVersions)) return false;
  foreach ($declaredVersions as $declaredVersion) {
    $declaredVersion = trim($declaredVersion);
    if (preg_match('/^[0-9]+\.[0-9]+$/D', $declaredVersion) !== 1) continue;
    $extension = explode('.', $declaredVersion);
    if ((int)$core[0] === (int)$extension[0] && (int)$core[1] === (int)$extension[1]) return true;
  }
  return false;
}

function brendyValidateExtensionMetadata($metadata, $type = null, $requireCompatibility = true)
{
  if (!is_array($metadata) || count($metadata) > 100 || !is_bool($requireCompatibility) ||
      ($type !== null && !in_array($type, array('plugin', 'theme'), true))) return false;

  foreach (array('author', 'version', 'releaseDate', 'brendyRelease', 'compatible', 'icon', 'category') as $required) {
    if (!isset($metadata[$required]) || !is_string($metadata[$required])) return false;
  }
  if ($metadata['author'] === '' || mb_strlen($metadata['author'], CHARSET) > 200 ||
      preg_match('/[\x00-\x1F\x7F]/', $metadata['author'])) return false;
  if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,49}$/D', $metadata['version']) !== 1 ||
      preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $metadata['releaseDate']) !== 1) return false;
  $releaseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $metadata['releaseDate']);
  if (!$releaseDate || $releaseDate->format('Y-m-d') !== $metadata['releaseDate']) return false;
  if (preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-+][A-Za-z0-9.-]+)?$/D', $metadata['brendyRelease']) !== 1 ||
      preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/D', $metadata['icon']) !== 1 ||
      preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $metadata['category']) !== 1) return false;
  if ($type === 'theme' && $metadata['category'] !== 'theme') return false;

  if (isset($metadata['website']) && $metadata['website'] !== '' &&
      !brendyIsSafeHttpUrl($metadata['website'], 2048)) return false;
  if (isset($metadata['email']) && $metadata['email'] !== '' &&
      (!is_string($metadata['email']) || strlen($metadata['email']) > 254 ||
       !filter_var($metadata['email'], FILTER_VALIDATE_EMAIL))) return false;

  $declaredVersions = array_slice(explode(',', $metadata['compatible']), 0, 20);
  if (empty($declaredVersions)) return false;
  foreach ($declaredVersions as $declaredVersion) {
    if (preg_match('/^[0-9]+\.[0-9]+$/D', trim($declaredVersion)) !== 1) return false;
  }
  if ($requireCompatibility && !brendyExtensionMetadataCompatible($metadata)) return false;

  foreach ($metadata as $key => $value) {
    if (!is_string($key) || strlen($key) > 64 || (!is_scalar($value) && $value !== null)) return false;
    if (is_string($value) && (strlen($value) > 20000 || strpos($value, "\0") !== false)) return false;
  }
  return true;
}

/**
 * Read the declared plugin class name without executing plugin.php. This keeps
 * inactive/incompatible extensions visible in the admin catalog while their PHP
 * remains completely unloaded. The runtime still performs the normal source scan
 * before any actual include.
 */
function brendyPluginClassNameFromSource($pluginFile)
{
  $parsed = brendyParsePhpSourceFile($pluginFile, 16777216);
  if (!is_array($parsed) || !isset($parsed['tokens']) || !is_array($parsed['tokens'])) return '';
  $tokens = $parsed['tokens'];

  // A plugin directory owns exactly one named BrendyPlugin* runtime class.
  // Skip Foo::class and anonymous "new class" tokens, and require the class name
  // to be the immediate significant token after T_CLASS. This avoids heuristic
  // look-ahead accidentally treating a method/function name as a class.
  $found = array();
  $count = count($tokens);
  for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) continue;

    $previous = null;
    for ($k = $i - 1; $k >= 0; $k--) {
      if (is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
      $previous = $tokens[$k];
      break;
    }
    if ((is_array($previous) && in_array($previous[0], array(T_DOUBLE_COLON, T_NEW), true)) || $previous === '::') continue;

    $class = '';
    for ($j = $i + 1; $j < $count; $j++) {
      if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
      if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $class = $tokens[$j][1];
      break;
    }
    if (is_string($class) && preg_match('/^BrendyPlugin[A-Za-z0-9_]{1,120}$/D', $class) === 1) {
      $found[] = $class;
      if (count($found) > 1) return '';
    }
  }
  return count($found) === 1 ? $found[0] : '';
}

function brendyPluginClassNameAvailable($className, $excludeDirectory = '')
{
  if (!is_string($className) || preg_match('/^BrendyPlugin[A-Za-z0-9_]{1,120}$/D', $className) !== 1 ||
      !is_string($excludeDirectory)) return false;
  $root = realpath(PATH_PLUGINS);
  if ($root === false) return false;
  foreach (array_slice(Filesystem::listDirectories(PATH_PLUGINS), 0, 1000) as $pluginPath) {
    $real = realpath($pluginPath);
    if ($real === false || is_link($pluginPath) || strpos($real, $root . DS) !== 0) continue;
    $directory = basename($real);
    if ($excludeDirectory !== '' && hash_equals($excludeDirectory, $directory)) continue;
    $declared = brendyPluginClassNameFromSource($real . DS . 'plugin.php');
    if ($declared !== '' && strcasecmp($declared, $className) === 0) return false;
  }
  return !class_exists($className, false);
}

/*
| Install a Brendy plugin or theme from a ZIP uploaded by an administrator.
| Archives are extracted into a private random staging directory first, then
| validated before a single top-level extension directory is moved into place.
*/
function installBrendyExtensionArchive($uploadedFile, $type)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyInstallExtensionArchiveUnlocked($uploadedFile, $type);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyInstallExtensionArchiveUnlocked($uploadedFile, $type)
{
  if (!checkRole(array('admin'), false) || !in_array($type, array('plugin', 'theme'), true) ||
      !is_array($uploadedFile) || !isset($uploadedFile['name'], $uploadedFile['tmp_name'], $uploadedFile['error'], $uploadedFile['size']) ||
      !is_string($uploadedFile['name']) || !is_string($uploadedFile['tmp_name']) || $uploadedFile['error'] !== UPLOAD_ERR_OK ||
      !is_scalar($uploadedFile['size']) || (int)$uploadedFile['size'] < 1 || (int)$uploadedFile['size'] > 20971520 ||
      !is_uploaded_file($uploadedFile['tmp_name']) || Text::lowercase(Filesystem::extension($uploadedFile['name'])) !== 'zip' ||
      !class_exists('ZipArchive')) return false;

  $staging = PATH_TMP . 'extension-' . bin2hex(random_bytes(16));
  if (!Filesystem::mkdir($staging, true)) return false;
  try {
    if (!Filesystem::unzip($uploadedFile['tmp_name'], $staging, null, 104857600, 5000)) return false;
    $scannedEntries = @scandir($staging);
    if (!is_array($scannedEntries)) return false;
    $entries = array_values(array_filter($scannedEntries, function ($entry) {
      return $entry !== '.' && $entry !== '..' && $entry !== '__MACOSX' && $entry !== '.DS_Store';
    }));
    if (count($entries) !== 1) return false;

    $directoryName = $entries[0];
    if (!brendyIsSafeExtensionDirectoryName($directoryName) ||
        ($type === 'plugin' && brendyIsRetiredBundledPluginDirectory($directoryName))) return false;
    $source = $staging . DS . $directoryName;
    if (!is_dir($source) || is_link($source)) return false;

    $metadataFile = $source . DS . 'metadata.json';
    $requiredCode = $source . DS . ($type === 'plugin' ? 'plugin.php' : 'index.php');
    $metadataRaw = Filesystem::readFileBounded($metadataFile, 1048576, 2);
    $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true, 64) : null;
    if (!brendyValidateExtensionMetadata($metadata, $type) ||
        !brendyValidateExtensionPhpSecurity($requiredCode, 5242880)) return false;
    if ($type === 'plugin') {
      $pluginClass = brendyPluginClassNameFromSource($requiredCode);
      if ($pluginClass === '' || !brendyPluginClassNameAvailable($pluginClass)) return false;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    $fileCount = 0;
    $totalSize = 0;
    foreach ($iterator as $fileInfo) {
      if ($fileInfo->isLink() || ++$fileCount > 5000) return false;
      if ($fileInfo->isFile()) {
        $totalSize += $fileInfo->getSize();
        if ($totalSize > 104857600) return false;
        $name = $fileInfo->getFilename();
        $lowerName = strtolower($name);
        if ($name === '' || $name[0] === '.' || brendyIsServerControlFilename($name) ||
            preg_match('/\.(?:phar|phtml|pht|inc|module|install|cgi|fcgi|pl|py|sh|exe|dll|so|dylib|bin|cmd|bat|ps1|com|scr|jar|class)$/iD', $name) ||
            preg_match('/\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh)(?:\.|$)/iD', $name)) {
          // Plain .php is the only executable source type accepted; it is
          // parsed and statically screened below. Multi-extension PHP names
          // are intentionally rejected because Apache may execute them.
          if (preg_match('/\.php$/iD', $name) !== 1 || preg_match('/\.php\./i', $name)) return false;
        }
        $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($source) + 1));
        foreach (explode('/', $relative) as $segment) {
          if ($segment === '' || $segment[0] === '.' || rtrim($segment, ' .') !== $segment) return false;
        }
        if (preg_match('/\.php$/iD', $name) && !brendyValidateExtensionPhpSecurity($fileInfo->getPathname())) return false;
      }
    }

    $base = $type === 'plugin' ? PATH_PLUGINS : PATH_THEMES;
    $destination = $base . $directoryName;
    if (file_exists($destination) || is_link($destination)) return false;
    if (!Filesystem::mv($source, $destination)) return false;
    @chmod($destination, DIR_PERMISSIONS);
    return $directoryName;
  } catch (Throwable $e) {
    // Uploaded extension trees are untrusted filesystem input. Iterator/stat
    // failures must reject the archive instead of bubbling into an admin 500.
    Log::set(__METHOD__ . LOG_SEP . 'Extension archive validation failed safely.', LOG_TYPE_ERROR);
    return false;
  } finally {
    if (Filesystem::directoryExists($staging)) Filesystem::deleteRecursive($staging);
  }
}

function deleteBrendyExtension($directoryName, $type)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyDeleteExtensionUnlocked($directoryName, $type);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyDeleteExtensionUnlocked($directoryName, $type)
{
  global $site, $plugins;
  if (!checkRole(array('admin'), false) || !brendyIsSafeExtensionDirectoryName($directoryName) ||
      !in_array($type, array('plugin', 'theme'), true)) return false;

  if ($type === 'theme') {
    if (hash_equals((string)$site->theme(), $directoryName)) return false;
    $base = PATH_THEMES;
  } else {
    $base = PATH_PLUGINS;
    // Source deletion is independent of whether plugin.php was loaded. A corrupt
    // or incompatible extension is intentionally absent from $plugins['all'], so
    // inspect persisted state paths directly and require state cleanup first.
    $databaseState = PATH_PLUGINS_DATABASES . $directoryName;
    $workspaceState = PATH_WORKSPACES . $directoryName . DS;
    if (file_exists($databaseState) || is_link($databaseState) ||
        file_exists($workspaceState) || is_link($workspaceState)) return false;
  }

  $path = $base . $directoryName;
  if (!is_dir($path) || is_link($path) || !Sanitize::pathFile($base, $directoryName)) return false;
  return Filesystem::deleteRecursive($path);
}

// Activate a theme
function activateTheme($themeDirectory)
{
  if (!brendyAcquireExtensionMutationLock()) return false;
  try {
    return brendyActivateThemeUnlocked($themeDirectory);
  } finally {
    brendyReleaseExtensionMutationLock();
  }
}

function brendyActivateThemeUnlocked($themeDirectory)
{
  global $site;
  global $syslog;
  global $L, $language;
  global $plugins;

	if (!brendyIsSafeExtensionDirectoryName($themeDirectory) ||
		!is_dir(PATH_THEMES . $themeDirectory) || is_link(PATH_THEMES . $themeDirectory) ||
		!Sanitize::pathFile(PATH_THEMES, $themeDirectory)) return false;

	$currentTheme = $site->theme();
	if ($themeDirectory === $currentTheme) return true;

	$metadataFile = PATH_THEMES . $themeDirectory . DS . 'metadata.json';
	if (!Sanitize::pathFile(PATH_THEMES . $themeDirectory . DS, 'metadata.json')) return false;
	$metadataRaw = Filesystem::readFileBounded($metadataFile, 1048576, 2);
	$metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true, 64) : null;
	if (!brendyValidateExtensionMetadata($metadata, 'theme')) return false;

	$themeRoot = PATH_THEMES . $themeDirectory . DS;
	$indexFile = $themeRoot . 'index.php';
	$defaultLanguageFile = $themeRoot . 'jezici' . DS . DEFAULT_LANGUAGE_FILE;
	if (!is_file($indexFile) || is_link($indexFile) || !is_readable($indexFile) ||
		brendyReadThemeLanguageFile($defaultLanguageFile) === false ||
		!brendyValidateExtensionPhpTreeSecurity($themeRoot)) return false;

	// The install hook is an executable boundary. Scan the complete theme first,
	// then keep the individual file containment/size checks as defense in depth.
	$installFile = $themeRoot . 'install.php';
	if (file_exists($installFile)) {
		$installSize = @filesize($installFile);
		if (!is_file($installFile) || is_link($installFile) || !Sanitize::pathFile(PATH_THEMES . $themeDirectory . DS, 'install.php') ||
			!is_int($installSize) || $installSize < 1 || $installSize > 2097152) return false;
		try {
			// An install hook can indicate failure by explicitly returning FALSE,
			// not only by throwing an exception. Never commit the new theme when
			// its install hook reports failure.
			$installResult = include_once($installFile);
			if ($installResult === false) {
				Log::set(__METHOD__ . LOG_SEP . 'Theme installation script returned failure.', LOG_TYPE_ERROR);
				return false;
			}
		} catch (Throwable $e) {
			Log::set(__METHOD__ . LOG_SEP . 'Theme installation script failed.', LOG_TYPE_ERROR);
			return false;
		}
	}

	$currentPluginClass = brendyPluginClassNameForDirectory($currentTheme, false);
	$currentPluginWasActive = $currentPluginClass !== '' && pluginActivated($currentPluginClass);
	$newPluginClass = brendyPluginClassNameForDirectory($themeDirectory, false);
	$newPluginExists = $newPluginClass !== '' && isset($plugins['all'][$newPluginClass]);
	$newPluginWasActive = $newPluginExists && pluginActivated($newPluginClass);
	if ($newPluginExists && !$newPluginWasActive && !activatePlugin($newPluginClass)) return false;

	if (!$site->set(array('theme' => $themeDirectory))) {
		if ($newPluginExists && !$newPluginWasActive && !deactivatePlugin($newPluginClass)) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back newly activated companion plugin after theme-setting failure.', LOG_TYPE_ERROR);
		}
		return false;
	}

	if ($currentPluginWasActive && !deactivatePlugin($currentPluginClass)) {
		// Roll back the visible theme first. Only deactivate the new companion if
		// the old theme setting was actually restored; otherwise keeping the new
		// companion active is the least-inconsistent state for the theme that remains
		// configured after a failed rollback.
		$themeRolledBack = $site->set(array('theme' => $currentTheme));
		if (!$themeRolledBack) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to roll back the theme setting; preserving the new companion plugin state.', LOG_TYPE_ERROR);
		} elseif ($newPluginExists && !$newPluginWasActive && !deactivatePlugin($newPluginClass)) {
			Log::set(__METHOD__ . LOG_SEP . 'Theme setting rolled back but the new companion plugin could not be deactivated.', LOG_TYPE_ERROR);
		}
		return false;
	}

	$syslog->add(array(
		'dictionaryKey' => 'new-theme-configured',
		'notes' => $themeDirectory
	));
	Alert::set($L->g('The changes have been saved'));
	return true;
}


function requireAjaxMethod($method)
{
  $method = strtoupper(trim((string)$method));
  $allowedMethods = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS');
  if (!in_array($method, $allowedMethods, true)) {
    http_response_code(500);
    ajaxResponse(1, 'Invalid AJAX endpoint configuration');
  }
  $actual = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper(trim($_SERVER['REQUEST_METHOD'])) : '';
  if ($actual === $method) return true;
  http_response_code(405);
  if (!headers_sent()) header('Allow: ' . $method);
  global $L;
  ajaxResponse(1, isset($L) ? $L->g('Method not allowed') : 'Method not allowed');
}

function requireAjaxRole($roles)
{
  global $L;
  if (!is_array($roles) || empty($roles) || !checkRole($roles, false)) {
    http_response_code(403);
    ajaxResponse(1, $L->g('You do not have sufficient permissions'));
  }
  return true;
}

// Send JSON with the same security and cache policy for every internal AJAX
// response. Some legacy endpoints intentionally return a raw array instead of
// the standard status/message envelope, so keep transport hardening separate.
function generatedFileNeedsRefresh($file, $dependencies = array())
{
  if (!is_string($file) || $file === '') return true;
  clearstatcache(true, $file);
  if (is_link($file) || !is_file($file)) return true;
  $generatedMtime = @filemtime($file);
  if (!is_int($generatedMtime) || $generatedMtime < 1) return true;
  if (!is_array($dependencies)) return true;
  foreach ($dependencies as $dependency) {
    // Configured dependencies are required inputs, not optional hints. Missing,
    // linked or unreadable source state must never make a generated artifact look
    // fresh merely because there is no mtime to compare.
    if (!is_string($dependency) || $dependency === '') return true;
    clearstatcache(true, $dependency);
    if (is_link($dependency) || !is_file($dependency) || !is_readable($dependency)) return true;
    $modified = @filemtime($dependency);
    // PHP exposes file mtimes at one-second granularity on common hosting. If a
    // dependency is rewritten in the same second as the generated artifact, an
    // equality check cannot prove ordering. Treat equality as stale so a cache
    // can never remain permanently older than same-second content. The next
    // rebuild performed in a later second naturally restores the fast path.
    if (!is_int($modified) || $modified < 1 || $modified >= $generatedMtime) return true;
  }
  return false;
}

function serveGeneratedXmlFile($file, $contentType, $maxSize)
{
  $allowedTypes = array('application/xml');
  if (!is_string($file) || $file === '' || !is_string($contentType) ||
      !in_array($contentType, $allowedTypes, true) || !is_int($maxSize) || $maxSize < 1) {
    http_response_code(500);
    exit(0);
  }

  $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
  if (!in_array($method, array('GET', 'HEAD'), true)) {
    if (!headers_sent()) {
      header('Allow: GET, HEAD');
      header('Cache-Control: no-store');
    }
    http_response_code(405);
    exit(0);
  }

  // Capture bytes and HTTP metadata from the same regular-file descriptor.
  // Generated artifacts are atomically replaced; pathname stat + readfile()
  // could otherwise mix the old Content-Length/ETag with bytes from a newer file.
  $snapshot = Filesystem::readFileSnapshotBounded($file, $maxSize, 1);
  if (!is_array($snapshot) || !isset($snapshot['data'], $snapshot['size'], $snapshot['mtime']) ||
      !is_string($snapshot['data']) || !is_int($snapshot['size']) || $snapshot['size'] < 1 ||
      !is_int($snapshot['mtime']) || $snapshot['mtime'] < 1) {
    if (!headers_sent()) header('Cache-Control: no-store');
    http_response_code(404);
    exit(0);
  }

  $size = $snapshot['size'];
  $mtime = $snapshot['mtime'];
  $etag = '"' . hash('sha256', $snapshot['data']) . '"';
  $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
  if (!headers_sent()) {
    header('Content-Type: ' . $contentType . '; charset=UTF-8');
    header('Cache-Control: public, max-age=300, stale-while-revalidate=60');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
  }

  $notModified = false;
  $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) && is_string($_SERVER['HTTP_IF_NONE_MATCH'])
    ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
  if ($ifNoneMatch !== '') {
    foreach (explode(',', $ifNoneMatch) as $candidate) {
      $candidate = trim($candidate);
      if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
        $notModified = true;
        break;
      }
    }
  } elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && is_string($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($since !== false && $since >= $mtime) $notModified = true;
  }
  if ($notModified) {
    http_response_code(304);
    exit(0);
  }

  if (!headers_sent()) header('Content-Length: ' . $size);
  if ($method === 'HEAD') exit(0);
  echo $snapshot['data'];
  exit(0);
}

function sendJsonResponse($payload, $httpStatus = null, $fallbackJson = '{}')
{
  if ($httpStatus !== null) {
    $httpStatus = filter_var($httpStatus, FILTER_VALIDATE_INT);
    if ($httpStatus === false || $httpStatus < 100 || $httpStatus > 599) {
      $httpStatus = 500;
    }
    http_response_code($httpStatus);
  }
  if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=' . CHARSET);
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
  }
  if (!is_string($fallbackJson) || $fallbackJson === '') {
    $fallbackJson = '{}';
  }
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if (!is_string($json)) {
    http_response_code(500);
    $json = $fallbackJson;
  }
  exit($json);
}

function ajaxResponse($status = 0, $message = "", $data = array())
{
  if (!is_array($data)) {
    http_response_code(500);
    $status = 1;
    $message = 'Response payload is invalid.';
    $data = array();
  }
  $output = $data;
  // Reserved envelope fields must always come from the function arguments.
  // A data payload must never be able to turn an error into a success response.
  $output['status'] = $status;
  $output['message'] = is_scalar($message) ? (string)$message : '';
  sendJsonResponse($output, null, '{"status":1,"message":"Response encoding failed."}');
}

/*
| Stage regular files into the protected Brendy temporary directory before a
| destructive metadata/database change. Callers can either roll the files back
| or purge the staging directory after their commit succeeds.
*/
function stageFilesForDeletion($paths, $label = 'files')
{
  if (!is_array($paths) || count($paths) > 100) return false;
  if (!is_string($label) || preg_match('/^[a-z0-9-]{1,32}$/D', $label) !== 1) $label = 'files';

  $targets = array();
  foreach ($paths as $path) {
    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) return false;
    if (!file_exists($path) && !is_link($path)) continue;
    if (!is_file($path) || is_link($path)) return false;
    $targets[] = $path;
  }
  $targets = array_values(array_unique($targets));
  if (empty($targets)) return array('directory' => null, 'files' => array());

  if (!Filesystem::directoryExists(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) return false;
  $stageDirectory = PATH_TMP . 'staged-delete-' . $label . '-' . bin2hex(random_bytes(8)) . DS;
  if (!Filesystem::mkdir($stageDirectory, true)) return false;

  $staged = array();
  foreach ($targets as $index => $target) {
    $stageFile = $stageDirectory . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '-' . basename($target);
    if (!Filesystem::mv($target, $stageFile)) {
      $state = array('directory' => $stageDirectory, 'files' => $staged);
      rollbackStagedDeletion($state);
      return false;
    }
    $staged[] = array('target' => $target, 'stage' => $stageFile);
  }
  return array('directory' => $stageDirectory, 'files' => $staged);
}

function rollbackStagedDeletion($state)
{
  if (!is_array($state) || !isset($state['files']) || !is_array($state['files'])) return false;
  $ok = true;
  foreach (array_reverse($state['files']) as $item) {
    if (!is_array($item) || !isset($item['target'], $item['stage']) ||
      !is_string($item['target']) || !is_string($item['stage'])) {
      $ok = false;
      continue;
    }
    if (is_file($item['stage']) && !is_link($item['stage'])) {
      if (file_exists($item['target']) || is_link($item['target']) || !Filesystem::mv($item['stage'], $item['target'])) {
        $ok = false;
      }
    }
  }
  $directory = isset($state['directory']) && is_string($state['directory']) ? $state['directory'] : null;
  if ($ok && $directory !== null && Filesystem::directoryExists($directory) && !Filesystem::deleteRecursive($directory)) {
    $ok = false;
  }
  return $ok;
}

function purgeStagedDeletion($state)
{
  if (!is_array($state) || !array_key_exists('directory', $state)) return false;
  if ($state['directory'] === null) return true;
  if (!is_string($state['directory']) || !Filesystem::directoryExists($state['directory'])) return false;
  return Filesystem::deleteRecursive($state['directory']);
}

/*
| Validate non-image user uploads using extension, decoded MIME type and a
| small magic-byte check for container formats. This prevents active content
| (HTML/SVG/PHP/shell/executables) from being smuggled behind an allowed file
| suffix. Images are delegated to validateUploadedImage().
*/
function validateUploadedAttachment($file, $originalName = null)
{
  if (!is_string($file) || !is_file($file) || is_link($file)) return false;

  $name = ($originalName === null) ? basename($file) : (string)$originalName;
  if (strlen($name) < 1 || strlen($name) > 255 || basename($name) !== $name ||
      strpos($name, "\0") !== false || brendyHasDangerousExecutableFilename($name)) return false;
  $extension = Text::lowercase(Filesystem::extension($name));
  if (!in_array($extension, $GLOBALS['ALLOWED_FILE_EXTENSIONS'], true)) return false;
  if (in_array($extension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) {
    return validateUploadedImage($file, $name);
  }

  // Bind file size and magic bytes to one opened descriptor. JSON is capped at
  // 8 MiB and can be parsed from the same full snapshot; other attachments only
  // need a small prefix so a 100 MiB upload never becomes a 100 MiB PHP string.
  if ($extension === 'json') {
    $snapshot = Filesystem::readFileSnapshotBounded($file, 8388608, 1);
  } else {
    $snapshot = Filesystem::readFilePrefixSnapshotBounded($file, 512, BRENDY_MAX_FILE_UPLOAD_BYTES, 1);
  }
  if (!is_array($snapshot) || !isset($snapshot['data'], $snapshot['size']) ||
      !is_string($snapshot['data']) || !is_int($snapshot['size'])) return false;
  $fileSize = $snapshot['size'];
  $head = $extension === 'json' ? substr($snapshot['data'], 0, 512) : $snapshot['data'];
  if ($head === '') return false;

  $mime = Filesystem::mimeType($file);
  if (!is_string($mime) || $mime === '') return false;
  $mime = strtolower(trim(explode(';', $mime, 2)[0]));
  $blockedMime = array(
    'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
    'application/x-httpd-php', 'application/x-php', 'text/x-php', 'application/x-sh',
    'application/x-shellscript', 'text/x-shellscript', 'application/x-executable',
    'application/x-sharedlib', 'application/x-msdownload', 'application/x-dosexec'
  );
  if (in_array($mime, $blockedMime, true)) return false;

  $startsWith = static function ($signature) use ($head) {
    return strncmp($head, $signature, strlen($signature)) === 0;
  };

  switch ($extension) {
    case 'pdf':
      return $mime === 'application/pdf' && $startsWith('%PDF-');
    case 'zip':
      return in_array($mime, array('application/zip', 'application/x-zip-compressed', 'application/octet-stream'), true) &&
        ($startsWith("PK\x03\x04") || $startsWith("PK\x05\x06") || $startsWith("PK\x07\x08"));
    case 'gz':
      return in_array($mime, array('application/gzip', 'application/x-gzip', 'application/octet-stream'), true) && $startsWith("\x1F\x8B");
    case 'tar':
      return in_array($mime, array('application/x-tar', 'application/octet-stream'), true) &&
        (strlen($head) >= 262 ? substr($head, 257, 5) === 'ustar' : false);
    case 'docx':
      return in_array($mime, array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'), true) && $startsWith("PK\x03\x04");
    case 'xlsx':
      return in_array($mime, array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'), true) && $startsWith("PK\x03\x04");
    case 'pptx':
      return in_array($mime, array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'), true) && $startsWith("PK\x03\x04");
    case 'doc':
    case 'xls':
    case 'ppt':
      $ole = $startsWith("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
      return $ole && (strpos($mime, 'application/vnd.ms-') === 0 || in_array($mime, array('application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'), true));
    case 'txt':
      return in_array($mime, array('text/plain', 'application/octet-stream'), true) && strpos($head, "\0") === false;
    case 'md':
      return in_array($mime, array('text/plain', 'text/markdown', 'text/x-markdown', 'application/octet-stream'), true) && strpos($head, "\0") === false;
    case 'json':
      if (!in_array($mime, array('application/json', 'text/json', 'text/plain', 'application/octet-stream'), true) || strpos($head, "\0") !== false) return false;
      json_decode($snapshot['data'], true);
      return json_last_error() === JSON_ERROR_NONE;
    case 'mp3':
      $mp3Signature = $startsWith('ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0);
      return $mp3Signature && in_array($mime, array('audio/mpeg', 'audio/mp3', 'application/octet-stream'), true);
    case 'mp4':
      return in_array($mime, array('video/mp4', 'audio/mp4', 'application/mp4', 'application/octet-stream'), true) &&
        (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp');
    case 'wav':
      return in_array($mime, array('audio/wav', 'audio/x-wav', 'audio/wave', 'application/octet-stream'), true) &&
        strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE';
    case 'ogg':
      return in_array($mime, array('audio/ogg', 'video/ogg', 'application/ogg', 'application/octet-stream'), true) && $startsWith('OggS');
  }
  return false;
}

/*
| Strictly validate an uploaded raster image. An extension alone is not a
| security boundary: the decoded MIME type and image metadata must agree.
*/
function validateUploadedImage($file, $originalName = null)
{
	if (!is_string($file) || !is_file($file) || is_link($file)) {
		return false;
	}
	$fileSize = @filesize($file);
	if (!is_int($fileSize) || $fileSize < 1 || $fileSize > BRENDY_MAX_IMAGE_UPLOAD_BYTES) {
    return false;
  }

	$name = ($originalName === null) ? basename($file) : (string)$originalName;
	if (strlen($name) < 1 || strlen($name) > 255 || basename($name) !== $name ||
			strpos($name, "\0") !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false ||
			preg_match('/[\x00-\x1F\x7F]/', $name) === 1) return false;
  $extension = Text::lowercase(Filesystem::extension($name));
  $allowed = array(
    'gif' => array('image/gif'),
    'png' => array('image/png'),
    'jpg' => array('image/jpeg'),
    'jpeg' => array('image/jpeg'),
    'webp' => array('image/webp')
  );
  if (!isset($allowed[$extension])) {
    return false;
  }

  $mime = Filesystem::mimeType($file);
  if (!is_string($mime) || !in_array(strtolower($mime), $allowed[$extension], true)) {
    return false;
  }

	$imageInfo = @getimagesize($file);
	if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime']) ||
		!in_array(strtolower($imageInfo['mime']), $allowed[$extension], true)) {
		return false;
	}
	$width = (int) $imageInfo[0];
	$height = (int) $imageInfo[1];
	return $width > 0 && $height > 0 && $width <= BRENDY_MAX_IMAGE_DIMENSION && $height <= BRENDY_MAX_IMAGE_DIMENSION &&
		($width * $height) <= BRENDY_MAX_IMAGE_PIXELS;
}

/*
| This function checks the image extension,
| generate a new filename to not overwrite the exists,
| generate the thumbnail,
| and move the image to a proper place
|
| @file		string	Path and filename of the image
| @imageDir	string	Path where the image is going to be stored
| @thumbnailDir	string	Path where the thumbnail is going to be stored, if you don't set the variable is not going to create the thumbnail
|
| @return	string/boolean	Path and filename of the new image or FALSE if there were some error
*/
function transformImage($file, $imageDir, $thumbnailDir = false)
{
  global $site;

  if (!is_string($file) || !is_string($imageDir) || $imageDir === '' ||
      strpos($file . $imageDir, "\0") !== false ||
      (!empty($thumbnailDir) && (!is_string($thumbnailDir) || strpos($thumbnailDir, "\0") !== false))) {
    return false;
  }

  // Block dotfiles
  if (strpos(basename($file), '.') === 0) {
    if (file_exists($file)) {
      @unlink($file);
    }
    return false;
  }

  // Check the extension, decoded MIME type and image metadata.
  $fileExtension = Filesystem::extension($file);
  $fileExtension = Text::lowercase($fileExtension);
  if (!in_array($fileExtension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true) ||
      !validateUploadedImage($file)) {
    if (is_file($file)) {
      @unlink($file);
    }
    return false;
  }

  if (!Filesystem::directoryExists($imageDir) && !Filesystem::mkdir($imageDir, true)) {
	if (is_file($file)) @unlink($file);
    return false;
  }
  if (!Filesystem::directoryExists($imageDir)) {
	if (is_file($file)) @unlink($file);
	return false;
  }
  if (!empty($thumbnailDir) && !Filesystem::directoryExists($thumbnailDir) &&
      !Filesystem::mkdir($thumbnailDir, true)) {
	if (is_file($file)) @unlink($file);
    return false;
  }
  if (!empty($thumbnailDir) && !Filesystem::directoryExists($thumbnailDir)) {
	if (is_file($file)) @unlink($file);
	return false;
  }

  // Generate a filename to not overwrite current image if exists
  $filename = Filesystem::filename($file);

  // Additional sanitization for filenames to prevent issues with special characters
  $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
  $filenameWithoutExt = Text::removeSpecialCharacters($filenameWithoutExt, '-');
  $filenameWithoutExt = Text::removeQuotes($filenameWithoutExt);
  $filenameWithoutExt = Text::removeSpaces($filenameWithoutExt, '-');
	$filenameWithoutExt = mb_substr($filenameWithoutExt, 0, 150, CHARSET);
	if ($filenameWithoutExt === '') {
		$filenameWithoutExt = 'image';
	}
	$filename = $filenameWithoutExt . '.' . $fileExtension;

	// Publish under the next available name with a bounded collision retry.
	// nextFilename() alone is advisory: another request may claim its candidate
	// before the no-clobber mv() commits.
	$image = Filesystem::moveToUniqueFilename($file, $filename, $imageDir, 16);
	if (!is_string($image) || !is_file($image) || is_link($image)) {
		if (is_file($file) && !is_link($file)) @unlink($file);
		return false;
	}
	$nextFilename = Filesystem::filename($image);
	@chmod($image, 0644);

  // Generate Thumbnail
  if (!empty($thumbnailDir) && $site->thumbnailEnable()) {
    $Image = new Image();
    // setImage() returns false when GD cannot decode the source (missing
    // format support or corrupted file). Skip saveImage() in that case and
    // log so the operator can diagnose — the original image remains stored
    // so the Media Manager can still display it via the fallback path.
    if ($Image->setImage($image, $site->thumbnailWidth(), $site->thumbnailHeight(), 'crop') === false) {
      Log::set('Thumbnail generation skipped (GD cannot decode): ' . $image, LOG_TYPE_ERROR);
    } else {
		if (!$Image->saveImage($thumbnailDir . $nextFilename, $site->thumbnailQuality())) {
			Log::set('Thumbnail generation failed while saving: ' . $thumbnailDir . $nextFilename, LOG_TYPE_ERROR);
		}
    }
  }

  return $image;
}

/*
| Builds the file list for the Media Manager. Scans originals (not thumbnails)
| and pairs each original with its matching thumbnail. Used by list-images.php
| and the Media Manager preload in media.php.
|
| The thumbnail filename usually matches the original, but can differ:
|  - Legacy uploads: before commit 82f30f8b thumbnails were forced to .jpg, so
|    pre-existing pairs may have different extensions (e.g., original test.png
|    with thumbnail test.jpg).
|  - Thumbnail generation disabled or failed: no thumbnail file exists at all.
|    In that case 'thumbnail' is returned as an empty string so the client can
|    fall back to the original image URL instead of building a broken
|    PAGE_THUMBNAILS_URL + filename that would 404.
|
| @imagePath     string  Filesystem path to the originals directory (trailing DS)
| @thumbnailPath string  Filesystem path to the thumbnails directory (trailing DS)
| @chunk         int     Chunk size for pagination
|
| @return array[]  Array of chunks; each chunk is an array of items with keys
|                  'filename' (original) and 'thumbnail' (resolved preview).
*/
function mediaManagerCollectImages($imagePath, $query = '')
{
  global $site;
  if (!is_string($imagePath) || !is_dir($imagePath) || is_link($imagePath) || !is_string($query) ||
      mb_strlen($query, CHARSET) > 100 || strpos($query, "\0") !== false) return array();
  $query = trim($query);
  $queryLower = $query === '' ? '' : Text::lowercase($query);
  $logoFilename = ($site && method_exists($site, 'logo')) ? $site->logo(false) : '';
  $files = array();
  try {
    $iterator = new DirectoryIterator($imagePath);
    foreach ($iterator as $entry) {
      if ($entry->isDot() || $entry->isLink() || !$entry->isFile()) continue;
      $filename = $entry->getFilename();
      if (strlen($filename) > 255 || preg_match('/[\x00-\x1F\x7F]/', $filename) ||
          strpos($filename, '/') !== false || strpos($filename, '\\') !== false ||
          ($logoFilename && $filename === $logoFilename)) continue;
      $extension = Text::lowercase(pathinfo($filename, PATHINFO_EXTENSION));
      if (!in_array($extension, $GLOBALS['ALLOWED_IMG_EXTENSION'], true)) continue;
      if ($queryLower !== '' && mb_strpos(Text::lowercase($filename), $queryLower, 0, CHARSET) === false) continue;
      $files[] = array('filename' => $filename, 'modified' => $entry->getMTime());
      if (count($files) >= 10000) break;
    }
  } catch (UnexpectedValueException $e) {
    return array();
  }
  usort($files, static function ($a, $b) {
    if (MEDIA_MANAGER_SORT_BY_DATE && $a['modified'] !== $b['modified']) return $b['modified'] <=> $a['modified'];
    return strnatcasecmp($a['filename'], $b['filename']);
  });
  return $files;
}

function mediaManagerResolveImageItems($files, $imagePath, $thumbnailPath)
{
  if (!is_array($files) || !is_string($imagePath) || !is_string($thumbnailPath)) return array();
  $items = array();
  foreach (array_slice($files, 0, 10000) as $file) {
    if (!is_array($file) || !isset($file['filename']) || !is_string($file['filename'])) continue;
    $filename = $file['filename'];
    $thumbnail = '';
    if (is_file($thumbnailPath . $filename) && !is_link($thumbnailPath . $filename)) {
      $thumbnail = $filename;
    } else {
      $base = pathinfo($filename, PATHINFO_FILENAME);
      $candidate = $base . '.jpg';
      if ($candidate !== $filename && is_file($thumbnailPath . $candidate) && !is_link($thumbnailPath . $candidate) &&
          !is_file($imagePath . $candidate)) $thumbnail = $candidate;
    }
    $items[] = array('filename' => $filename, 'thumbnail' => $thumbnail);
  }
  return $items;
}

/** Efficient server-side page for Media Manager. Thumbnail lookups are limited to the requested page. */
function mediaManagerImagePage($imagePath, $thumbnailPath, $pageNumber, $itemsPerPage, $query = '')
{
  if (!is_int($pageNumber) || $pageNumber < 1 || $pageNumber > 100000 || !is_int($itemsPerPage) ||
      $itemsPerPage < 1 || $itemsPerPage > 100 || !is_string($thumbnailPath)) {
    return array('numberOfPages' => 0, 'totalFiles' => 0, 'files' => array());
  }
  $files = mediaManagerCollectImages($imagePath, $query);
  $totalFiles = count($files);
  $numberOfPages = $totalFiles > 0 ? (int)ceil($totalFiles / $itemsPerPage) : 0;
  $offset = ($pageNumber - 1) * $itemsPerPage;
  $pageFiles = $offset < $totalFiles ? array_slice($files, $offset, $itemsPerPage) : array();
  return array(
    'numberOfPages' => $numberOfPages,
    'totalFiles' => $totalFiles,
    'files' => mediaManagerResolveImageItems($pageFiles, $imagePath, $thumbnailPath)
  );
}

/* Backward-compatible full-list wrapper retained for third-party/admin integrations. */
function mediaManagerListImages($imagePath, $thumbnailPath, $chunk, $query = '')
{
  if (!is_int($chunk) || $chunk < 1 || $chunk > 100 || !is_string($thumbnailPath)) return array();
  $files = mediaManagerCollectImages($imagePath, $query);
  $items = mediaManagerResolveImageItems($files, $imagePath, $thumbnailPath);
  return $items ? array_chunk($items, $chunk) : array();
}

function downloadRestrictedFile($file)
{
	if (!is_string($file) || $file === '' || strpos($file, "\0") !== false ||
		!is_file($file) || is_link($file)) return false;

	$handle = @fopen($file, 'rb');
	if ($handle === false) return false;
	$stat = @fstat($handle);
	if (!Filesystem::openedFileMatchesPath($handle, $file, true) || !is_array($stat) ||
		!isset($stat['size']) || !is_int($stat['size']) || $stat['size'] < 0) {
		@fclose($handle);
		return false;
	}
	$size = $stat['size'];
	$name = basename($file);
	$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
	if (!is_string($fallbackName) || $fallbackName === '') $fallbackName = 'download.bin';
	if (!headers_sent()) {
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('X-Content-Type-Options: nosniff');
		header("Content-Disposition: attachment; filename=\"{$fallbackName}\"; filename*=UTF-8''" . rawurlencode($name));
		header('Cache-Control: no-store, max-age=0');
		header('Content-Length: ' . $size);
	}

	$read = 0;
	while ($read < $size) {
		$chunk = @fread($handle, min(1048576, $size - $read));
		if (!is_string($chunk) || $chunk === '') {
			@fclose($handle);
			return false;
		}
		$length = strlen($chunk);
		if ($length > ($size - $read)) {
			@fclose($handle);
			return false;
		}
		echo $chunk;
		$read += $length;
	}
	$closed = @fclose($handle);
	if ($read !== $size || !$closed) return false;
	exit(0);
}

/**
 * Sanitizira HTML sadržaj prije javnog prikaza.
 *
 * Brendy namjerno pohranjuje izvorni sadržaj radi uređivanja, ali javni prikaz
 * prolazi kroz strogi allowlist. Time se blokiraju script/iframe/object/embed,
 * event-handler atributi, javascript/data URL-ovi i opasan inline CSS.
 */
function brendySanitizeContentHtml($html)
{
	if (!is_string($html) || $html === '') return '';
	if (strlen($html) > BRENDY_MAX_CONTENT_BYTES) {
		Log::set(__FUNCTION__ . LOG_SEP . 'Content exceeds the configured rendering limit.', LOG_TYPE_ERROR);
		return '';
	}

	static $cache = array();
	$cacheKey = hash('sha256', $html);
	if (isset($cache[$cacheKey])) return $cache[$cacheKey];

	// ext-dom is mandatory in the installer. Keep a fail-closed fallback for
	// installations where the module is removed after setup.
	if (!class_exists('DOMDocument')) {
		$safe = nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, CHARSET));
		Log::set(__FUNCTION__ . LOG_SEP . 'DOM extension unavailable; rendered content as plain text.', LOG_TYPE_ERROR);
		return $safe;
	}

	$allowedTags = array_fill_keys(array(
		'p','br','h1','h2','h3','h4','h5','h6','strong','b','em','i','u','s','del','ins',
		'blockquote','ul','ol','li','a','img','figure','figcaption','table','thead','tbody',
		'tfoot','tr','th','td','hr','pre','code','span','div','sup','sub','mark','small',
		'kbd','samp','var','section','article','aside','header','footer','main','details','summary','time'
	), true);
	$dropWithChildren = array_fill_keys(array(
		'script','style','iframe','object','embed','form','input','button','textarea','select','option',
		'meta','link','base','svg','math','template','canvas','applet','frameset','frame'
	), true);

	$dom = new DOMDocument('1.0', 'UTF-8');
	$previous = libxml_use_internal_errors(true);
	$loaded = $dom->loadHTML(
		'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
		LIBXML_NONET | LIBXML_COMPACT
	);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	if (!$loaded) {
		return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, CHARSET));
	}

	$sanitizeNode = function ($node) use (&$sanitizeNode, $allowedTags, $dropWithChildren, $dom) {
		if ($node instanceof DOMComment) {
			if (trim($node->nodeValue) !== 'pagebreak') {
				$node->parentNode && $node->parentNode->removeChild($node);
			}
			return;
		}
		if ($node instanceof DOMProcessingInstruction) {
			$node->parentNode && $node->parentNode->removeChild($node);
			return;
		}
		if (!($node instanceof DOMElement)) {
			if ($node->hasChildNodes()) {
				foreach (iterator_to_array($node->childNodes) as $child) $sanitizeNode($child);
			}
			return;
		}

		$tag = strtolower($node->tagName);
		if (isset($dropWithChildren[$tag])) {
			$node->parentNode && $node->parentNode->removeChild($node);
			return;
		}
		if (!isset($allowedTags[$tag])) {
			$parent = $node->parentNode;
			if (!$parent) return;
			foreach (iterator_to_array($node->childNodes) as $child) {
				$sanitizeNode($child);
				if ($child->parentNode === $node) $parent->insertBefore($child, $node);
			}
			$parent->removeChild($node);
			return;
		}

		foreach (iterator_to_array($node->attributes) as $attribute) {
			$name = strtolower($attribute->name);
			$value = (string)$attribute->value;
			$keep = false;
			$clean = $value;

			if (strpos($name, 'on') === 0 || strpos($name, 'xmlns') === 0 || strpos($name, 'xlink') === 0) {
				$keep = false;
			} elseif ($name === 'class') {
				$tokens = preg_split('/\s+/', trim($value));
				$tokens = array_values(array_filter(array_slice($tokens ?: array(), 0, 24), static function ($token) {
					return is_string($token) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $token) === 1;
				}));
				$clean = implode(' ', $tokens);
				$keep = ($clean !== '');
			} elseif ($name === 'title' || $name === 'alt') {
				$clean = mb_substr($value, 0, 1000, CHARSET);
				$keep = true;
			} elseif ($name === 'dir') {
				$clean = strtolower(trim($value));
				$keep = in_array($clean, array('ltr','rtl','auto'), true);
			} elseif ($name === 'lang') {
				$clean = trim($value);
				$keep = preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8}){0,4}$/D', $clean) === 1;
			} elseif ($name === 'role' || preg_match('/^aria-[a-z0-9-]{1,40}$/D', $name) === 1) {
				$clean = mb_substr(trim($value), 0, 500, CHARSET);
				$keep = ($clean !== '');
			} elseif ($name === 'style') {
				$clean = brendySanitizeInlineStyle($value);
				$keep = ($clean !== '');
			} elseif ($tag === 'a' && $name === 'href') {
				$clean = brendySanitizeContentUrl($value, false);
				$keep = ($clean !== false);
			} elseif ($tag === 'a' && $name === 'target') {
				$clean = strtolower(trim($value));
				$keep = in_array($clean, array('_blank','_self'), true);
			} elseif ($tag === 'a' && $name === 'rel') {
				$tokens = preg_split('/\s+/', strtolower(trim($value)));
				$tokens = array_values(array_unique(array_intersect($tokens ?: array(), array('noopener','noreferrer','nofollow','ugc','sponsored'))));
				$clean = implode(' ', $tokens);
				$keep = ($clean !== '');
			} elseif ($tag === 'img' && $name === 'src') {
				$clean = brendySanitizeContentUrl($value, true);
				$keep = ($clean !== false);
			} elseif ($tag === 'img' && in_array($name, array('width','height'), true)) {
				$clean = trim($value);
				$keep = preg_match('/^[0-9]{1,5}$/D', $clean) === 1 && (int)$clean <= 20000;
			} elseif ($tag === 'img' && $name === 'loading') {
				$clean = strtolower(trim($value));
				$keep = in_array($clean, array('lazy','eager'), true);
			} elseif ($tag === 'img' && $name === 'decoding') {
				$clean = strtolower(trim($value));
				$keep = in_array($clean, array('async','sync','auto'), true);
			} elseif (in_array($tag, array('td','th'), true) && in_array($name, array('colspan','rowspan'), true)) {
				$clean = trim($value);
				$keep = preg_match('/^[1-9][0-9]{0,2}$/D', $clean) === 1 && (int)$clean <= 100;
			} elseif ($tag === 'th' && $name === 'scope') {
				$clean = strtolower(trim($value));
				$keep = in_array($clean, array('row','col','rowgroup','colgroup'), true);
			} elseif ($tag === 'ol' && $name === 'start') {
				$clean = trim($value);
				$keep = preg_match('/^-?[0-9]{1,9}$/D', $clean) === 1;
			} elseif ($tag === 'li' && $name === 'value') {
				$clean = trim($value);
				$keep = preg_match('/^-?[0-9]{1,9}$/D', $clean) === 1;
			} elseif ($tag === 'time' && $name === 'datetime') {
				$clean = trim($value);
				$keep = strlen($clean) <= 64 && preg_match('/^[0-9T:+.Z -]+$/D', $clean) === 1;
			}

			if ($keep) $node->setAttribute($name, $clean);
			else $node->removeAttributeNode($attribute);
		}

		if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') {
			$rel = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: array();
			$rel[] = 'noopener';
			$rel[] = 'noreferrer';
			$node->setAttribute('rel', implode(' ', array_values(array_unique(array_filter($rel)))));
		}

		foreach (iterator_to_array($node->childNodes) as $child) $sanitizeNode($child);
	};

	$body = $dom->getElementsByTagName('body')->item(0);
	if (!$body) return '';
	foreach (iterator_to_array($body->childNodes) as $child) $sanitizeNode($child);

	$output = '';
	foreach (iterator_to_array($body->childNodes) as $child) $output .= $dom->saveHTML($child);
	if (!is_string($output)) $output = '';

	if (count($cache) >= 64) $cache = array();
	$cache[$cacheKey] = $output;
	return $output;
}

function brendyIsSafeHttpUrl($url, $maxLength = 4096)
{
  if (!is_string($url)) return false;
  $maxLength = max(1, min(16384, (int)$maxLength));
  $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, CHARSET));
  if ($url === '' || strlen($url) > $maxLength || preg_match('/[\x00-\x20\x7F]/', $url) || strpos($url, '\\') !== false ||
      filter_var($url, FILTER_VALIDATE_URL) === false) return false;
  $parts = parse_url($url);
  return is_array($parts) && isset($parts['scheme'], $parts['host']) && $parts['host'] !== '' &&
    in_array(strtolower((string)$parts['scheme']), array('http', 'https'), true) &&
    !isset($parts['user']) && !isset($parts['pass']);
}

function brendySanitizeContentUrl($url, $imageSource = false)
{
	if (!is_string($url)) return false;
	$url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, CHARSET);
	if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F]/', $url) || strpos($url, '\\') !== false) return false;
	if (!$imageSource && $url[0] === '#') return $url;
	if (strpos($url, '//') === 0) return false;

	if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $url, $match) === 1) {
		$scheme = strtolower($match[1]);
		$allowed = $imageSource ? array('http','https') : array('http','https','mailto','tel');
		if (!in_array($scheme, $allowed, true)) return false;
		if (in_array($scheme, array('http','https'), true)) {
			$parts = parse_url($url);
			if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
		}
		return $url;
	}

	// Relative URLs are valid, but reject a colon before any slash/query/hash so
	// an unknown scheme cannot masquerade as a local path.
	$colon = strpos($url, ':');
	$slash = strcspn($url, '/?#');
	if ($colon !== false && $colon < $slash) return false;
	return $url;
}

function brendySanitizeInlineStyle($style)
{
	if (!is_string($style) || strlen($style) > 4096) return '';
	$allowed = array_fill_keys(array(
		'text-align','color','background-color','font-weight','font-style','text-decoration',
		'font-size','line-height','margin-left','margin-right','padding-left','padding-right'
	), true);
	$safe = array();
	foreach (array_slice(explode(';', $style), 0, 40) as $declaration) {
		$parts = explode(':', $declaration, 2);
		if (count($parts) !== 2) continue;
		$property = strtolower(trim($parts[0]));
		$value = trim($parts[1]);
		if (!isset($allowed[$property]) || $value === '' || strlen($value) > 200) continue;
		if (preg_match('/[\x00-\x1F\x7F<>\\\\]/', $value) || preg_match('/(?:url\s*\(|expression\s*\(|@import|javascript:|vbscript:|data:)/i', $value)) continue;
		if (preg_match('/^[#(),.%+\-\sA-Za-z0-9]+$/D', $value) !== 1) continue;
		$safe[] = $property . ': ' . $value;
	}
	return implode('; ', $safe);
}
