<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach (array(
    'SESSION_GC_MAXLIFETIME' => 3600,
    'SESSION_ABSOLUTE_MAXLIFETIME' => 43200,
    'SESSION_ID_ROTATE_INTERVAL' => 900,
    'STEP_UP_AUTH_TTL' => 900,
    'REMEMBER_COOKIE_USERNAME' => 'BRENDY_REMEMBER_USERNAME',
    'REMEMBER_COOKIE_TOKEN' => 'BRENDY_REMEMBER_TOKEN',
    'REMEMBER_COOKIE_EXPIRE_IN_DAYS' => 30
) as $name => $value) {
    if (!defined($name)) define($name, $value);
}

if (!class_exists('Session', false)) {
    class Session
    {
        private static $started = true;
        private static $data = array();
        public static $destroyCount = 0;

        public static function reset($data)
        {
            self::$started = true;
            self::$data = is_array($data) ? $data : array();
            self::$destroyCount = 0;
        }
        public static function started() { return self::$started; }
        public static function start($path, $secure) { self::$started = true; return true; }
        public static function get($key) { return array_key_exists($key, self::$data) ? self::$data[$key] : false; }
        public static function set($key, $value) { if (!self::$started) return false; self::$data[$key] = $value; return true; }
        public static function remove($key) { unset(self::$data[$key]); return true; }
        public static function regenerate($deleteOldSession = true) { return self::$started; }
        public static function destroy() { self::$data = array(); self::$started = false; self::$destroyCount++; return true; }
    }
}

if (!class_exists('BrendyAuthTestUsers', false)) {
    class BrendyAuthTestUsers
    {
        public $row;
        public function __construct($row) { $this->row = $row; }
        public function getUserDB($username) { return $username === 'alice' ? $this->row : false; }
        public function isEnabled($username) { return $username === 'alice' && !empty($this->row['enabled']); }
        public function twoFactorEnabled($username) { return false; }
    }
}

if (!class_exists('BrendyAuthTestSite', false)) {
    class BrendyAuthTestSite
    {
        public function urlPath() { return '/'; }
        public function isHTTPS() { return false; }
    }
}

require_once dirname(__DIR__) . DS . 'jezgra' . DS . 'login.class.php';

return array(
    'authenticated sessions require creation time and revocation token' => function () {
        $token = str_repeat('a', 64);
        $GLOBALS['users'] = new BrendyAuthTestUsers(array(
            'password' => '$2y$10$test',
            'role' => 'admin',
            'tokenAuth' => $token,
            'enabled' => true
        ));
        $GLOBALS['site'] = new BrendyAuthTestSite();
        $login = new Login();
        $now = time();
        $fingerprint = $login->fingerPrint();

        $valid = array(
            'fingerPrint' => $fingerprint,
            'username' => 'alice',
            'role' => 'admin',
            'tokenAuth' => $token,
            'sessionTime' => $now,
            'sessionCreated' => $now,
            'sessionRotated' => $now
        );
        Session::reset($valid);
        brendyTestAssert($login->isLogged() === true, 'A complete current authenticated session should remain valid.');

        $missingCreated = $valid;
        unset($missingCreated['sessionCreated']);
        Session::reset($missingCreated);
        brendyTestAssert($login->isLogged() === false, 'A legacy authenticated session without sessionCreated must fail closed.');
        brendyTestAssert(Session::$destroyCount === 1, 'Missing sessionCreated must destroy the legacy authenticated session.');

        $missingToken = $valid;
        unset($missingToken['tokenAuth']);
        Session::reset($missingToken);
        brendyTestAssert($login->isLogged() === false, 'A legacy authenticated session without tokenAuth must fail closed.');
        brendyTestAssert(Session::$destroyCount === 1, 'Missing tokenAuth must destroy the legacy authenticated session.');

        $rotated = $valid;
        $rotated['tokenAuth'] = str_repeat('b', 64);
        Session::reset($rotated);
        brendyTestAssert($login->isLogged() === false, 'A session bound to an old tokenAuth must be revoked.');
    },

    'pending two-factor challenge is bound to credential generation' => function () {
        $source = Filesystem::readFileBounded(dirname(__DIR__) . DS . 'jezgra' . DS . 'administracija' . DS . 'kontroleri' . DS . 'login.php', 262144, 1);
        brendyTestAssert(is_string($source), 'Unable to read login controller for 2FA binding contract.');
        brendyTestAssert(strpos($source, "'twoFactorPendingAuthBinding'") !== false, 'Pending 2FA cleanup must include the auth binding.');
        brendyTestAssert(strpos($source, 'Session::set(\'twoFactorPendingAuthBinding\', hash(\'sha256\', $currentAuthToken))') !== false,
            'Password step must bind the pending 2FA challenge to the current auth token.');
        brendyTestAssert(strpos($source, 'hash_equals($authBinding, $currentBinding)') !== false,
            'Pending 2FA validation must compare the stored binding with current credential state.');
    },

    'session fingerprint prefers the request user agent safely' => function () {
        $token = str_repeat('c', 64);
        $GLOBALS['users'] = new BrendyAuthTestUsers(array(
            'password' => '$2y$10$test',
            'role' => 'admin',
            'tokenAuth' => $token,
            'enabled' => true
        ));
        $GLOBALS['site'] = new BrendyAuthTestSite();
        $oldServerAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $oldEnvAgent = getenv('HTTP_USER_AGENT');
        try {
            $_SERVER['HTTP_USER_AGENT'] = 'Brendy-Test-Browser/1.0';
            putenv('HTTP_USER_AGENT=Different-Environment-Agent/9.9');
            $login = new Login();
            brendyTestAssert($login->fingerPrint() === hash('sha256', 'Brendy-Test-Browser/1.0'),
                'Session fingerprint did not prefer the actual request user-agent header.');

            $_SERVER['HTTP_USER_AGENT'] = "bad\nagent";
            $expectedFallback = hash('sha256', 'Brendy-CMS/' . BRENDY_VERSION);
            brendyTestAssert($login->fingerPrint() === $expectedFallback,
                'Malformed request user-agent did not fail closed to the bounded fallback fingerprint.');
        } finally {
            if ($oldServerAgent === null) unset($_SERVER['HTTP_USER_AGENT']); else $_SERVER['HTTP_USER_AGENT'] = $oldServerAgent;
            if ($oldEnvAgent === false) putenv('HTTP_USER_AGENT'); else putenv('HTTP_USER_AGENT=' . $oldEnvAgent);
        }
    },

);
