<?php defined('BRENDY') or die('Brendy CMS.');

if ($login->role() === 'admin') {
	$reauthTargetUser = is_string($layout['parameters']) ? $layout['parameters'] : '';
	$reauthRoute = $reauthTargetUser !== '' ? 'edit-user/' . rawurlencode($reauthTargetUser) : 'users';
	brendyRequireRecentAuthentication($reauthRoute);
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
	if (!isset($_POST['username']) || !is_string($_POST['username'])) {
		Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
		Redirect::page('users');
	}
	// Prevent non-administrators to change other users
	if ($login->role()!=='admin') {
		$_POST['username'] = $login->username();
		unset($_POST['role']);
	}

	$success = false;
	if (isset($_POST['deleteUserAndDeleteContent']) && ($login->role()==='admin')) {
		$_POST['deleteContent'] = true;
		$success = deleteUser($_POST);
	} elseif (isset($_POST['deleteUserAndKeepContent']) && ($login->role()==='admin')) {
		$_POST['deleteContent'] = false;
		$success = deleteUser($_POST);
	} elseif (isset($_POST['disableUser']) && ($login->role()==='admin')) {
		$success = disableUser(array('username'=>$_POST['username']));
	} elseif (isset($_POST['enableUser']) && ($login->role()==='admin')) {
		$success = enableUser(array('username'=>$_POST['username']));
	} elseif (isset($_POST['regenerateAuthToken'])) {
		// Session-revocation token rotation is security-sensitive even when a
		// non-admin rotates their own token. Require fresh credentials first.
		brendyRequireRecentAuthentication('edit-user/' . rawurlencode($_POST['username']));
		$success = regenerateUserAuthToken(array('username'=>$_POST['username']));
	} else {
		$success = editUser($_POST);
	}

	Alert::set($success ? $L->g('The changes have been saved') : $L->g('Invalid request'),
		$success ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);

	if ($login->role()==='admin') {
		Redirect::page('users');
	}
	Redirect::page('edit-user/'.$login->username());
}

// ============================================================================
// Main after POST
// ============================================================================

$username = is_string($layout['parameters']) ? $layout['parameters'] : '';

// Prevent non-administrators to change other users
if ($login->role()!=='admin') {
	$username = $login->username();
}

try {
	$user = new User($username);
} catch (Exception $e) {
	Redirect::page('users');
}

// Title of the page
$layout['title'] = $L->g('Edit user').' - '.$layout['title'];
