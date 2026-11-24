<?php defined('BRENDY') or die('Brendy CMS.');
checkRole(array('admin'));
brendyRequireRecentAuthentication('themes');
brendyRequireHttpMethod('POST');
$directory = isset($_POST['directory']) && is_string($_POST['directory']) ? $_POST['directory'] : '';
$ok = deleteBrendyExtension($directory, 'theme');
Alert::set($ok ? $L->g('Theme files deleted') : $L->g('Theme files could not be deleted'), $ok ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
Redirect::page('themes');
