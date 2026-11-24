<?php defined('BRENDY') or die('Brendy CMS.');

$healthChecks = array();
$addHealthCheck = static function ($key, $label, $status, $detail, $icon) use (&$healthChecks) {
	if (!in_array($status, array('ok', 'warning', 'error'), true)) $status = 'warning';
	$healthChecks[$key] = array(
		'label' => (string)$label,
		'status' => $status,
		'detail' => (string)$detail,
		'icon' => preg_match('/^[a-z0-9-]{1,50}$/D', (string)$icon) ? $icon : 'info-circle'
	);
};

$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$phpRecommended = version_compare(PHP_VERSION, '8.4.0', '>=');
$phpStatus = !$phpOk ? 'error' : ($phpRecommended ? 'ok' : 'warning');
$addHealthCheck('php', $L->g('PHP runtime'), $phpStatus,
	$L->g('Detected version') . ': ' . PHP_VERSION . ' · ' . $L->g('Required minimum') . ': 8.2 · ' . $L->g('Recommended') . ': 8.4+',
	$phpRecommended ? 'code-slash' : 'exclamation-triangle');

$requiredExtensions = array('filter', 'hash', 'json', 'session', 'mbstring', 'gd', 'dom', 'fileinfo');
$missingExtensions = array_values(array_filter($requiredExtensions, static function ($extension) {
	return !extension_loaded($extension);
}));
$addHealthCheck('extensions', $L->g('Required extensions'), empty($missingExtensions) ? 'ok' : 'error',
	empty($missingExtensions) ? $L->g('All required PHP extensions are available') :
		$L->g('Missing') . ': ' . implode(', ', $missingExtensions), 'box-seam');

$recommendedExtensions = array('curl', 'zip');
$missingRecommended = array_values(array_filter($recommendedExtensions, static function ($extension) {
	return !extension_loaded($extension);
}));
$addHealthCheck('recommended-extensions', $L->g('Recommended extensions'), empty($missingRecommended) ? 'ok' : 'warning',
	empty($missingRecommended) ? $L->g('Network and backup extensions are available') :
		$L->g('Some network and backup features are limited') . ' · ' . $L->g('Missing') . ': ' . implode(', ', $missingRecommended), 'puzzle');

$contentWritable = is_dir(PATH_CONTENT) && !is_link(PATH_CONTENT) && is_writable(PATH_CONTENT);
$addHealthCheck('content', $L->g('Content storage'), $contentWritable ? 'ok' : 'error',
	$contentWritable ? $L->g('The content directory is writable and is not a symbolic link') :
		$L->g('The content directory must be writable and must not be a symbolic link'), 'hdd-stack');

$storageProbeOk = false;
$storageProbePath = null;
try {
	$storageProbePath = PATH_TMP . 'health-' . bin2hex(random_bytes(8)) . '.tmp';
	$storageProbePayload = 'Brendy storage probe ' . BRENDY_BUILD . ' ' . bin2hex(random_bytes(16));
	if (is_dir(PATH_TMP) && !is_link(PATH_TMP) && is_writable(PATH_TMP) &&
		Filesystem::atomicWrite($storageProbePath, $storageProbePayload, 0600)) {
		$storageProbeRead = Filesystem::readFileBounded($storageProbePath, 4096, 1);
		$storageProbeOk = is_string($storageProbeRead) && hash_equals(hash('sha256', $storageProbePayload), hash('sha256', $storageProbeRead));
	}
} catch (Throwable $e) {
	$storageProbeOk = false;
}
if (is_string($storageProbePath) && file_exists($storageProbePath) && !is_link($storageProbePath)) @unlink($storageProbePath);
$addHealthCheck('storage-io', $L->g('Storage write test'), $storageProbeOk ? 'ok' : 'error',
	$storageProbeOk ? $L->g('Atomic write, read verification and cleanup succeeded') :
		$L->g('Atomic storage write test failed; review filesystem permissions and available space'), 'hdd');

$diskFree = @disk_free_space(PATH_CONTENT);
$diskTotal = @disk_total_space(PATH_CONTENT);
$diskStatus = 'warning';
$diskDetail = $L->g('Free disk space could not be determined');
if (is_numeric($diskFree) && $diskFree >= 0) {
	$diskFree = (float)$diskFree;
	$diskTotal = is_numeric($diskTotal) && $diskTotal > 0 ? (float)$diskTotal : 0.0;
	$diskRatio = $diskTotal > 0 ? $diskFree / $diskTotal : 1.0;
	$diskStatus = ($diskFree < 67108864 || $diskRatio < 0.01) ? 'error' :
		(($diskFree < 268435456 || $diskRatio < 0.05) ? 'warning' : 'ok');
	$diskDetail = $L->g('Free') . ': ' . Filesystem::bytesToHumanFileSize((int)$diskFree);
	if ($diskTotal > 0) $diskDetail .= ' · ' . $L->g('Total') . ': ' . Filesystem::bytesToHumanFileSize((int)$diskTotal);
}
$addHealthCheck('disk-space', $L->g('Disk space'), $diskStatus, $diskDetail, 'pie-chart');

$scheduledGraceSeconds = 300;
$overdueScheduled = method_exists($pages, 'overduePublishableScheduledCount')
	? $pages->overduePublishableScheduledCount($scheduledGraceSeconds) : 0;
$scheduledStatus = $overdueScheduled > 0 ? 'warning' : 'ok';
$scheduledDetail = $overdueScheduled > 0
	? $L->g('Publishable scheduled pages overdue') . ': ' . $overdueScheduled . ' · ' . $L->g('Grace period') . ': 5 ' . $L->g('minutes')
	: $L->g('No publishable scheduled pages are overdue');
$addHealthCheck('scheduled-publication', $L->g('Scheduled publication'), $scheduledStatus, $scheduledDetail, 'clock-history');

$editorFile = PATH_ROOT . 'jezgra' . DS . 'urednik' . DS . 'tinymce' . DS . 'tinymce.min.js';
$editorOk = is_file($editorFile) && !is_link($editorFile) && is_readable($editorFile) && @filesize($editorFile) > 100000;
$addHealthCheck('editor', $L->g('Local editor'), $editorOk ? 'ok' : 'error',
	$editorOk ? BRENDY_EDITOR_NAME . ' ' . BRENDY_EDITOR_VERSION . ' · Brendy ' . BRENDY_EDITOR_INTEGRATION_VERSION . ' · ' . $L->g('Local runtime is available') :
		$L->g('The local editor runtime is missing or unreadable'), 'pencil-square');

