<?php defined('BRENDY') or die('Brendy CMS.');

/**
 * HMAC-authenticated baseline for Brendy's own runtime code.
 *
 * Plugins/themes have their own executable-tree scanner. This class covers the
 * core (`jezgra`) plus the public bootstrap/protection files so System Health
 * can detect post-deployment tampering, deletion or unexpected injected files.
 * It intentionally performs no writes itself; installer/runtime callers persist
 * the returned payload through their existing atomic JSON database writers.
 */
class CoreIntegrity
{
    const SCHEMA_VERSION = 1;
    const MAX_FILES = 5000;
    const MAX_ENTRIES = 15000;
    const MAX_FILE_BYTES = 67108864;   // 64 MiB per file
    const MAX_TOTAL_BYTES = 536870912; // 512 MiB total scan budget

    private static function validKey($keyHex)
    {
        return is_string($keyHex) && preg_match('/^[a-f0-9]{64}$/iD', $keyHex) === 1;
    }

    private static function pathInside($path, $root)
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($path . DIRECTORY_SEPARATOR, $root) === 0;
    }

    /**
     * Return sorted relative-path => SHA-256 map, or false on any ambiguity.
     * Symlinks and non-regular leaves fail closed instead of being skipped.
     */
    public static function collect($rootPath, $kernelPath)
    {
        $root = is_string($rootPath) ? realpath($rootPath) : false;
        $kernel = is_string($kernelPath) ? realpath($kernelPath) : false;
        if ($root === false || $kernel === false || is_link($rootPath) || is_link($kernelPath) ||
            !is_dir($root) || !is_dir($kernel) || !self::pathInside($kernel, $root)) return false;

        $files = array();
        $count = 0;
        $total = 0;

        // Root bootstrap/protection files. install.php is included whenever it
        // exists so an altered/reintroduced installer is detectable. Verification
        // explicitly allows only its later deletion as a legitimate hardening step.
        foreach (array('index.php', '.htaccess', 'install.php') as $relative) {
            $path = $root . DIRECTORY_SEPARATOR . $relative;
            if (!file_exists($path) && !is_link($path)) continue;
            if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
            $size = @filesize($path);
            if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES) return false;
            $hash = Filesystem::hashFileBounded($path, 'sha256', self::MAX_FILE_BYTES, 0, $size);
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
            $files[$relative] = $hash;
            $count++;
            $total += $size;
        }

        // Include unexpected root executable files in integrity comparisons.
        // A direct-web backdoor must not be invisible merely because it sits
        // outside jezgra. Never bless one when creating a new baseline.
        $rootEntries = @scandir($root);
        if (!is_array($rootEntries)) return false;
        foreach ($rootEntries as $relative) {
            if (in_array($relative, array('.', '..', 'index.php', 'install.php', '.htaccess'), true)) continue;
            if (!preg_match('/\.(?:php[0-9]*|pht|phtml|phar|phps|inc|module|install|cgi|fcgi|pl|py|sh)(?:\.|$)/iD', $relative)) continue;
            $path = $root . DIRECTORY_SEPARATOR . $relative;
            if (is_link($path) || !is_file($path) || !is_readable($path)) return false;
            $size = @filesize($path);
            if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES) return false;
            $count++;
            $total += $size;
            if ($count > self::MAX_FILES || $total > self::MAX_TOTAL_BYTES) return false;
            $hash = Filesystem::hashFileBounded($path, 'sha256', self::MAX_FILE_BYTES, 0, $size);
            if (!is_string($hash)) return false;
            $files[$relative] = $hash;
        }

        try {
            $directory = new RecursiveDirectoryIterator($kernel, FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
            $entries = 0;
            foreach ($iterator as $info) {
                if (++$entries > self::MAX_ENTRIES || !$info instanceof SplFileInfo) return false;
                $path = $info->getPathname();
                if (is_link($path) || !$info->isReadable()) return false;
                $real = realpath($path);
                if ($real === false || !self::pathInside($real, $kernel)) return false;
                if ($info->isDir()) continue;
                if (!$info->isFile()) return false;
                $relativeKernel = substr($real, strlen(rtrim($kernel, DIRECTORY_SEPARATOR)) + 1);
                if (!is_string($relativeKernel) || $relativeKernel === '' || strpos($relativeKernel, "\0") !== false) return false;
                $relative = 'jezgra/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativeKernel);
                if (strlen($relative) > 1024) return false;
                $size = $info->getSize();
                if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES) return false;
                $count++;
                $total += $size;
                if ($count > self::MAX_FILES || $total > self::MAX_TOTAL_BYTES) return false;
                $hash = Filesystem::hashFileBounded($real, 'sha256', self::MAX_FILE_BYTES, 0, $size);
                if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
                $files[$relative] = $hash;
            }
        } catch (Throwable $e) {
            return false;
        }

        if (empty($files) || $count !== count($files)) return false;
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function canonical($build, $generatedAt, $files)
    {
        if (!is_int($build) || $build < 1 || !is_string($generatedAt) || !is_array($files)) return false;
        ksort($files, SORT_STRING);
        $payload = array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'build' => $build,
            'generatedAt' => $generatedAt,
            'files' => $files
        );
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return false;
        }
    }

    public static function createPayload($rootPath, $kernelPath, $build, $keyHex)
    {
        if (!self::validKey($keyHex)) return false;
        $files = self::collect($rootPath, $kernelPath);
        if (!is_array($files) || !isset($files['index.php'])) return false;
        foreach ($files as $relative => $hash) {
            if (strpos($relative, 'jezgra/') !== 0 && !in_array($relative, array('index.php', 'install.php', '.htaccess'), true)) return false;
        }
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $canonical = self::canonical((int)$build, $generatedAt, $files);
        $key = @hex2bin($keyHex);
        if (!is_string($canonical) || !is_string($key) || strlen($key) !== 32) return false;
        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'build' => (int)$build,
            'generatedAt' => $generatedAt,
            'files' => $files,
            'signature' => hash_hmac('sha256', "Brendy-Core-Integrity-v1\n" . $canonical, $key)
        );
    }

    /**
     * Verify HMAC first, then compare the current code tree byte-for-byte.
     */
    public static function verifyPayload($payload, $rootPath, $kernelPath, $keyHex)
    {
        $result = array(
            'ok' => false,
            'authentic' => false,
            'build' => 0,
            'fileCount' => 0,
            'modified' => array(),
            'missing' => array(),
            'unexpected' => array(),
            'error' => ''
        );
        if (!self::validKey($keyHex) || !is_array($payload) ||
            !isset($payload['schemaVersion'], $payload['build'], $payload['generatedAt'], $payload['files'], $payload['signature']) ||
            (int)$payload['schemaVersion'] !== self::SCHEMA_VERSION || !is_numeric($payload['build']) || (int)$payload['build'] < 1 ||
            !is_string($payload['generatedAt']) || strlen($payload['generatedAt']) > 64 || !is_array($payload['files']) ||
            !is_string($payload['signature']) || preg_match('/^[a-f0-9]{64}$/D', $payload['signature']) !== 1 ||
            count($payload['files']) < 1 || count($payload['files']) > self::MAX_FILES) {
            $result['error'] = 'baseline-format';
            return $result;
        }
        $files = array();
        foreach ($payload['files'] as $relative => $hash) {
            if (!is_string($relative) || $relative === '' || strlen($relative) > 1024 || strpos($relative, "\0") !== false ||
                !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                $result['error'] = 'baseline-format';
                return $result;
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);
        $canonical = self::canonical((int)$payload['build'], $payload['generatedAt'], $files);
        $key = @hex2bin($keyHex);
        if (!is_string($canonical) || !is_string($key) || strlen($key) !== 32) {
            $result['error'] = 'baseline-key';
            return $result;
        }
        $expectedSignature = hash_hmac('sha256', "Brendy-Core-Integrity-v1\n" . $canonical, $key);
        if (!hash_equals($expectedSignature, $payload['signature'])) {
            $result['error'] = 'baseline-signature';
            return $result;
        }
        $result['authentic'] = true;
        $result['build'] = (int)$payload['build'];
        $result['fileCount'] = count($files);

        $current = self::collect($rootPath, $kernelPath);
        if (!is_array($current)) {
            $result['error'] = 'scan-failed';
            return $result;
        }
        foreach ($files as $relative => $hash) {
            if (!isset($current[$relative])) {
                // Administrators are encouraged to remove install.php after setup.
                // Its absence is safe; modification or later reappearance is not.
                if ($relative !== 'install.php') $result['missing'][] = $relative;
            } elseif (!hash_equals($hash, $current[$relative])) {
                $result['modified'][] = $relative;
            }
        }
        foreach ($current as $relative => $hash) {
            if (!isset($files[$relative])) $result['unexpected'][] = $relative;
        }
        $result['ok'] = empty($result['modified']) && empty($result['missing']) && empty($result['unexpected']);
        if (!$result['ok']) $result['error'] = 'tree-mismatch';
        return $result;
    }
}
