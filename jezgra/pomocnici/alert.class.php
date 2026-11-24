<?php defined('BRENDY') or die('Brendy CMS.');

class Alert {

	// Status, 0 = OK, 1 = Fail
	public static function set($value, $status=ALERT_STATUS_OK, $key='alert')
	{
		if (!is_scalar($value) || !is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) {
			return false;
		}
		$value = (string)$value;
		if (strlen($value) > 10000) $value = substr($value, 0, 10000);
		$status = ($status === ALERT_STATUS_FAIL) ? ALERT_STATUS_FAIL : ALERT_STATUS_OK;
		return Session::set('defined', true) &&
			Session::set('alertStatus', $status) &&
			Session::set($key, $value);
	}

	public static function get($key='alert')
	{
		if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) return false;
		Session::set('defined', false);
		$value = Session::get($key);
		return is_string($value) ? $value : false;
	}

	public static function status()
	{
		return Session::get('alertStatus');
	}

	public static function p($key='alert')
	{
		echo Sanitize::html(self::get($key));
	}

	public static function defined()
	{
		return Session::get('defined');
	}

}
