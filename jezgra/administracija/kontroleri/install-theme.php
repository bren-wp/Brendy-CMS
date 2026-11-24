<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

brendyRequireRecentAuthentication('themes');

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

$themeDirectory = $layout['parameters'];

// Activate theme and stop on compatibility, filesystem or install-script errors.
if (!activateTheme($themeDirectory)) {
	Log::set('Theme activation failed: ' . (is_scalar($themeDirectory) ? (string)$themeDirectory : ''), LOG_TYPE_ERROR);
	Alert::set($L->g('Theme could not be activated'), ALERT_STATUS_FAIL);
}

// Redirect
Redirect::page('themes');
