<?php defined('BRENDY') or die('Brendy CMS.');

checkRole(array('admin', 'editor', 'author'));

function brendyClearTwoFactorSetup()
{
	foreach (array('twoFactorSetupUser', 'twoFactorSetupSecret', 'twoFactorSetupExpires') as $key) Session::remove($key);
}


function brendyTwoFactorManagementAvailable($username)
{
	global $security, $L;
	if (!$security->loginProtectionAvailable()) {
		http_response_code(503);
		Alert::set($L->g('Authentication protection storage is unavailable. Try again after the server storage is repaired.'), ALERT_STATUS_FAIL);
		return false;
	}
	if ($security->isLoginBlocked($username)) {
		$retryAfter = $security->retryAfterSeconds($username);
		http_response_code(429);
		if ($retryAfter > 0 && !headers_sent()) header('Retry-After: ' . $retryAfter);
		Alert::set($L->g('Too many credential verification attempts. Try again later.'), ALERT_STATUS_FAIL);
		return false;
	}
	return true;
}

function brendyTwoFactorCredentialFailure($username)
{
	global $security;
	return is_string($username) && $username !== '' ? $security->addLoginFailure($username) : false;
}

function brendyCurrentTwoFactorCodeIsValid($username, $code, &$usedRecovery = false)
{
	global $users, $security;
	$usedRecovery = false;
	if (!is_string($code) || trim($code) === '' || strlen($code) > 64) return false;
	if (Totp::normalizeRecoveryCode($code) !== false) {
		$usedRecovery = $users->consumeTwoFactorRecoveryCode($username, $code);
		return $usedRecovery;
	}
	$data = $users->twoFactorData($username);
	$secret = is_array($data) ? $security->decryptTwoFactorSecret($data['secret']) : false;
	if (!is_string($secret)) return false;
	$counter = Totp::verify($secret, $code, null, 1);
	return is_int($counter) && $users->consumeTwoFactorCounter($username, $counter);
}

