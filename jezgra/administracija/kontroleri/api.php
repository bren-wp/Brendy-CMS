<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

brendyRequireRecentAuthentication('api');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!pluginActivated('BrendyPluginAPI') && !activatePlugin('BrendyPluginAPI')) {
		ajaxResponse(1, $L->g('The API plugin could not be activated'));
	}
	$brendyApiPlugin = getPlugin('BrendyPluginAPI');
	if (!$brendyApiPlugin || !method_exists($brendyApiPlugin, 'rotateCredentials')) {
		ajaxResponse(1, $L->g('The API plugin is unavailable'));
	}
	$username = $login->username();
	$expiryDays = brendyIntegerParameter($_POST, 'expiryDays', 90, 0, 365);
	if ($expiryDays === null || !in_array($expiryDays, array(0, 30, 90, 180, 365), true)) {
		ajaxResponse(1, $L->g('Invalid API credential lifetime'));
	}
	$credentials = $brendyApiPlugin->rotateCredentials($username, $expiryDays);
	if (!is_array($credentials) || !isset($credentials['readToken'], $credentials['writeToken']) ||
		!is_string($credentials['readToken']) || !is_string($credentials['writeToken'])) {
		ajaxResponse(1, $L->g('API credentials could not be generated'));
	}
	$syslog->add(array(
		'dictionaryKey' => 'api-credentials-regenerated',
		'notes' => '',
		'username' => $username
	));
	// The plaintext values exist only in this one response. The plugin database
	// stores SHA-256 digests and cannot reveal the credentials afterwards.
	ajaxResponse(0, $L->g('API credentials generated'), array(
		'apiURL' => DOMAIN_BASE.'api/',
		'username' => $username,
		'apiToken' => $credentials['readToken'],
		'writeToken' => $credentials['writeToken'],
		'authorizationHeader' => 'Authorization: Bearer ' . $credentials['readToken'],
		'writeHeader' => 'X-Brendy-Authentication: ' . $credentials['writeToken'],
		'expiresAt' => isset($credentials['expiresAt']) ? (int)$credentials['expiresAt'] : 0
	));
}

$layout['title'] = $L->g('API access').' - '.$layout['title'];
