<?php defined('BRENDY') or die('Brendy CMS.');

class User
{
	protected $vars = array();

	function __construct($username)
	{
		global $users;

		if ($username !== false && !is_string($username)) {
			throw new InvalidArgumentException('Invalid username type.');
		}
		$this->vars['username'] = $username;

		if ($username === false) {
			$row = $users->getDefaultFields();
		} else {
			if (Text::isEmpty($username) || !$users->exists($username)) {
				$errorMessage = 'User not found in the database by username [' . $username . ']';
				Log::set(__METHOD__ . LOG_SEP . $errorMessage);
				throw new Exception($errorMessage);
			}
			$row = $users->getUserDB($username);
		}

		foreach ($row as $field => $value) {
			$this->setField($field, $value);
		}
	}

	public function getValue($field)
	{
		if (isset($this->vars[$field])) {
			return $this->vars[$field];
		}
		return false;
	}

	public function setField($field, $value)
	{
		if (!is_string($field) || $field === '' || strlen($field) > 100) {
			return false;
		}
		$this->vars[$field] = $value;
		return true;
	}

	public function getDB()
	{
		return $this->vars;
	}

	public function username()
	{
		return $this->getValue('username');
	}

	public function description()
	{
		return $this->getValue('description');
	}

	public function nickname()
	{
		return $this->getValue('nickname');
	}

	public function firstName()
	{
		return $this->getValue('firstName');
	}

	public function lastName()
	{
		return $this->getValue('lastName');
	}

	public function tokenAuth()
	{
		return $this->getValue('tokenAuth');
	}

	public function role()
	{
		return $this->getValue('role');
	}

	public function password()
	{
		return $this->getValue('password');
	}

	public function passwordChangedAt()
	{
		return max(0, (int)$this->getValue('passwordChangedAt'));
	}

	public function passwordHistoryCount()
	{
		$history = $this->getValue('passwordHistory');
		return is_array($history) ? min(PASSWORD_HISTORY_LIMIT, count($history)) : 0;
	}

	public function lastLoginAt()
	{
		return max(0, (int)$this->getValue('lastLoginAt'));
	}

	public function lastLoginMethod()
	{
		$method = $this->getValue('lastLoginMethod');
		return is_string($method) && in_array($method, array('password', 'two-factor', 'remember'), true) ? $method : '';
	}

	public function twoFactorEnabled()
	{
		return $this->getValue('twoFactorEnabled') === true;
	}

	public function twoFactorRecoveryCount()
	{
		$codes = $this->getValue('twoFactorRecoveryCodes');
		return is_array($codes) ? count($codes) : 0;
	}

	public function enabled()
	{
		$enabled = $this->getValue('enabled');
		$password = $this->getValue('password');
		return $enabled === true && is_string($password) && $password !== '' && $password !== '!';
	}

	public function hasUsablePassword()
	{
		$password = $this->getValue('password');
		return is_string($password) && $password !== '' && $password !== '!';
	}

	public function salt()
	{
		return $this->getValue('salt');
	}

	public function email()
	{
		return $this->getValue('email');
	}

	public function registered()
	{
		return $this->getValue('registered');
	}

	public function twitter()
	{
		return $this->getValue('twitter');
	}

	public function facebook()
	{
		return $this->getValue('facebook');
	}

	public function codepen()
	{
		return $this->getValue('codepen');
	}

	public function instagram()
	{
		return $this->getValue('instagram');
	}

	public function github()
	{
		return $this->getValue('github');
	}

	public function gitlab()
	{
		return $this->getValue('gitlab');
	}

	public function linkedin()
	{
		return $this->getValue('linkedin');
	}

	public function xing()
	{
		return $this->getValue('xing');
	}

	public function telegram()
	{
		return $this->getValue('telegram');
	}

	public function mastodon()
	{
		return $this->getValue('mastodon');
	}

	public function vk()
	{
		return $this->getValue('vk');
	}

	public function youtube()
	{
		return $this->getValue('youtube');
	}

	public function bluesky()
	{
		return $this->getValue('bluesky');
	}

	public function profilePicture()
	{
		$username = $this->getValue('username');
		if (!is_string($username) || $username === '' || basename($username) !== $username ||
			strpos($username, "\0") !== false || strpos($username, '/') !== false || strpos($username, '\\') !== false) return false;
		$filename = $username . '.png';
		$path = PATH_UPLOADS_PROFILES . $filename;
		if (!is_file($path) || is_link($path)) {
			return false;
		}
		$version = Filesystem::fileVersionToken($path, (string)BRENDY_BUILD);
		return DOMAIN_UPLOADS_PROFILES . rawurlencode($username) . '.png?version=' . rawurlencode($version);
	}

	public function json($returnsArray = false)
	{
		$tmp['username'] 	= $this->username();
		$tmp['firstName'] 	= $this->firstName();
		$tmp['lastName'] 	= $this->lastName();
		$tmp['nickname'] 	= $this->nickname();
		$tmp['description'] 	= $this->description();
		$tmp['twitter'] 	= $this->twitter();
		$tmp['facebook'] 	= $this->facebook();
		$tmp['codepen'] 	= $this->codepen();
		$tmp['instagram'] 	= $this->instagram();
		$tmp['github'] 		= $this->github();
		$tmp['gitlab'] 		= $this->gitlab();
		$tmp['linkedin'] 	= $this->linkedin();
		$tmp['xing'] 		= $this->xing();
		$tmp['telegram'] 		= $this->telegram();
		$tmp['mastodon']	= $this->mastodon();
		$tmp['vk']		= $this->vk();
		$tmp['youtube'] 	= $this->youtube();
		$tmp['bluesky'] 	= $this->bluesky();
		$tmp['profilePicture']	= $this->profilePicture();

		if ($returnsArray) {
			return $tmp;
		}

		return json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
