<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginAPI extends BrendyPlugin
{

	private $method;

	// HTTP status applied to the final response. Inner handlers may override it
	// by calling setStatus() before returning their result. The body still carries
	// the legacy status:'0'|'1' field for backwards compatibility.
	private $httpCode = 200;
	private $httpMessage = 'OK';
	private $authenticatedUsername = null;
	private $inputError = null;

	private function setStatus($code)
	{
		$messages = array(
			200 => 'OK',
			201 => 'Created',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			409 => 'Conflict',
			413 => 'Payload Too Large',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable'
		);
		$this->httpCode = $code;
		$this->httpMessage = isset($messages[$code]) ? $messages[$code] : 'Error';
	}

	public function init()
	{
		// API bearer secrets are stored only as SHA-256 digests. Fresh credentials
		// are generated explicitly from the administration area and shown once.
		$this->dbFields = array(
			'readTokenHash' => '',
			'writeTokenHash' => '',
			'credentialOwner' => '',
			'credentialCreatedAt' => 0,
			'credentialExpiresAt' => 0,
			'numberOfItems' => 15
		);
	}

	private function validTokenHash($value)
	{
		return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
	}

	private function tokenDigest($token)
	{
		if (!is_string($token) || preg_match('/^[a-f0-9]{64,128}$/iD', $token) !== 1) return false;
		return 'sha256:' . hash('sha256', strtolower($token));
	}

	private function tokenMatches($token, $storedHash)
	{
		$digest = $this->tokenDigest($token);
		return is_string($digest) && $this->validTokenHash($storedHash) && hash_equals($storedHash, $digest);
	}

	protected function migrateLegacySettings($loaded)
	{
		$loaded = parent::migrateLegacySettings($loaded);

		// Never keep API secrets in query/body-compatible legacy fields. A historical
		// plaintext read token may be converted to its digest for audit continuity,
		// but credentials without an administrator owner remain inactive until rotated.
		if (isset($loaded['token']) && is_string($loaded['token']) &&
			preg_match('/^[a-f0-9]{64,128}$/iD', $loaded['token']) === 1 &&
			(!isset($loaded['readTokenHash']) || !$this->validTokenHash($loaded['readTokenHash']))) {
			$digest = $this->tokenDigest($loaded['token']);
			if (is_string($digest)) $loaded['readTokenHash'] = $digest;
		}
		if ((!isset($loaded['credentialOwner']) || !is_string($loaded['credentialOwner']) || $loaded['credentialOwner'] === '') &&
			isset($loaded['writeOwner']) && is_string($loaded['writeOwner']) &&
			preg_match('/^[\pL\pN][\pL\pN._@-]{0,99}$/uD', $loaded['writeOwner']) === 1) {
			$loaded['credentialOwner'] = $loaded['writeOwner'];
		}
		unset($loaded['token'], $loaded['writeOwner'], $loaded['legacyCredentialTransport'], $loaded['legacyWriteAuth']);
		return $loaded;
	}

	public function prepare()
	{
		$before = $this->db;
		$changed = false;

		foreach (array('readTokenHash', 'writeTokenHash') as $field) {
			if ($this->db[$field] !== '' && !$this->validTokenHash($this->db[$field])) {
				$this->db[$field] = '';
				$changed = true;
			}
		}
		// Both read and write credentials share one administrator owner so disabling
		// or demoting that account revokes the complete API credential pair.
		if ((!isset($this->db['credentialOwner']) || !is_string($this->db['credentialOwner']) ||
			($this->db['credentialOwner'] !== '' && preg_match('/^[\pL\pN][\pL\pN._@-]{0,99}$/uD', $this->db['credentialOwner']) !== 1))) {
			$this->db['credentialOwner'] = '';
			$this->db['readTokenHash'] = '';
			$this->db['writeTokenHash'] = '';
			$changed = true;
		}
		foreach (array('credentialCreatedAt', 'credentialExpiresAt') as $timeField) {
			$value = isset($this->db[$timeField]) && is_int($this->db[$timeField]) ? $this->db[$timeField] : 0;
			if ($value < 0 || $value > (time() + 3660 * 86400)) $value = 0;
			if (!isset($this->db[$timeField]) || $this->db[$timeField] !== $value) {
				$this->db[$timeField] = $value;
				$changed = true;
			}
		}
		if ($this->db['credentialCreatedAt'] > 0 && $this->db['credentialExpiresAt'] > 0 &&
			$this->db['credentialExpiresAt'] <= $this->db['credentialCreatedAt']) {
			$this->db['credentialExpiresAt'] = 0;
			$changed = true;
		}
		if ($changed && !$this->save()) {
			$this->db = $before;
			Log::set(__METHOD__ . LOG_SEP . 'Unable to persist API credential migration.', LOG_TYPE_ERROR);
			return false;
		}
		return true;
	}

	public function rotateCredentials($username, $expiresInDays = 90)
	{
		global $users;
		if (!is_string($username) || !isset($users) || !is_object($users) || !$users->exists($username)) return false;
		$user = $users->getUserDB($username);
		if (!is_array($user) || !isset($user['role']) || $user['role'] !== 'admin' || !$users->isEnabled($username)) return false;
		$expiresInDays = filter_var($expiresInDays, FILTER_VALIDATE_INT);
		if ($expiresInDays === false || !in_array((int)$expiresInDays, array(0, 30, 90, 180, 365), true)) return false;

		$readToken = bin2hex(random_bytes(32));
		$writeToken = bin2hex(random_bytes(32));
		$now = time();
		$before = $this->db;
		$this->db['readTokenHash'] = $this->tokenDigest($readToken);
		$this->db['writeTokenHash'] = $this->tokenDigest($writeToken);
		$this->db['credentialOwner'] = $username;
		$this->db['credentialCreatedAt'] = $now;
		$this->db['credentialExpiresAt'] = ((int)$expiresInDays === 0) ? 0 : $now + ((int)$expiresInDays * 86400);
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return array(
			'readToken' => $readToken,
			'writeToken' => $writeToken,
			'createdAt' => $this->db['credentialCreatedAt'],
			'expiresAt' => $this->db['credentialExpiresAt']
		);
	}

	private function credentialsActive()
	{
		global $users;
		$expiresAt = isset($this->db['credentialExpiresAt']) ? (int)$this->db['credentialExpiresAt'] : 0;
		if ($expiresAt > 0 && $expiresAt <= time()) return false;
		$owner = isset($this->db['credentialOwner']) && is_string($this->db['credentialOwner']) ? $this->db['credentialOwner'] : '';
		if ($owner === '') return false;
		if (!isset($users) || !is_object($users) || !$users->exists($owner) || !$users->isEnabled($owner)) return false;
		$row = $users->getUserDB($owner);
		return is_array($row) && isset($row['role']) && $row['role'] === 'admin';
	}

	public function credentialStatus()
	{
		$generated = $this->validTokenHash($this->db['readTokenHash']) && $this->validTokenHash($this->db['writeTokenHash']);
		$expiresAt = isset($this->db['credentialExpiresAt']) ? (int)$this->db['credentialExpiresAt'] : 0;
		return array(
			'generated' => $generated,
			'active' => $generated && $this->credentialsActive(),
			'owner' => isset($this->db['credentialOwner']) && is_string($this->db['credentialOwner']) ? $this->db['credentialOwner'] : '',
			'createdAt' => isset($this->db['credentialCreatedAt']) ? (int)$this->db['credentialCreatedAt'] : 0,
			'expiresAt' => $expiresAt,
			'expired' => $expiresAt > 0 && $expiresAt <= time()
		);
	}

	private function verifyReadToken($token)
	{
		return $this->credentialsActive() && $this->tokenMatches($token, $this->db['readTokenHash']);
	}

	// Fixed-window API abuse guard. The bucket key is a SHA-256 digest of the
	// client address and access class, so the workspace never stores raw client
	// IPs. The small local JSON file is protected with flock and purged on every
	// update to keep shared-hosting storage bounded.
	private function consumeRateLimit($method)
	{
		global $security;
		$method = strtoupper((string)$method);
		$isWrite = in_array($method, array('POST', 'PUT', 'DELETE'), true);
		$limit = $isWrite ? 60 : 300;
		$window = 60;
		$ip = isset($security) && is_object($security) && method_exists($security, 'getUserIp')
			? (string)$security->getUserIp() : '';
		$context = $isWrite ? 'api-write' : 'api-read';
		$key = ($ip !== '' && isset($security) && is_object($security) && method_exists($security, 'pseudonymizeSecurityIdentifier'))
			? $security->pseudonymizeSecurityIdentifier(strtolower($ip), $context) : false;
		if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
			Log::set(__METHOD__ . LOG_SEP . 'API identity pseudonymization is unavailable.', LOG_TYPE_ERROR);
			$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
		}
		$workspace = $this->workspace();
		if ((!is_dir($workspace) && !Filesystem::mkdir($workspace, true)) || is_link($workspace)) {
			Log::set(__METHOD__ . LOG_SEP . 'API rate-limit workspace is unavailable.', LOG_TYPE_ERROR);
			$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
		}
		$lockPath = $workspace . 'api-rate-limit.lock';
		$handle = Filesystem::acquireLock($lockPath, LOCK_EX, 0600);
		if ($handle === false) {
			Log::set(__METHOD__ . LOG_SEP . 'Unable to acquire a safe API rate-limit lock.', LOG_TYPE_ERROR);
			$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
		}
		try {
			$file = $workspace . 'api-rate-limit.json';
			$data = array();
			if (file_exists($file) || is_link($file)) {
				$raw = Filesystem::readFileBounded($file, 2097152, 2);
				if (!is_string($raw)) {
					Log::set(__METHOD__ . LOG_SEP . 'Unsafe, unreadable or oversized API rate-limit state.', LOG_TYPE_ERROR);
					$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
				}
				try {
					$decoded = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
				} catch (JsonException $e) {
					$decoded = null;
				}
				if (!is_array($decoded)) {
					Log::set(__METHOD__ . LOG_SEP . 'Malformed API rate-limit state.', LOG_TYPE_ERROR);
					$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
				}
				$data = $decoded;
			}
			$now = time();
			foreach ($data as $bucketKey => $entry) {
				if (!is_string($bucketKey) || preg_match('/^[a-f0-9]{64}$/D', $bucketKey) !== 1 ||
					!is_array($entry) || !isset($entry['reset'], $entry['count']) ||
					!is_numeric($entry['reset']) || !is_numeric($entry['count']) || (int)$entry['reset'] <= $now) {
					unset($data[$bucketKey]);
				}
			}
			$reset = $now + $window;
			$count = 0;
			if (isset($data[$key]) && is_array($data[$key])) {
				$reset = max($now + 1, min($now + $window, (int)$data[$key]['reset']));
				$count = max(0, (int)$data[$key]['count']);
			}
			$count++;
			$data[$key] = array('reset' => $reset, 'count' => $count);
			if (count($data) > 5000) {
				uasort($data, function ($a, $b) { return (int)$a['reset'] <=> (int)$b['reset']; });
				$data = array_slice($data, -5000, null, true);
			}
			$json = json_encode($data, JSON_UNESCAPED_SLASHES);
			if (!is_string($json) || !Filesystem::atomicWrite($file, $json . PHP_EOL, 0600)) {
				Log::set(__METHOD__ . LOG_SEP . 'Unable to persist API rate-limit state.', LOG_TYPE_ERROR);
				$this->response(503, 'Service Unavailable', array('status' => '1', 'message' => 'API protection storage is unavailable.'));
			}
			$remaining = max(0, $limit - $count);
			if (!headers_sent()) {
				header('X-RateLimit-Limit: ' . $limit);
				header('X-RateLimit-Remaining: ' . $remaining);
				header('X-RateLimit-Reset: ' . $reset);
			}
			if ($count > $limit) {
				$retry = max(1, $reset - $now);
				if (!headers_sent()) header('Retry-After: ' . $retry);
				$this->response(429, 'Too Many Requests', array('message' => 'API rate limit exceeded.', 'retryAfter' => $retry));
			}
			return true;
		} finally {
			Filesystem::releaseLock($handle);
		}
	}

	private function verifyWriteToken($token)
	{
		global $users;
		if (!$this->credentialsActive()) return false;
		if ($this->tokenMatches($token, $this->db['writeTokenHash'])) {
			$owner = $this->db['credentialOwner'];
			if (!is_string($owner) || !isset($users) || !is_object($users) || !$users->exists($owner)) return false;
			$row = $users->getUserDB($owner);
			if (!is_array($row) || !isset($row['role']) || $row['role'] !== 'admin' || !$users->isEnabled($owner)) return false;
			$this->authenticatedUsername = $owner;
			return true;
		}

		return false;
	}


	public function healthStatus()
	{
		$health = parent::healthStatus();
		$issues = isset($health['issues']) && is_array($health['issues']) ? $health['issues'] : array();
		$status = isset($health['status']) ? $health['status'] : 'ok';
		if (!$this->validTokenHash($this->db['readTokenHash']) || !$this->validTokenHash($this->db['writeTokenHash'])) {
			$issues[] = 'api-credentials-not-generated';
			if ($status === 'ok') $status = 'warning';
		}
		$credentialStatus = $this->credentialStatus();
		if ($credentialStatus['generated'] && $credentialStatus['owner'] === '') {
			$issues[] = 'api-credentials-owner-unbound';
			if ($status === 'ok') $status = 'warning';
		}
		if ($credentialStatus['expired']) {
			$issues[] = 'api-credentials-expired';
			if ($status === 'ok') $status = 'warning';
		}
		return array('status' => $status, 'issues' => array_values(array_unique($issues)));
	}


	public function post()
	{
		$numberOfItems = brendyIntegerParameter($_POST, 'numberOfItems', 15, 1, 100);
		if ($numberOfItems === null) return false;
		$before = $this->db;
		$this->db['numberOfItems'] = (int)$numberOfItems;
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	public function form()
	{
		global $L;
		$html = $this->settingsIntro();
		$html .= '<div class="form-group">';
		$html .= '<label>' . Theme::escape($L->get('URL')) . '</label>';
		$html .= '<p class="text-muted text-break"><code>' . Theme::escape(DOMAIN_BASE . 'api/{endpoint}') . '</code></p>';
		$html .= '</div>';
		$html .= '<div class="form-group">';
		$html .= '<label>' . Theme::escape($L->get('API Token')) . '</label>';
		$html .= '<p class="text-muted">' . Theme::escape($L->get('API credentials are generated from the API access screen and are not stored in readable form')) . '</p>';
		$html .= '</div>';
		return $html;
	}


	// API HOOKS
	// ----------------------------------------------------------------------------

	public function beforeAll()
	{

		// CHECK URL
		// ------------------------------------------------------------
		$URI = $this->webhook('api', true, false);
		if ($URI === false) {
			return false;
		}

		// METHOD
		// ------------------------------------------------------------
		$method = $this->getMethod();
		if (!in_array($method, array('GET', 'POST', 'PUT', 'DELETE'), true)) {
			header('Allow: GET, POST, PUT, DELETE');
			$this->response(405, 'Method Not Allowed', array('message' => 'Method not allowed.'));
		}

		$this->consumeRateLimit($method);

		// METHOD INPUTS
		// ------------------------------------------------------------
		$inputs = $this->getMethodInputs();
		if ($this->inputError !== null) {
			$this->response($this->inputError === 'too-large' ? 413 : 400, $this->inputError === 'too-large' ? 'Payload Too Large' : 'Bad Request', array(
				'message' => $this->inputError === 'too-large' ? 'Request body is too large.' : 'Malformed request payload.'
			));
		}
		// API credentials are header-only. Reject query/body credential aliases even
		// when a valid header is also present so secrets cannot leak into access logs,
		// browser history, analytics or application payload traces.
		if (array_key_exists('token', $inputs) || array_key_exists('authentication', $inputs) ||
			array_key_exists('token', $_GET) || array_key_exists('authentication', $_GET)) {
			$this->response(400, 'Bad Request', array('message' => 'API credentials must be sent only in request headers.'));
		}

		$authorization = isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])
			? trim($_SERVER['HTTP_AUTHORIZATION']) : '';
		$readToken = '';
		if ($authorization !== '') {
			if (preg_match('/^Bearer\s+([a-f0-9]{64,128})$/iD', $authorization, $matches) !== 1) {
				$this->response(400, 'Bad Request', array('message' => 'Invalid Authorization header.'));
			}
			$readToken = strtolower($matches[1]);
		}

		$authenticationHeader = isset($_SERVER['HTTP_X_BRENDY_AUTHENTICATION']) &&
			is_string($_SERVER['HTTP_X_BRENDY_AUTHENTICATION'])
			? trim($_SERVER['HTTP_X_BRENDY_AUTHENTICATION']) : '';
		$writeToken = '';
		if ($authenticationHeader !== '') {
			if (preg_match('/^[a-f0-9]{64,128}$/iD', $authenticationHeader) !== 1) {
				$this->response(400, 'Bad Request', array('message' => 'Invalid write authentication header.'));
			}
			$writeToken = strtolower($authenticationHeader);
		}

		// ENDPOINT PARAMETERS
		// ------------------------------------------------------------
		$parameters = $this->getEndpointParameters($URI);
		if (empty($parameters)) {
			$this->response(400, 'Bad Request', array('message' => 'Missing endpoint parameters.'));
		}

		// API READ TOKEN
		// ------------------------------------------------------------
		if ($readToken === '') {
			$this->response(401, 'Unauthorized', array('message' => 'Missing API token.'));
		}
		if (!$this->verifyReadToken($readToken)) {
			$this->response(401, 'Unauthorized', array('message' => 'Invalid API token.'));
		}

		// API WRITE TOKEN
		// ------------------------------------------------------------
		$writePermissions = $writeToken !== '' && $this->verifyWriteToken($writeToken);

		// API responses may contain private drafts/settings when write credentials
		// are present; never let a browser/proxy persist those authenticated bodies.
		if (!headers_sent()) {
			header('Cache-Control: no-store, max-age=0, private');
			header('Pragma: no-cache');
			header('X-Content-Type-Options: nosniff');
			header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
		}

		// Clean inputs
		// ------------------------------------------------------------
		$parameterCount = count($parameters);
		if (in_array($method, array('POST', 'PUT', 'DELETE'), true) && !$writePermissions) {
			$this->response(403, 'Forbidden', array('message' => 'Write authentication is required.'));
		}

		// ENDPOINTS
		// ------------------------------------------------------------

		// (GET) /api/meta - authenticated capability discovery for integrations.
		if (($method === 'GET') && ($parameters[0] === 'meta') && $parameterCount === 1) {
			$data = $this->getMeta($writePermissions);
		}
		// (GET) /api/pages
		elseif (($method === 'GET') && ($parameters[0] === 'pages') && $parameterCount === 1) {
			$data = $this->getPages($inputs, $writePermissions);
		}
		// (GET) /api/pages/<key>, including nested page keys
		elseif (($method === 'GET') && ($parameters[0] === 'pages') && $parameterCount >= 2) {
			$pageKey = $this->pageKeyFromParameters($parameters);
			if ($pageKey === false) $this->response(400, 'Bad Request', array('message' => 'Invalid page key.'));
			$data = $this->getPage($pageKey, $writePermissions);
		}
		// (PUT) /api/pages/<key>
		elseif (($method === 'PUT') && ($parameters[0] === 'pages') && $parameterCount >= 2 && $writePermissions) {
			$pageKey = $this->pageKeyFromParameters($parameters);
			if ($pageKey === false) $this->response(400, 'Bad Request', array('message' => 'Invalid page key.'));
			$data = $this->editPage($pageKey, $inputs);
		}
		// (DELETE) /api/pages/<key>
		elseif (($method === 'DELETE') && ($parameters[0] === 'pages') && $parameterCount >= 2 && $writePermissions) {
			$pageKey = $this->pageKeyFromParameters($parameters);
			if ($pageKey === false) $this->response(400, 'Bad Request', array('message' => 'Invalid page key.'));
			$data = $this->deletePage($pageKey);
		}
		// (POST) /api/pages
		elseif (($method === 'POST') && ($parameters[0] === 'pages') && $parameterCount === 1 && $writePermissions) {
			$data = $this->createPage($inputs);
		}
		// (GET) /api/settings
		elseif (($method === 'GET') && ($parameters[0] === 'settings') && $parameterCount === 1 && $writePermissions) {
			$data = $this->getSettings();
		}
		// (PUT) /api/settings
		elseif (($method === 'PUT') && ($parameters[0] === 'settings') && $parameterCount === 1 && $writePermissions) {
			$data = $this->editSettings($inputs);
		}
		// (POST) /api/images
		elseif (($method === 'POST') && ($parameters[0] === 'images') && $parameterCount === 1 && $writePermissions) {
			$data = $this->uploadImage($inputs);
		}
		// (GET) /api/tags
		elseif (($method === 'GET') && ($parameters[0] === 'tags') && $parameterCount === 1) {
			$data = $this->getTags();
		}
		// (GET) /api/tags/<key>
		elseif (($method === 'GET') && ($parameters[0] === 'tags') && $parameterCount === 2) {
			$tagKey = $parameters[1];
			$data = $this->getTag($tagKey);
		}
		// (GET) /api/categories
		elseif (($method === 'GET') && ($parameters[0] === 'categories') && $parameterCount === 1) {
			$data = $this->getCategories();
		}
		// (GET) /api/categories/<key>
		elseif (($method === 'GET') && ($parameters[0] === 'categories') && $parameterCount === 2) {
			$categoryKey = $parameters[1];
			$data = $this->getCategory($categoryKey);
		}
		// (GET) /api/users
		elseif (($method === 'GET') && ($parameters[0] === 'users') && $parameterCount === 1) {
			$data = $this->getUsers($writePermissions);
		}
		// (GET) /api/users/<username>
		elseif (($method === 'GET') && ($parameters[0] === 'users') && $parameterCount === 2) {
			$username = $parameters[1];
			$data = $this->getUser($username, $writePermissions);
		}
		// (GET) /api/files/<page-key>
		elseif (($method === 'GET') && ($parameters[0] === 'files') && $parameterCount >= 2) {
			$pageKey = $this->pageKeyFromParameters($parameters);
			if ($pageKey === false) $this->response(400, 'Bad Request', array('message' => 'Invalid page key.'));
			$data = $this->getFiles($pageKey, $writePermissions);
		}
		// (POST) /api/files/<page-key>
		elseif (($method === 'POST') && ($parameters[0] === 'files') && $parameterCount >= 2 && $writePermissions) {
			$pageKey = $this->pageKeyFromParameters($parameters);
			if ($pageKey === false) $this->response(400, 'Bad Request', array('message' => 'Invalid page key.'));
			$data = $this->uploadFile($pageKey);
		} else {
				$this->response(404, 'Not Found', array('message' => 'Invalid endpoint.'));
		}

		$this->response($this->httpCode, $this->httpMessage, $data);
	}

	// PRIVATE METHODS
	// ----------------------------------------------------------------------------

	// Validate page key to prevent path traversal (CWE-22)
	private function isValidPageKey($pageKey)
	{
		return isValidPageKey($pageKey);
	}

	private function pageKeyFromParameters($parameters)
	{
		if (!is_array($parameters) || count($parameters) < 2) return false;
		$pageKey = implode('/', array_slice($parameters, 1));
		return $this->isValidPageKey($pageKey) ? $pageKey : false;
	}

	private function getMeta($writePermissions)
	{
		$status = $this->credentialStatus();
		$endpoints = array(
			array('path' => '/api/meta', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/pages', 'methods' => array('GET', 'POST'), 'write' => 'POST'),
			array('path' => '/api/pages/{key}', 'methods' => array('GET', 'PUT', 'DELETE'), 'write' => array('PUT', 'DELETE')),
			array('path' => '/api/tags', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/tags/{key}', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/categories', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/categories/{key}', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/users', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/users/{username}', 'methods' => array('GET'), 'write' => false),
			array('path' => '/api/files/{page-key}', 'methods' => array('GET', 'POST'), 'write' => 'POST'),
			array('path' => '/api/images', 'methods' => array('POST'), 'write' => true),
			array('path' => '/api/settings', 'methods' => array('GET', 'PUT'), 'write' => true)
		);
		return array(
			'status' => '0',
			'message' => 'API capabilities.',
			'data' => array(
				'cms' => defined('BRENDY_NAME') ? BRENDY_NAME : 'Brendy CMS',
				'cmsVersion' => defined('BRENDY_VERSION') ? BRENDY_VERSION : '',
				'apiVersion' => '2.3',
				'authenticatedWrite' => $writePermissions === true,
				'credentialExpiresAt' => $status['expiresAt'],
				'endpoints' => $endpoints
			)
		);
	}

	private function getMethod()
	{
		// METHODS
		// ------------------------------------------------------------
		// GET
		// POST
		// PUT
		// DELETE

		$this->method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
			? strtoupper($_SERVER['REQUEST_METHOD']) : '';
		return $this->method;
	}

	private function getMethodInputs()
	{
		$this->inputError = null;
		$contentType = isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])
			? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'], 2)[0])) : '';

		switch ($this->method) {
			case 'GET':
			case 'DELETE':
				return $this->cleanInputs($_GET);
			case 'POST':
				// PHP already parses form and multipart bodies. Reading php://input again
				// for an empty multipart request would treat MIME boundaries as JSON.
				if (!empty($_POST) || !empty($_FILES) || $contentType === 'multipart/form-data' ||
					$contentType === 'application/x-www-form-urlencoded') {
					return $this->cleanInputs($_POST);
				}
				break;
			case 'PUT':
				break;
			default:
				$this->inputError = 'malformed';
				return array();
		}

		$raw = file_get_contents('php://input', false, null, 0, 2097153);
		if (!is_string($raw)) {
			$this->inputError = 'malformed';
			return array();
		}
		if (strlen($raw) > 2097152) {
			$this->inputError = 'too-large';
			return array();
		}
		if (trim($raw) === '') {
			$this->inputError = 'malformed';
			return array();
		}
		return $this->cleanInputs($raw);
	}

	// Returns an array with key=>value with the inputs
	// If the content is JSON is parsed to array
	//
	// Note: DB-bound values are sanitized by core (Pages::add/edit, Site::set);
	// sanitizing again here would double-encode form/query values like
	// "Cats & Dogs" into "Cats &amp;amp; Dogs".
	private function cleanInputs($inputs)
	{
		if (is_array($inputs)) {
			if ($this->validateInputTree($inputs)) return $inputs;
			$this->inputError = 'malformed';
			return array();
		}
		if (is_string($inputs)) {
			if (strlen($inputs) > 2 * 1024 * 1024) {
				$this->inputError = 'too-large';
				return array();
			}
			$decoded = json_decode($inputs, true, 32);
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !$this->validateInputTree($decoded)) {
				$this->inputError = 'malformed';
				return array();
			}
			return $decoded;
		}
		$this->inputError = 'malformed';
		return array();
	}

	private function validateInputTree($value, $depth = 0, &$items = 0, &$bytes = 0)
	{
		if ($depth > 8) return false;
		if (is_array($value)) {
			$items += count($value);
			if ($items > 5000) return false;
			foreach ($value as $key => $child) {
				if ((!is_string($key) && !is_int($key)) || (is_string($key) && (strlen($key) > 200 || preg_match('//u', $key) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $key)))) return false;
				if (is_string($key)) $bytes += strlen($key);
				if ($bytes > 2097152 || !$this->validateInputTree($child, $depth + 1, $items, $bytes)) {
					return false;
				}
			}
			return true;
		}
		if (is_string($value)) {
			$bytes += strlen($value);
			return $bytes <= 2097152 && preg_match('//u', $value) === 1 && strpos($value, "\0") === false;
		}
		return is_scalar($value) || $value === null;
	}

	private function getEndpointParameters($URI)
	{
		// ENDPOINT Parameters
		// ------------------------------------------------------------
		// /api/pages 		| GET  | returns all pages
		// /api/pages/{key}	| GET  | returns the page with the {key}
		// /api/pages 		| POST | create a new page

		if (!is_string($URI) || strlen($URI) > 2048) {
			return array();
		}
		$URI = trim($URI, '/');
		if ($URI === '') {
			return array();
		}
		$parameters = explode('/', $URI);
		if (count($parameters) > 33) {
			return array();
		}

		// Sanitize parameters
		foreach ($parameters as $key => $value) {
			if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) ||
				strpos($value, '/') !== false || strpos($value, '\\') !== false) {
				return array();
			}
			$parameters[$key] = $value;
		}

		return $parameters;
	}

	private function response($code = 200, $message = 'OK', $data = array())
	{
		$code = (int)$code;
		if ($code === 401 && !headers_sent()) header('WWW-Authenticate: Bearer realm="Brendy CMS API"');
		sendJsonResponse($data, $code, '{"status":"1","message":"Response encoding failed."}');
	}

	private function getTags()
	{
		global $tags;
		$tmp = array(
			'status' => '0',
			'message' => 'List of tags.',
			'data' => array()
		);
		foreach (array_slice($tags->keys(), 0, 1000) as $key) {
			$tag = $tags->getMap($key);
			array_push($tmp['data'], $tag);
		}
		return $tmp;
	}

	// Returns the tag information and the pages releated to the tag
	// The array with the pages has the complete information of each page
	private function getTag($key)
	{
		try {
			$tag = new Tag($key);
		} catch (Throwable $e) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'Tag not found by the key: ' . $key
			);
		}

		$list = array();
		foreach (array_slice($tag->pages(), 0, 1000) as $pageKey) {
			try {
				$page = new Page($pageKey);
				if (!$this->isPublicPage($page)) continue;
				array_push($list, $page->json(true));
			} catch (Throwable $e) {
			}
		}

		$data = $tag->json($returnsArray = true);
		$data['pages'] = $list;

		return array(
			'status' => '0',
			'message' => 'Information about the tag and pages related.',
			'data' => $data
		);
	}

	private function getPages($args, $includeUnpublished = false)
	{
		global $pages;

		// Query parameters are an API contract, not PHP truthy values. Reject
		// malformed booleans/integers instead of silently changing the query.
		$booleanDefaults = array(
			'published' => true, 'static' => false, 'draft' => false,
			'sticky' => false, 'scheduled' => false, 'untagged' => false
		);
		$booleanValues = array();
		foreach ($booleanDefaults as $key => $default) {
			$value = brendyBooleanParameter($args, $key, $default);
			if ($value === null) {
				$this->setStatus(400);
				return array('status' => '1', 'message' => 'Invalid boolean parameter: ' . $key);
			}
			$booleanValues[$key] = $value;
		}
		$published = $booleanValues['published'];
		$static = $booleanValues['static'];
		$draft = $includeUnpublished && $booleanValues['draft'];
		$sticky = $booleanValues['sticky'];
		$scheduled = $includeUnpublished && $booleanValues['scheduled'];
		$untagged = $booleanValues['untagged'];

		$numberOfItems = (int)$this->getValue('numberOfItems');
		if (array_key_exists('numberOfItems', $args)) {
			$parsed = is_scalar($args['numberOfItems']) ? filter_var($args['numberOfItems'], FILTER_VALIDATE_INT) : false;
			if ($parsed === false || $parsed < 0 || $parsed > 100) {
				$this->setStatus(400);
				return array('status' => '1', 'message' => 'Invalid numberOfItems parameter.');
			}
			$numberOfItems = (int)$parsed;
		}
		if ($numberOfItems === 0) $numberOfItems = (int)$this->getValue('numberOfItems');
		if ($numberOfItems < 1) $numberOfItems = 15;
		$numberOfItems = min($numberOfItems, 100);

		$pageNumber = 1;
		if (array_key_exists('pageNumber', $args)) {
			$parsed = is_scalar($args['pageNumber']) ? filter_var($args['pageNumber'], FILTER_VALIDATE_INT) : false;
			if ($parsed === false || $parsed < 1 || $parsed > 1000000) {
				$this->setStatus(400);
				return array('status' => '1', 'message' => 'Invalid pageNumber parameter.');
			}
			$pageNumber = (int)$parsed;
		}

		if ($includeUnpublished) {
			$list = $pages->getList($pageNumber, $numberOfItems, $published, $static, $sticky, $draft, $scheduled, $untagged);
			$total = $pages->countByType($published, $static, $sticky, $draft, $scheduled, $untagged);
		} else {
			$list = $pages->getPublicList($pageNumber, $numberOfItems, $published, $static, $sticky, $untagged);
			$total = $pages->countPublic($published, $static, $sticky, $untagged);
		}
		// List methods return false when pageNumber is past the end; treat as empty.
		if ($list === false) $list = array();

		// Count the exact same pre-pagination result set so meta.total/hasMore
		// remain correct for every filter, including untagged=true.
		$hasMore = ($numberOfItems > 0) && (($pageNumber * $numberOfItems) < $total);

		$tmp = array(
			'status' => '0',
			'message' => 'List of pages',
			'numberOfItems' => $numberOfItems,
			'meta' => array(
				'total' => $total,
				'pageNumber' => $pageNumber,
				'pageSize' => $numberOfItems,
				'hasMore' => $hasMore
			),
			'data' => array()
		);

		foreach ($list as $pageKey) {
			try {
				$page = new Page($pageKey);
				array_push($tmp['data'], $page->json(true));
			} catch (Throwable $e) {
				// Continue; one malformed legacy row must not break the full list.
			}
		}

		return $tmp;
	}

	private function getPage($key, $includeUnpublished = false)
	{
		try {
			$page = new Page($key);
			if (!$includeUnpublished && !$this->isPublicPage($page)) {
				$this->setStatus(404);
				return array('status' => '1', 'message' => 'Page not found.');
			}
			return array(
				'status' => '0',
				'message' => 'Page filtered by key: ' . $key,
				'data' => $page->json(true)
			);
		} catch (Throwable $e) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'Page not found.'
			);
		}
	}

	private function createPage($args)
	{
		// This function is defined on functions.php
		$key = createPage($args, $this->authenticatedUsername);
		if ($key === false) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'Error trying to create the new page.'
			);
		}

		$this->setStatus(201);
		try {
			$page = new Page($key);
			return array(
				'status' => '0',
				'message' => 'Page created.',
				'data' => $page->json(true)
			);
		} catch (Exception $e) {
			// The page was created but failed to load. Fall back to the legacy
			// minimal payload so the caller still gets the key.
			return array(
				'status' => '0',
				'message' => 'Page created.',
				'data' => array('key' => $key)
			);
		}
	}

	private function editPage($key, $args)
	{
		global $pages;

		if (!$pages->exists($key)) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'Page not found.'
			);
		}

		$args['key'] = $key;
		$newKey = editPage($args, $this->authenticatedUsername);

		if ($newKey === false) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'Error trying to edit the page.'
			);
		}

		try {
			$page = new Page($newKey);
			return array(
				'status' => '0',
				'message' => 'Page edited.',
				'data' => $page->json(true)
			);
		} catch (Exception $e) {
			// The page was edited but failed to load. Fall back to the legacy
			// minimal payload so the caller still gets the key.
			return array(
				'status' => '0',
				'message' => 'Page edited.',
				'data' => array('key' => $newKey)
			);
		}
	}

	private function deletePage($key)
	{
		global $pages;

		if (!$pages->exists($key)) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'Page not found.'
			);
		}

		if (deletePage($key, $this->authenticatedUsername)) {
			return array(
				'status' => '0',
				'message' => 'Page deleted.'
			);
		}

		$this->setStatus(500);
		return array(
			'status' => '1',
			'message' => 'Error trying to delete the page.'
		);
	}

	/*
	| Upload an image and generate the thumbnails
	| Returns the image and thumbnail URL
	|
	| @inputs		array
	| @inputs['uuid']	string	Page UUID
	| @_FILE		array	https://www.php.net/manual/en/reserved.variables.files.php
	|
	| @return		array
	*/
	private function uploadImage($inputs)
	{
		global $pages;
		// Set upload directory
		if (isset($inputs['uuid']) && IMAGE_RESTRICT) {
			if (!is_string($inputs['uuid']) || preg_match('/^[a-f0-9]{32}$/i', $inputs['uuid']) !== 1) {
				$this->setStatus(400);
				return array('status' => '1', 'message' => 'Invalid UUID.');
			}
			$imageDirectory 	= PATH_UPLOADS_PAGES . $inputs['uuid'] . DS;
			$thumbnailDirectory 	= $imageDirectory . 'thumbnails' . DS;
			$imageEndpoint 		= DOMAIN_UPLOADS_PAGES . $inputs['uuid'] . '/';
			$thumbnailEndpoint 	= $imageEndpoint . 'thumbnails' . '/';
			if ($pages->getByUUID($inputs['uuid']) === false) {
				$this->setStatus(404);
				return array('status' => '1', 'message' => 'Page UUID not found.');
			}
			if (!Filesystem::directoryExists($thumbnailDirectory) && !Filesystem::mkdir($thumbnailDirectory, true)) {
				$this->setStatus(500);
				return array('status' => '1', 'message' => 'Upload directory is unavailable.');
			}
		} else {
			$imageDirectory 	= PATH_UPLOADS;
			$thumbnailDirectory 	= PATH_UPLOADS_THUMBNAILS;
			$imageEndpoint 		= DOMAIN_UPLOADS;
			$thumbnailEndpoint 	= DOMAIN_UPLOADS_THUMBNAILS;
		}

		if (!isset($_FILES['image']) || !is_array($_FILES['image']) ||
			!isset($_FILES['image']['name'], $_FILES['image']['tmp_name'], $_FILES['image']['error'], $_FILES['image']['size']) ||
			!is_string($_FILES['image']['name'])) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'No image sent.'
			);
		}

		if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'Error uploading the image, maximum load file size allowed: ' . Filesystem::bytesToHumanFileSize(min((int)Text::toBytes(ini_get('upload_max_filesize')), BRENDY_MAX_IMAGE_UPLOAD_BYTES))
			);
		}
		if (!is_scalar($_FILES['image']['size']) || (int)$_FILES['image']['size'] < 1 ||
			(int)$_FILES['image']['size'] > BRENDY_MAX_IMAGE_UPLOAD_BYTES || !is_string($_FILES['image']['tmp_name']) ||
			!is_uploaded_file($_FILES['image']['tmp_name'])) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'Invalid or oversized image upload.');
		}

		$originalName = $_FILES['image']['name'];
		$filename = brendySanitizeUploadedFilename($originalName, 'image');
		if ($filename === false || !validateUploadedImage($_FILES['image']['tmp_name'], $filename)) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'Invalid image file.');
		}
		if (!Filesystem::directoryExists(PATH_TMP) && !Filesystem::mkdir(PATH_TMP, true)) {
			$this->setStatus(500);
			return array('status' => '1', 'message' => 'Temporary directory is unavailable.');
		}
		$temporaryFilename = Filesystem::nextFilename($filename, PATH_TMP);
		if ($temporaryFilename === false) {
			$this->setStatus(500);
			return array('status' => '1', 'message' => 'Unable to allocate a safe temporary filename.');
		}
		if (!moveUploadedFileSafely($_FILES['image']['tmp_name'], PATH_TMP . $temporaryFilename)) {
			$this->setStatus(500);
			return array('status' => '1', 'message' => 'Unable to store uploaded image.');
		}

		// Transform image and create thumbnails
		$image = transformImage(PATH_TMP . $temporaryFilename, $imageDirectory, $thumbnailDirectory);
		if ($image) {
			$filename = Filesystem::filename($image);
			return array(
				'status' => '0',
				'message' => 'Image uploaded.',
				'image' => $imageEndpoint . rawurlencode($filename),
				'thumbnail' => $thumbnailEndpoint . rawurlencode($filename)
			);
		}

		$this->setStatus(400);
		return array(
			'status' => '1',
			'message' => 'Image extension not allowed.'
		);
	}

	/*
	 | Get the settings
	 |
	 | @args	array
	 |
	 | @return	array
         */
	private function getSettings()
	{
		global $site;
		return array(
			'status' => '0',
			'message' => 'Settings.',
			'data' => $site->get()
		);
	}

	/*
	 | Edit the settings
	 | You can edit any field defined in the class site.class.php variable $dbFields
         |
         | @args	array
	 |
	 | @return	array
         */
	private function editSettings($args)
	{
		if (editSettings($args, $this->authenticatedUsername)) {
			return array(
				'status' => '0',
				'message' => 'Settings edited.'
			);
		}
		$this->setStatus(400);
		return array(
			'status' => '1',
			'message' => 'Error trying to edit the settings.'
		);
	}

	/*
	 | Returns the categories in the system
	 | Included the category name, key, description and the list of pages
	 | The list of pages are the page's key
	 |
	 | @return	array
         */
	private function getCategories()
	{
		global $categories;
		$tmp = array(
			'status' => '0',
			'message' => 'List of categories.',
			'data' => array()
		);
		foreach (array_slice($categories->keys(), 0, 1000) as $key) {
			$category = $categories->getMap($key);
			array_push($tmp['data'], $category);
		}
		return $tmp;
	}

	/*
	 | Returns information about the category and pages related
	 | The pages are expanded which mean the title, content and more fields are returned in the query
	 | This can degrade the performance
	 |
	 | @key		string	Category key
	 |
	 | @return	array
         */
	private function getCategory($key)
	{
		try {
			$category = new Category($key);
		} catch (Throwable $e) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'Category not found by the key: ' . $key
			);
		}

		$list = array();
		foreach (array_slice($category->pages(), 0, 1000) as $pageKey) {
			try {
				$page = new Page($pageKey);
				if (!$this->isPublicPage($page)) continue;
				array_push($list, $page->json(true));
			} catch (Throwable $e) {
			}
		}

		$data = $category->json($returnsArray = true);
		$data['pages'] = $list;

		return array(
			'status' => '0',
			'message' => 'Information about the category and pages related.',
			'data' => $data
		);
	}

	/*
	 | Returns the user profile
	 |
	 | @username	string	Username
	 |
	 | @return	array
         */
	private function getUser($username, $includeDisabled = false)
	{
		try {
			$user = new User($username);
		} catch (Throwable $e) {
			$this->setStatus(404);
			return array(
				'status' => '1',
				'message' => 'User not found by username: ' . $username
			);
		}

		if (!$includeDisabled && !$user->enabled()) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'User not found.');
		}

		$data = $user->json($returnsArray = true);
		return array(
			'status' => '0',
			'message' => 'User profile.',
			'data' => $data
		);
	}

	/*
	 | Returns all the users
	 |
	 | @return	array
         */
	private function getUsers($includeDisabled = false)
	{
		global $users;
		$data = array();
		foreach (array_slice($users->db, 0, 1000, true) as $username => $profile) {
			try {
				$user = new User($username);
				if (!$includeDisabled && !$user->enabled()) continue;
				$data[$username] = $user->json($returnsArray = true);
			} catch (Throwable $e) {
				continue;
			}
		}

		return array(
			'status' => '0',
			'message' => 'Users profiles.',
			'data' => $data
		);
	}

	/*
	 | Returns all files uploaded for a specific page, includes any type of file.
	 |
	 | @return	array
         */
	private function getFiles($pageKey, $includeUnpublished = false)
	{
		try {
			$page = new Page($pageKey);
			if (!$includeUnpublished && !$this->isPublicPage($page)) {
				$this->setStatus(404);
				return array('status' => '1', 'message' => 'Upload directory not found.');
			}
		} catch (Throwable $e) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'Upload directory not found.');
		}
		$uuid = $page->uuid();
		if (!is_string($uuid) || preg_match('/^[a-f0-9]{32}$/iD', $uuid) !== 1) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'Upload directory not found.');
		}
		$chunk = false;
		$sortByDate = true;
		// Store and enumerate files through the canonical UUID directory. Page-key
		// paths are compatibility symlinks and safe upload helpers deliberately
		// refuse to write through symlinks.
		$path = PATH_UPLOADS_PAGES . $uuid . DS;
		$basePath = realpath(PATH_UPLOADS_PAGES);
		$realPath = realpath($path);
		if (!Filesystem::directoryExists($path) || $basePath === false || $realPath === false ||
			($realPath !== $basePath && strpos($realPath, $basePath . DS) !== 0)) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'Upload directory not found.');
		}
		$listFiles = Filesystem::listFiles($path, '*', '*', $sortByDate, $chunk);
		$listFiles = array_slice($listFiles, 0, 1000);
		$baseUrl = DOMAIN_UPLOADS_PAGES . rawurlencode($uuid) . '/';

		$files = array();
		foreach ($listFiles as $file) {
			if (!is_file($file) || is_link($file)) {
				continue;
			}
			$info = array('thumbnail' => '');
			$info['filename'] = basename($file);
			$extension = Text::lowercase(Filesystem::extension($info['filename']));
			if (!in_array($extension, array_unique(array_merge(
				$GLOBALS['ALLOWED_FILE_EXTENSIONS'], $GLOBALS['ALLOWED_IMG_EXTENSION']
			)), true)) {
				continue;
			}
			$info['file'] = $baseUrl . rawurlencode($info['filename']);
			$info['mime'] = Filesystem::mimeType($file);
			$info['size'] = Filesystem::getSize($file);

			// Check if thumbnail exists for the file
			$thumbnail = $path . 'thumbnails' . DS . $info['filename'];
			if (is_file($thumbnail) && !is_link($thumbnail)) {
				$info['thumbnail'] = $baseUrl . 'thumbnails/' . rawurlencode($info['filename']);
			}

			array_push($files, $info);
		}

		return array(
			'status' => '0',
			'message' => 'Files for the page key: ' . $pageKey,
			'data' => $files
		);
	}

	/*
	| Upload a file to a particular page
	| Returns the file URL
	|
	| @inputs		array
	| @inputs['uuid']	string	Page UUID
	| @_FILE		array	https://www.php.net/manual/en/reserved.variables.files.php
	|
	| @return		array
	*/
	private function uploadFile($pageKey)
	{
		global $pages;
		$pageRow = $pages->getPageDB($pageKey);
		if (!is_array($pageRow) || !isset($pageRow['uuid']) || !is_string($pageRow['uuid']) ||
			preg_match('/^[a-f0-9]{32}$/iD', $pageRow['uuid']) !== 1) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'Page not found.');
		}
		$uuid = $pageRow['uuid'];
		if (!isset($_FILES['file']) || !is_array($_FILES['file']) ||
			!isset($_FILES['file']['name'], $_FILES['file']['tmp_name'], $_FILES['file']['error']) ||
			!is_string($_FILES['file']['name'])) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'File not sent.'
			);
		}

		if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			$this->setStatus(400);
			return array(
				'status' => '1',
				'message' => 'Error uploading the file.'
			);
		}
		if (!isset($_FILES['file']['size']) || !is_scalar($_FILES['file']['size']) ||
			(int)$_FILES['file']['size'] < 1 || (int)$_FILES['file']['size'] > BRENDY_MAX_FILE_UPLOAD_BYTES ||
			!isset($_FILES['file']['tmp_name']) || !is_string($_FILES['file']['tmp_name']) ||
			!is_uploaded_file($_FILES['file']['tmp_name'])) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'Invalid or oversized upload.');
		}

		$filename = brendySanitizeUploadedFilename($_FILES['file']['name'], 'file');
		if ($filename === false) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'Invalid filename.');
		}

		// Check file extension
		$fileExtension = Text::lowercase(Filesystem::extension($filename));
		if (!in_array($fileExtension, $GLOBALS['ALLOWED_FILE_EXTENSIONS'], true)) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'File type not allowed.');
		}
		if (!validateUploadedAttachment($_FILES['file']['tmp_name'], $filename)) {
			$this->setStatus(400);
			return array('status' => '1', 'message' => 'File content does not match an allowed file type.');
		}

		$uploadDirectory = PATH_UPLOADS_PAGES . $uuid . DS;
		$basePath = realpath(PATH_UPLOADS_PAGES);
		$realUploadDirectory = realpath($uploadDirectory);
		if (!Filesystem::directoryExists($uploadDirectory) || $basePath === false || $realUploadDirectory === false ||
			($realUploadDirectory !== $basePath && strpos($realUploadDirectory, $basePath . DS) !== 0)) {
			$this->setStatus(404);
			return array('status' => '1', 'message' => 'Upload directory not found.');
		}
		$storedFilename = moveUploadedFileUniquely($_FILES['file']['tmp_name'], $filename, $uploadDirectory, 16);
		if (!is_string($storedFilename)) {
			$this->setStatus(500);
			return array(
				'status' => '1',
				'message' => 'Error moving the file to the final path.'
			);
		}

		$absolutePath = $uploadDirectory . $storedFilename;
		@chmod($absolutePath, 0644);
		return array(
			'status' => '0',
			'message' => 'File uploaded.',
			'filename' => $storedFilename,
			'absoluteURL' => DOMAIN_UPLOADS_PAGES . rawurlencode($uuid) . '/' . rawurlencode($storedFilename)
		);
	}

	private function isPublicPage($page)
	{
		global $pages;
		return $page instanceof Page && is_object($pages) && method_exists($pages, 'isPubliclyAccessible') &&
			$pages->isPubliclyAccessible($page->key());
	}
}
