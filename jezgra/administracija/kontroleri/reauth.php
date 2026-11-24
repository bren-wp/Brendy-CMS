<?php defined('BRENDY') or die('Brendy CMS.');

checkRole(array('admin', 'editor', 'author'));

$username = $login->username();
if (!is_string($username) || !$users->exists($username)) brendyForceLogout();

// If a recent proof is already present, consume the pending destination and
// continue without asking for credentials twice.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $login->recentAuthenticationValid()) {
	Redirect::page(brendyConsumeRecentAuthenticationReturnRoute());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$security->loginProtectionAvailable()) {
		http_response_code(503);
		Alert::set($L->g('Login protection storage is unavailable. Try again after the server storage is repaired.'), ALERT_STATUS_FAIL);
		Log::set(__FILE__ . LOG_SEP . 'Recent-authentication denied because throttle storage is unavailable.', LOG_TYPE_ERROR);
	} elseif ($security->isLoginBlocked($username)) {
		$retryAfter = $security->retryAfterSeconds($username);
		http_response_code(429);
		if ($retryAfter > 0 && !headers_sent()) header('Retry-After: ' . $retryAfter);
		Alert::set($L->g('Too many login attempts. Try again later.'), ALERT_STATUS_FAIL);
	} else {
		$password = isset($_POST['currentPassword']) && is_string($_POST['currentPassword']) ? $_POST['currentPassword'] : '';
		$code = isset($_POST['twoFactorCode']) && is_string($_POST['twoFactorCode']) ? trim($_POST['twoFactorCode']) : '';
		$verified = $password !== '' && strlen($password) <= 4096 && $users->verifyPassword($username, $password);
		$usedRecovery = false;

		if ($verified && $users->twoFactorEnabled($username)) {
			$verified = false;
			if ($code !== '' && strlen($code) <= 64 && !preg_match('/[\x00-\x1F\x7F]/', $code)) {
				$data = $users->twoFactorData($username);
				$secret = is_array($data) ? $security->decryptTwoFactorSecret($data['secret']) : false;
				$normalizedCode = preg_replace('/\s+/', '', $code);
				if (is_string($secret) && is_string($normalizedCode) && preg_match('/^[0-9]{6}$/D', $normalizedCode)) {
					$counter = Totp::verify($secret, $normalizedCode, null, 1);
					if (is_int($counter)) $verified = $users->consumeTwoFactorCounter($username, $counter);
				}
				if (!$verified && Totp::normalizeRecoveryCode($code) !== false) {
					$verified = $users->consumeTwoFactorRecoveryCode($username, $code);
					$usedRecovery = $verified;
				}
			}
		}

		if (!$verified) {
			$security->addLoginFailure($username);
			$attempts = (int)Session::get('recentAuthAttempts') + 1;
			Session::set('recentAuthAttempts', $attempts);
			$syslog->add(array(
				'dictionaryKey' => 'recent-authentication-failed',
				'notes' => 'attempt-' . min($attempts, 999),
				'username' => $username
			));
			if ($attempts >= STEP_UP_AUTH_MAX_ATTEMPTS) {
				$login->clearRecentAuthentication();
				$login->logout();
				Redirect::admin();
			}
			Alert::set($L->g('Current credentials are incorrect'), ALERT_STATUS_FAIL);
		} else {
			$method = $users->twoFactorEnabled($username) ? 'two-factor' : 'password';
			// A successful step-up is a privilege boundary. Rotate the session ID and
			// CSRF token before granting recent-auth state so a copied pre-step-up
			// session cannot inherit the elevated window.
			if (!brendyRotateAuthenticatedSession(null, $username)) {
				Log::set(__FILE__ . LOG_SEP . 'Recent-authentication succeeded but session rotation failed.', LOG_TYPE_ERROR);
				$login->logout();
				Redirect::page('login');
			}
			if (!$login->markRecentAuthentication($method)) {
				Alert::set($L->g('Unable to establish a recent authentication window'), ALERT_STATUS_FAIL);
			} else {
				$auditRecorded = $syslog->add(array(
					'dictionaryKey' => $usedRecovery ? 'recent-authentication-recovery-code' : 'recent-authentication-successful',
					'notes' => $method,
					'username' => $username
				));
				if (!$auditRecorded) {
					$login->clearRecentAuthentication();
					http_response_code(503);
					Alert::set($L->g('Sensitive action blocked because the activity log integrity could not be verified'), ALERT_STATUS_FAIL);
				} else {
					if ($usedRecovery) {
						Alert::set($L->g('A recovery code was used. Consider generating a fresh set of recovery codes.'), ALERT_STATUS_OK);
					}
					Redirect::page(brendyConsumeRecentAuthenticationReturnRoute());
				}
			}
		}
	}
}

$layout['title'] = $L->g('Confirm identity') . ' - ' . $layout['title'];
$reauthNeedsTwoFactor = $users->twoFactorEnabled($username);
