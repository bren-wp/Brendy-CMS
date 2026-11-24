<?php defined('BRENDY') or die('Brendy CMS.');

class Date {

	private static function parseExact($date, $format)
	{
		if (!is_string($date) || !is_string($format) || $date === '' || $format === '' ||
			strlen($date) > 512 || strlen($format) > 128 || strpos($date, "\0") !== false || strpos($format, "\0") !== false) {
			return false;
		}
		try {
			$parsed = DateTime::createFromFormat($format, $date);
		} catch (Throwable $e) {
			return false;
		}
		if (!$parsed instanceof DateTime) return false;
		$errors = DateTime::getLastErrors();
		if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) return false;
		return $parsed->format($format) === $date ? $parsed : false;
	}

	// Browser datetime-local has no timezone; preserve CMS wall-clock time.
	public static function fromLocalInput($value)
	{
		if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?$/D', $value)) return false;
		if (substr($value, 0, 4) === '0000') return false;
		$value = str_replace('T', ' ', $value);
		if (strlen($value) === 16) $value .= ':00';
		$parsed = self::parseExact($value, 'Y-m-d H:i:s');
		return $parsed === false ? false : $value;
	}

	// Returns string with the date translated
	// Example: $date = 'Mon, 27th March' > 'Lun, 27th Marzo'
	public static function translate($date)
	{
		global $L;
		if (!is_string($date)) return '';
		if (!isset($L) || !is_object($L) || !method_exists($L, 'currentLanguage') || !method_exists($L, 'getDates')) return $date;

		// If English default language don't translate
		if ($L->currentLanguage() === 'en') {
			return $date;
		}

		$dates = $L->getDates();
		if (!is_array($dates)) return $date;
		foreach ($dates as $english=>$anotherLang) {
			if (!is_string($english) || $english === '' || !is_scalar($anotherLang)) continue;
			$pattern = '/\b' . preg_quote($english, '/') . '\b/u';
			$replacement = (string)$anotherLang;
			$updated = preg_replace_callback($pattern, static function () use ($replacement) {
				return $replacement;
			}, $date);
			if (is_string($updated)) $date = $updated;
		}
		return $date;
	}

	// Return current Unix timestamp, GMT+0
	public static function unixTime()
	{
		return time();
	}

	// Return the local time/date according to locale settings.
	public static function current($format)
	{
		if (!is_string($format) || $format === '' || strlen($format) > 128) return false;
		try {
			$Date = new DateTime();
			$output = $Date->format($format);
		} catch (Throwable $e) {
			return false;
		}
		return self::translate($output);
	}

	// Returns the current time shifted by offset
	// $offset could be +1 day, +1 month
	public static function currentOffset($format, $offset)
	{
		if (!is_string($format) || $format === '' || strlen($format) > 128 ||
			!is_string($offset) || $offset === '' || strlen($offset) > 128 || strpos($offset, "\0") !== false) return false;
		try {
			$Date = new DateTime();
			if ($Date->modify($offset) === false) return false;
			$output = $Date->format($format);
		} catch (Throwable $e) {
			return false;
		}
		return self::translate($output);
	}

	public static function offset($date, $format, $offset)
	{
		if (!is_string($date) || $date === '' || strlen($date) > 512 ||
			!is_string($format) || $format === '' || strlen($format) > 128 ||
			!is_string($offset) || $offset === '' || strlen($offset) > 128 || strpos($offset, "\0") !== false) return false;
		$Date = self::parseExact($date, $format);
		if ($Date === false) return false;
		try {
			if ($Date->modify($offset) === false) return false;
			$output = $Date->format($format);
		} catch (Throwable $e) {
			return false;
		}
		return self::translate($output);
	}

	// Format a local time/date according to locale settings.
	public static function format($date, $currentFormat, $outputFormat)
	{
		if (!is_string($outputFormat) || $outputFormat === '' || strlen($outputFormat) > 128) return false;
		$Date = self::parseExact($date, $currentFormat);
		if ($Date === false) return false;
		try {
			$output = $Date->format($outputFormat);
		} catch (Throwable $e) {
			return false;
		}
		return self::translate($output);
	}

	public static function convertToUTC($date, $currentFormat, $outputFormat)
	{
		if (!is_string($outputFormat) || $outputFormat === '' || strlen($outputFormat) > 128) return false;
		$Date = self::parseExact($date, $currentFormat);
		if ($Date === false) return false;
		try {
			$Date->setTimezone(new DateTimeZone('UTC'));
			$output = $Date->format($outputFormat);
		} catch (Throwable $e) {
			return false;
		}
		return self::translate($output);
	}

	public static function timeago($time)
	{
		if (!is_int($time) && !(is_string($time) && ctype_digit($time))) return false;
		$time = time() - (int)$time;
		if ($time < 0) return false;

		$tokens = array (
			31536000 => 'year',
			2592000 => 'month',
			604800 => 'week',
			86400 => 'day',
			3600 => 'hour',
			60 => 'minute',
			1 => 'second'
		);

		foreach ($tokens as $unit => $text) {
			if ($time < $unit) continue;
			$numberOfUnits = floor($time / $unit);
			return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');
		}
		return '0 seconds';
	}

	// Return array('Africa/Abidjan'=>'Africa/Abidjan (GMT+00:00)', ...)
	public static function timezoneList()
	{
		$tmp = array();
		$timezone_identifiers_list = timezone_identifiers_list();
		foreach ($timezone_identifiers_list as $timezone_identifier) {
			try {
				$date_time_zone = new DateTimeZone($timezone_identifier);
				$date_time = new DateTime('now', $date_time_zone);
				$offsetSeconds = (int)$date_time_zone->getOffset($date_time);
			} catch (Throwable $e) {
				continue;
			}
			$sign = $offsetSeconds < 0 ? '-' : '+';
			$absolute = abs($offsetSeconds);
			$hours = intdiv($absolute, 3600);
			$mins = intdiv($absolute % 3600, 60);
			$text = str_replace('_', ' ', $timezone_identifier);
			$tmp[$timezone_identifier] = sprintf('%s (GMT%s%02d:%02d)', $text, $sign, $hours, $mins);
		}
		return $tmp;
	}
}
