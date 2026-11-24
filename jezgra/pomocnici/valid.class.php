<?php defined('BRENDY') or die('Brendy CMS.');

class Valid {

	public static function ip($ip)
	{
		return filter_var($ip, FILTER_VALIDATE_IP);
	}

	// Returns the email filtered or FALSE if the filter fails.
	public static function email($email)
	{
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	public static function int($int)
	{
		if($int === 0) {
			return true;
		}
		elseif(filter_var($int, FILTER_VALIDATE_INT) === false ) {
			return false;
		}

		return true;
	}

	// Thanks, http://php.net/manual/en/function.checkdate.php#113205
	public static function date($date, $format='Y-m-d H:i:s')
	{
		if (!is_string($date) || !is_string($format) || $date === '' || $format === '' ||
			strlen($date) > 512 || strlen($format) > 128 || strpos($date, "\0") !== false || strpos($format, "\0") !== false) {
			return false;
		}
		try {
			$tmp = DateTime::createFromFormat($format, $date);
		} catch (Throwable $e) {
			return false;
		}
		if (!$tmp instanceof DateTime) return false;
		$errors = DateTime::getLastErrors();
		if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) return false;
		return $tmp->format($format) === $date;
	}

}
