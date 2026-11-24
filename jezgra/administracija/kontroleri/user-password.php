<?php defined('BRENDY') or die('Brendy CMS.');

// Password changes are credential-boundary operations for every account, not
// only when an administrator changes another user. Fresh interactive logins
// already satisfy this check; Remember-me or stale sessions must prove the
// primary credential again (and 2FA when enabled) before reaching this form.
$targetPasswordUser = is_string($layout['parameters']) ? $layout['parameters'] : '';
if ($login->role() !== 'admin') $targetPasswordUser = $login->username();
if ($targetPasswordUser !== '') {
	brendyRequireRecentAuthentication('user-password/' . rawurlencode($targetPasswordUser));
}

// ============================================================================
// Functions
// ============================================================================

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!isset($_POST['username'], $_POST['newPassword'], $_POST['confirmPassword']) ||
		!is_string($_POST['username']) || !is_string($_POST['newPassword']) ||
		!is_string($_POST['confirmPassword'])) {
		Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
		Redirect::page('users');
	}
	// Prevent non-administrators to change other users
	$username = $_POST['username'];
	if ($login->role()!=='admin') {
	    $username = $login->username();
	}

	if (changeUserPassword(array(
		'username'=>$username,
		'currentPassword'=>isset($_POST['currentPassword']) && is_string($_POST['currentPassword']) ? $_POST['currentPassword'] : '',
		'newPassword'=>$_POST['newPassword'],
		'confirmPassword'=>$_POST['confirmPassword']
	))) {
		if ($login->role()==='admin') {
			Redirect::page('users');
		}
		Redirect::page('edit-user/'.$login->username());
	}
}

// ============================================================================
// Main after POST
// ============================================================================

// Prevent non-administrators to change other users
if ($login->role()!=='admin') {
	$layout['parameters'] = $login->username();
}

try {
$username = is_string($layout['parameters']) ? $layout['parameters'] : '';
	$user = new User($username);
} catch (Exception $e) {
	Redirect::page('users');
}

// Title of the page
$layout['title'] = $L->g('Change password').' - '.$layout['title'];
