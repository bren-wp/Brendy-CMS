<?php defined('BRENDY') or die('Brendy CMS.');

function clearPendingTwoFactorLogin()
{
	foreach (array('twoFactorPendingUser', 'twoFactorPendingIdentifier', 'twoFactorPendingExpires',
		'twoFactorPendingAttempts', 'twoFactorPendingEnableApi', 'twoFactorPendingFingerprint',
		'twoFactorPendingAuthBinding') as $key) {
		Session::remove($key);
	}
}

function pendingTwoFactorUser()
{
	global $login, $users;
	$username = Session::get('twoFactorPendingUser');
	$expires = (int)Session::get('twoFactorPendingExpires');
	$fingerprint = Session::get('twoFactorPendingFingerprint');
	$authBinding = Session::get('twoFactorPendingAuthBinding');
	$currentFingerprint = isset($login) && is_object($login) ? $login->fingerPrint() : '';
	$userDB = is_string($username) && isset($users) && is_object($users) && method_exists($users, 'getUserDB')
		? $users->getUserDB($username) : false;
	$currentToken = is_array($userDB) && isset($userDB['tokenAuth']) && is_string($userDB['tokenAuth'])
		? $userDB['tokenAuth'] : '';
	$currentBinding = $currentToken !== '' ? hash('sha256', $currentToken) : '';
	if (!is_string($username) || $username === '' || $expires <= time() ||
		$expires > time() + TWO_FACTOR_LOGIN_TTL + 60 || !is_string($fingerprint) ||
		!is_string($currentFingerprint) || $fingerprint === '' || !hash_equals($fingerprint, $currentFingerprint) ||
		!is_string($authBinding) || preg_match('/^[a-f0-9]{64}$/D', $authBinding) !== 1 ||
		$currentBinding === '' || !hash_equals($authBinding, $currentBinding)) {
		clearPendingTwoFactorLogin();
		return false;
	}
	return $username;
}