// Validate every core JSON database without exposing its contents.
$coreDatabases = array(DB_SITE, DB_PAGES, DB_CATEGORIES, DB_TAGS, DB_USERS, DB_SECURITY, DB_SYSLOG, DB_CORE_INTEGRITY);
$jsonFailures = array();
$jsonEntries = 0;
$jsonBytes = 0;
foreach ($coreDatabases as $databaseFile) {
	$inspection = BrendyJsonDatabase::inspectFile($databaseFile, true);
	if (empty($inspection['ok'])) $jsonFailures[] = basename($databaseFile);
	else {
		$jsonEntries += isset($inspection['entries']) ? (int)$inspection['entries'] : 0;
		$jsonBytes += isset($inspection['bytes']) ? (int)$inspection['bytes'] : 0;
	}
}
$pluginDatabaseFiles = glob(PATH_PLUGINS_DATABASES . '*' . DS . 'db.php');
if (!is_array($pluginDatabaseFiles)) $pluginDatabaseFiles = array();
$checkedPluginDatabaseFiles = array_slice($pluginDatabaseFiles, 0, 500);
foreach ($checkedPluginDatabaseFiles as $databaseFile) {
	$inspection = BrendyJsonDatabase::inspectFile($databaseFile, true, 33554432);
	if (empty($inspection['ok'])) $jsonFailures[] = 'plugin/' . basename(dirname($databaseFile));
	else {
		$jsonEntries += isset($inspection['entries']) ? (int)$inspection['entries'] : 0;
		$jsonBytes += isset($inspection['bytes']) ? (int)$inspection['bytes'] : 0;
	}
}
$jsonStatus = empty($jsonFailures) ? 'ok' : 'error';
$jsonDetail = empty($jsonFailures)
	? $L->g('Validated databases') . ': ' . (count($coreDatabases) + count($checkedPluginDatabaseFiles)) . ' · ' .
		$L->g('Entries') . ': ' . $jsonEntries . ' · ' . $L->g('Size') . ': ' . Filesystem::bytesToHumanFileSize($jsonBytes)
	: $L->g('Invalid databases') . ': ' . implode(', ', array_slice($jsonFailures, 0, 8));
$addHealthCheck('json-integrity', $L->g('JSON storage integrity'), $jsonStatus, $jsonDetail, 'database-check');


// Verify that page metadata still matches the actual content files. The scan is
// deliberately bounded so a very large site cannot turn System Health into an
// expensive request.
$contentIntegrityErrors = 0;
$contentIntegrityLegacy = 0;
$contentIntegrityOrphans = 0;
$contentIntegrityVerified = 0;
$contentIntegrityBytes = 0;
$contentIntegrityLimited = false;
$contentRows = $pages->getDB(false);
if (!is_array($contentRows)) $contentRows = array();
foreach ($contentRows as $contentKey => $contentRow) {
	if ($contentIntegrityVerified + $contentIntegrityErrors >= 5000 || $contentIntegrityBytes >= 536870912) {
		$contentIntegrityLimited = true;
		break;
	}
	if (!is_string($contentKey) || !is_array($contentRow) ||
		preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[\pL\pN._~/-]{1,200}$#uD', $contentKey) !== 1) {
		$contentIntegrityErrors++;
		continue;
	}
	$contentFile = PATH_PAGES . $contentKey . DS . FILENAME;
	if (!is_file($contentFile) || is_link($contentFile) || !is_readable($contentFile)) {
		$contentIntegrityErrors++;
		continue;
	}
	$contentSize = @filesize($contentFile);
	if (!is_int($contentSize) || $contentSize < 0 || $contentSize > BRENDY_MAX_CONTENT_BYTES) {
		$contentIntegrityErrors++;
		continue;
	}
	$contentIntegrityBytes += $contentSize;
	$storedSha256 = isset($contentRow['contentHash']) && is_string($contentRow['contentHash'])
		? strtolower($contentRow['contentHash']) : '';
	if (preg_match('/^[a-f0-9]{64}$/D', $storedSha256) === 1) {
		$actualSha256 = Filesystem::hashFileBounded($contentFile, 'sha256', BRENDY_MAX_CONTENT_BYTES, 0, $contentSize);
		if (!is_string($actualSha256) || !hash_equals($storedSha256, strtolower($actualSha256))) {
			$contentIntegrityErrors++;
			continue;
		}
	} else {
		$storedMd5 = isset($contentRow['md5file']) && is_string($contentRow['md5file'])
			? strtolower($contentRow['md5file']) : '';
		$actualMd5 = Filesystem::hashFileBounded($contentFile, 'md5', BRENDY_MAX_CONTENT_BYTES, 0, $contentSize);
		if (preg_match('/^[a-f0-9]{32}$/D', $storedMd5) !== 1 || !is_string($actualMd5) ||
			!hash_equals($storedMd5, strtolower($actualMd5))) {
			$contentIntegrityErrors++;
			continue;
		}
		$contentIntegrityLegacy++;
	}
	$contentIntegrityVerified++;
}
// Reverse check: find page content files that exist on disk but no longer
// have a corresponding pages.php/JSON record. These are not rendered by the
// CMS, but usually indicate an interrupted migration, manual copy or stale data.
$pagesRoot = realpath(PATH_PAGES);
if ($pagesRoot !== false && is_dir($pagesRoot) && !is_link(PATH_PAGES)) {
	$diskContentFilesChecked = 0;
	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($pagesRoot, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ($iterator as $diskEntry) {
			if (++$diskContentFilesChecked > 10000) {
				$contentIntegrityLimited = true;
				break;
			}
			if (!$diskEntry->isFile() || $diskEntry->isLink() || $diskEntry->getFilename() !== FILENAME) continue;
			$directory = dirname($diskEntry->getPathname());
			if (strpos($directory, $pagesRoot . DS) !== 0) continue;
			$key = str_replace(DS, '/', substr($directory, strlen($pagesRoot) + 1));
			if ($key === '' || !array_key_exists($key, $contentRows)) $contentIntegrityOrphans++;
		}
	} catch (Throwable $e) {
		$contentIntegrityLimited = true;
	}
}

$contentIntegrityStatus = $contentIntegrityErrors > 0 ? 'error' :
	(($contentIntegrityLegacy > 0 || $contentIntegrityOrphans > 0 || $contentIntegrityLimited) ? 'warning' : 'ok');
$contentIntegrityDetail = $L->g('Verified content files') . ': ' . $contentIntegrityVerified;
if ($contentIntegrityErrors > 0) $contentIntegrityDetail .= ' · ' . $L->g('Content integrity issues') . ': ' . $contentIntegrityErrors;
if ($contentIntegrityLegacy > 0) $contentIntegrityDetail .= ' · ' . $L->g('Legacy content checksums') . ': ' . $contentIntegrityLegacy;
if ($contentIntegrityOrphans > 0) $contentIntegrityDetail .= ' · ' . $L->g('Orphaned content files') . ': ' . $contentIntegrityOrphans;
if ($contentIntegrityLimited) $contentIntegrityDetail .= ' · ' . $L->g('Content integrity scan was limited');
$addHealthCheck('content-integrity', $L->g('Content integrity'), $contentIntegrityStatus,
	$contentIntegrityDetail, 'file-earmark-check');

