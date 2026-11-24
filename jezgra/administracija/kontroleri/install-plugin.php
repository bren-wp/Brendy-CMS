<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

brendyRequireRecentAuthentication('plugins');

// ============================================================================
// Functions
// ============================================================================

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================

// ============================================================================
// Main after POST
// ============================================================================
brendyRequireHttpMethod('POST');

$pluginClassName = normalizeBrendyPluginClassName($layout['parameters']);
if (!isset($plugins['all'][$pluginClassName])) {
	Alert::set($L->g('Plugin could not be activated'), ALERT_STATUS_FAIL);
	Redirect::page('plugins');
}
if (!activatePlugin($pluginClassName)) {
	Log::set('Plugin activation failed: ' . $pluginClassName, LOG_TYPE_ERROR);
	Alert::set($L->g('Plugin could not be activated'), ALERT_STATUS_FAIL);
	Redirect::page('plugins#' . rawurlencode($pluginClassName));
}

if (isset($plugins['all'][$pluginClassName])) {
	$plugin = $plugins['all'][$pluginClassName];
} else {
	Redirect::page('plugins');
}

if (method_exists($plugin, 'form')) {
	Redirect::page('configure-plugin/'.$pluginClassName);
}

Redirect::page('plugins#'.$pluginClassName);
