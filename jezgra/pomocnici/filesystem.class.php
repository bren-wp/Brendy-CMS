<?php defined('BRENDY') or die('Brendy CMS.');

class Filesystem
{

	/**
	 * Verify that an opened descriptor and a pathname still identify the same
	 * regular file. When $requireSameSize is TRUE, size is part of the identity
	 * contract as well, which makes bounded reads/hashes fail closed when the
	 * file changes while it is being consumed.
	 */
	private static function regularFileStatsMatch($streamStat, $pathStat, $requireSameSize = false)
	{
		if (!is_array($streamStat) || !is_array($pathStat) ||
			!isset($streamStat['mode'], $pathStat['mode'])) return false;
		if (((int)$streamStat['mode'] & 0170000) !== 0100000 ||
			((int)$pathStat['mode'] & 0170000) !== 0100000) return false;
		if (isset($streamStat['dev'], $streamStat['ino'], $pathStat['dev'], $pathStat['ino']) &&
			((string)$streamStat['dev'] !== (string)$pathStat['dev'] ||
			 (string)$streamStat['ino'] !== (string)$pathStat['ino'])) return false;
		if ($requireSameSize) {
			if (!isset($streamStat['size'], $pathStat['size']) || !is_int($streamStat['size']) ||
				!is_int($pathStat['size']) || $streamStat['size'] !== $pathStat['size']) return false;
		}
		return true;
	}

	/** Detect replacement or observable in-place modification during a read. */
	private static function unchangedReadSnapshot($before, $after)
	{
		if (!self::regularFileStatsMatch($before, $after, true)) return false;
		foreach (array('mtime', 'ctime', 'nlink') as $field) {
			if (!isset($before[$field], $after[$field]) || $before[$field] !== $after[$field]) return false;
		}
		return true;
	}

	/** Best-effort cleanup: refuse to remove an entry with a different identity.
	 * PHP pathname APIs cannot make the final stat/unlink indivisible. */
	private static function removeOwnedRegularFile($pathname, $expected, $links = 1)
	{
		clearstatcache(true, $pathname);
		$current = @lstat($pathname);
		return self::regularFileStatsMatch($expected, $current) &&
			($current['nlink'] ?? 0) === $links && @unlink($pathname);
	}

	/**
	 * Verify that an already-open regular-file descriptor still belongs to the
	 * requested pathname. This is intentionally a narrow public wrapper around
	 * the internal stat comparison so streaming responders can keep metadata and
	 * bytes on the same descriptor without reopening a mutable pathname.
	 */
	public static function openedFileMatchesPath($handle, $pathname, $requireSameSize = true)
	{
		if (!is_resource($handle) || !is_string($pathname) || $pathname === '' ||
			strpos($pathname, "\0") !== false) return false;
		clearstatcache(true, $pathname);
		if (is_link($pathname)) return false;
		return self::regularFileStatsMatch(@fstat($handle), @lstat($pathname), $requireSameSize === true);
	}

	/**
	 * Return a compact browser-cache version token from one pathname metadata
	 * snapshot. Atomic replacement changes the inode on normal POSIX hosting, so
	 * same-second/same-size replacements still invalidate URLs without hashing
	 * the file contents on every render. Raw filesystem identifiers are hashed
	 * before exposure.
	 */
	public static function fileVersionToken($pathname, $fallback = '')
	{
		$fallback = is_scalar($fallback) ? (string)$fallback : '';
		if (!is_string($pathname) || $pathname === '' || strpos($pathname, "\0") !== false || is_link($pathname)) return $fallback;
		clearstatcache(true, $pathname);
		$stat = @lstat($pathname);
		if (!is_array($stat) || !isset($stat['mode'], $stat['mtime'], $stat['size']) ||
			((int)$stat['mode'] & 0170000) !== 0100000 || !is_int($stat['mtime']) || $stat['mtime'] < 1 ||
			!is_int($stat['size']) || $stat['size'] < 0) return $fallback;
		$dev = isset($stat['dev']) && is_int($stat['dev']) ? $stat['dev'] : 0;
		$ino = isset($stat['ino']) && is_int($stat['ino']) ? $stat['ino'] : 0;
		$ctime = isset($stat['ctime']) && is_int($stat['ctime']) ? $stat['ctime'] : 0;
		return substr(hash('sha256', $stat['mtime'] . ':' . $stat['size'] . ':' . $dev . ':' . $ino . ':' . $ctime), 0, 20);
	}


	// Returns an array with the absolutes directories.
	public static function listDirectories($path, $regex = '*', $sortByDate = false)
	{
		if (!is_string($path) || !is_string($regex) || strpos($path . $regex, "\0") !== false) return array();
		$directories = glob($path . $regex, GLOB_ONLYDIR);

		if (empty($directories)) {
			return array();
		}

		$directories = array_values(array_filter($directories, function ($directory) {
			return is_dir($directory) && !is_link($directory);
		}));
		if ($sortByDate) {
			$modified = array();
			foreach ($directories as $directory) $modified[$directory] = (int)@filemtime($directory);
			usort($directories, static function ($a, $b) use ($modified) {
				$order = $modified[$b] <=> $modified[$a];
				return $order !== 0 ? $order : strcmp($a, $b);
			});
		}

		return array_slice($directories, 0, 10000);
	}

	// Returns an array with the list of files with the absolute path
	// $sortByDate = TRUE, the first file is the newer file
	// $chunk = amount of chunks, FALSE if you don't want to chunk
	public static function listFiles($path, $regex = '*', $extension = '*', $sortByDate = false, $chunk = false)
	{
		if (!is_string($path) || !is_string($regex) || !is_string($extension) ||
			strpos($path . $regex . $extension, "\0") !== false) return array();
		$files = glob($path . $regex . '.' . $extension);

		if (empty($files)) {
			return array();
		}

		$files = array_values(array_filter($files, function ($file) {
			return is_file($file) && !is_link($file);
		}));
		if ($sortByDate) {
			$modified = array();
			foreach ($files as $file) $modified[$file] = (int)@filemtime($file);
			usort($files, static function ($a, $b) use ($modified) {
				$order = $modified[$b] <=> $modified[$a];
				return $order !== 0 ? $order : strcmp($a, $b);
			});
		}

		// Split the list of files into chunks
		// http://php.net/manual/en/function.array-chunk.php
		$files = array_slice($files, 0, 10000);
		if ($chunk !== false) {
			$chunk = filter_var($chunk, FILTER_VALIDATE_INT);
			if ($chunk === false || $chunk < 1 || $chunk > 10000) return array();
			return array_chunk($files, $chunk);
		}

		return $files;
	}