function checkLogin($args)
{
	global $security, $login, $users, $L;

	$loginIdentifier = is_array($args) && isset($args['username']) && is_string($args['username']) ? $args['username'] : '';
	if (!$security->loginProtectionAvailable()) {
		http_response_code(503);
		Alert::set($L->g('Login protection storage is unavailable. Try again after the server storage is repaired.'), ALERT_STATUS_FAIL);
		Log::set(__FILE__ . LOG_SEP . 'Login denied because throttle storage is unavailable.', LOG_TYPE_ERROR);
		return false;
	}
	if ($security->isLoginBlocked($loginIdentifier)) {
		$retryAfter = $security->retryAfterSeconds($loginIdentifier);
		http_response_code(429);
		if ($retryAfter > 0 && !headers_sent()) header('Retry-After: ' . $retryAfter);
		Alert::set($L->g('Too many login attempts. Try again later.'), ALERT_STATUS_FAIL);
		return false;
	}
	if (!is_array($args) || !isset($args['username'], $args['password']) ||
		!is_string($args['username']) || !is_string($args['password']) ||
		strlen($args['username']) > 254 || preg_match('/[\x00-\x1F\x7F]/', $args['username']) ||
		strlen($args['password']) > 4096) {
		$security->addLoginFailure($loginIdentifier);
		Alert::set($L->g('Username or password incorrect'), ALERT_STATUS_FAIL);
		return false;
	}

	$authenticatedUsername = $login->verifyPrimaryCredentials($args['username'], $args['password']);
	if (!is_string($authenticatedUsername) || $authenticatedUsername === '') {
		$security->addLoginFailure($loginIdentifier);
		Alert::set($L->g('Username or password incorrect'), ALERT_STATUS_FAIL);
		return false;
	}

	if ($users->twoFactorEnabled($authenticatedUsername)) {
		// Cross the password-authentication boundary with a fresh anonymous session
		// identifier before storing the short-lived second-factor challenge. The
		// final authenticated login rotates it once more after TOTP/recovery succeeds.
		if (!Session::regenerate(true)) {
			Alert::set($L->g('Unable to establish a secure session.'), ALERT_STATUS_FAIL);
			return false;
		}
		// Do not establish an authenticated session until the second factor passes.
		// Remember-me is intentionally unavailable for 2FA accounts because a long-
		// lived browser cookie would otherwise become a bypass for the second factor.
		$login->invalidateRememberMe(false);
		clearPendingTwoFactorLogin();
		Session::set('twoFactorPendingUser', $authenticatedUsername);
		Session::set('twoFactorPendingIdentifier', $loginIdentifier);
		Session::set('twoFactorPendingExpires', time() + TWO_FACTOR_LOGIN_TTL);
		$userDB = $users->getUserDB($authenticatedUsername);
		$currentAuthToken = is_array($userDB) && isset($userDB['tokenAuth']) && is_string($userDB['tokenAuth'])
			? $userDB['tokenAuth'] : '';
		if ($currentAuthToken === '' || preg_match('/^[a-f0-9]{64,128}$/iD', $currentAuthToken) !== 1) {
			clearPendingTwoFactorLogin();
			Alert::set($L->g('Unable to establish a secure session.'), ALERT_STATUS_FAIL);
			return false;
		}
		Session::set('twoFactorPendingAttempts', 0);
		Session::set('twoFactorPendingEnableApi', isset($_GET['enableAPI']));
		Session::set('twoFactorPendingFingerprint', $login->fingerPrint());
		Session::set('twoFactorPendingAuthBinding', hash('sha256', $currentAuthToken));
		$security->generateTokenCSRF();
		return true;
	}

	$userDB = $users->getUserDB($authenticatedUsername);
	if (!is_array($userDB) || !$login->setLogin($authenticatedUsername, $userDB['role'], false, 'password')) {
		$security->addLoginFailure($loginIdentifier);
		Alert::set($L->g('Username or password incorrect'), ALERT_STATUS_FAIL);
		return false;
	}
	$security->clearLoginFailures(null, $loginIdentifier);
	if (!hash_equals($loginIdentifier, $authenticatedUsername)) $security->clearLoginFailures(null, $authenticatedUsername);
	if (isset($args['remember'])) $login->setRememberMe($authenticatedUsername);
	if (!$users->recordSuccessfulLogin($authenticatedUsername, 'password')) {
		Log::set(__FILE__ . LOG_SEP . 'Unable to record successful password login metadata.', LOG_TYPE_WARN);
	}
	if (isset($GLOBALS['syslog']) && is_object($GLOBALS['syslog'])) {
		$GLOBALS['syslog']->add(array('dictionaryKey'=>'login-successful', 'notes'=>'password', 'username'=>$authenticatedUsername));
	}
	$security->generateTokenCSRF();
	if (isset($_GET['enableAPI'])) Redirect::page('api');
	Redirect::page('dashboard');
	return true;
}