$username = $login->username();
if (!is_string($username) || !$users->exists($username)) brendyForceLogout();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$currentPassword = isset($_POST['currentPassword']) && is_string($_POST['currentPassword']) ? $_POST['currentPassword'] : '';
	if (isset($_POST['cancelSetup'])) {
		brendyClearTwoFactorSetup();
		Alert::set($L->g('Two-factor setup was cancelled'), ALERT_STATUS_OK);
		Redirect::page('two-factor');
	}

	if (!brendyTwoFactorManagementAvailable($username)) {
		Redirect::page('two-factor');
	}

	if (isset($_POST['prepareTwoFactor'])) {
		if (!$security->twoFactorCryptoAvailable()) {
			Alert::set($L->g('Two-factor setup requires Sodium or OpenSSL support on the server'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		if ($currentPassword === '' || strlen($currentPassword) > 4096 || !$users->verifyPassword($username, $currentPassword)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Current password is incorrect'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$secret = Totp::generateSecret(20);
		if (!is_string($secret)) {
			Alert::set($L->g('Unable to prepare two-factor authentication'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		brendyClearTwoFactorSetup();
		Session::set('twoFactorSetupUser', $username);
		Session::set('twoFactorSetupSecret', $secret);
		Session::set('twoFactorSetupExpires', time() + TWO_FACTOR_SETUP_TTL);
		Redirect::page('two-factor');
	}

	if (isset($_POST['enableTwoFactor'])) {
		$setupUser = Session::get('twoFactorSetupUser');
		$secret = Session::get('twoFactorSetupSecret');
		$expires = (int)Session::get('twoFactorSetupExpires');
		$code = isset($_POST['twoFactorCode']) && is_string($_POST['twoFactorCode']) ? $_POST['twoFactorCode'] : '';
		if (!is_string($setupUser) || !hash_equals($username, $setupUser) || !is_string($secret) ||
			$expires <= time() || $expires > time() + TWO_FACTOR_SETUP_TTL + 60) {
			brendyClearTwoFactorSetup();
			Alert::set($L->g('Two-factor setup expired. Start again.'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		if ($currentPassword === '' || strlen($currentPassword) > 4096 || !$users->verifyPassword($username, $currentPassword)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Current password is incorrect'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$counter = Totp::verify($secret, $code, null, 1);
		if (!is_int($counter)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Authenticator code is invalid. Check the device time and try again.'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$encryptedSecret = $security->encryptTwoFactorSecret($secret);
		if (!is_string($encryptedSecret)) {
			Alert::set($L->g('The server could not securely encrypt the two-factor secret'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$recoveryCodes = Totp::generateRecoveryCodes(8);
		$recoveryHashes = array();
		foreach ($recoveryCodes as $recoveryCode) {
			$hash = Totp::hashRecoveryCode($recoveryCode);
			if (!is_string($hash)) {
				Alert::set($L->g('Unable to generate recovery codes'), ALERT_STATUS_FAIL);
				Redirect::page('two-factor');
			}
			$recoveryHashes[] = $hash;
		}
		$newToken = $users->enableTwoFactor($username, $encryptedSecret, $recoveryHashes, $counter);
		if (!is_string($newToken)) {
			Alert::set($L->g('Unable to enable two-factor authentication'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		if (!brendyRotateAuthenticatedSession($newToken, $username)) {
			$login->logout();
			Redirect::page('login');
		}
		$login->invalidateRememberMe(false);
		Session::set('twoFactorRecoveryDisplay', $recoveryCodes);
		brendyClearTwoFactorSetup();
		$syslog->add(array('dictionaryKey'=>'two-factor-enabled', 'notes'=>$username, 'username'=>$username));
		Alert::set($L->g('Two-factor authentication is enabled'), ALERT_STATUS_OK);
		Redirect::page('two-factor');
	}

	if (isset($_POST['disableTwoFactor'])) {
		$code = isset($_POST['twoFactorCode']) && is_string($_POST['twoFactorCode']) ? $_POST['twoFactorCode'] : '';
		if ($currentPassword === '' || strlen($currentPassword) > 4096 || !$users->verifyPassword($username, $currentPassword)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Current password is incorrect'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$usedRecovery = false;
		if (!brendyCurrentTwoFactorCodeIsValid($username, $code, $usedRecovery)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Two-factor code is invalid or has already been used.'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$newToken = $users->disableTwoFactor($username);
		if (!is_string($newToken)) {
			Alert::set($L->g('Unable to disable two-factor authentication'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		if (!brendyRotateAuthenticatedSession($newToken, $username)) {
			$login->logout();
			Redirect::page('login');
		}
		$login->invalidateRememberMe(false);
		brendyClearTwoFactorSetup();
		$syslog->add(array('dictionaryKey'=>'two-factor-disabled', 'notes'=>$username, 'username'=>$username));
		Alert::set($L->g('Two-factor authentication is disabled'), ALERT_STATUS_OK);
		Redirect::page('two-factor');
	}

	if (isset($_POST['regenerateRecoveryCodes'])) {
		$code = isset($_POST['twoFactorCode']) && is_string($_POST['twoFactorCode']) ? $_POST['twoFactorCode'] : '';
		if ($currentPassword === '' || strlen($currentPassword) > 4096 || !$users->verifyPassword($username, $currentPassword)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Current password is incorrect'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$usedRecovery = false;
		if (!brendyCurrentTwoFactorCodeIsValid($username, $code, $usedRecovery)) {
			brendyTwoFactorCredentialFailure($username);
			Alert::set($L->g('Two-factor code is invalid or has already been used.'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		$recoveryCodes = Totp::generateRecoveryCodes(8);
		$hashes = array();
		foreach ($recoveryCodes as $recoveryCode) {
			$hash = Totp::hashRecoveryCode($recoveryCode);
			if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
				Alert::set($L->g('Unable to generate recovery codes'), ALERT_STATUS_FAIL);
				Redirect::page('two-factor');
			}
			$hashes[] = $hash;
		}
		if (!$users->replaceTwoFactorRecoveryCodes($username, $hashes)) {
			Alert::set($L->g('Unable to generate recovery codes'), ALERT_STATUS_FAIL);
			Redirect::page('two-factor');
		}
		if (!brendyRotateAuthenticatedSession(null, $username)) {
			$login->logout();
			Redirect::page('login');
		}
		Session::set('twoFactorRecoveryDisplay', $recoveryCodes);
		$syslog->add(array('dictionaryKey'=>'two-factor-recovery-codes-regenerated', 'notes'=>$username, 'username'=>$username));
		Alert::set($L->g('New recovery codes were generated. Previous recovery codes are no longer valid.'), ALERT_STATUS_OK);
		Redirect::page('two-factor');
	}
}

$layout['title'] = $L->g('Two-factor authentication') . ' - ' . $layout['title'];
$user = new User($username);
$setupSecret = Session::get('twoFactorSetupSecret');
$setupUser = Session::get('twoFactorSetupUser');
$setupExpires = (int)Session::get('twoFactorSetupExpires');
if (!is_string($setupSecret) || !is_string($setupUser) || !hash_equals($username, $setupUser) || $setupExpires <= time()) {
	brendyClearTwoFactorSetup();
	$setupSecret = false;
}
$recoveryCodesDisplay = Session::get('twoFactorRecoveryDisplay');
if (!is_array($recoveryCodesDisplay)) $recoveryCodesDisplay = array();
Session::remove('twoFactorRecoveryDisplay');
