<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

// ============================================================================
// Functions
// ============================================================================

// ============================================================================
// Main before POST
// ============================================================================
$plugin = false;
$pluginClassName = normalizeBrendyPluginClassName($layout['parameters']);

// Check if the plugin exists
if (isset($plugins['all'][$pluginClassName])) {
	$plugin = $plugins['all'][$pluginClassName];
	if (!method_exists($plugin, 'runtimeReady') || !$plugin->runtimeReady()) Redirect::page('plugins');
} else {
	Redirect::page('plugins');
}

// Check if the plugin has the method form()
if (!method_exists($plugin, 'form')) {
	Redirect::page('plugins');
}

if (method_exists($plugin, 'requiresRecentAuthentication') && $plugin->requiresRecentAuthentication()) {
	brendyRequireRecentAuthentication('configure-plugin/' . rawurlencode($pluginClassName));
}

// ============================================================================
// POST Method
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Call the method post of the plugin
	$result = $plugin->post();
	if ($result !== false) {
		$syslog->add(array(
			'dictionaryKey'=>'plugin-configured',
			'notes'=>$plugin->name()
		));
		Alert::set( $L->g('The changes have been saved') );
	} else {
		Alert::set($L->g('The changes could not be saved'), ALERT_STATUS_FAIL);
	}
	Redirect::page('configure-plugin/'.$plugin->className());
}

// ============================================================================
// Main after POST
// ============================================================================

// Title of the page
$layout['title'] = $L->g('Plugin').' - '.$plugin->name().' - '.$layout['title'];
