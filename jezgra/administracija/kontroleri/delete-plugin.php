<?php defined('BRENDY') or die('Brendy CMS.');
checkRole(array('admin'));
brendyRequireRecentAuthentication('plugins');
brendyRequireHttpMethod('POST');
$directory = isset($_POST['directory']) && is_string($_POST['directory']) ? $_POST['directory'] : '';
$removeState = isset($_POST['removeState']) && brendyBooleanValue($_POST['removeState'], null) === true;
if ($removeState) {
	$ok = removeBrendyPluginState($directory);
	Alert::set($ok ? $L->g('plugin-deactivated') : $L->g('Plugin could not be deactivated'), $ok ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
	Redirect::page('plugins');
}
$ok = deleteBrendyExtension($directory, 'plugin');
Alert::set($ok ? $L->g('Plugin files deleted') : $L->g('Plugin files could not be deleted'), $ok ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
Redirect::page('plugins');