function checkTwoFactorLogin($args)
{
	global $security, $login, $users, $L, $syslog;

	$username = pendingTwoFactorUser();
	if ($username === false || !$users->twoFactorEnabled($username)) {
		clearPendingTwoFactorLogin();
		Alert::set($L->g('Two-factor login session expired. Sign in again.'), ALERT_STATUS_FAIL);
		return false;
	}
	$identifier = Session::get('twoFactorPendingIdentifier');
	$identifier = is_string($identifier) ? $identifier : $username;
	if (!$security->loginProtectionAvailable()) {
		http_response_code(503);
		Alert::set($L->g('Login protection storage is unavailable. Try again after the server storage is repaired.'), ALERT_STATUS_FAIL);
		Log::set(__FILE__ . LOG_SEP . 'Two-factor login denied because throttle storage is unavailable.', LOG_TYPE_ERROR);
		return false;
	}
	if ($security->isLoginBlocked($identifier)) {
		clearPendingTwoFactorLogin();
		$retryAfter = $security->retryAfterSeconds($identifier);
		http_response_code(429);
		if ($retryAfter > 0 && !headers_sent()) header('Retry-After: ' . $retryAfter);
		Alert::set($L->g('Too many login attempts. Try again later.'), ALERT_STATUS_FAIL);
		return false;
	}

	$code = is_array($args) && isset($args['twoFactorCode']) && is_string($args['twoFactorCode'])
		? trim($args['twoFactorCode']) : '';
	if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
		$code = '';
	}

	$data = $users->twoFactorData($username);
	$secret = is_array($data) ? $security->decryptTwoFactorSecret($data['secret']) : false;
	$usedRecovery = false;
	$verified = false;
	if (is_string($secret) && preg_match('/^[0-9]{6}$/D', preg_replace('/\s+/', '', $code))) {
		$counter = Totp::verify($secret, $code, null, 1);
		if (is_int($counter)) $verified = $users->consumeTwoFactorCounter($username, $counter);
	}
	if (!$verified && Totp::normalizeRecoveryCode($code) !== false) {
		$verified = $users->consumeTwoFactorRecoveryCode($username, $code);
		$usedRecovery = $verified;
	}

	if (!$verified) {
		$security->addLoginFailure($identifier);
		$attempts = (int)Session::get('twoFactorPendingAttempts') + 1;
		Session::set('twoFactorPendingAttempts', $attempts);
		if ($attempts >= TWO_FACTOR_LOGIN_MAX_ATTEMPTS) {
			$syslog->add(array('dictionaryKey'=>'two-factor-challenge-blocked', 'notes'=>$username, 'username'=>$username));
			clearPendingTwoFactorLogin();
			Alert::set($L->g('Too many invalid two-factor codes. Sign in again.'), ALERT_STATUS_FAIL);
		} else {
			Alert::set($L->g('Two-factor code is invalid or has already been used.'), ALERT_STATUS_FAIL);
		}
		return false;
	}

	$userDB = $users->getUserDB($username);
	if (!is_array($userDB) || !$login->setLogin($username, $userDB['role'], true, 'two-factor')) {
		clearPendingTwoFactorLogin();
		Alert::set($L->g('Unable to establish a secure session.'), ALERT_STATUS_FAIL);
		return false;
	}
	$enableApi = Session::get('twoFactorPendingEnableApi') === true;
	clearPendingTwoFactorLogin();
	$security->clearLoginFailures(null, $identifier);
	if (!hash_equals($identifier, $username)) $security->clearLoginFailures(null, $username);
	if (!$users->recordSuccessfulLogin($username, 'two-factor')) {
		Log::set(__FILE__ . LOG_SEP . 'Unable to record successful two-factor login metadata.', LOG_TYPE_WARN);
	}
	$security->generateTokenCSRF();
	$syslog->add(array(
		'dictionaryKey' => $usedRecovery ? 'two-factor-recovery-code-used' : 'two-factor-login-successful',
		'notes' => $username,
		'username' => $username
	));
	if ($usedRecovery) Alert::set($L->g('A recovery code was used. Consider generating a fresh set of recovery codes.'), ALERT_STATUS_OK);
	if ($enableApi) Redirect::page('api');
	Redirect::page('dashboard');
	return true;
}

function checkRememberMe()
{
	global $security, $login, $users, $syslog;
	if ($security->isBlocked()) return false;
	if ($login->verifyUserByRemember()) {
		$username = $login->username();
		if (is_string($username) && $username !== '') {
			if (!$users->recordSuccessfulLogin($username, 'remember')) {
				Log::set(__FILE__ . LOG_SEP . 'Unable to record successful remember-me login metadata.', LOG_TYPE_WARN);
			}
			$syslog->add(array('dictionaryKey'=>'login-successful', 'notes'=>'remember', 'username'=>$username));
		}
		$security->clearLoginFailures();
		$security->generateTokenCSRF();
		Redirect::page('dashboard');
		return true;
	}
	return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	if (pendingTwoFactorUser() === false) checkRememberMe();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['cancelTwoFactor'])) {
		clearPendingTwoFactorLogin();
		Redirect::page('login');
	}
	if (isset($_POST['twoFactorStep'])) checkTwoFactorLogin($_POST);
	else checkLogin($_POST);
}
