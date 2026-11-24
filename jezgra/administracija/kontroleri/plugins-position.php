<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));
brendyRequireRecentAuthentication('plugins-position');

// ============================================================================
// Functions
// ============================================================================

// ============================================================================
// Main before POST
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$pluginList = isset($_POST['plugin-list']) && is_string($_POST['plugin-list']) ? $_POST['plugin-list'] : '';
	if ($pluginList === '' || strlen($pluginList) > 10000) {
		Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
	} else {
		changePluginsPosition(array_slice(explode(',', $pluginList), 0, 500));
	}
	Redirect::page('plugins-position');
}
// ============================================================================
// POST Method
// ============================================================================

// ============================================================================
// Main after POST
// ============================================================================

// Title of the page
$layout['title'] .= ' - '.$L->g('Plugins');