// Validate the encoded parent/child hierarchy independently from filesystem
// checks. Missing parents and a public child below a non-public parent are
// unsafe visibility inversions; other type differences (for example a future
// scheduled child below an already-public parent) are legitimate.
$hierarchyMissingParents = 0;
$hierarchyVisibilityInversions = 0;
$hierarchyChecked = 0;
foreach ($contentRows as $contentKey => $contentRow) {
	if (++$hierarchyChecked > 10000) break;
	if (!is_string($contentKey) || !is_array($contentRow)) continue;
	$separator = strrpos($contentKey, '/');
	if ($separator === false) continue;
	$parentKey = substr($contentKey, 0, $separator);
	if ($parentKey === '' || !isset($contentRows[$parentKey]) || !is_array($contentRows[$parentKey])) {
		$hierarchyMissingParents++;
		continue;
	}
	$childType = isset($contentRow['type']) && is_string($contentRow['type']) ? $contentRow['type'] : '';
	$parentType = isset($contentRows[$parentKey]['type']) && is_string($contentRows[$parentKey]['type']) ? $contentRows[$parentKey]['type'] : '';
	$publicTypes = array('published', 'sticky', 'static');
	if (in_array($childType, $publicTypes, true) && !in_array($parentType, $publicTypes, true)) $hierarchyVisibilityInversions++;
}
$hierarchyStatus = ($hierarchyMissingParents > 0 || $hierarchyVisibilityInversions > 0) ? 'warning' : 'ok';
$hierarchyDetail = $L->g('Checked content rows') . ': ' . min($hierarchyChecked, 10000);
if ($hierarchyMissingParents > 0) $hierarchyDetail .= ' · ' . $L->g('Missing parent pages') . ': ' . $hierarchyMissingParents;
if ($hierarchyVisibilityInversions > 0) $hierarchyDetail .= ' · ' . $L->g('Public children below non-public parents') . ': ' . $hierarchyVisibilityInversions;
$addHealthCheck('content-hierarchy', $L->g('Content hierarchy'), $hierarchyStatus,
	$hierarchyDetail, 'diagram-3');

// Page media is stored canonically under UUID directories. Legacy/user-friendly
// page-key URLs are symlink aliases; verify them separately so broken aliases do
// not masquerade as content/database corruption.
$uploadAliasChecked = 0;
$uploadAliasMissing = 0;
$uploadAliasInvalid = 0;
$uploadCanonicalMissing = 0;
$uploadAliasLimited = false;
foreach ($contentRows as $contentKey => $contentRow) {
	if (++$uploadAliasChecked > 10000) {
		$uploadAliasLimited = true;
		break;
	}
	if (!is_string($contentKey) || !is_array($contentRow) || !isset($contentRow['uuid']) ||
		!is_string($contentRow['uuid']) || !isValidPageUuid($contentRow['uuid'])) continue;
	$source = PATH_UPLOADS_PAGES . $contentRow['uuid'];
	if (!is_dir($source) || is_link($source)) {
		$uploadCanonicalMissing++;
		continue;
	}
	$alias = brendyPageUploadAliasPath($contentKey, $contentRows);
	if (!is_string($alias)) {
		$uploadAliasInvalid++;
		continue;
	}
	$sourceReal = realpath($source);
	if (!is_string($sourceReal)) {
		$uploadAliasInvalid++;
		continue;
	}
	// Exact canonical path needs no symlink (possible only for an unusual key
	// equal to the UUID itself).
	if (!is_link($alias) && is_dir($alias) && realpath($alias) === $sourceReal &&
		rtrim($alias, DS) === rtrim($sourceReal, DS)) continue;
	if (!file_exists($alias) && !is_link($alias)) {
		$uploadAliasMissing++;
		continue;
	}
	$target = is_link($alias) ? realpath($alias) : false;
	if (!is_string($target) || !hash_equals($sourceReal, $target)) $uploadAliasInvalid++;
}
$uploadAliasStatus = ($uploadAliasInvalid > 0 || $uploadCanonicalMissing > 0) ? 'error' : (($uploadAliasMissing > 0 || $uploadAliasLimited) ? 'warning' : 'ok');
$uploadAliasDetail = $L->g('Checked page upload aliases') . ': ' . min($uploadAliasChecked, 10000);
if ($uploadAliasMissing > 0) $uploadAliasDetail .= ' · ' . $L->g('Missing page upload aliases') . ': ' . $uploadAliasMissing;
if ($uploadAliasInvalid > 0) $uploadAliasDetail .= ' · ' . $L->g('Invalid page upload aliases') . ': ' . $uploadAliasInvalid;
if ($uploadCanonicalMissing > 0) $uploadAliasDetail .= ' · ' . $L->g('Missing canonical page upload directories') . ': ' . $uploadCanonicalMissing;
if ($uploadAliasLimited) $uploadAliasDetail .= ' · ' . $L->g('Upload alias scan was limited');
$addHealthCheck('page-upload-aliases', $L->g('Page upload aliases'), $uploadAliasStatus,
	$uploadAliasDetail, 'link-45deg');

