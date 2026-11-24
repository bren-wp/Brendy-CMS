<?php defined('BRENDY') or die('Brendy CMS.');

class TCP {

	public static function http($url, $method='GET', $verifySSL=true, $timeOut=10, $followRedirections=true, $binary=true, $headers=false)
	{
		// Compatibility wrapper. Brendy intentionally ignores requests to disable
		// TLS verification or enable redirects: those options would weaken the
		// SSRF/TLS boundary. Existing callers still receive the response body.
		$result = self::request($url, array(
			'method' => $method,
			'timeout' => $timeOut,
			'maxBytes' => 10485760,
			'includeHeaders' => ($headers === true)
		));
		return is_array($result) && isset($result['body']) && is_string($result['body']) ? $result['body'] : false;
	}

	/**
	 * Bounded HTTPS request with DNS pinning. Redirects are never followed: every
	 * target would require a fresh public-address validation to remain SSRF-safe.
	 */
	public static function request($url, $options=array())
	{
		if (!is_array($options) || !function_exists('curl_init')) return false;
		$resolved = self::resolvePublicHttpsUrl($url);
		if ($resolved === false) return false;

		$method = isset($options['method']) && is_string($options['method'])
			? strtoupper(trim($options['method'])) : 'GET';
		if (!in_array($method, array('GET', 'POST'), true)) return false;
		$timeout = isset($options['timeout']) ? max(1, min(30, (int)$options['timeout'])) : 10;
		$maxBytes = isset($options['maxBytes']) ? max(1024, min(10485760, (int)$options['maxBytes'])) : 10485760;
		$includeHeaders = isset($options['includeHeaders']) && $options['includeHeaders'] === true;
		$body = isset($options['body']) ? $options['body'] : null;
		if ($body !== null && (!is_string($body) || strlen($body) > 1048576)) return false;
		$contentType = isset($options['contentType']) && is_string($options['contentType']) ? trim($options['contentType']) : '';
		$allowedContentTypes = array('application/x-www-form-urlencoded', 'application/json');
		if ($contentType !== '' && !in_array($contentType, $allowedContentTypes, true)) return false;
		if ($method === 'GET' && $body !== null && $body !== '') return false;

		$output = '';
		$ch = curl_init();
		if ($ch === false) return false;
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, $includeHeaders);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$output, $maxBytes) {
			$length = strlen($data);
			if (strlen($output) + $length > $maxBytes) return 0;
			$output .= $data;
			return $length;
		});
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		// A proxy can resolve the target independently and defeat CURLOPT_RESOLVE.
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Brendy-CMS/' . (defined('BRENDY_VERSION') ? BRENDY_VERSION : '1'));
		if ($resolved['resolve'] !== null) curl_setopt($ch, CURLOPT_RESOLVE, array($resolved['resolve']));
		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		if ($contentType !== '') curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $contentType));

		$success = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = $success === false ? curl_error($ch) : '';
		curl_close($ch);
		if ($success === false || $status < 200 || $status >= 300) {
			Log::set(__METHOD__ . LOG_SEP . 'HTTPS request failed' . ($error !== '' ? ': ' . substr($error, 0, 300) : ''), LOG_TYPE_WARN);
			return false;
		}
		return array('status' => $status, 'body' => $output);
	}

	/**
	 * Send a small application/x-www-form-urlencoded POST through the same
	 * DNS-pinned HTTPS boundary used by other remote Brendy operations.
	 */
	public static function postForm($url, $fields, $timeout=10, $maxBytes=1048576)
	{
		if (!is_array($fields) || count($fields) > 50) return false;
		$normalized = array();
		foreach ($fields as $key => $value) {
			if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $key) !== 1 ||
				(!is_string($value) && !is_numeric($value))) return false;
			$value = (string)$value;
			if (strlen($value) > 8192 || strpos($value, "\0") !== false) return false;
			$normalized[$key] = $value;
		}
		$body = http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
		$result = self::request($url, array(
			'method' => 'POST',
			'timeout' => $timeout,
			'maxBytes' => $maxBytes,
			'contentType' => 'application/x-www-form-urlencoded',
			'body' => $body
		));
		return is_array($result) && isset($result['body']) && is_string($result['body']) ? $result['body'] : false;
	}

	public static function download($url, $destination)
	{
		// Remote imports are security-sensitive. Require cURL so DNS can be
		// resolved, checked and pinned before the request (SSRF/DNS-rebinding).
		if (!function_exists('curl_init')) {
			return false;
		}
		$resolved = self::resolvePublicHttpsUrl($url);
		if ($resolved === false) {
			return false;
		}

		if (!is_string($destination) || $destination === '' || strpos($destination, "\0") !== false ||
			is_link($destination) || (file_exists($destination) && !is_file($destination))) return false;
		$directory = dirname($destination);
		if ((!is_dir($directory) && !mkdir($directory, DIR_PERMISSIONS, true)) || is_link($directory)) {
			return false;
		}
		$temporary = Filesystem::temporaryFile($directory, '.brendy-download-', 0600);
		if ($temporary === false) return false;
		$output = Filesystem::openEmptyStagingFile($temporary);
		if ($output === false) {
			@unlink($temporary);
			return false;
		}

		$bytes = 0;
		$maxBytes = 268435456; // 256 MiB compressed download limit.
		$ch = curl_init();
		if ($ch === false) {
			@fclose($output);
			@unlink($temporary);
			return false;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		// A proxy can resolve the target independently and defeat CURLOPT_RESOLVE.
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Brendy-CMS/' . (defined('BRENDY_VERSION') ? BRENDY_VERSION : '1'));
		if ($resolved['resolve'] !== null) curl_setopt($ch, CURLOPT_RESOLVE, array($resolved['resolve']));
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use ($output, &$bytes, $maxBytes) {
			$length = strlen($data);
			$bytes += $length;
			if ($bytes > $maxBytes) {
				return 0;
			}
			return fwrite($output, $data);
		});
		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}

		$success = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$flushed = @fflush($output);
		clearstatcache(true, $temporary);
		$stageStat = @fstat($output);
		$stable = Filesystem::openedFileMatchesPath($output, $temporary, true) && ($stageStat['nlink'] ?? 0) === 1;
		$closed = @fclose($output);

		if ($success === false || $status < 200 || $status >= 300 || $bytes === 0 || !$flushed || !$stable || !$closed) {
			@unlink($temporary);
			return false;
		}
		if (!Filesystem::publishStagedFile($temporary, $destination, 0600, $stageStat)) {
			if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
			return false;
		}
		return $bytes;
	}

	// Public destination policy: IPv6 translation/local-use prefixes are not
	// suitable for SSRF pinning, even when PHP labels them globally reachable.
	private static function isPublicAddress($address)
	{
		if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return false;
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return true;
		$binary = @inet_pton($address);
		// Restrict IPv6 to the currently allocated global-unicast range 2000::/3.
		return is_string($binary) && strlen($binary) === 16 && (ord($binary[0]) & 0xe0) === 0x20;
	}

	private static function isDnsHostname($host)
	{
		// A numeric final label or a single label can be reinterpreted by URL
		// clients as an alternative IPv4 literal or expanded by a DNS search suffix.
		return is_string($host) && strlen($host) <= 253 &&
			preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $host) === 1;
	}

	private static function resolvePublicHttpsUrl($url)
	{
		if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F]/', $url) ||
			!filter_var($url, FILTER_VALIDATE_URL)) return false;
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
			strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}
		$port = isset($parts['port']) ? (int)$parts['port'] : 443;
		if ($port !== 443) {
			return false;
		}

		$host = strtolower($parts['host']);
		// parse_url preserves the square brackets of an IPv6 URL literal.
		$ipHost = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;
		$isIp = filter_var($ipHost, FILTER_VALIDATE_IP) !== false;
		if (!$isIp && !self::isDnsHostname($host)) return false;
		if ($isIp && !self::isPublicAddress($ipHost)) return false;
		$addresses = array();
		if ($isIp) {
			$addresses[] = $ipHost;
		} elseif (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					if (isset($record['ip'])) $addresses[] = $record['ip'];
					if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
				}
			}
		}
		if (empty($addresses)) {
			return false;
		}

		$publicAddress = false;
		foreach (array_unique($addresses) as $address) {
			if (self::isPublicAddress($address)) {
				$publicAddress = $address;
				break;
			}
		}
		if ($publicAddress === false) {
			return false;
		}

		$resolveAddress = strpos($publicAddress, ':') !== false ? '[' . $publicAddress . ']' : $publicAddress;
		return array('resolve' => $isIp ? null : $host . ':' . $port . ':' . $resolveAddress);
	}

}
