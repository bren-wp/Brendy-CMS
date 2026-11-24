<?php defined('BRENDY') or die('Brendy CMS.');
require_once dirname(__DIR__) . '/pomocnici/filesystem.class.php';
require_once dirname(__DIR__) . '/pomocnici/restore-journal.class.php';

/** Block PHP requests during a content swap; recovery precedes site-marker checks. */
function brendyRestoreUnavailable(): void
{
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Web-stranica je privremeno nedostupna zbog održavanja ili oporavka podataka.');
}

function brendyRestoreGate(string $installation): void
{
    foreach (['sadrzaj','brendy-content'] as $name) {
        $root = $installation . DS . $name; $tmp = $root . DS . 'tmp';
        if (is_link($root) || is_link($tmp)) brendyRestoreUnavailable();
        // A clean installation has no mutable runtime. An established site
        // must have a gate even when upgrading from an older version.
        if (!is_dir($tmp)) {
            if (!is_file($root . DS . 'databases' . DS . 'site.php')) continue;
            if (!@mkdir($tmp,0700) && !is_dir($tmp)) brendyRestoreUnavailable();
        }
        $lock = $tmp . DS . 'restore-request.lock';
        $handle = Filesystem::acquireLock($lock, LOCK_SH | LOCK_NB, 0600);
        if ($handle === false) brendyRestoreUnavailable();
        if (!is_readable($tmp)) brendyRestoreUnavailable();
        $generation = brendyRestoreGeneration($handle);
        if ($generation === false) brendyRestoreUnavailable();
        $GLOBALS['_brendyRestoreGates'][$name] = ['handle'=>$handle,'generation'=>$generation];
        $journal = new RestoreJournal($root);
        if ($journal->pending()) {
            if (!@flock($handle, LOCK_EX | LOCK_NB) || !Filesystem::openedFileMatchesPath($handle,$lock,false)) brendyRestoreUnavailable();
            // Invalidate pre-recovery request snapshots as well as ordinary restores.
            $GLOBALS['_brendyRestoreGates'][$name]['generation'] = brendyAdvanceRestoreGeneration($handle);
            try { $result = $journal->recover(); } catch (Throwable $e) { $result = 'blocked'; }
            if ($result === 'blocked') {
                error_log('Brendy CMS: restore recovery requires operator attention.');
                brendyRestoreUnavailable();
            }
            if ($result !== 'none') error_log('Brendy CMS: restore recovery ' . $result . '; staging retained for review.');
            // Keep exclusive access for the remainder of the recovering request.
        }
    }
}

function brendyRestoreExclusive(): void
{
    $gate = $GLOBALS['_brendyRestoreGates'][BRENDY_CONTENT_DIRECTORY] ?? [];
    $handle = $gate['handle'] ?? null;
    if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB) ||
        !Filesystem::openedFileMatchesPath($handle, PATH_TMP . 'restore-request.lock', false)) brendyRestoreUnavailable();
    // flock conversion is not necessarily atomic. Refuse a stale request if
    // another restore acquired the exclusive gate in the conversion window.
    if (brendyRestoreGeneration($handle) !== $gate['generation']) brendyRestoreUnavailable();
    $GLOBALS['_brendyRestoreGates'][BRENDY_CONTENT_DIRECTORY]['generation'] = brendyAdvanceRestoreGeneration($handle);
    // Never downgrade during the request: its loaded objects predate the swap.
}

function brendyAdvanceRestoreGeneration($handle): string
{
    $generation = bin2hex(random_bytes(16));
    if (!@rewind($handle) || !@ftruncate($handle,0) || @fwrite($handle,$generation) !== 32 || !@fflush($handle) ||
        brendyRestoreGeneration($handle) !== $generation) brendyRestoreUnavailable();
    return $generation;
}

function brendyRestoreGeneration($handle)
{
    if (!is_resource($handle) || !@rewind($handle)) return false;
    $stat = @fstat($handle);
    $size = $stat['size'] ?? -1;
    if (!is_int($size) || $size < 0 || $size > 64) return false;
    $value = @stream_get_contents($handle,65);
    // A killed writer may leave a partial token. It is still a distinct
    // generation and may safely be replaced under the exclusive lock.
    return is_string($value) && strlen($value) === $size ? $value : false;
}
