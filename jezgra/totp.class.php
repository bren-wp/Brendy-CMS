<?php defined('BRENDY') or die('Brendy CMS.');

/**
 * Local RFC 6238 TOTP implementation used by Brendy two-factor authentication.
 * No remote service or external API is required.
 */
class Totp
{
	const PERIOD = 30;
	const DIGITS = 6;

	private static function base32Alphabet()
	{
		return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	}

	public static function generateSecret($bytes = 20)
	{
		$bytes = max(20, min(64, (int)$bytes));
		return self::base32Encode(random_bytes($bytes));
	}

	public static function base32Encode($binary)
	{
		if (!is_string($binary) || $binary === '') return false;
		$alphabet = self::base32Alphabet();
		$buffer = 0;
		$bitsLeft = 0;
		$output = '';
		$length = strlen($binary);
		for ($i = 0; $i < $length; $i++) {
			$buffer = ($buffer << 8) | ord($binary[$i]);
			$bitsLeft += 8;
			while ($bitsLeft >= 5) {
				$bitsLeft -= 5;
				$output .= $alphabet[($buffer >> $bitsLeft) & 31];
			}
			// Keep only the unconsumed low bits so the integer never grows large.
			if ($bitsLeft > 0) $buffer &= (1 << $bitsLeft) - 1;
			else $buffer = 0;
		}
		if ($bitsLeft > 0) $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
		return $output;
	}

	public static function base32Decode($secret)
	{
		if (!is_string($secret)) return false;
		$secret = strtoupper(str_replace(array(' ', '-', '='), '', trim($secret)));
		if ($secret === '' || strlen($secret) > 256 || preg_match('/^[A-Z2-7]+$/D', $secret) !== 1) return false;
		$alphabet = self::base32Alphabet();
		$map = array_flip(str_split($alphabet));
		$buffer = 0;
		$bitsLeft = 0;
		$output = '';
		$length = strlen($secret);
		for ($i = 0; $i < $length; $i++) {
			if (!isset($map[$secret[$i]])) return false;
			$buffer = ($buffer << 5) | $map[$secret[$i]];
			$bitsLeft += 5;
			if ($bitsLeft >= 8) {
				$bitsLeft -= 8;
				$output .= chr(($buffer >> $bitsLeft) & 0xff);
			}
			if ($bitsLeft > 0) $buffer &= (1 << $bitsLeft) - 1;
			else $buffer = 0;
		}
		return $output === '' ? false : $output;
	}

	public static function codeForCounter($secret, $counter)
	{
		$key = self::base32Decode($secret);
		$counter = (int)$counter;
		if ($key === false || $counter < 0) return false;
		$high = intdiv($counter, 4294967296);
		$low = $counter % 4294967296;
		$binaryCounter = pack('N2', $high, $low);
		$hash = hash_hmac('sha1', $binaryCounter, $key, true);
		if (!is_string($hash) || strlen($hash) !== 20) return false;
		$offset = ord($hash[19]) & 0x0f;
		$value = ((ord($hash[$offset]) & 0x7f) << 24) |
			((ord($hash[$offset + 1]) & 0xff) << 16) |
			((ord($hash[$offset + 2]) & 0xff) << 8) |
			(ord($hash[$offset + 3]) & 0xff);
		$modulus = 10 ** self::DIGITS;
		return str_pad((string)($value % $modulus), self::DIGITS, '0', STR_PAD_LEFT);
	}

	/**
	 * Return the matched moving counter or FALSE. A small +/- one-step clock
	 * window tolerates ordinary clock drift without making codes long-lived.
	 */
	public static function verify($secret, $code, $timestamp = null, $window = 1)
	{
		if (!is_string($code)) return false;
		$code = preg_replace('/\s+/', '', trim($code));
		if (!is_string($code) || preg_match('/^[0-9]{6}$/D', $code) !== 1) return false;
		$timestamp = $timestamp === null ? time() : (int)$timestamp;
		if ($timestamp < 0) return false;
		$window = max(0, min(2, (int)$window));
		$current = intdiv($timestamp, self::PERIOD);
		for ($offset = -$window; $offset <= $window; $offset++) {
			$counter = $current + $offset;
			if ($counter < 0) continue;
			$expected = self::codeForCounter($secret, $counter);
			if (is_string($expected) && hash_equals($expected, $code)) return $counter;
		}
		return false;
	}

	public static function provisioningUri($secret, $account, $issuer = 'Brendy CMS')
	{
		if (!is_string($secret) || self::base32Decode($secret) === false) return false;
		$account = is_string($account) ? trim($account) : '';
		$issuer = is_string($issuer) ? trim($issuer) : 'Brendy CMS';
		if ($account === '' || strlen($account) > 254 || $issuer === '' || strlen($issuer) > 80) return false;
		$label = rawurlencode($issuer . ':' . $account);
		return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) .
			'&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}

	public static function generateRecoveryCodes($count = 8)
	{
		$count = max(4, min(12, (int)$count));
		$codes = array();
		for ($i = 0; $i < $count; $i++) {
			$raw = strtoupper(bin2hex(random_bytes(10))); // 80 bits per recovery code.
			$codes[] = implode('-', str_split($raw, 4));
		}
		return $codes;
	}

	public static function normalizeRecoveryCode($code)
	{
		if (!is_string($code)) return false;
		$code = strtoupper(preg_replace('/[^A-F0-9]/i', '', trim($code)));
		return is_string($code) && preg_match('/^[A-F0-9]{20}$/D', $code) === 1 ? $code : false;
	}

	public static function hashRecoveryCode($code)
	{
		$normalized = self::normalizeRecoveryCode($code);
		return $normalized === false ? false : hash('sha256', 'brendy-recovery-v1|' . $normalized);
	}
}
