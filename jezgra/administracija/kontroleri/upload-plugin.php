<?php defined('BRENDY') or die('Brendy CMS.');
checkRole(array('admin'));
brendyRequireRecentAuthentication('plugins');
brendyRequireHttpMethod('POST');
$result = isset($_FILES['extensionZip']) ? installBrendyExtensionArchive($_FILES['extensionZip'], 'plugin') : false;
Alert::set($result === false ? $L->g('Plugin ZIP could not be installed') : $L->g('Plugin ZIP installed successfully'), $result === false ? ALERT_STATUS_FAIL : ALERT_STATUS_OK);
Redirect::page('plugins');
