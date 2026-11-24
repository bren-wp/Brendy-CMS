<?php defined('BRENDY') or die('Brendy CMS.');

$logoPath = HTML_PATH_CORE_IMG . 'logo.svg';
$logoClass = 'logo-icon';
if ($site->logo(false)) {
	$logoPath = $site->logo(true);
	$logoClass = 'logo-icon custom-logo';
}
$logoPath = Theme::escape($logoPath);
$logoAlt = Theme::escape(BRENDY_NAME . ' logo');
$pendingTwoFactorUser = function_exists('pendingTwoFactorUser') ? pendingTwoFactorUser() : false;
$pendingTwoFactor = is_string($pendingTwoFactorUser) && $pendingTwoFactorUser !== '';

echo '<div class="login-logo"><div class="' . $logoClass . '"><img src="' . $logoPath . '" alt="' . $logoAlt . '"></div><h1>' . htmlspecialchars(BRENDY_NAME, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '</h1></div>';
echo BrendyAdminUi::formOpen(array());
echo BrendyAdminUi::formInputHidden(array('name' => 'tokenCSRF', 'value' => $security->getTokenCSRF()));

if ($pendingTwoFactor) {
	echo BrendyAdminUi::formInputHidden(array('name' => 'twoFactorStep', 'value' => '1'));
	echo '<div class="alert alert-light border small" role="status"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>' .
		Theme::escape($L->g('Password accepted. Enter the code from your authenticator app or one unused recovery code.')) . '</div>';
	echo '<div class="form-group"><label for="jstwoFactorCode">' . Theme::escape($L->g('Two-factor code')) . '</label>' .
		'<div class="input-icon-wrapper"><input type="text" class="form-control" id="jstwoFactorCode" name="twoFactorCode" maxlength="64" ' .
		'autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" placeholder="123456" autofocus required>' .
		'<span class="input-icon"><i class="bi bi-key" aria-hidden="true"></i></span></div>' .
		'<small class="form-text text-muted">' . Theme::escape($L->g('Remember me is disabled for accounts protected by two-factor authentication.')) . '</small></div>';
	echo '<button type="submit" class="btn btn-login" name="verifyTwoFactor"><i class="bi bi-shield-check" aria-hidden="true"></i><span>' .
		Theme::escape($L->g('Verify and sign in')) . '</span></button>';
	echo '<button type="submit" class="btn btn-link w-100 mt-2" name="cancelTwoFactor" value="1">' . Theme::escape($L->g('Cancel and sign in again')) . '</button>';
} else {
	$usernameLabel = Theme::escape($L->g('Email or username'));
	$passwordLabel = Theme::escape($L->g('Password'));
	$rememberLabel = Theme::escape($L->g('Remember me'));
	$loginLabel = Theme::escape($L->g('Login'));
	$loginUsernameValue = isset($_POST['username']) && is_string($_POST['username']) ? Sanitize::html($_POST['username']) : '';
	echo '<div class="form-group"><label for="jsusername">' . $usernameLabel . '</label><div class="input-icon-wrapper">' .
		'<input type="text" dir="auto" inputmode="email" value="' . $loginUsernameValue .
		'" class="form-control" id="jsusername" name="username" maxlength="254" spellcheck="false" autocapitalize="none" placeholder="' .
		$usernameLabel . '" autocomplete="username" autofocus required><span class="input-icon"><i class="bi bi-envelope" aria-hidden="true"></i></span></div></div>';
	echo '<div class="form-group"><label for="jspassword">' . $passwordLabel . '</label><div class="input-icon-wrapper">' .
		'<input type="password" class="form-control" id="jspassword" name="password" maxlength="4096" placeholder="' . $passwordLabel .
		'" autocomplete="current-password" required><span class="input-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span></div></div>';
	echo '<div class="form-check"><input class="form-check-input" type="checkbox" value="true" id="jsremember" name="remember">' .
		'<label class="form-check-label" for="jsremember">' . $rememberLabel . '</label></div>';
	echo '<button type="submit" class="btn btn-login" name="save"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span>' . $loginLabel . '</span></button>';
}

echo BrendyAdminUi::formClose();
echo '<div class="login-footer"><p>Brendy CMS · <a href="https://brendigo.com" target="_blank" rel="noopener noreferrer">Brendigo</a></p></div>';
