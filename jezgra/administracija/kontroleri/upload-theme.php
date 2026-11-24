<?php defined('BRENDY') or die('Brendy CMS.');
checkRole(array('admin'));
brendyRequireRecentAuthentication('themes');
brendyRequireHttpMethod('POST');
$result = isset($_FILES['extensionZip']) ? installBrendyExtensionArchive($_FILES['extensionZip'], 'theme') : false;
Alert::set($result === false ? $L->g('Theme ZIP could not be installed') : $L->g('Theme ZIP installed successfully'), $result === false ? ALERT_STATUS_FAIL : ALERT_STATUS_OK);
Redirect::page('themes');