$revisionStorageOk = (is_dir(PATH_REVISIONS) || Filesystem::mkdir(PATH_REVISIONS, true)) && !is_link(PATH_REVISIONS) && is_writable(PATH_REVISIONS);
$revisionCount = 0;
$revisionOrphanGroups = 0;
$revisionUnsafeGroups = 0;
$knownRevisionUuids = array();
foreach ($contentRows as $contentRow) {
	if (is_array($contentRow) && isset($contentRow['uuid']) && is_string($contentRow['uuid']) &&
		preg_match('/^[a-f0-9]{32}$/iD', $contentRow['uuid']) === 1) $knownRevisionUuids[strtolower($contentRow['uuid'])] = true;
}
if ($revisionStorageOk) {
	$revisionRoots = glob(PATH_REVISIONS . '*', GLOB_ONLYDIR);
	if (is_array($revisionRoots)) {
		foreach (array_slice($revisionRoots, 0, 5000) as $revisionRoot) {
			if (is_link($revisionRoot)) { $revisionUnsafeGroups++; continue; }
			$revisionUuid = strtolower(basename($revisionRoot));
			if (preg_match('/^[a-f0-9]{32}$/D', $revisionUuid) !== 1) { $revisionUnsafeGroups++; continue; }
			if (!isset($knownRevisionUuids[$revisionUuid])) $revisionOrphanGroups++;
			$found = glob($revisionRoot . DS . '*', GLOB_ONLYDIR);
			if (is_array($found)) {
				foreach ($found as $revisionPath) {
					if (!is_link($revisionPath) && preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', basename($revisionPath)) === 1) $revisionCount++;
				}
			}
		}
	}
}
$revisionStatus = !$revisionStorageOk || $revisionUnsafeGroups > 0 ? 'error' : ($revisionOrphanGroups > 0 ? 'warning' : 'ok');
$revisionDetail = $revisionStorageOk ? $L->g('Revision storage is writable') . ' · ' . $L->g('Stored revisions') . ': ' . $revisionCount :
	$L->g('Revision storage is unavailable or unsafe');
if ($revisionOrphanGroups > 0) $revisionDetail .= ' · ' . $L->g('Orphaned revision groups') . ': ' . $revisionOrphanGroups;
if ($revisionUnsafeGroups > 0) $revisionDetail .= ' · ' . $L->g('Unsafe revision groups') . ': ' . $revisionUnsafeGroups;
$addHealthCheck('revisions', $L->g('Content revisions'), $revisionStatus, $revisionDetail, 'clock-history');

$auditCount = count($syslog->getDB());
$auditChain = method_exists($syslog, 'verifyChain') ? $syslog->verifyChain() : array('ok'=>false, 'protected'=>0, 'reason'=>'unsupported');
$auditChainOk = is_array($auditChain) && isset($auditChain['ok']) && $auditChain['ok'] === true;
$auditProtected = is_array($auditChain) && isset($auditChain['protected']) ? max(0, (int)$auditChain['protected']) : 0;
$auditReason = is_array($auditChain) && isset($auditChain['reason']) && is_scalar($auditChain['reason']) ? (string)$auditChain['reason'] : 'invalid';
$auditHeadSequence = is_array($auditChain) && isset($auditChain['headSequence']) ? max(0, (int)$auditChain['headSequence']) : 0;
$auditStatus = !$auditChainOk ? 'error' : ($auditCount > SYSLOG_RETENTION_AMOUNT ? 'warning' : 'ok');
$auditDetail = $L->g('Retained events') . ': ' . $auditCount . ' / ' . SYSLOG_RETENTION_AMOUNT .
	' · ' . $L->g('HMAC protected events') . ': ' . $auditProtected .
	' · ' . $L->g('Head sequence') . ': ' . $auditHeadSequence;
if (!$auditChainOk) $auditDetail .= ' · ' . $L->g('Audit chain validation failed') . ' (' . Theme::escape($auditReason) . ')';
else $auditDetail .= ' · ' . $L->g('Audit chain validation passed') . ' · ' . $L->g('Both retained audit boundaries are externally anchored');
$addHealthCheck('audit-log', $L->g('Activity log integrity'), $auditStatus, $auditDetail, 'journal-check');

$coreFiles = array(
	PATH_ROOT . 'index.php', PATH_KERNEL . 'release.php', PATH_KERNEL . 'functions.php',
	PATH_KERNEL . 'pokretanje' . DS . 'variables.php', PATH_KERNEL . 'administracija' . DS . 'teme' . DS . 'brendy-admin' . DS . 'index.php'
);
$coreFileFailures = array();
foreach ($coreFiles as $coreFile) {
	if (!is_file($coreFile) || is_link($coreFile) || !is_readable($coreFile) || @filesize($coreFile) <= 0) {
		$coreFileFailures[] = basename($coreFile);
	}
}
$addHealthCheck('core-files', $L->g('Core file integrity'), empty($coreFileFailures) ? 'ok' : 'error',
	empty($coreFileFailures) ? $L->g('Critical core files are present, regular and readable') :
		$L->g('Missing or unsafe core files') . ': ' . implode(', ', $coreFileFailures), 'shield-check');

// Verify the HMAC-authenticated deployment baseline. Unlike the presence-only
// check above, this detects byte changes, deletion and unexpected files anywhere
// in Brendy's core tree. The baseline is refreshed only on install/update.
$coreIntegrity = verifyBrendyCoreIntegrityBaseline();
$coreIntegrityOk = is_array($coreIntegrity) && !empty($coreIntegrity['ok']);
$coreIntegrityAuthentic = is_array($coreIntegrity) && !empty($coreIntegrity['authentic']);
$coreIntegrityStatus = $coreIntegrityOk ? 'ok' : 'error';
if ($coreIntegrityOk) {
	$coreIntegrityDetail = $L->g('Signed core baseline verified') . ' · ' .
		$L->g('Baseline build') . ': ' . (int)$coreIntegrity['build'] . ' · ' .
		$L->g('Protected core files') . ': ' . (int)$coreIntegrity['fileCount'];
} elseif (!$coreIntegrityAuthentic) {
	$coreIntegrityDetail = $L->g('Core integrity baseline is unavailable or invalid');
} else {
	$coreIntegrityDetail = $L->g('Core integrity mismatch detected') . ' · ' .
		$L->g('Modified') . ': ' . count($coreIntegrity['modified'] ?? array()) . ' · ' .
		$L->g('Missing') . ': ' . count($coreIntegrity['missing'] ?? array()) . ' · ' .
		$L->g('Unexpected') . ': ' . count($coreIntegrity['unexpected'] ?? array());
}
$addHealthCheck('core-authenticity', $L->g('Core code authenticity'), $coreIntegrityStatus, $coreIntegrityDetail, 'fingerprint');

$databaseOk = is_file(DB_SITE) && !is_link(DB_SITE) && is_readable(DB_SITE) && is_writable(dirname(DB_SITE));
$addHealthCheck('database', $L->g('Site database'), $databaseOk ? 'ok' : 'error',
	$databaseOk ? $L->g('The site database is readable and its directory is writable') :
		$L->g('Check database file permissions'), 'file-earmark-lock');

$https = $site->isHTTPS();
$addHealthCheck('https', 'HTTPS', $https ? 'ok' : 'warning',
	$https ? $L->g('The administration session is protected by HTTPS') :
		$L->g('Enable HTTPS before using this site in production'), 'shield-lock');

$debugDisabled = !defined('DEBUG_MODE') || DEBUG_MODE === false;
$displayErrors = strtolower((string)ini_get('display_errors'));
$errorsHidden = $displayErrors === '' || $displayErrors === '0' || $displayErrors === 'off';
$productionErrors = $debugDisabled && $errorsHidden;
$addHealthCheck('errors', $L->g('Production error handling'), $productionErrors ? 'ok' : 'warning',
	$productionErrors ? $L->g('Debug output is disabled') : $L->g('Disable debug and display_errors in production'), 'bug');

// PHP-level hardening matters even when Brendy strips identifying headers at
// the application boundary. In particular, allow_url_include can turn an
// otherwise local include mistake into remote code execution on vulnerable
// application code, while expose_php leaks needless runtime fingerprinting.
$allowUrlInclude = strtolower(trim((string)ini_get('allow_url_include')));
$allowUrlIncludeOff = $allowUrlInclude === '' || $allowUrlInclude === '0' || $allowUrlInclude === 'off';
$exposePhp = strtolower(trim((string)ini_get('expose_php')));
$exposePhpOff = $exposePhp === '' || $exposePhp === '0' || $exposePhp === 'off';
$phpHardeningOk = $allowUrlIncludeOff && $exposePhpOff && $errorsHidden;
$phpHardeningDetail = 'allow_url_include=' . ($allowUrlIncludeOff ? 'Off' : 'On') .
	' · expose_php=' . ($exposePhpOff ? 'Off' : 'On') .
	' · display_errors=' . ($errorsHidden ? 'Off' : 'On');
$addHealthCheck('php-hardening', $L->g('PHP security configuration'), $phpHardeningOk ? 'ok' : 'warning',
	$phpHardeningDetail, 'shield-lock-fill');

$configuredUrl = Sanitize::htmlDecode($site->url());
$urlStatus = $configuredUrl !== '' && filter_var($configuredUrl, FILTER_VALIDATE_URL) &&
	strtolower((string)parse_url($configuredUrl, PHP_URL_SCHEME)) === 'https';
$addHealthCheck('url', $L->g('Canonical site URL'), $urlStatus ? 'ok' : 'warning',
	$urlStatus ? $L->g('A secure canonical URL is configured') :
		$L->g('Set the full HTTPS site URL in advanced settings'), 'link-45deg');

$cookieParameters = session_get_cookie_params();
$sameSite = isset($cookieParameters['samesite']) ? strtolower((string)$cookieParameters['samesite']) : '';
$strictSessions = (string)ini_get('session.use_strict_mode') === '1' &&
	(string)ini_get('session.use_only_cookies') === '1' &&
	!empty($cookieParameters['httponly']) && $sameSite === 'strict' &&
	(!$https || !empty($cookieParameters['secure']));
$addHealthCheck('session', $L->g('Session protection'), $strictSessions ? 'ok' : 'error',
	$strictSessions ? $L->g('Strict cookie-only sessions with HttpOnly and SameSite are enabled') :
		$L->g('Review PHP session and cookie security settings'), 'shield-check');

$sessionHandler = strtolower((string)ini_get('session.save_handler'));
$sessionIsolationApplicable = $sessionHandler === 'files';
$sessionIsolated = !$sessionIsolationApplicable || (method_exists('Session', 'usesIsolatedSavePath') && Session::usesIsolatedSavePath());
$sessionStorageDetail = !$sessionIsolationApplicable
	? $L->g('Session storage uses a non-file PHP handler') . ': ' . Theme::escape($sessionHandler)
	: ($sessionIsolated ? $L->g('File sessions are isolated inside the private Brendy temporary directory') :
		$L->g('File session isolation is unavailable; administrator sessions are blocked until private storage is available'));
$addHealthCheck('session-storage', $L->g('Session storage isolation'), $sessionIsolated ? 'ok' : 'error',
	$sessionStorageDetail, 'folder-lock');

// Verify that the administration response is using nonce-bound scripts rather
// than the broad `unsafe-inline` execution permission. This check inspects the
// actual response header generated for the current request.
$cspHeaders = function_exists('headers_list') ? headers_list() : array();
$adminCsp = '';
foreach ($cspHeaders as $headerLine) {
	if (stripos($headerLine, 'Content-Security-Policy:') === 0) { $adminCsp = $headerLine; break; }
}
$cspNonceOk = preg_match("/script-src[^;]*'nonce-[a-f0-9]{48}'/i", $adminCsp) === 1 &&
	stripos($adminCsp, "script-src 'self' 'unsafe-inline'") === false;
$addHealthCheck('admin-csp', $L->g('Administrator CSP'), $cspNonceOk ? 'ok' : 'warning',
	$cspNonceOk ? $L->g('Inline administrator scripts require a per-request nonce') :
		$L->g('Verify that the administrator CSP blocks unrestricted inline scripts'), 'file-lock2');

// Exercise the exact full-tree scanner used by runtime activation/installation.
// Scanning per package keeps symlink/tree/byte-limit semantics identical to the
// real security boundary and allows the authenticated build-bound cache to avoid
// reparsing every PHP file whenever an administrator opens System Health.
$extensionTreesChecked = 0;
$extensionTreeFailures = 0;
foreach (array(PATH_PLUGINS, PATH_THEMES) as $extensionRoot) {
	$extensionDirectories = array_slice(Filesystem::listDirectories($extensionRoot, '*', false), 0, 250);
	foreach ($extensionDirectories as $extensionDirectory) {
		$extensionTreesChecked++;
		if (!brendyValidateExtensionPhpTreeSecurity($extensionDirectory, 250, 8388608)) {
			$extensionTreeFailures++;
		}
	}
}
$addHealthCheck('extension-scanner', $L->g('Extension source scanner'), $extensionTreeFailures === 0 ? 'ok' : 'error',
	$L->g('Scanned extension packages') . ': ' . $extensionTreesChecked . ' · ' . $L->g('Rejected') . ': ' . $extensionTreeFailures,
	'shield-shaded');

$extensionCachePath = brendyExtensionSecurityCachePath();
$extensionCacheExists = is_string($extensionCachePath) && is_file($extensionCachePath) && !is_link($extensionCachePath);
$extensionCacheEntries = $extensionCacheExists ? brendyLoadExtensionSecurityCache() : array();
$extensionCacheSize = $extensionCacheExists ? @filesize($extensionCachePath) : 0;
$extensionCacheInvalid = $extensionCacheExists &&
	(!is_int($extensionCacheSize) || $extensionCacheSize < 10 || $extensionCacheSize > 2097152 || empty($extensionCacheEntries));
$extensionCacheStatus = $extensionCacheInvalid ? 'warning' : 'ok';
$extensionCacheDetail = !$extensionCacheExists
	? $L->g('Scanner cache is cold and will be populated on demand')
	: ($extensionCacheInvalid
		? $L->g('Scanner cache failed HMAC or format validation and will be ignored')
		: $L->g('Authenticated cache entries') . ': ' . count($extensionCacheEntries));
$addHealthCheck('extension-scanner-cache', $L->g('Extension scanner cache'), $extensionCacheStatus,
	$extensionCacheDetail, 'database-check');

// Report password-hash quality without exposing account names or hashes.
$passwordRows = $users->getDB();
$legacyPasswordHashes = 0;
$invalidPasswordHashes = 0;
$passwordRehashNeeded = 0;
$disabledAccounts = 0;
$legacyDisabledAccounts = 0;
$disabledPersistentTokens = 0;
$activeRememberTokens = 0;
$expiredRememberTokens = 0;
$legacyRememberTokens = 0;
$passwordHistoryMalformed = 0;
$passwordChangeDateUnknown = 0;
$preferredPasswordAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$preferredPasswordOptions = defined('PASSWORD_ARGON2ID')
	? array('memory_cost'=>PASSWORD_ARGON_MEMORY_COST, 'time_cost'=>PASSWORD_ARGON_TIME_COST, 'threads'=>PASSWORD_ARGON_THREADS) : array();
foreach ($passwordRows as $passwordRow) {
	if (!is_array($passwordRow)) { $invalidPasswordHashes++; continue; }
	$hash = isset($passwordRow['password']) && is_string($passwordRow['password']) ? $passwordRow['password'] : '';
	$enabled = isset($passwordRow['enabled']) && $passwordRow['enabled'] === true && $hash !== '' && $hash !== '!';
	if (!$enabled) $disabledAccounts++;
	if ($hash === '!') { $legacyDisabledAccounts++; continue; }
	$info = password_get_info($hash);
	if (!empty($info['algo'])) {
		if (password_needs_rehash($hash, $preferredPasswordAlgo, $preferredPasswordOptions)) $passwordRehashNeeded++;
	} elseif (preg_match('/^[a-f0-9]{40}$/iD', $hash) === 1) {
		$legacyPasswordHashes++;
	} else {
		$invalidPasswordHashes++;
	}
	$history = isset($passwordRow['passwordHistory']) && is_array($passwordRow['passwordHistory']) ? $passwordRow['passwordHistory'] : null;
	if ($history === null || count($history) > PASSWORD_HISTORY_LIMIT) $passwordHistoryMalformed++;
	if ($hash !== '!' && (!isset($passwordRow['passwordChangedAt']) || (int)$passwordRow['passwordChangedAt'] <= 0)) $passwordChangeDateUnknown++;
	$remember = isset($passwordRow['tokenRemember']) && is_string($passwordRow['tokenRemember']) ? $passwordRow['tokenRemember'] : '';
	if ($remember !== '') {
		$expires = isset($passwordRow['tokenRememberExpires']) ? (int)$passwordRow['tokenRememberExpires'] : 0;
		if (!$enabled) $disabledPersistentTokens++;
		if (preg_match('/^sha256:[a-f0-9]{64}$/D', $remember) !== 1) $legacyRememberTokens++;
		if ($expires > time()) $activeRememberTokens++; else $expiredRememberTokens++;
	}
}
$passwordStatus = $invalidPasswordHashes > 0 ? 'error' : (($legacyPasswordHashes + $passwordRehashNeeded) > 0 ? 'warning' : 'ok');
if ($invalidPasswordHashes > 0) {
	$passwordDetail = $L->g('Invalid password hashes detected') . ': ' . $invalidPasswordHashes;
} elseif ($legacyPasswordHashes > 0) {
	$passwordDetail = $L->g('Legacy password hashes awaiting migration') . ': ' . $legacyPasswordHashes;
} elseif ($passwordRehashNeeded > 0) {
	$passwordDetail = $L->g('Accounts awaiting password rehash') . ': ' . $passwordRehashNeeded;
} else {
	$passwordDetail = $L->g('Preferred password hashing is active');
}
if ($disabledAccounts > 0) $passwordDetail .= ' · ' . $L->g('Disabled accounts') . ': ' . $disabledAccounts;
$addHealthCheck('password-hashes', $L->g('Password hash health'), $passwordStatus, $passwordDetail, 'key');

$accountLifecycleStatus = ($disabledPersistentTokens > 0 || $legacyRememberTokens > 0) ? 'error' :
	($legacyDisabledAccounts > 0 ? 'warning' : 'ok');
$accountLifecycleDetail = $L->g('Disabled accounts preserve password hashes and revoke sessions') . ': ' .
	max(0, $disabledAccounts - $legacyDisabledAccounts);
if ($legacyDisabledAccounts > 0) {
	$accountLifecycleDetail .= ' · ' . $L->g('Legacy disabled accounts requiring a new password') . ': ' . $legacyDisabledAccounts;
}
if ($disabledPersistentTokens > 0) {
	$accountLifecycleDetail .= ' · ' . $L->g('Persistent tokens found on disabled accounts') . ': ' . $disabledPersistentTokens;
}
if ($legacyRememberTokens > 0) {
	$accountLifecycleDetail .= ' · ' . $L->g('Legacy plaintext persistent-token verifiers') . ': ' . $legacyRememberTokens;
}
$addHealthCheck('account-lifecycle', $L->g('Account lifecycle'), $accountLifecycleStatus, $accountLifecycleDetail, 'person-check');

$passwordHistoryStatus = $passwordHistoryMalformed > 0 ? 'error' : (PASSWORD_HISTORY_LIMIT < 3 ? 'warning' : 'ok');
$passwordHistoryDetail = sprintf($L->g('Current password plus last %d passwords are protected from reuse'), PASSWORD_HISTORY_LIMIT);
if ($passwordChangeDateUnknown > 0) {
	$passwordHistoryDetail .= ' · ' . $L->g('Legacy accounts without a recorded password change date') . ': ' . $passwordChangeDateUnknown;
}
if ($passwordHistoryMalformed > 0) {
	$passwordHistoryDetail .= ' · ' . $L->g('Malformed password history records') . ': ' . $passwordHistoryMalformed;
}
$addHealthCheck('password-history', $L->g('Password reuse protection'), $passwordHistoryStatus, $passwordHistoryDetail, 'clock-history');

// Two-factor health: verify adoption for administrator accounts and ensure any
// enabled seed can still be decrypted by the currently available local crypto.
$twoFactorAdmins = 0;
$twoFactorAdminsEnabled = 0;
$twoFactorUsersEnabled = 0;
$twoFactorInvalid = 0;
$twoFactorLowRecovery = 0;
$twoFactorRememberBypass = 0;
foreach ($passwordRows as $healthUsername => $passwordRow) {
	if (!is_string($healthUsername) || !is_array($passwordRow)) continue;
	$isAdminAccount = isset($passwordRow['role']) && $passwordRow['role'] === 'admin' &&
		isset($passwordRow['enabled']) && $passwordRow['enabled'] === true &&
		isset($passwordRow['password']) && is_string($passwordRow['password']) && $passwordRow['password'] !== '!';
	if ($isAdminAccount) $twoFactorAdmins++;
	$enabled2fa = isset($passwordRow['twoFactorEnabled']) && $passwordRow['twoFactorEnabled'] === true;
	if (!$enabled2fa) continue;
	$twoFactorUsersEnabled++;
	if ($isAdminAccount) $twoFactorAdminsEnabled++;
	$encryptedSeed = isset($passwordRow['twoFactorSecret']) && is_string($passwordRow['twoFactorSecret']) ? $passwordRow['twoFactorSecret'] : '';
	if ($security->decryptTwoFactorSecret($encryptedSeed) === false) $twoFactorInvalid++;
	$recovery = isset($passwordRow['twoFactorRecoveryCodes']) && is_array($passwordRow['twoFactorRecoveryCodes'])
		? $passwordRow['twoFactorRecoveryCodes'] : array();
	if (count($recovery) < 3) $twoFactorLowRecovery++;
	if (!empty($passwordRow['tokenRemember']) || !empty($passwordRow['tokenRememberExpires'])) $twoFactorRememberBypass++;
}
$twoFactorCrypto = $security->twoFactorCryptoAvailable();
$twoFactorStatus = ($twoFactorInvalid > 0 || $twoFactorRememberBypass > 0 || ($twoFactorUsersEnabled > 0 && !$twoFactorCrypto)) ? 'error' :
	(($twoFactorAdmins > 0 && $twoFactorAdminsEnabled === 0) || $twoFactorLowRecovery > 0 ? 'warning' : 'ok');
$twoFactorDetail = $L->g('Administrator accounts protected by 2FA') . ': ' . $twoFactorAdminsEnabled . '/' . $twoFactorAdmins .
	' · ' . $L->g('Protected accounts') . ': ' . $twoFactorUsersEnabled .
	' · ' . $L->g('Local encryption') . ': ' . ($twoFactorCrypto ? $L->g('Available') : $L->g('Unavailable'));
if ($twoFactorInvalid > 0) $twoFactorDetail .= ' · ' . $L->g('Invalid encrypted 2FA seeds') . ': ' . $twoFactorInvalid;
if ($twoFactorLowRecovery > 0) $twoFactorDetail .= ' · ' . $L->g('Accounts low on recovery codes') . ': ' . $twoFactorLowRecovery;
if ($twoFactorRememberBypass > 0) $twoFactorDetail .= ' · ' . $L->g('Invalid persistent tokens on 2FA accounts') . ': ' . $twoFactorRememberBypass;
$addHealthCheck('two-factor', $L->g('Two-factor authentication health'), $twoFactorStatus, $twoFactorDetail, 'shield-lock-fill');

$securityDb = method_exists($security, 'getDB') ? $security->getDB() : array();
$twoFactorKeyRaw = is_array($securityDb) && isset($securityDb['twoFactorEncryptionKey']) && is_string($securityDb['twoFactorEncryptionKey'])
	? $securityDb['twoFactorEncryptionKey'] : '';
$twoFactorKeyValid = preg_match('/^[a-f0-9]{64}$/iD', $twoFactorKeyRaw) === 1;
$auditKeyValid = method_exists($security, 'auditLogHmacKey') && is_string($security->auditLogHmacKey());
$identityKeyValid = method_exists($security, 'identityHmacKey') && is_string($security->identityHmacKey());
$coreIntegrityKeyValid = method_exists($security, 'coreIntegrityHmacKey') && is_string($security->coreIntegrityHmacKey());
$extensionCacheKey = method_exists($security, 'extensionSecurityCacheKey') ? $security->extensionSecurityCacheKey() : false;
$extensionCacheKeyValid = is_string($extensionCacheKey) && preg_match('/^[a-f0-9]{64}$/D', $extensionCacheKey) === 1;
$keyMaterialOk = $twoFactorKeyValid && $auditKeyValid && $identityKeyValid && $coreIntegrityKeyValid && $extensionCacheKeyValid;
$keyMaterialDetail = $L->g('2FA encryption key') . ': ' . ($twoFactorKeyValid ? $L->g('Valid') : $L->g('Invalid')) .
	' · ' . $L->g('Audit HMAC key') . ': ' . ($auditKeyValid ? $L->g('Valid') : $L->g('Invalid')) .
	' · ' . $L->g('Identity HMAC key') . ': ' . ($identityKeyValid ? $L->g('Valid') : $L->g('Invalid')) .
	' · ' . $L->g('Core integrity HMAC key') . ': ' . ($coreIntegrityKeyValid ? $L->g('Valid') : $L->g('Invalid')) .
	' · ' . $L->g('Extension cache HMAC key') . ': ' . ($extensionCacheKeyValid ? $L->g('Valid') : $L->g('Invalid'));
$addHealthCheck('security-key-material', $L->g('Security key material'), $keyMaterialOk ? 'ok' : 'error',
	$keyMaterialDetail, 'key-fill');
$ipThrottleEntries = is_array($securityDb) && isset($securityDb['blackList']) && is_array($securityDb['blackList']) ? count($securityDb['blackList']) : 0;
$accountThrottleEntries = $security->getAccountFailureCount();
$loginProtectionStorage = method_exists($security, 'loginProtectionAvailable') && $security->loginProtectionAvailable();
$rememberStatus = !$loginProtectionStorage ? 'error' : ($expiredRememberTokens > 0 ? 'warning' : 'ok');
$rememberDetail = $L->g('Server-side remember token expiry') . ': ' . REMEMBER_COOKIE_EXPIRE_IN_DAYS . ' ' . $L->g('days') .
	' · ' . $L->g('Active remember tokens') . ': ' . $activeRememberTokens;
if ($expiredRememberTokens > 0) $rememberDetail .= ' · ' . $L->g('Expired remember tokens') . ': ' . $expiredRememberTokens;
$rememberDetail .= ' · ' . $L->g('Throttle storage') . ': ' . ($loginProtectionStorage ? $L->g('Available') : $L->g('Unavailable')) .
	' · ' . $L->g('Pseudonymized IP throttle entries') . ': ' . $ipThrottleEntries .
	' · ' . $L->g('Account throttle entries') . ': ' . $accountThrottleEntries;
$addHealthCheck('login-protection', $L->g('Login protection'), $rememberStatus, $rememberDetail, 'shield-lock');

$opcacheAvailable = extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
$addHealthCheck('opcache', 'OPcache', $opcacheAvailable ? 'ok' : 'warning',
	$opcacheAvailable ? $L->g('PHP bytecode caching is enabled') : $L->g('Enable OPcache for improved production performance'), 'lightning-charge');

$uploadMax = (string)ini_get('upload_max_filesize');
$postMax = (string)ini_get('post_max_size');
$memoryLimit = (string)ini_get('memory_limit');
$addHealthCheck('limits', $L->g('Runtime limits'), ($uploadMax !== '' && $postMax !== '') ? 'ok' : 'warning',
	$L->g('Upload limit') . ': ' . $uploadMax . ' · ' . $L->g('Brendy image limit') . ': ' . Filesystem::bytesToHumanFileSize(BRENDY_MAX_IMAGE_UPLOAD_BYTES) . ' / ' . number_format(BRENDY_MAX_IMAGE_PIXELS / 1000000, 0) . ' MP' . ' · ' . $L->g('POST limit') . ': ' . $postMax . ' · ' . $L->g('Memory limit') . ': ' . $memoryLimit, 'speedometer');

$htaccess = PATH_ROOT . '.htaccess';
$htaccessContent = Filesystem::readFileBounded($htaccess, 1048576, 1);
$storageProtected = is_string($htaccessContent) && strpos($htaccessContent, 'sadrzaj') !== false &&
	strpos($htaccessContent, 'Options -Indexes') !== false &&
	strpos($htaccessContent, 'Options -MultiViews') !== false &&
	strpos($htaccessContent, 'TRACE|TRACK|CONNECT') !== false &&
	strpos($htaccessContent, 'well-known') !== false &&
	strpos($htaccessContent, 'inc|module|install') !== false &&
	strpos($htaccessContent, 'RewriteRule ^(?:sadrzaj|brendy-content)/') !== false &&
	strpos($htaccessContent, 'RewriteRule ^tests(?:/|$)') !== false;
$addHealthCheck('web-rules', $L->g('Web storage rules'), $storageProtected ? 'ok' : 'warning',
	$storageProtected ? $L->g('Apache protection rules for private storage are present') :
		$L->g('Verify equivalent private-storage rules in your web server configuration'), 'server');

$incompatible = array();
$unhealthy = array();
$retiredPluginResidue = array();
foreach (brendyRetiredBundledPluginDirectories() as $retiredDirectory) {
	$source = PATH_PLUGINS . $retiredDirectory;
	if (file_exists($source) || is_link($source)) $retiredPluginResidue[] = $retiredDirectory;
}
// State from fully removed bundled plugins should disappear after upgrade.
foreach (brendyRetiredBundledPluginStateDirectories() as $retiredStateDirectory) {
	if (file_exists(PATH_PLUGINS_DATABASES . $retiredStateDirectory) || is_link(PATH_PLUGINS_DATABASES . $retiredStateDirectory) ||
		file_exists(PATH_WORKSPACES . $retiredStateDirectory) || is_link(PATH_WORKSPACES . $retiredStateDirectory)) {
		$retiredPluginResidue[] = $retiredStateDirectory . '-state';
	}
}

$pluginWarnings = array();
foreach ($plugins['all'] as $plugin) {
	if (!is_object($plugin) || !$plugin instanceof BrendyPlugin) continue;
	$pluginName = Sanitize::htmlDecode($plugin->name());
	if (!$plugin->isCompatible()) $incompatible[] = $pluginName;
	if (method_exists($plugin, 'healthStatus')) {
		$status = $plugin->healthStatus();
		if (is_array($status) && isset($status['status'])) {
			if ($status['status'] === 'error') $unhealthy[] = $pluginName;
			elseif ($status['status'] === 'warning') $pluginWarnings[] = $pluginName;
		}
	}
}
// Inactive/incompatible extensions stay metadata-only on System Health. Include
// their static contract/security state without executing plugin.php.
foreach (isset($pluginsCatalog) && is_array($pluginsCatalog) ? $pluginsCatalog : array() as $catalogKey => $entry) {
	if (isset($plugins['all'][$catalogKey]) || !is_array($entry)) continue;
	$directory = isset($entry['directory']) && is_string($entry['directory']) ? $entry['directory'] : 'plugin';
	$name = $directory;
	if (empty($entry['metadataValid']) || !empty($entry['classCollision']) ||
		(array_key_exists('sourceSafe', $entry) && $entry['sourceSafe'] === false) ||
		(empty($entry['className']) && !empty($entry['metadataValid'])) || (!empty($entry['installed']) && empty($entry['databaseOk']))) {
		$unhealthy[] = $name;
	} elseif (empty($entry['compatible'])) {
		$incompatible[] = $name;
	}
}
$pluginStatus = !empty($unhealthy) ? 'error' : ((!empty($incompatible) || !empty($pluginWarnings) || !empty($retiredPluginResidue)) ? 'warning' : 'ok');
$pluginDetail = $L->g('Loaded plugins') . ': ' . count($plugins['all']) . ' · ' .
	$L->g('Plugins') . ': ' . count(isset($pluginsCatalog) && is_array($pluginsCatalog) ? $pluginsCatalog : array()) . ' · ' .
	$L->g('Enabled') . ': ' . count($pluginsInstalled);
if (!empty($incompatible)) $pluginDetail .= ' · ' . $L->g('Compatibility warnings') . ': ' . implode(', ', array_slice(array_unique($incompatible), 0, 5));
if (!empty($pluginWarnings)) $pluginDetail .= ' · ' . $L->g('Warnings') . ': ' . implode(', ', array_slice(array_unique($pluginWarnings), 0, 5));
if (!empty($unhealthy)) $pluginDetail .= ' · ' . $L->g('Errors') . ': ' . implode(', ', array_slice(array_unique($unhealthy), 0, 5));
if (!empty($retiredPluginResidue)) $pluginDetail .= ' · ' . $L->g('Retired plugin residue') . ': ' . implode(', ', array_slice(array_unique($retiredPluginResidue), 0, 6));
$addHealthCheck('plugins', $L->g('Plugin health'), $pluginStatus, $pluginDetail, 'puzzle');

$allThemes = buildThemes();
$activeTheme = null;
$themeWarnings = array();
$themeErrors = array();
foreach ($allThemes as $candidateTheme) {
	$candidateName = isset($candidateTheme['name']) ? (string)$candidateTheme['name'] : (string)($candidateTheme['dirname'] ?? 'theme');
	$candidateHealth = isset($candidateTheme['health']) && is_array($candidateTheme['health']) ? $candidateTheme['health'] : array('status' => 'warning');
	if (($candidateHealth['status'] ?? '') === 'error') $themeErrors[] = $candidateName;
	elseif (($candidateHealth['status'] ?? '') === 'warning') $themeWarnings[] = $candidateName;
	if (isset($candidateTheme['dirname']) && hash_equals((string)$site->theme(), (string)$candidateTheme['dirname'])) $activeTheme = $candidateTheme;
}
$themeStatus = 'error';
$themeDetail = $L->g('The active theme could not be loaded');
if (is_array($activeTheme)) {
	$themeHealth = isset($activeTheme['health']) && is_array($activeTheme['health']) ? $activeTheme['health'] : array();
	$themeStatus = isset($themeHealth['status']) && in_array($themeHealth['status'], array('ok', 'warning', 'error'), true) ? $themeHealth['status'] : 'warning';
	$themeDetail = $L->g('Active theme') . ': ' . ($activeTheme['name'] ?? $site->theme()) . ' · ' .
		$L->g('Version') . ': ' . ($activeTheme['version'] ?? '—');
}
$addHealthCheck('theme', $L->g('Theme health'), $themeStatus, $themeDetail, 'palette2');

$themeLibraryStatus = !empty($themeErrors) ? 'error' : (!empty($themeWarnings) ? 'warning' : 'ok');
$themeLibraryDetail = $L->g('Available themes') . ': ' . count($allThemes);
if (!empty($themeWarnings)) $themeLibraryDetail .= ' · ' . $L->g('Warnings') . ': ' . implode(', ', array_slice(array_unique($themeWarnings), 0, 5));
if (!empty($themeErrors)) $themeLibraryDetail .= ' · ' . $L->g('Errors') . ': ' . implode(', ', array_slice(array_unique($themeErrors), 0, 5));
$addHealthCheck('theme-library', $L->g('Theme library health'), $themeLibraryStatus, $themeLibraryDetail, 'collection');

$languageFile = PATH_LANGUAGES . $site->language() . '.json';
$languageOk = is_file($languageFile) && !is_link($languageFile) && is_readable($languageFile);
$addHealthCheck('language', $L->g('Interface language'), $languageOk ? 'ok' : 'error',
	$languageOk ? $L->g('Active language') . ': ' . $site->language() : $L->g('The selected language file is unavailable'), 'translate');

$counts = array('ok' => 0, 'warning' => 0, 'error' => 0);
foreach ($healthChecks as $check) $counts[$check['status']]++;
$overall = $counts['error'] > 0 ? 'error' : ($counts['warning'] > 0 ? 'warning' : 'ok');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
	<?php echo BrendyAdminUi::pageTitle(array('title' => $L->g('System health'), 'icon' => 'heartbeat')); ?>
	<a class="btn btn-sm btn-outline-primary" href="<?php echo HTML_PATH_ADMIN_ROOT ?>system-health"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> <?php $L->p('Run checks again') ?></a>
</div>

<div class="alert <?php echo $overall === 'ok' ? 'alert-success' : ($overall === 'warning' ? 'alert-warning' : 'alert-danger') ?>" role="status">
	<strong><?php echo Theme::escape($overall === 'ok' ? $L->g('All checks passed') : ($overall === 'warning' ? $L->g('Review recommended items') : $L->g('Action is required'))) ?></strong>
	<span class="ms-2"><?php echo (int)$counts['ok'] ?> <?php $L->p('Passed') ?> · <?php echo (int)$counts['warning'] ?> <?php $L->p('Warnings') ?> · <?php echo (int)$counts['error'] ?> <?php $L->p('Errors') ?></span>
</div>

<div class="brendy-health-grid">
	<?php foreach ($healthChecks as $check) : ?>
	<article class="brendy-health-card brendy-health-card--<?php echo Theme::escape($check['status']) ?>">
		<div class="brendy-health-card__icon"><i class="bi bi-<?php echo Theme::escape($check['icon']) ?>" aria-hidden="true"></i></div>
		<h3 class="h6 mb-2"><?php echo Theme::escape($check['label']) ?></h3>
		<p class="small text-muted mb-0"><?php echo Theme::escape($check['detail']) ?></p>
	</article>
	<?php endforeach ?>
</div>

<p class="small text-muted mt-4"><i class="bi bi-info-circle" aria-hidden="true"></i> <?php $L->p('These checks are local diagnostics and do not replace server-level monitoring or an external security review.') ?></p>