	/** Normalize terminal separators before checking links. Reject ambiguous terminal dot paths. */
	private static function directoryOperationPath($path)
	{
		if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || strpos($path, '://') !== false) return false;
		$path = rtrim($path, DS === '\\' ? "/\\" : '/');
		if ($path === '' || in_array(basename($path), array('.', '..'), true) ||
			(DS === '\\' && preg_match('/^[A-Za-z]:$/D', $path))) return false;
		clearstatcache(true, $path);
		return $path;
	}

	public static function mkdir($pathname, $recursive = false)
	{
		$pathname = self::directoryOperationPath($pathname);
		if ($pathname === false || is_link($pathname)) return false;
		if (is_dir($pathname)) {
			return true;
		}
		if (file_exists($pathname)) {
			return false;
		}
		Log::set('mkdir ' . $pathname . ' recursive = ' . $recursive, LOG_TYPE_INFO);
		return @mkdir($pathname, DIR_PERMISSIONS, $recursive);
	}

	/**
	 * Open and acquire a filesystem lock without trusting the pathname after
	 * fopen(). Lock files are security boundaries for JSON writes, security state,
	 * audit data and plugin operations, so verify that the opened descriptor and
	 * the current directory entry still refer to the same regular file.
	 *
	 * @return resource|false
	 */
	public static function acquireLock($pathname, $operation = LOCK_EX, $permissions = 0600)
	{
		$allowedOperations = array(LOCK_EX, LOCK_EX | LOCK_NB, LOCK_SH, LOCK_SH | LOCK_NB);
		if (!is_string($pathname) || $pathname === '' || strpos($pathname, "\0") !== false ||
			!in_array($operation, $allowedOperations, true) || !is_int($permissions) ||
			$permissions < 0 || $permissions > 0777 || is_link($pathname) ||
			(file_exists($pathname) && !is_file($pathname))) return false;

		$parentInput = dirname($pathname);
		$parent = realpath($parentInput);
		if ($parent === false || !is_dir($parent) || is_link($parentInput)) return false;
		$path = $parent . DS . basename($pathname);
		if (is_link($path) || (file_exists($path) && !is_file($path))) return false;

		// Create lock files privately even under a permissive host umask.
		$previousMask = umask(0077);
		try { $handle = @fopen($path, 'c+'); }
		finally { umask($previousMask); }
		if ($handle === false) return false;

		$streamStat = @fstat($handle);
		$pathStat = @lstat($path);
		if (is_link($path) || !self::regularFileStatsMatch($streamStat, $pathStat) ||
			($streamStat['nlink'] ?? 0) !== 1 || !@flock($handle, $operation)) {
			@fclose($handle);
			return false;
		}

		// Re-check after waiting for the lock. A path can be replaced while this
		// process blocks in flock(); the opened descriptor must remain canonical.
		clearstatcache(true, $path);
		$pathStat = @lstat($path);
		if (is_link($path) || !self::regularFileStatsMatch(@fstat($handle), $pathStat) ||
			($pathStat['nlink'] ?? 0) !== 1 || !@chmod($path, $permissions)) {
			@flock($handle, LOCK_UN);
			@fclose($handle);
			return false;
		}
		clearstatcache(true, $path);
		$finalStat = @lstat($path);
		if (!self::regularFileStatsMatch(@fstat($handle), $finalStat) ||
			($finalStat['nlink'] ?? 0) !== 1 || (($finalStat['mode'] ?? 0) & 0777) !== $permissions) {
			self::releaseLock($handle);
			return false;
		}
		return $handle;
	}

	public static function releaseLock($handle)
	{
		if (!is_resource($handle)) return false;
		$unlocked = @flock($handle, LOCK_UN);
		$closed = @fclose($handle);
		return $unlocked && $closed;
	}

	/**
	 * Read a regular file and its descriptor metadata from one bounded snapshot.
	 * The returned metadata belongs to the exact bytes in `data`, which allows
	 * HTTP responders to build Content-Length/ETag/Last-Modified without a second
	 * pathname stat that could race an atomic replacement.
	 *
	 * @return array|false Array with data, size, mtime, dev and ino.
	 */
	public static function readFileSnapshotBounded($pathname, $maxBytes, $minBytes = 0)
	{
		if (!is_string($pathname) || $pathname === '' || strpos($pathname, "\0") !== false ||
			!is_int($maxBytes) || !is_int($minBytes) || $minBytes < 0 || $maxBytes < $minBytes ||
			$maxBytes > 1073741824 || is_link($pathname) || !is_file($pathname)) return false;

		$handle = @fopen($pathname, 'rb');
		if ($handle === false) return false;
		try {
			$before = @fstat($handle);
			$pathBefore = @lstat($pathname);
			if (is_link($pathname) || !self::regularFileStatsMatch($before, $pathBefore, true)) return false;
			$size = isset($before['size']) && is_int($before['size']) ? $before['size'] : -1;
			if ($size < $minBytes || $size > $maxBytes) return false;

			$data = @stream_get_contents($handle, $maxBytes + 1);
			if (!is_string($data) || strlen($data) !== $size) return false;

			clearstatcache(true, $pathname);
			$after = @fstat($handle);
			$pathAfter = @lstat($pathname);
			if (is_link($pathname) || !self::unchangedReadSnapshot($before, $after) ||
				!self::regularFileStatsMatch($after, $pathAfter, true) ||
				!isset($after['size']) || !is_int($after['size']) || $after['size'] !== $size) return false;

			return array(
				'data' => $data,
				'size' => $size,
				'mtime' => isset($after['mtime']) && is_int($after['mtime']) ? $after['mtime'] : 0,
				'dev' => isset($after['dev']) && is_int($after['dev']) ? $after['dev'] : 0,
				'ino' => isset($after['ino']) && is_int($after['ino']) ? $after['ino'] : 0
			);
		} finally {
			@fclose($handle);
		}
	}


	/**
	 * Read only a bounded prefix while still returning metadata for the complete
	 * regular file from the same descriptor snapshot. Useful for magic-byte and
	 * lightweight type checks on large uploads without loading the whole file.
	 *
	 * @return array|false Array with data (prefix), size, mtime, dev and ino.
	 */
	public static function readFilePrefixSnapshotBounded($pathname, $prefixBytes, $maxBytes, $minBytes = 0)
	{
		if (!is_string($pathname) || $pathname === '' || strpos($pathname, "\0") !== false ||
			!is_int($prefixBytes) || $prefixBytes < 1 || $prefixBytes > 1048576 ||
			!is_int($maxBytes) || !is_int($minBytes) || $minBytes < 0 || $maxBytes < $minBytes ||
			$maxBytes > 1073741824 || is_link($pathname) || !is_file($pathname)) return false;

		$handle = @fopen($pathname, 'rb');
		if ($handle === false) return false;
		try {
			$before = @fstat($handle);
			$pathBefore = @lstat($pathname);
			if (is_link($pathname) || !self::regularFileStatsMatch($before, $pathBefore, true)) return false;
			$size = isset($before['size']) && is_int($before['size']) ? $before['size'] : -1;
			if ($size < $minBytes || $size > $maxBytes) return false;

			$wanted = min($prefixBytes, $size);
			$data = $wanted > 0 ? @fread($handle, $wanted) : '';
			if (!is_string($data) || strlen($data) !== $wanted) return false;

			clearstatcache(true, $pathname);
			$after = @fstat($handle);
			$pathAfter = @lstat($pathname);
			if (is_link($pathname) || !self::unchangedReadSnapshot($before, $after) ||
				!self::regularFileStatsMatch($after, $pathAfter, true) ||
				!isset($after['size']) || !is_int($after['size']) || $after['size'] !== $size) return false;

			return array(
				'data' => $data,
				'size' => $size,
				'mtime' => isset($after['mtime']) && is_int($after['mtime']) ? $after['mtime'] : 0,
				'dev' => isset($after['dev']) && is_int($after['dev']) ? $after['dev'] : 0,
				'ino' => isset($after['ino']) && is_int($after['ino']) ? $after['ino'] : 0
			);
		} finally {
			@fclose($handle);
		}
	}

	/**
	 * Read only the bytes from a bounded descriptor snapshot.
	 *
	 * @return string|false
	 */
	public static function readFileBounded($pathname, $maxBytes, $minBytes = 0)
	{
		$snapshot = self::readFileSnapshotBounded($pathname, $maxBytes, $minBytes);
		return is_array($snapshot) && isset($snapshot['data']) && is_string($snapshot['data'])
			? $snapshot['data'] : false;
	}

	/**
	 * Hash a regular file through one bounded descriptor snapshot. Multiple
	 * algorithms are updated in the same streaming pass so page integrity can
	 * keep its legacy MD5 field without reading the content twice.
	 *
	 * @return array|false Map of normalized algorithm => lowercase digest.
	 */
	public static function hashFileDigestsBounded($pathname, $algorithms, $maxBytes = 1073741824, $minBytes = 0, $expectedBytes = null)
	{
		if (!is_string($pathname) || $pathname === '' || strpos($pathname, "\0") !== false ||
			!is_array($algorithms) || empty($algorithms) || count($algorithms) > 4 ||
			!is_int($maxBytes) || !is_int($minBytes) || $minBytes < 0 || $maxBytes < $minBytes ||
			$maxBytes > 1073741824 || ($expectedBytes !== null && (!is_int($expectedBytes) || $expectedBytes < $minBytes || $expectedBytes > $maxBytes)) ||
			is_link($pathname) || !is_file($pathname)) return false;

		$allowed = array('sha256' => true, 'md5' => true);
		$contexts = array();
		foreach ($algorithms as $algorithm) {
			if (!is_string($algorithm)) return false;
			$algorithm = strtolower(trim($algorithm));
			if (!isset($allowed[$algorithm]) || isset($contexts[$algorithm])) return false;
			$contexts[$algorithm] = hash_init($algorithm);
		}

		$handle = @fopen($pathname, 'rb');
		if ($handle === false) return false;
		try {
			$before = @fstat($handle);
			$pathBefore = @lstat($pathname);
			if (is_link($pathname) || !self::regularFileStatsMatch($before, $pathBefore, true)) return false;
			$size = isset($before['size']) && is_int($before['size']) ? $before['size'] : -1;
			if ($size < $minBytes || $size > $maxBytes || ($expectedBytes !== null && $size !== $expectedBytes)) return false;

			$read = 0;
			if (count($contexts) === 1) {
				// Native bounded streaming avoids allocating PHP chunks for the common
				// SHA-256-only recovery path; preserve the exact byte-count and stat checks.
				$read = @hash_update_stream(reset($contexts), $handle, $size);
			} else {
				// Multiple digests still share one bounded read of the file.
				while (!feof($handle)) {
					$remaining = $size - $read;
					if ($remaining <= 0) break;
					$chunk = @fread($handle, min(1048576, $remaining));
					if (!is_string($chunk) || $chunk === '') return false;
					$length = strlen($chunk);
					if ($length > $remaining) return false;
					foreach ($contexts as $context) hash_update($context, $chunk);
					$read += $length;
				}
			}
			if ($read !== $size) return false;

			clearstatcache(true, $pathname);
			$after = @fstat($handle);
			$pathAfter = @lstat($pathname);
			if (is_link($pathname) || !self::unchangedReadSnapshot($before, $after) ||
				!self::regularFileStatsMatch($after, $pathAfter, true) ||
				!isset($after['size']) || !is_int($after['size']) || $after['size'] !== $size) return false;

			$result = array();
			foreach ($contexts as $algorithm => $context) $result[$algorithm] = hash_final($context);
			return $result;
		} finally {
			@fclose($handle);
		}
	}

	public static function hashFileBounded($pathname, $algorithm = 'sha256', $maxBytes = 1073741824, $minBytes = 0, $expectedBytes = null)
	{
		$digests = self::hashFileDigestsBounded($pathname, array($algorithm), $maxBytes, $minBytes, $expectedBytes);
		$algorithm = is_string($algorithm) ? strtolower(trim($algorithm)) : '';
		return is_array($digests) && isset($digests[$algorithm]) ? $digests[$algorithm] : false;
	}

	public static function rmdir($pathname)
	{
		$pathname = self::directoryOperationPath($pathname);
		if ($pathname === false || is_link($pathname) || !is_dir($pathname)) return false;
		$real = realpath($pathname);
		if ($real === false || dirname($real) === $real) return false;
		Log::set('rmdir = ' . $pathname, LOG_TYPE_INFO);
		return @rmdir($real);
	}

	public static function mv($oldname, $newname)
	{
		$oldname = self::directoryOperationPath($oldname);
		$newname = self::directoryOperationPath($newname);
		if ($oldname === false || $newname === false ||
			is_link($oldname) || is_link($newname) || file_exists($newname) ||
			(!is_file($oldname) && !is_dir($oldname))) {
			return false;
		}
		$destinationParent = realpath(dirname($newname));
		if ($destinationParent === false || !is_dir($destinationParent) || is_link(dirname($newname))) {
			return false;
		}
		$sourcePath = realpath($oldname);
		$destinationPath = $destinationParent . DS . basename($newname);
		if ($sourcePath === false || dirname($sourcePath) === $sourcePath ||
			(is_dir($sourcePath) && strpos($destinationPath, $sourcePath . DS) === 0)) return false;

		Log::set('mv ' . $oldname . ' ' . $newname, LOG_TYPE_INFO);

		// Regular files use a no-clobber path. A successful move has a strict
		// postcondition: the source no longer exists. Callers use mv() for rollback
		// transactions, so returning TRUE while both paths exist would corrupt that
		// state machine.
		if (is_file($sourcePath)) {
			clearstatcache(true, $sourcePath);
			$sourceIdentity = @lstat($sourcePath);
			if (!self::regularFileStatsMatch($sourceIdentity, $sourceIdentity) || ($sourceIdentity['nlink'] ?? 0) !== 1) return false;
			if (@link($sourcePath, $destinationPath)) {
				clearstatcache(true, $sourcePath);
				clearstatcache(true, $destinationPath);
				$linked = @lstat($destinationPath);
				if (self::regularFileStatsMatch($sourceIdentity, $linked, true) &&
					($linked['nlink'] ?? 0) === 2 && self::removeOwnedRegularFile($sourcePath, $sourceIdentity, 2)) return true;
				// Rollback only our link, never a replacement directory entry.
				self::removeOwnedRegularFile($destinationPath, $sourceIdentity, 2);
				return false;
			}
			if (file_exists($destinationPath) || is_link($destinationPath)) return false;
			$copyIdentity = null;
			if (!self::copyFileWithIdentity($sourcePath, $destinationPath, null, $copyIdentity)) return false;
			clearstatcache(true, $sourcePath);
			if (self::unchangedReadSnapshot($sourceIdentity, @lstat($sourcePath)) &&
				self::removeOwnedRegularFile($sourcePath, $sourceIdentity)) return true;
			self::removeOwnedRegularFile($destinationPath, $copyIdentity);
			return false;
		}

		// Directory moves are used as transaction boundaries throughout content,
		// plugin and backup operations. Only a same-filesystem atomic rename can
		// guarantee that TRUE means the source disappeared completely. A recursive
		// copy/delete fallback can leave a partially deleted source if cleanup fails,
		// so fail closed instead of reporting a false success.
		return @rename($sourcePath, $destinationPath);
	}


	/**
	 * Move a regular staging file into a directory under the next available
	 * filename. nextFilename() is advisory, so a bounded retry closes the race
	 * where another request creates the same candidate before mv() commits.
	 *
	 * @return string|false Absolute committed path, or false on error.
	 */
	public static function moveToUniqueFilename($source, $filename, $directory, $maxAttempts = 16)
	{
		if (!is_string($source) || !is_string($filename) || !is_string($directory) ||
			!is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 128 ||
			$source === '' || $filename === '' || strpos($source . $filename . $directory, "\0") !== false ||
			!is_file($source) || is_link($source) || basename($filename) !== $filename ||
			!is_dir($directory) || is_link($directory)) return false;

		$directoryReal = realpath($directory);
		if ($directoryReal === false || !is_dir($directoryReal)) return false;
		$directoryReal = rtrim($directoryReal, "/\\") . DS;

		for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
			$candidate = self::nextFilename($filename, $directoryReal);
			if ($candidate === false) return false;
			$destination = $directoryReal . $candidate;
			if (self::mv($source, $destination)) return $destination;

			// Retry only a genuine destination collision. Any other failure is
			// treated as fatal so permission/I/O errors are not hidden in a loop.
			if (!is_file($source) || is_link($source) ||
				(!file_exists($destination) && !is_link($destination))) return false;
		}
		return false;
	}

	// Durable, exclusive file copy used by recursive copy and cross-filesystem
	// moves. The destination is never exposed as a successful copy until all
	// bytes have been written, flushed and fsync()ed when supported.
	public static function copyFileSafely($source, $destination, $permissions = null)
	{
		$identity = null;
		return self::copyFileWithIdentity($source, $destination, $permissions, $identity);
	}

	private static function copyFileWithIdentity($source, $destination, $permissions, &$createdIdentity)
	{
		$createdIdentity = null;
		if (!is_string($source) || !is_string($destination) || $source === '' || $destination === '' ||
			strpos($source . $destination, "\0") !== false || !is_file($source) || is_link($source) ||
			file_exists($destination) || is_link($destination) ||
			($permissions !== null && (!is_int($permissions) || $permissions < 0 || $permissions > 0777))) {
			return false;
		}
		$sourcePath = realpath($source);
		$destinationParent = realpath(dirname($destination));
		if ($sourcePath === false || $destinationParent === false || !is_dir($destinationParent) ||
			is_link(dirname($destination))) return false;
		$destinationPath = $destinationParent . DS . basename($destination);
		if ($destinationPath === $sourcePath) return false;

		$input = @fopen($sourcePath, 'rb');
		if ($input === false) return false;
		$sourceBefore = @fstat($input);
		$sourcePathBefore = @lstat($sourcePath);
		if (is_link($sourcePath) || !self::regularFileStatsMatch($sourceBefore, $sourcePathBefore, true) ||
			!isset($sourceBefore['size']) || !is_int($sourceBefore['size']) || $sourceBefore['size'] < 0) {
			@fclose($input);
			return false;
		}
		$sourceSize = $sourceBefore['size'];

		$previousMask = umask(0077);
		try { $output = @fopen($destinationPath, 'xb'); }
		finally { umask($previousMask); }
		if ($output === false) {
			@fclose($input);
			return false;
		}
		clearstatcache(true, $destinationPath);
		$initialOutput = @fstat($output);
		$destinationOwned = !is_link($destinationPath) &&
			self::regularFileStatsMatch($initialOutput, @lstat($destinationPath), true) &&
			($initialOutput['nlink'] ?? 0) === 1 && (($initialOutput['mode'] ?? 0) & 0077) === 0;
		if (!$destinationOwned) {
			@fclose($input);
			@fclose($output);
			return false;
		}

		$copied = @stream_copy_to_stream($input, $output, $sourceSize);
		$flushed = ($copied !== false && $copied === $sourceSize) && @fflush($output);
		$synced = $flushed;
		if ($flushed && function_exists('fsync')) $synced = @fsync($output);

		clearstatcache(true, $sourcePath);
		clearstatcache(true, $destinationPath);
		$sourceAfter = @fstat($input);
		$sourceStable = !is_link($sourcePath) &&
			self::unchangedReadSnapshot($sourceBefore, $sourceAfter) &&
			self::regularFileStatsMatch($sourceAfter, @lstat($sourcePath), true) &&
			is_array($sourceAfter) && isset($sourceAfter['size']) && is_int($sourceAfter['size']) &&
			$sourceAfter['size'] === $sourceSize;
		$outputStat = @fstat($output);
		$destinationStable = !is_link($destinationPath) &&
			self::regularFileStatsMatch($outputStat, @lstat($destinationPath), true) &&
			($outputStat['nlink'] ?? 0) === 1;
		$destinationSizeOk = is_array($outputStat) && isset($outputStat['size']) && is_int($outputStat['size']) &&
			$outputStat['size'] === $sourceSize;
		$mode = $permissions;
		if ($mode === null) {
			$mode = isset($sourceBefore['mode']) && is_int($sourceBefore['mode']) ? ($sourceBefore['mode'] & 0777) : 0640;
		}

		@fclose($input);
		@fclose($output);
		if ($copied === false || $copied !== $sourceSize || !$flushed || !$synced || !$sourceStable ||
			!$destinationStable || !$destinationSizeOk) {
			// Remove only the exact directory entry that still represents the file
			// created by this copy. Never unlink a path that was replaced concurrently.
			if ($destinationStable) self::removeOwnedRegularFile($destinationPath, $outputStat);
			return false;
		}
		// Do not report a successful private-file copy when permissions were
		// not applied. Verify the same inode remains at the destination and
		// only remove a file that is still demonstrably ours on failure.
		clearstatcache(true, $destinationPath);
		$preModeStat = @lstat($destinationPath);
		if (!self::regularFileStatsMatch($outputStat, $preModeStat, true) || ($preModeStat['nlink'] ?? 0) !== 1) return false;
		$permissionSet = @chmod($destinationPath, $mode);
		clearstatcache(true, $destinationPath);
		$finalStat = @lstat($destinationPath);
		$stillOwned = !is_link($destinationPath) && self::regularFileStatsMatch($outputStat, $finalStat, true) && ($finalStat['nlink'] ?? 0) === 1;
		$finalMode = $stillOwned ? @fileperms($destinationPath) : false;
		if (!$permissionSet || !$stillOwned || !is_int($finalMode) || ($finalMode & 0777) !== $mode) {
			if ($stillOwned) self::removeOwnedRegularFile($destinationPath, $outputStat);
			return false;
		}
		$createdIdentity = $finalStat;
		return true;
	}


	// Flush a regular file to the filesystem when the runtime supports fsync().
	// This is used after generators write directly to a staging file (e.g. GD/ZIP).
	public static function syncFile($filename)
	{
		if (!is_string($filename) || $filename === '' || strpos($filename, "\0") !== false ||
			!is_file($filename) || is_link($filename)) return false;
		$handle = @fopen($filename, 'r+b');
		if ($handle === false) return false;
		$before = @fstat($handle);
		$pathBefore = @lstat($filename);
		if (is_link($filename) || !self::regularFileStatsMatch($before, $pathBefore, true)) {
			@fclose($handle);
			return false;
		}
		$flushed = @fflush($handle);
		$synced = $flushed;
		if ($flushed && function_exists('fsync')) $synced = @fsync($handle);
		clearstatcache(true, $filename);
		$stable = !is_link($filename) && self::regularFileStatsMatch(@fstat($handle), @lstat($filename), true);
		@fclose($handle);
		return $flushed && $synced && $stable;
	}


	// Create a staging file only inside the requested directory. PHP tempnam()
	// may fall back to the system temporary directory when the target directory
	// is not usable; accepting that fallback can expose private data and breaks
	// the same-filesystem assumption required for atomic rename operations.
	public static function temporaryFile($directory, $prefix = '.brendy-tmp-', $permissions = 0600)
	{
		if (!is_string($directory) || !is_string($prefix) || $directory === '' || $prefix === '' ||
			strlen($prefix) > 64 || preg_match('~[^a-zA-Z0-9_.-]~', $prefix) ||
			strpos($directory, "\0") !== false || !is_int($permissions) || $permissions < 0 || $permissions > 0777 ||
			!is_dir($directory) || is_link($directory) || !is_writable($directory)) return false;
		$parent = realpath($directory);
		$identity = $parent === false ? false : self::directoryIdentity($parent);
		if ($identity === false) return false;
		// Exclusive creation avoids tempnam's fallback and never truncates an existing entry.
		for ($attempt = 0; $attempt < 8; $attempt++) {
			if (self::directoryIdentity($parent) !== $identity) return false;
			$path = $parent . DS . $prefix . bin2hex(random_bytes(16));
			$mask = umask(0077);
			try { $handle = @fopen($path, 'x+b'); }
			finally { umask($mask); }
			if ($handle === false) continue;
			$stat = @fstat($handle);
			$ok = self::directoryIdentity($parent) === $identity && self::openedFileMatchesPath($handle, $path) &&
				($stat['nlink'] ?? 0) === 1 && @chmod($path, $permissions);
			clearstatcache(true, $path);
			$ok = $ok && self::directoryIdentity($parent) === $identity && self::openedFileMatchesPath($handle, $path) &&
				((@lstat($path)['mode'] ?? 0) & 0777) === $permissions;
			$closed = @fclose($handle);
			if ($ok && $closed) return $path;
			if (self::directoryIdentity($parent) === $identity) self::removeOwnedRegularFile($path, $stat);
			return false;
		}
		return false;
	}

	// Publish a completely generated staging file beside its destination. The
	// staging file must live in the same real directory so rename() remains atomic
	// and cannot cross an unexpected mount or symlink boundary. Existing regular
	// destination files may be replaced; symlinks and non-files are rejected.
	public static function publishStagedFile($stagingFile, $destination, $permissions = 0640, $expectedStage = null)
	{
		if (!is_string($stagingFile) || !is_string($destination) || $stagingFile === '' || $destination === '' ||
			strpos($stagingFile . $destination, "\0") !== false || !is_int($permissions) ||
			$permissions < 0 || $permissions > 0777 || !is_file($stagingFile) || is_link($stagingFile) ||
			is_link($destination) || (file_exists($destination) && !is_file($destination))) return false;

		$stageReal = realpath($stagingFile);
		$destinationParent = realpath(dirname($destination));
		if ($stageReal === false || $destinationParent === false || !is_dir($destinationParent) ||
			is_link(dirname($destination)) || dirname($stageReal) !== $destinationParent) return false;
		$destinationPath = $destinationParent . DS . basename($destination);
		$parentIdentity = self::directoryIdentity($destinationParent);
		if ($parentIdentity === false) return false;
		clearstatcache(true, $stageReal);
		$stageStat = @lstat($stageReal);
		if (!is_array($stageStat) || (int)($stageStat['nlink'] ?? 0) !== 1 ||
			($expectedStage !== null && !self::regularFileStatsMatch($expectedStage, $stageStat, true)) ||
			$destinationPath === $stageReal || !self::syncFile($stageReal)) return false;
		clearstatcache(true, $stageReal);
		$afterSync = @lstat($stageReal);
		if (!self::regularFileStatsMatch($stageStat, $afterSync, true) || (int)($afterSync['nlink'] ?? 0) !== 1) return false;
		// Apply the requested mode *before* exposing the finished file. A
		// failed chmod is a failed publication, even if rename would succeed.
		if (self::directoryIdentity($destinationParent) !== $parentIdentity || !@chmod($stageReal, $permissions)) return false;
		clearstatcache(true, $stageReal);
		$finalStage = @lstat($stageReal);
		if (!self::regularFileStatsMatch($stageStat, $finalStage, true) || (int)($finalStage['nlink'] ?? 0) !== 1 || (($finalStage['mode'] ?? 0) & 0777) !== $permissions) return false;
		clearstatcache(true, $destinationPath);
		if (is_link($destinationPath) || (file_exists($destinationPath) && !is_file($destinationPath))) return false;
		if (self::directoryIdentity($destinationParent) !== $parentIdentity || !@rename($stageReal, $destinationPath)) return false;
		clearstatcache(true, $destinationPath);
		$published = @lstat($destinationPath);
		return self::directoryIdentity($destinationParent) === $parentIdentity && !is_link($destinationPath) && self::regularFileStatsMatch($finalStage, $published, true) &&
			($published['nlink'] ?? 0) === 1;
	}

	/** Open a freshly allocated empty staging file without truncating an alias. */
	public static function openEmptyStagingFile($filename)
	{
		if (!is_string($filename) || $filename === '' || strpos($filename, "\0") !== false) return false;
		clearstatcache(true, $filename);
		$before = @lstat($filename);
		if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000 ||
			($before['nlink'] ?? 0) !== 1 || ($before['size'] ?? -1) !== 0) return false;
		$handle = @fopen($filename, 'r+b');
		if ($handle === false) return false;
		clearstatcache(true, $filename);
		$after = @fstat($handle);
		if (!self::regularFileStatsMatch($before, $after, true) || ($after['nlink'] ?? 0) !== 1 ||
			!self::openedFileMatchesPath($handle, $filename, true)) {
			@fclose($handle);
			return false;
		}
		return $handle;
	}

	public static function atomicWrite($filename, $data, $permissions = 0640)
	{
		if (!is_string($filename) || !is_string($data) || $filename === '' || strpos($filename, "\0") !== false ||
			is_link($filename) || (file_exists($filename) && !is_file($filename)) ||
			!is_int($permissions) || $permissions < 0 || $permissions > 0777) return false;
		$directory = dirname($filename);
		if (!is_dir($directory) || is_link($directory)) return false;

		$directory = realpath($directory);
		$parentIdentity = $directory === false ? false : self::directoryIdentity($directory);
		if ($parentIdentity === false) return false;
		$filename = $directory . DS . basename($filename);
		$temp = self::temporaryFile($directory, '.brendy-write-', 0600);
		if ($temp === false || self::directoryIdentity($directory) !== $parentIdentity) return false;
		clearstatcache(true, $temp);
		$allocatedStage = @lstat($temp);
		$handle = self::openEmptyStagingFile($temp);
		if ($handle === false) {
			if (self::directoryIdentity($directory) === $parentIdentity) self::removeOwnedRegularFile($temp, $allocatedStage);
			return false;
		}
		$length = strlen($data);
		$written = 0;
		// Bound each userspace copy. substr($data, $written) duplicated the entire
		// remaining payload on every short write; on large JSON databases that can
		// cause avoidable memory spikes and quadratic copying under I/O pressure.
		$writeChunkBytes = 1048576;
		while ($written < $length) {
			$remaining = $length - $written;
			$buffer = substr($data, $written, min($writeChunkBytes, $remaining));
			if ($buffer === '') {
				$written = -1;
				break;
			}
			$chunk = @fwrite($handle, $buffer);
			if ($chunk === false || $chunk === 0) {
				$written = -1;
				break;
			}
			$written += $chunk;
		}
		$flushed = @fflush($handle);
		clearstatcache(true, $temp);
		$stageStat = @fstat($handle);
		$stable = self::openedFileMatchesPath($handle, $temp, true) && ($stageStat['nlink'] ?? 0) === 1;
		$closed = @fclose($handle);
		if ($written !== $length || !$flushed || !$stable || !$closed) {
			if (self::directoryIdentity($directory) === $parentIdentity) self::removeOwnedRegularFile($temp, $stageStat);
			return false;
		}
		// One durable publication path for hand-written and generator-produced
		// files: sync, permission and atomic replacement rules stay identical.
		if (self::directoryIdentity($directory) !== $parentIdentity || !self::publishStagedFile($temp, $filename, $permissions, $stageStat)) {
			if (self::directoryIdentity($directory) === $parentIdentity) self::removeOwnedRegularFile($temp, $stageStat);
			return false;
		}
		return true;
	}

	public static function rmfile($filename)
	{
		if (!is_string($filename) || $filename === '' || strpos($filename, "\0") !== false ||
			(!is_file($filename) && !is_link($filename))) {
			return false;
		}
		Log::set('rmfile = ' . $filename, LOG_TYPE_INFO);
		return @unlink($filename);
	}

	public static function fileExists($filename)
	{
		return is_string($filename) && $filename !== '' && strpos($filename, "\0") === false && file_exists($filename);
	}

	public static function directoryExists($path)
	{
		// Preserve the root-directory query while rejecting trailing-separator links.
		if ($path === DS) return is_dir($path);
		$path = self::directoryOperationPath($path);
		return $path !== false && !is_link($path) && is_dir($path);
	}

	/** A directory identity is deliberately independent of mtime and link count:
	 * creating a child changes both on normal filesystems. */
	private static function directoryIdentity($path)
	{
		clearstatcache(true);
		if (realpath($path) !== $path) return false;
		$stat = @lstat($path);
		if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 ||
			!isset($stat['dev'], $stat['ino'])) return false;
		return array('dev' => $stat['dev'], 'ino' => $stat['ino']);
	}

	/** Check every tracked ancestor, not just the immediate parent. */
	private static function directoryChainMatches($path, $identities)
	{
		if (!isset($identities[$path])) return false;
		while (isset($identities[$path])) {
			if (self::directoryIdentity($path) !== $identities[$path]) return false;
			$parent = dirname($path);
			if ($parent === $path) break;
			$path = $parent;
		}
		return true;
	}

	private static function rememberDirectory($path, &$identities)
	{
		if (isset($identities[$path])) return self::directoryChainMatches($path, $identities);
		$identity = self::directoryIdentity($path);
		if ($identity === false) return false;
		$identities[$path] = $identity;
		return self::directoryChainMatches($path, $identities);
	}

	/** Only delete entries created by this operation in still-owned directory chains. */
	private static function rollbackDirectoryOperation($files, $created, $identities)
	{
		foreach (array_reverse($files, true) as $file => $identity) {
			if (self::directoryChainMatches(dirname($file), $identities)) self::removeOwnedRegularFile($file, $identity);
		}
		foreach (array_reverse($created, true) as $path => $unused) {
			if (self::directoryChainMatches($path, $identities)) @rmdir($path);
		}
	}

	// Copy recursive a directory to another
	// If the destination directory not exists is created
	// $source = /home/diego/example or /home/diego/example/
	// $destination = /home/diego/newplace or /home/diego/newplace/
	public static function copyRecursive($source, $destination, $skipDirectory = false)
	{
		if (!is_string($source) || !is_string($destination) || strpos($source . $destination, "\0") !== false) return false;
		$sourceInput = self::directoryOperationPath($source);
		$destination = self::directoryOperationPath($destination);
		if ($sourceInput === false || $destination === false || is_link($sourceInput)) return false;
		$source = realpath($sourceInput);
		if ($source === false || !is_dir($source) || is_link($destination)) return false;

		$destinationCreated = false;
		if (!self::directoryExists($destination)) {
			if (!@mkdir($destination, DIR_PERMISSIONS, true)) return false;
			$destinationCreated = true;
		}
		$destinationInput = $destination;
		clearstatcache(true);
		$destination = realpath($destinationInput);
		if ($destination === false || is_link($destinationInput) || !is_dir($destination) || $destination === $source) return false;

		$skipPaths = array();
		$skipCandidates = is_array($skipDirectory) ? $skipDirectory : array($skipDirectory);
		foreach ($skipCandidates as $skipCandidate) {
			if (!is_string($skipCandidate) || $skipCandidate === '') continue;
			$resolvedSkip = realpath(rtrim($skipCandidate, DS));
			if ($resolvedSkip !== false) $skipPaths[] = $resolvedSkip;
		}
		if (strpos($destination, $source . DS) === 0) {
			$destinationAlreadySkipped = false;
			foreach ($skipPaths as $skipPath) {
				if ($destination === $skipPath || strpos($destination, $skipPath . DS) === 0) {
					$destinationAlreadySkipped = true;
					break;
				}
			}
			if (!$destinationAlreadySkipped) $skipPaths[] = $destination;
		}

		$createdFiles = array();
		$createdDirectories = array();
		$sourceIdentities = array();
		$destinationIdentities = array();
		try {
			if (!self::rememberDirectory($source, $sourceIdentities) || !self::rememberDirectory($destination, $destinationIdentities)) throw new RuntimeException('Invalid copy root identity.');
			if ($destinationCreated) $createdDirectories[$destination] = true;
			$directoryIterator = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
			$filterIterator = new RecursiveCallbackFilterIterator(
				$directoryIterator,
				function ($item) use ($skipPaths) {
					if ($item->isLink()) return false;
					$itemPath = $item->getPathname();
					foreach ($skipPaths as $skipPath) {
						if ($itemPath === $skipPath || strpos($itemPath, $skipPath . DS) === 0) return false;
					}
					return true;
				}
			);
			$iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::SELF_FIRST);
			foreach ($iterator as $item) {
				$itemPath = $item->getPathname();
				clearstatcache(true);
				if (realpath($itemPath) !== $itemPath || strpos($itemPath, $source . DS) !== 0 ||
					!self::directoryChainMatches(dirname($itemPath), $sourceIdentities)) {
					throw new RuntimeException('Unsafe recursive copy source.');
				}
				$target = $destination . DS . $iterator->getSubPathName();
				if (!self::directoryChainMatches(dirname($target), $destinationIdentities) || is_link($target)) throw new RuntimeException('Unsafe recursive copy destination.');
				if ($item->isDir()) {
					if (!self::rememberDirectory($itemPath, $sourceIdentities)) throw new RuntimeException('Source directory changed.');
					$created = !is_dir($target);
					if ($created && !@mkdir($target, DIR_PERMISSIONS)) throw new RuntimeException('Unable to create recursive copy directory.');
					if (!self::rememberDirectory($target, $destinationIdentities)) throw new RuntimeException('Destination directory changed.');
					if ($created) $createdDirectories[$target] = true;
					continue;
				}

				$targetParent = realpath(dirname($target));
				if ($targetParent === false ||
					($targetParent !== $destination && strpos($targetParent, $destination . DS) !== 0) ||
					file_exists($target) || is_link($target) || !self::copyFileWithIdentity($itemPath, $target, null, $copyIdentity)) {
					throw new RuntimeException('Unable to copy recursive file.');
				}
				$createdFiles[$target] = $copyIdentity;
				if (!self::directoryChainMatches(dirname($itemPath), $sourceIdentities) ||
					!self::directoryChainMatches(dirname($target), $destinationIdentities)) throw new RuntimeException('Copy directory changed.');
			}
			foreach ($sourceIdentities as $path => $identity) if (self::directoryIdentity($path) !== $identity) throw new RuntimeException('Copy source changed.');
			foreach ($destinationIdentities as $path => $identity) if (self::directoryIdentity($path) !== $identity) throw new RuntimeException('Copy destination changed.');
			return true;
		} catch (Throwable $e) {
			self::rollbackDirectoryOperation($createdFiles, $createdDirectories, $destinationIdentities);
			Log::set(__METHOD__ . LOG_SEP . 'Recursive copy rolled back after failure.', LOG_TYPE_ERROR);
			return false;
		}
	}

	// Delete a file or directory recursive
	// The directory is deleted
	/** Snapshot entry identity without following symlinks; used by destructive operations. */
	private static function entryMatches($path, $expected)
	{
		clearstatcache(true, $path);
		$current = @lstat($path);
		return is_array($expected) && is_array($current) &&
			($current['mode'] & 0170000) === ($expected['mode'] & 0170000) &&
			$current['dev'] === $expected['dev'] && $current['ino'] === $expected['ino'];
	}

	/** Preflight before any mutation; track each directory before entering it.
	 * Limit depth and entry count to fail closed on unreasonable trees. */
	private static function snapshotTree($root, $skipLinks = false)
	{
		$directories = array(); $entries = array();
		if (!self::rememberDirectory(dirname($root), $directories) || !self::rememberDirectory($root, $directories)) return false;
		$device = $directories[$root]['dev']; $visited = 0;
		$walk = static function ($directory, $depth) use (&$walk, &$entries, &$directories, &$visited, $device, $skipLinks) {
			if ($depth > 128 || !self::directoryChainMatches($directory, $directories)) return false;
			foreach (new DirectoryIterator($directory) as $item) {
				if ($item->isDot()) continue;
				if (!self::directoryChainMatches($directory, $directories) || ++$visited > 100000) return false;
				$path = $item->getPathname(); clearstatcache(true, $path); $stat = @lstat($path);
				if (!is_array($stat) || $stat['dev'] !== $device) return false;
				$type = $stat['mode'] & 0170000;
				if ($type === 0120000 && $skipLinks) continue;
				if (!in_array($type, array(0100000, 0040000, 0120000), true)) return false;
				$entries[$path] = array_intersect_key($stat, array_flip(array('mode', 'dev', 'ino', 'size', 'mtime', 'ctime', 'nlink')));
				if ($type === 0040000 && (!self::rememberDirectory($path, $directories) ||
					!self::entryMatches($path, $stat) || !$walk($path, $depth + 1))) return false;
			}
			return self::directoryChainMatches($directory, $directories);
		};
		try { if (!$walk($root, 0)) return false; }
		catch (Throwable $e) { return false; }
		return array('directories' => $directories, 'entries' => $entries);
	}

	private static function deleteTreeSnapshot($root, $snapshot, $deleteDirectory)
	{
		$directories = $snapshot['directories'];
		if (!self::directoryChainMatches($root, $directories)) return false;
		// Check the entire plan before deleting, and recheck each entry at use.
		foreach ($snapshot['entries'] as $path => $stat) {
			if (!self::directoryChainMatches(dirname($path), $directories) || !self::entryMatches($path, $stat)) return false;
		}
		foreach (array_reverse($snapshot['entries'], true) as $path => $stat) {
			if (!self::directoryChainMatches(dirname($path), $directories) || !self::entryMatches($path, $stat)) return false;
			$type = $stat['mode'] & 0170000;
			if ($type === 0040000) {
				if (!self::directoryChainMatches($path, $directories) || !@rmdir($path)) return false;
			} elseif ($type === 0100000 || $type === 0120000) {
				if (!@unlink($path)) return false;
			} else return false;
		}
		if (!self::directoryChainMatches($root, $directories)) return false;
		if ($deleteDirectory) return @rmdir($root);
		$remaining = @scandir($root);
		return $remaining === array('.', '..');
	}

	public static function deleteRecursive($source, $deleteDirectory = true, $expectedRoot = null)
	{
		$source = self::directoryOperationPath($source);
		if ($source === false) return false;
		$parent = realpath(dirname($source));
		if ($parent === false || is_link(dirname($source))) return false;
		$source = rtrim($parent, DS) . DS . basename($source);
		clearstatcache(true, $source); $root = @lstat($source);
		if (!is_array($root)) return false;
        // Optional caller-owned directory identity, checked before any traversal.
        if ($expectedRoot !== null && $expectedRoot !== [$root['dev'],$root['ino'],$root['mode'] & 0170000]) return false;
		if (($root['mode'] & 0170000) === 0120000) return self::entryMatches($source, $root) && @unlink($source);
		if (($root['mode'] & 0170000) !== 0040000 || realpath($source) !== $source || dirname($source) === $source) return false;
		$snapshot = self::snapshotTree($source);
		if ($snapshot === false || !self::entryMatches($source, $root)) return false;
		Log::set('deleteRecursive = ' . $source, LOG_TYPE_INFO);
		return self::deleteTreeSnapshot($source, $snapshot, $deleteDirectory);
	}

	// Shared plain/AES archive writer. Source entries and directory identities
	// are checked both before scheduling libzip reads and after close().
	public static function zip($source, $destination, $password = null)
	{
		if (!extension_loaded('zip') || !is_string($source) || !is_string($destination) ||
			$source === '' || $destination === '' || strpos($source . $destination, "\0") !== false ||
			($password !== null && (!is_string($password) || $password === '' ||
			!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256')))) return false;
		$sourceInput = self::directoryOperationPath($source);
		if ($sourceInput === false || is_link($sourceInput)) return false;
		$source = realpath($sourceInput); $parent = realpath(dirname($destination));
		if ($source === false || $parent === false || dirname($source) === $source || is_link(dirname($destination))) return false;
		$destination = $parent . DS . basename($destination);
		$parentIdentity = self::directoryIdentity($parent);
		if ($parentIdentity === false || $destination === $source || strpos($destination, $source . DS) === 0 ||
			is_link($destination) || (file_exists($destination) && !is_file($destination))) return false;
		if (is_dir($source)) {
			$snapshot = self::snapshotTree($source, true);
			$base = $source;
		} else {
			$base = dirname($source); $dirs = array();
			clearstatcache(true, $source); $stat = @lstat($source);
			$snapshot = self::rememberDirectory($base, $dirs) && is_array($stat) && ($stat['mode'] & 0170000) === 0100000
				? array('directories' => $dirs, 'entries' => array($source => $stat)) : false;
		}
		if ($snapshot === false) return false;
		$total = 0; $names = array();
		foreach ($snapshot['entries'] as $path => $stat) {
			$name = str_replace(DS, '/', substr($path, strlen($base) + 1));
			if ($name === '' || strlen($name) > 4096 || preg_match('//u', $name) !== 1 ||
				preg_match('/[\x00-\x1F\x7F]/', $name) || strpos($name, '\\') !== false) return false;
			$parts = explode('/', $name);
			if (count($parts) > 64 || isset($names[strtolower($name)])) return false;
			$names[strtolower($name)] = true;
			foreach ($parts as $part) {
				$stem = strtolower((string)strtok($part, '.'));
				if ($part === '' || strlen($part) > 255 || strpos($part, ':') !== false || rtrim($part, ' .') !== $part ||
					preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/D', $stem)) return false;
			}
			if (($stat['mode'] & 0170000) === 0100000) {
				$size = $stat['size'];
				if ($size < 0 || $size > 1073741824 - $total) return false;
				$total += $size;
			}
		}
		// libzip may replace its output inode on close. Isolate its output in
		// a private directory so cleanup never needs to trust a public temp name.
		$private = $parent . DS . '.brendy-zip-' . bin2hex(random_bytes(16));
		if (self::directoryIdentity($parent) !== $parentIdentity || !@mkdir($private, 0700)) return false;
		$privateIdentity = self::directoryIdentity($private);
		$archive = $private . DS . 'archive.zip'; $stage = false; $stageIdentity = null;
		try {
			if ($privateIdentity === false || ((@fileperms($private) ?: 0) & 0777) !== 0700) return false;
			$zip = new ZipArchive();
			if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) return false;
			$ok = $password === null || $zip->setPassword($password);
			try {
				foreach ($snapshot['entries'] as $path => $stat) {
					if (!$ok || !self::directoryChainMatches(dirname($path), $snapshot['directories']) || !self::entryMatches($path, $stat)) { $ok = false; break; }
					$name = str_replace(DS, '/', substr($path, strlen($base) + 1));
					if ($name === '' || strpos($name, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $name)) { $ok = false; break; }
					if (($stat['mode'] & 0170000) === 0040000) { $ok = $zip->addEmptyDir($name); }
					else {
						$flags = ZipArchive::FL_OVERWRITE | (defined('ZipArchive::FL_OPEN_FILE_NOW') ? ZipArchive::FL_OPEN_FILE_NOW : 0);
						$ok = self::unchangedReadSnapshot($stat, @lstat($path)) && $zip->addFile($path, $name, 0, 0, $flags);
						if ($ok && $stat['size'] > 1048576) $ok = $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 1);
						if ($ok && $password !== null) $ok = $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
					}
					if (!$ok) break;
				}
			} catch (Throwable $e) { $ok = false; }
			$closed = $zip->close();
			if (!$ok || !$closed || self::directoryIdentity($private) !== $privateIdentity || self::directoryIdentity($parent) !== $parentIdentity) return false;
			// Re-enumerate to detect new/removed/replaced entries, and compare file metadata.
			if (is_dir($source)) {
				$after = self::snapshotTree($source, true);
				if ($after === false || $after['directories'] != $snapshot['directories'] || array_diff_key($after['entries'], $snapshot['entries']) || array_diff_key($snapshot['entries'], $after['entries'])) return false;
			}
			foreach ($snapshot['entries'] as $path => $stat) {
				if (!self::directoryChainMatches(dirname($path), $snapshot['directories']) || !self::entryMatches($path, $stat) ||
					(($stat['mode'] & 0170000) === 0100000 && !self::unchangedReadSnapshot($stat, @lstat($path)))) return false;
			}
			// Exclusive copy to the final directory, then durable atomic publication.
			$stage = $parent . DS . '.brendy-zip-publish-' . bin2hex(random_bytes(16));
			if (!self::copyFileWithIdentity($archive, $stage, 0600, $stageIdentity)) return false;
			return self::directoryIdentity($parent) === $parentIdentity && self::publishStagedFile($stage, $destination, $password === null ? 0640 : 0600, $stageIdentity);
		} catch (Throwable $e) { return false; }
		finally {
			if (self::directoryIdentity($parent) === $parentIdentity) {
				if ($stage !== false && $stageIdentity !== null) self::removeOwnedRegularFile($stage, $stageIdentity);
				if ($privateIdentity !== false && self::directoryIdentity($private) === $privateIdentity) self::deleteRecursive($private);
			}
		}
	}

	// Uncompress a ZIP file with complete pre-validation and extraction rollback.
	// Existing destination content is never overwritten. Files and directories
	// created by a failed extraction are removed before returning FALSE.
	public static function unzip($source, $destination, $password = null, $maxBytes = 536870912, $maxEntries = 10000)
	{
		if (!extension_loaded('zip')) return false;
		$destination = self::directoryOperationPath($destination);
		if (!is_string($source) || $destination === false || !is_file($source) || is_link($source) ||
			strpos($source . $destination, "\0") !== false) return false;
		if (($password !== null && !is_string($password)) || (is_string($password) && strlen($password) > 1024) ||
			!is_scalar($maxBytes) || !is_scalar($maxEntries)) return false;
		$maxBytes = max(1, min(1073741824, (int)$maxBytes));
		$maxEntries = max(1, min(100000, (int)$maxEntries));

		$zip = new ZipArchive();
		if ($zip->open($source) !== true) return false;
		$createdFiles = array();
		$createdDirectories = array();
		$destinationCreated = false;
		$directoryIdentities = array();
		try {
			if ($password !== null && $password !== '') $zip->setPassword($password);
			if ($zip->numFiles > $maxEntries) throw new RuntimeException('ZIP entry limit exceeded.');

			if (is_link($destination)) throw new RuntimeException('Unsafe ZIP destination.');
			if (!is_dir($destination)) {
				if (file_exists($destination) || !mkdir($destination, DIR_PERMISSIONS, true)) {
					throw new RuntimeException('Unable to create ZIP destination.');
				}
				$destinationCreated = true;
			}
			$basePath = realpath($destination);
			if ($basePath === false || is_link($destination) || !self::rememberDirectory($basePath, $directoryIdentities)) throw new RuntimeException('Invalid ZIP destination.');
			if ($destinationCreated) $createdDirectories[$basePath] = true;

			$entries = array();
			$seenEntries = array();
			$totalBytes = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$stat = $zip->statIndex($index);
				if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
					throw new RuntimeException('Invalid ZIP entry metadata.');
				}
				$name = str_replace('\\', '/', (string)$stat['name']);
				if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || strlen($name) > 4096 ||
					preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || preg_match('/^[a-zA-Z]:/', $name) === 1 ||
					preg_match('//u', $name) !== 1) {
					throw new RuntimeException('Unsafe ZIP entry path.');
				}

				$isDirectory = substr($name, -1) === '/';
				$parts = explode('/', rtrim($name, '/'));
				if (count($parts) > 64) throw new RuntimeException('ZIP path depth limit exceeded.');
				$safeParts = array();
				foreach ($parts as $part) {
					$stem = strtolower((string)strtok($part, '.'));
					if ($part === '' || $part === '..' || strlen($part) > 255 || strpos($part, ':') !== false ||
						rtrim($part, " .") !== $part || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/D', $stem) === 1) {
						throw new RuntimeException('Unsafe ZIP path segment.');
					}
					if ($part !== '.') $safeParts[] = $part;
				}
				if (empty($safeParts)) throw new RuntimeException('Empty ZIP path.');
				$seenKey = strtolower(implode('/', $safeParts));
				if (isset($seenEntries[$seenKey])) throw new RuntimeException('Duplicate ZIP entry.');
				$seenEntries[$seenKey] = true;

				if (method_exists($zip, 'getExternalAttributesIndex')) {
					$operatingSystem = 0;
					$attributes = 0;
					if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
						$unixSystem = defined('ZipArchive::OPSYS_UNIX') ? ZipArchive::OPSYS_UNIX : 3;
						if ($operatingSystem === $unixSystem) {
							$fileType = ($attributes >> 16) & 0170000;
							if ($fileType !== 0 && $fileType !== 0100000 && $fileType !== 0040000) {
								throw new RuntimeException('Special ZIP entries are not allowed.');
							}
							$isDirectory = $isDirectory || $fileType === 0040000;
						}
					}
				}

				$size = (int)$stat['size'];
				if ($size < 0 || $size > $maxBytes || $totalBytes > ($maxBytes - $size)) {
					throw new RuntimeException('ZIP size limit exceeded.');
				}
				$totalBytes += $size;
				if (!$isDirectory && isset($stat['comp_size']) && $size > 1048576) {
					$compressedSize = (int)$stat['comp_size'];
					if ($compressedSize <= 0 || ($size / $compressedSize) > 500) {
						throw new RuntimeException('Suspicious ZIP compression ratio.');
					}
				}
				$entries[] = array(
					'index' => $index,
					'name' => (string)$stat['name'],
					'relative' => implode(DS, $safeParts),
					'directory' => $isDirectory,
					'size' => $size
				);
			}

			foreach ($entries as $entry) {
				$target = $basePath . DS . $entry['relative'];
				if ($entry['directory']) {
					if (!self::ensureExtractDirectory($basePath, $entry['relative'], $createdDirectories, $directoryIdentities)) {
						throw new RuntimeException('Unable to create ZIP directory.');
					}
					continue;
				}

				$relativeParent = dirname($entry['relative']);
				if (!self::ensureExtractDirectory($basePath, $relativeParent === '.' ? '' : $relativeParent, $createdDirectories, $directoryIdentities)) {
					throw new RuntimeException('Unable to create ZIP parent directory.');
				}
				$parent = dirname($target);
				$realParent = realpath($parent);
				if (!self::directoryChainMatches($parent, $directoryIdentities) || $realParent === false || ($realParent !== $basePath && strpos($realParent, $basePath . DS) !== 0) ||
					file_exists($target) || is_link($target)) {
					throw new RuntimeException('Unsafe ZIP extraction target.');
				}

				$input = $zip->getStream($entry['name']);
				$previousMask = umask(0077);
				try { $output = is_resource($input) ? @fopen($target, 'xb') : false; }
				finally { umask($previousMask); }
				if ($input === false || $output === false) {
					if (is_resource($input)) fclose($input);
					if (is_resource($output)) fclose($output);
					throw new RuntimeException('Unable to open ZIP stream.');
				}
				$createdFiles[$target] = @fstat($output);
				if (!self::directoryChainMatches($parent, $directoryIdentities) || !self::openedFileMatchesPath($output, $target) || ($createdFiles[$target]['nlink'] ?? 0) !== 1) {
					fclose($input); fclose($output);
					throw new RuntimeException('ZIP target identity changed.');
				}
				$copied = stream_copy_to_stream($input, $output, $entry['size'] + 1);
				fclose($input);
				$flushed = ($copied === $entry['size']) && @fflush($output);
				$synced = $flushed;
				if ($flushed && function_exists('fsync')) $synced = @fsync($output);
				$identity = @fstat($output);
				$stable = self::directoryChainMatches($parent, $directoryIdentities) && self::openedFileMatchesPath($output, $target) && ($identity['nlink'] ?? 0) === 1;
				fclose($output);
				if ($copied !== $entry['size'] || !$flushed || !$synced || !$stable) {
					throw new RuntimeException('Incomplete ZIP extraction write.');
				}
				clearstatcache(true, $target);
				if (!self::directoryChainMatches($parent, $directoryIdentities) || !self::regularFileStatsMatch($identity, @lstat($target), true) || !@chmod($target, 0644)) throw new RuntimeException('ZIP target permission change failed.');
				clearstatcache(true, $target);
				$final = @lstat($target);
				if (!self::directoryChainMatches($parent, $directoryIdentities) || !self::regularFileStatsMatch($identity, $final, true) || ($final['nlink'] ?? 0) !== 1 || (($final['mode'] ?? 0) & 0777) !== 0644) throw new RuntimeException('ZIP target changed.');
			}
			foreach ($directoryIdentities as $path => $identity) if (self::directoryIdentity($path) !== $identity) throw new RuntimeException('ZIP directory changed.');
		} catch (Throwable $e) {
			$zip->close();
			self::rollbackDirectoryOperation($createdFiles, $createdDirectories, $directoryIdentities);
			return false;
		}

		if (!$zip->close()) {
			self::rollbackDirectoryOperation($createdFiles, $createdDirectories, $directoryIdentities);
			return false;
		}
		return true;
	}

	private static function ensureExtractDirectory($basePath, $relative, &$createdDirectories, &$identities)
	{
		$current = $basePath;
		if (!self::directoryChainMatches($current, $identities)) return false;
		if ($relative === '') return true;
		foreach (explode(DS, $relative) as $part) {
			$current .= DS . $part;
			if (!self::directoryChainMatches(dirname($current), $identities) || is_link($current) || (file_exists($current) && !is_dir($current))) return false;
			$created = !is_dir($current);
			if ($created && !@mkdir($current, DIR_PERMISSIONS)) return false;
			if (!self::rememberDirectory($current, $identities)) return false;
			if ($created) $createdDirectories[$current] = true;
		}
		return true;
	}

	/*
  | Returns the next filename if the filename already exist otherwise returns the original filename
  |
  | @path	string	Path
  | @filename	string	Filename
  |
  | @return	string
  */
	public static function nextFilename($filename, $path = PATH_UPLOADS)
	{
		if (!is_string($filename) || !is_string($path) || strlen($filename) > 255 ||
			preg_match('/[\x00-\x1F\x7F]/', $filename . $path) === 1 ||
			basename($filename) !== $filename || !is_dir($path) || is_link($path)) return false;
		$path = rtrim($path, "/\\") . DS;
		// Clean filename and get extension
		$fileExtension 	= pathinfo($filename, PATHINFO_EXTENSION);
		$fileExtension 	= Text::lowercase($fileExtension);
		$filename 	= pathinfo($filename, PATHINFO_FILENAME);
		$filename 	= Text::removeSpaces($filename);
		$filename 	= Text::removeQuotes($filename);
		$filename = mb_substr($filename, 0, 200, CHARSET);
		if ($filename === '' || strlen($fileExtension) > 20) return false;

		// Search for the next filename
		$tmpName = $filename . '.' . $fileExtension;
		if (file_exists($path . $tmpName) || is_link($path . $tmpName)) {
			$number = 0;
			$tmpName = $filename . '_' . $number . '.' . $fileExtension;
			while (file_exists($path . $tmpName) || is_link($path . $tmpName)) {
				$number = $number + 1;
				if ($number > 100000) return false;
				$tmpName = $filename . '_' . $number . '.' . $fileExtension;
			}
		}
		return $tmpName;
	}

	/*
  | Returns the filename
  | Example:
  |	@file	/home/diego/dog.jpg
  |	@return dog.jpg
  |
  | @file	string	Full path of the file
  |
  | @return	string
  */
	public static function filename($file)
	{
		return is_string($file) && strpos($file, "\0") === false ? basename($file) : '';
	}

	/*
  | Returns the file extension
  | Example:
  |	@file	/home/diego/dog.jpg
  |	@return jpg
  |
  | @file	string	Full path of the file
  |
  | @return	string
  */
	public static function extension($file)
	{
		return is_string($file) && strpos($file, "\0") === false ? pathinfo($file, PATHINFO_EXTENSION) : '';
	}

	/**
	 * Get Size of file or directory in bytes
	 * @param  [string] $fileOrDirectory
	 * @return [int|bool]                  [bytes or false on error]
	 */
	public static function getSize($fileOrDirectory)
	{
		if ($fileOrDirectory !== DS) $fileOrDirectory = self::directoryOperationPath($fileOrDirectory);
		if ($fileOrDirectory === false || is_link($fileOrDirectory)) return false;
		// Files
		if (is_file($fileOrDirectory)) {
			$size = @filesize($fileOrDirectory);
			return $size === false ? false : $size;
		}
		// Directories
		if (is_dir($fileOrDirectory)) {
			$size = 0;
			$entries = 0;
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($fileOrDirectory, FilesystemIterator::SKIP_DOTS),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ($iterator as $file) {
					if (++$entries > 1000000) return false;
					if ($file->isLink()) continue;
					if (!$file->isFile()) return false;
					$entrySize = $file->getSize();
					if (!is_int($entrySize) || $entrySize < 0 || $size > PHP_INT_MAX - $entrySize) return false;
					$size += $entrySize;
				}
			} catch (Throwable $e) {
				// Iterator construction and traversal can both throw for unreadable or
				// concurrently removed paths. A partial size must never escape as valid.
				return false;
			}
			return $size;
		}
		return false;
	}

	// Validate a content/restore snapshot as untrusted filesystem input. This
	// deliberately rejects symlinks, special files, nested web-server control
	// files and executable-looking payloads outside the JSON database tree.
	// PHP files inside databases are accepted only when they parse as Brendy JSON
	// database files with the mandatory direct-access guard.
	public static function validateContentSnapshot($source, $maxBytes = 1073741824, $maxFiles = 100000)
	{
		if (!is_string($source) || $source === '' || strpos($source, "\0") !== false ||
			!is_dir($source) || is_link($source) || !is_scalar($maxBytes) || !is_scalar($maxFiles)) return false;
		$root = realpath($source);
		if ($root === false || !is_dir($root)) return false;
		$maxBytes = max(1, min(1073741824, (int)$maxBytes));
		$maxFiles = max(1, min(100000, (int)$maxFiles));
		$allowedProtectionFiles = array(
			'databases/.htaccess', 'pages/.htaccess', 'uploads/.htaccess',
			'workspaces/.htaccess', 'revisions/.htaccess'
		);
		$fileCount = 0;
		$totalBytes = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ($iterator as $entry) {
				if ($entry->isLink()) return false;
				$path = $entry->getPathname();
				$real = $entry->getRealPath();
				if ($real === false || ($real !== $root && strpos($real, $root . DS) !== 0)) return false;
				$relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
				if ($relative === '' || strlen($relative) > 4096 || strpos($relative, "\0") !== false ||
					preg_match('/[\x00-\x1F\x7F]/', $relative) === 1) return false;
				if ($entry->isDir()) continue;
				if (!$entry->isFile()) return false;
				$fileCount++;
				if ($fileCount > $maxFiles) return false;
				$size = $entry->getSize();
				if (!is_int($size) || $size < 0 || $size > $maxBytes || $totalBytes > ($maxBytes - $size)) return false;
				$totalBytes += $size;

				$basename = basename($path);
				if (function_exists('brendyIsServerControlFilename') && brendyIsServerControlFilename($basename) &&
					!in_array($relative, $allowedProtectionFiles, true)) return false;
				if (function_exists('brendyHasDangerousExecutableFilename') && brendyHasDangerousExecutableFilename($basename)) {
					if (strpos($relative, 'databases/') !== 0 || !class_exists('BrendyJsonDatabase', false)) return false;
					$check = BrendyJsonDatabase::inspectFile($path, true, min($maxBytes, 134217728));
					if (!is_array($check) || empty($check['ok'])) return false;
				}
			}
		} catch (Throwable $e) {
			return false;
		}
		return true;
	}

	// Compute a deterministic digest for a directory tree without following
	// symlinks. The digest covers each relative path, file size and SHA-256 file
	// digest in byte-sorted path order, so backup verification can detect both
	// content changes and file replacement/renaming while remaining independent
	// of filesystem iteration order and timestamps.
	public static function treeDigest($source, $maxBytes = 1073741824, $maxFiles = 100000)
	{
		if (!is_string($source) || $source === '' || strpos($source, "\0") !== false ||
			!is_dir($source) || is_link($source) || !is_scalar($maxBytes) || !is_scalar($maxFiles)) return false;
		$root = realpath($source);
		if ($root === false || !is_dir($root)) return false;
		$maxBytes = max(1, min(PHP_INT_MAX, (int)$maxBytes));
		$maxFiles = max(1, min(1000000, (int)$maxFiles));
		$files = array();
		$totalBytes = 0;
		$visited = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ($iterator as $entry) {
				if (++$visited > 1000000 || $iterator->getDepth() > 128 || $entry->isLink()) return false;
				$path = $entry->getPathname();
				$real = $entry->getRealPath();
				if ($real === false || ($real !== $root && strpos($real, $root . DS) !== 0)) return false;
				$relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
				if ($relative === '' || strlen($relative) > 4096 || strpos($relative, "\0") !== false ||
					preg_match('/[\x00-\x1F\x7F]/', $relative) === 1) return false;
				if ($entry->isDir()) continue;
				if (!$entry->isFile() || count($files) >= $maxFiles) return false;
				$size = $entry->getSize();
				if (!is_int($size) || $size < 0 || $size > $maxBytes || $totalBytes > ($maxBytes - $size)) return false;
				$totalBytes += $size;
				$files[$relative] = array('path' => $real, 'size' => $size);
			}
		} catch (Throwable $e) {
			return false;
		}
		uksort($files, 'strcmp');
		$context = hash_init('sha256');
		foreach ($files as $relative => $entry) {
			$fileHash = self::hashFileBounded($entry['path'], 'sha256', $maxBytes, 0, $entry['size']);
			if (!is_string($fileHash) || preg_match('/^[a-f0-9]{64}$/D', $fileHash) !== 1) return false;
			hash_update($context, strlen($relative) . ':' . $relative . ':' . $entry['size'] . ':' . $fileHash . "\n");
		}
		return array(
			'algorithm' => 'sha256-tree-v1',
			'hash' => hash_final($context),
			'files' => count($files),
			'bytes' => $totalBytes
		);
	}

	public static function bytesToHumanFileSize($bytes, $decimals = 2)
	{
		if (!is_numeric($bytes) || (float)$bytes < 0) return '0 B';
		$bytes = (float)$bytes;
		$decimals = max(0, min(6, (int)$decimals));
		$size = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$factor = $bytes > 0 ? min(count($size) - 1, (int)floor(log($bytes, 1024))) : 0;
		return sprintf("%.{$decimals}f ", $bytes / pow(1024, $factor)) . $size[$factor];
	}

	/*
  | Returns the mime type of the file
  | Example:
  |	@file	/home/diego/dog.jpg
  |	@return image/jpeg
  |
  | @file	[string]	Full path of the file
  |
  | @return	[string|bool]	Mime type as string or FALSE if not possible to get the mime type
  */
	public static function mimeType($file)
	{
		if (!is_string($file) || $file === '' || strpos($file, "\0") !== false ||
			!is_file($file) || is_link($file)) return false;
		if (function_exists('mime_content_type')) {
			$mimeType = @mime_content_type($file);
			return is_string($mimeType) && $mimeType !== '' ? $mimeType : false;
		}

		if (function_exists('finfo_open') && function_exists('finfo_file')) {
			$fileinfo = @finfo_open(FILEINFO_MIME_TYPE);
			if ($fileinfo === false) return false;
			$mimeType = @finfo_file($fileinfo, $file);
			finfo_close($fileinfo);
			return is_string($mimeType) && $mimeType !== '' ? $mimeType : false;
		}

		return false;
	}

	public static function symlink($from, $to)
	{
		if (!is_string($from) || !is_string($to) || $from === '' || $to === '' ||
			strpos($from . $to, "\0") !== false || is_link($from) || file_exists($to) || is_link($to) ||
			!is_dir(dirname($to)) || is_link(dirname($to))) return false;
		$from = realpath($from);
		$parent = realpath(dirname($to));
		if ($from === false || $parent === false) return false;
		$to = $parent . DS . basename($to);
		if (function_exists('symlink')) {
			Log::set('symlink from = ' . $from . ' to = ' . $to, LOG_TYPE_INFO);
			return @symlink($from, $to);
		} elseif (is_file($from)) {
			return self::copyFileSafely($from, $to);
		}
		return false;
	}
}
