<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyPluginComments extends BrendyPlugin
{
	private $commentStatusCountsCache = null;

	// Per-request static cache of decoded page-comments files.
	// Key: SHA-256(pageKey), Value: decoded array.
	private static $pageCache = array();

	// Per-request static cache of the blocked-ips file.
	private static $blocklistCache = null;

	// ------------------------------------------------------------------------
	// Init / Install / Uninstall
	// ------------------------------------------------------------------------

	public function init()
	{
		$this->dbFields = array(
			'enableOnPages'     => true,
			'enableOnStatic'    => false,
			'enableOnSticky'    => true,
			'commentsPerPage'   => 20,
			'sortOrder'         => 'newest',
			'notifyAdmin'       => true,
			'notifyAdminEmail'  => '',
			'honeypotEnabled'   => true,
			'timecheckEnabled'  => true,
			'timecheckSeconds'  => 3,
			'linkLimit'         => 2,
			'spamWords'         => '',
			'turnstileEnabled'  => false,
			'turnstileSiteKey'  => '',
			'turnstileSecretKey'=> '',
			'closedAfterDays'   => 0,
			'maxContentLength'  => 5000,
			'rateLimitSeconds'  => 30,
			'signingSecret'     => ''
		);
	}

	public function prepare()
	{
		// Declared settings are already normalized by BrendyPlugin. Only the
		// internal HMAC secret needs plugin-specific preparation here.
		if (!is_string($this->db['signingSecret']) || preg_match('/^[a-f0-9]{64}$/iD', $this->db['signingSecret']) !== 1) {
			$before = $this->db;
			$this->db['signingSecret'] = bin2hex(random_bytes(32));
			if (!$this->save()) {
				$this->db = $before;
				return false;
			}
		}
		return true;
	}

	public function install($position = 1)
	{
		// Let the parent create the workspace + database with default values.
		$installed = parent::install($position);
		if (!$installed) {
			return false;
		}

		// Generate a per-install signing secret used for HMAC tokens + IP hashes.
		$this->db['signingSecret'] = bin2hex(random_bytes(32));
		if (!$this->save()) {
			parent::uninstall();
			return false;
		}

		// Create sub-directories inside the workspace.
		$pagesDir = $this->workspace() . 'pages';
		if (!Filesystem::directoryExists($pagesDir) && !Filesystem::mkdir($pagesDir, true)) {
			parent::uninstall();
			return false;
		}

		// Initialise the blocklist file (empty).
		$blocklist = new BrendyJsonDatabase($this->workspace() . 'blocked-ips.php');
		if (empty($blocklist->db)) {
			$blocklist->db = array();
			if (!$blocklist->save()) {
				parent::uninstall();
				return false;
			}
		}

		return true;
	}

	protected function integerSettingBounds()
	{
		return array(
			'commentsPerPage' => array(1, 500),
			'maxContentLength' => array(200, 50000),
			'closedAfterDays' => array(0, 3650),
			'timecheckSeconds' => array(0, 60),
			'rateLimitSeconds' => array(0, 3600),
			'linkLimit' => array(0, 20)
		);
	}

	// ------------------------------------------------------------------------
	// Settings form
	// ------------------------------------------------------------------------

	public function form()
	{
		global $L;

		$html = $this->settingsIntro();

		// ----- Where to enable -----
		$html .= '<h4 class="mt-4">' . $L->get('Settings') . '</h4>';

		$html .= $this->settingsBooleanField('enableOnPages', 'enable-on-pages');
		$html .= $this->settingsBooleanField('enableOnStatic', 'enable-on-static-pages');
		$html .= $this->settingsBooleanField('enableOnSticky', 'enable-on-sticky-pages');

		// ----- Display -----
		$html .= $this->settingsNumberField('commentsPerPage', 'comments-per-page', 1, 500);

		$html .= $this->settingsSelectField('sortOrder', 'sort-order', array(
			'newest' => 'sort-newest-first',
			'oldest' => 'sort-oldest-first'
		));

		$html .= $this->settingsNumberField('maxContentLength', 'max-content-length', 200, 50000);

		$html .= $this->settingsNumberField('closedAfterDays', 'closed-after-days', 0, 3650);

		// ----- Moderation / notifications -----
		$html .= $this->settingsBooleanField('notifyAdmin', 'notify-admin');

		$html .= '<div class="form-group">';
		$html .= '<label for="commentsNotifyAdminEmail">' . Theme::escape($L->get('notify-admin-email')) . '</label>';
		$html .= '<input id="commentsNotifyAdminEmail" class="form-control" type="email" name="notifyAdminEmail" value="' . htmlspecialchars((string) $this->getValue('notifyAdminEmail', false), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">';
		$html .= '</div>';

		// ----- Bot gates (built-in, free) -----
		$html .= '<h4 class="mt-4">' . $L->get('Bot protection') . '</h4>';
		$html .= $this->settingsBooleanField('honeypotEnabled', 'enable-honeypot');
		$html .= $this->settingsBooleanField('timecheckEnabled', 'enable-timecheck');

		$html .= $this->settingsNumberField('timecheckSeconds', 'timecheck-seconds', 0, 60);

		$html .= $this->settingsNumberField('rateLimitSeconds', 'rate-limit-seconds', 0, 3600);

		$html .= $this->settingsNumberField('linkLimit', 'link-limit', 0, 20);

		$html .= '<div class="form-group">';
		$html .= '<label for="commentsSpamWords">' . Theme::escape($L->get('spam-words')) . '</label>';
		$html .= '<textarea id="commentsSpamWords" class="form-control" name="spamWords" rows="6">' . htmlspecialchars((string) $this->getValue('spamWords', false), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '</textarea>';
		$html .= '</div>';

		// ----- Turnstile (optional, recommended for public) -----
		$html .= '<h4 class="mt-4">' . $L->get('turnstile-section') . '</h4>';
		$html .= '<div class="alert alert-info">' . $L->get('turnstile-hint') . '</div>';

		$html .= $this->settingsBooleanField('turnstileEnabled', 'turnstile-enabled');

		$html .= '<div class="form-group">';
		$html .= '<label for="commentsTurnstileSiteKey">' . Theme::escape($L->get('turnstile-site-key')) . '</label>';
		$html .= '<input id="commentsTurnstileSiteKey" class="form-control" type="text" name="turnstileSiteKey" value="' . htmlspecialchars((string) $this->getValue('turnstileSiteKey', false), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">';
		$html .= '</div>';

		$html .= '<div class="form-group">';
		$html .= '<label for="commentsTurnstileSecretKey">' . Theme::escape($L->get('turnstile-secret-key')) . '</label>';
		$html .= '<input id="commentsTurnstileSecretKey" class="form-control" type="password" name="turnstileSecretKey" value="' . htmlspecialchars((string) $this->getValue('turnstileSecretKey', false), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">';
		$html .= '</div>';

		return $html;
	}

	public function post()
	{
		// Build and validate the complete candidate before writing. The generic
		// parent implementation saves first, which would briefly persist invalid
		// Turnstile settings and makes a failed second save hard to roll back.
		$before = $this->db;
		$candidate = $before;
		$args = is_array($_POST) ? $_POST : array();

		$booleanFields = array(
			'enableOnPages', 'enableOnStatic', 'enableOnSticky', 'notifyAdmin',
			'honeypotEnabled', 'timecheckEnabled', 'turnstileEnabled'
		);
		foreach ($booleanFields as $field) {
			if (!array_key_exists($field, $args)) continue;
			$value = brendyBooleanValue($args[$field], null);
			if ($value === null) return false;
			$candidate[$field] = $value;
		}

		$integerFields = array(
			'commentsPerPage' => array(1, 500),
			'timecheckSeconds' => array(0, 60),
			'rateLimitSeconds' => array(0, 3600),
			'linkLimit' => array(0, 20),
			'maxContentLength' => array(200, 50000),
			'closedAfterDays' => array(0, 3650)
		);
		foreach ($integerFields as $field => $range) {
			if (!array_key_exists($field, $args)) continue;
			$value = brendyIntegerValue($args[$field], null, $range[0], $range[1]);
			if ($value === null) return false;
			$candidate[$field] = $value;
		}

		if (array_key_exists('sortOrder', $args)) {
			if (!is_string($args['sortOrder']) || !in_array($args['sortOrder'], array('newest', 'oldest'), true)) return false;
			$candidate['sortOrder'] = $args['sortOrder'];
		}
		if (array_key_exists('notifyAdminEmail', $args)) {
			if (!is_string($args['notifyAdminEmail'])) return false;
			$email = trim($args['notifyAdminEmail']);
			if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) return false;
			$candidate['notifyAdminEmail'] = Sanitize::html($email);
		}
		foreach (array('turnstileSiteKey', 'turnstileSecretKey') as $field) {
			if (!array_key_exists($field, $args)) continue;
			if (!is_string($args[$field]) || strlen($args[$field]) > 200 ||
				preg_match('/[\x00-\x1F\x7F]/', $args[$field])) return false;
			// Credentials are opaque values, not HTML. Preserve their exact bytes
			// (apart from surrounding whitespace) and escape only when rendering.
			$candidate[$field] = trim($args[$field]);
		}
		if (array_key_exists('spamWords', $args)) {
			if (!is_string($args['spamWords']) || strlen($args['spamWords']) > 20000) return false;
			$candidate['spamWords'] = Sanitize::html($args['spamWords']);
		}
		if (!empty($candidate['turnstileEnabled']) &&
			(empty($candidate['turnstileSiteKey']) || empty($candidate['turnstileSecretKey']))) return false;

		// Never accept a submitted signing secret; retain the per-install secret.
		$candidate['signingSecret'] = isset($before['signingSecret']) && is_string($before['signingSecret']) &&
			preg_match('/^[a-f0-9]{64}$/D', $before['signingSecret']) === 1
			? $before['signingSecret'] : bin2hex(random_bytes(32));
		$this->db = $candidate;
		if (!$this->save()) {
			$this->db = $before;
			return false;
		}
		return true;
	}

	// ------------------------------------------------------------------------
	// Admin hooks
	// ------------------------------------------------------------------------

	public function adminSidebar()
	{
		global $L;
		global $login;
		if (!in_array($login->role(), array('admin', 'editor'), true)) return '';
		$pending = $this->countByStatus('pending');
		$badge = ($pending > 0)
			? ' <span class="badge text-bg-warning rounded-pill">' . $pending . '</span>'
			: '';
		return '<a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments"><i class="bi bi-chat-square-text" aria-hidden="true"></i><span>' . $L->get('comments') . $badge . '</span></a>';
	}

	public function adminHead()
	{
		return $this->includeCSS('admin.css');
	}

	public function dashboard()
	{
		global $L;
		global $login;
		if (!in_array($login->role(), array('admin', 'editor'), true)) return '';
		$pending = $this->countByStatus('pending');
		if ($pending === 0) {
			return '';
		}
		$url = HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments';
		$html  = '<div class="comments-dashboard-widget mt-4 pt-4 border-top">';
		$html .= '<h5><i class="bi bi-chat-square-text" aria-hidden="true"></i> ' . $L->get('comments') . '</h5>';
		$html .= '<p><a href="' . $url . '"><strong>' . $pending . '</strong> ' . $L->get('pending-comments') . '</a></p>';
		$html .= '</div>';
		return $html;
	}

	public function adminController()
	{
		global $layout;
		global $login;
		global $security;
		global $L;

		// Restrict to admin + editor roles. For non-admin, silently bail.
		if (!in_array($login->role(), array('admin', 'editor'), true)) {
			return;
		}

		$parts = array_filter(explode('/', $layout['parameters']), 'strlen');
		$parts = array_values($parts);
		$action = isset($parts[0]) ? $parts[0] : '';

		// Routing for action-style URLs. Non-action URLs (pending/approved/etc.)
		// are handled entirely in adminView() by reading $layout['parameters'].
		$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

		// Single-item actions: /approve|spam|trash|delete|restore/<pageKeyHash>/<uuid>
		if ($isPost && in_array($action, array('approve', 'spam', 'trash', 'delete', 'restore'), true)) {
			if (!isset($parts[1], $parts[2])) {
				Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
				Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments');
				return;
			}
			$pageKeyHash = Sanitize::html($parts[1]);
			$uuid        = Sanitize::html($parts[2]);
			if (!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $pageKeyHash) ||
				!preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid)) {
				Alert::set($L->g('Invalid request'), ALERT_STATUS_FAIL);
				Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments');
				return;
			}

			$pageKey = $this->resolvePageKeyFromHash($pageKeyHash);
			if ($pageKey === null) {
				Alert::set($L->g('Comment not found'), ALERT_STATUS_FAIL);
				Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments');
				return;
			}

			if ($action === 'delete') {
				$updated = $this->deleteComment($pageKey, $uuid);
				Alert::set($L->g($updated ? 'Comment deleted' : 'Comment not found'), $updated ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
			} elseif ($action === 'restore') {
				$updated = $this->updateCommentStatus($pageKey, $uuid, 'approved');
				Alert::set($L->g($updated ? 'Comment restored' : 'Comment not found'), $updated ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
			} else {
				$map = array('approve' => 'approved', 'spam' => 'spam', 'trash' => 'trash');
				$updated = $this->updateCommentStatus($pageKey, $uuid, $map[$action]);
				Alert::set($L->g($updated ? 'Comment updated' : 'Comment not found'), $updated ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
			}

			Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments');
			return;
		}

		// Bulk action on selection: POST /bulk with bulkAction + selected[] = "pageKeyHash:uuid"
		if ($isPost && $action === 'bulk') {
			$bulkAction = isset($_POST['bulkAction']) ? Sanitize::html($_POST['bulkAction']) : '';
			$selected   = isset($_POST['selected']) && is_array($_POST['selected']) ? $_POST['selected'] : array();
			$returnTab  = isset($_POST['returnTab']) ? Sanitize::html($_POST['returnTab']) : 'pending';
			if (!in_array($returnTab, array('pending', 'approved', 'spam', 'trash'), true)) {
				$returnTab = 'pending';
			}
			$selected = array_slice($selected, 0, 500);
			if (!in_array($bulkAction, array('approve', 'spam', 'trash', 'delete', 'restore'), true) || empty($selected)) {
				Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/' . $returnTab);
				return;
			}
			$map = array('approve' => 'approved', 'spam' => 'spam', 'trash' => 'trash', 'restore' => 'approved');
			$n = 0;
			foreach ($selected as $token) {
				if (!is_string($token) || strpos($token, ':') === false) continue;
				list($hash, $uuid) = explode(':', $token, 2);
				$hash = Sanitize::html($hash);
				$uuid = Sanitize::html($uuid);
				if (!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $hash) ||
					!preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid)) continue;
				$pageKey = $this->resolvePageKeyFromHash($hash);
				if ($pageKey === null) continue;
				$updated = $bulkAction === 'delete'
					? $this->deleteComment($pageKey, $uuid)
					: $this->updateCommentStatus($pageKey, $uuid, $map[$bulkAction]);
				if ($updated) $n++;
			}
			Alert::set(sprintf($L->g('%d comment(s) updated'), $n), $n > 0 ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
			Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/' . $returnTab);
			return;
		}

		// Blocked-IPs management
		if ($isPost && $action === 'blocked-ips') {
			$sub = isset($parts[1]) ? $parts[1] : '';
			if ($sub === 'add' && isset($_POST['ip']) && is_string($_POST['ip']) && $_POST['ip'] !== '') {
				$ip = trim($_POST['ip']);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					$updated = $this->addToBlocklist($this->hashIp($ip));
					Alert::set($L->g($updated ? 'IP blocked' : 'Unable to save changes'), $updated ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
				} else {
					Alert::set($L->g('Invalid IP'), ALERT_STATUS_FAIL);
				}
			} elseif ($sub === 'remove' && isset($parts[2])) {
				$hash = Sanitize::html($parts[2]);
				if (preg_match('/^[a-f0-9]{64}$/D', $hash)) {
					$updated = $this->removeFromBlocklist($hash);
					Alert::set($L->g($updated ? 'IP unblocked' : 'Unable to save changes'), $updated ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
				}
			}
			Redirect::url(HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/blocked-ips');
			return;
		}
	}

	public function adminView()
	{
		global $layout;
		global $login;
		global $L;
		global $security;

		if (!in_array($login->role(), array('admin', 'editor'), true)) {
			return '<div class="alert alert-warning">' . $L->g('Access denied') . '</div>';
		}

		$parts = array_filter(explode('/', $layout['parameters']), 'strlen');
		$parts = array_values($parts);
		$view  = isset($parts[0]) ? $parts[0] : 'pending';

		if ($view === 'blocked-ips') {
			return $this->viewBlockedIps();
		}

		if (!in_array($view, array('pending', 'approved', 'spam', 'trash'), true)) {
			$view = 'pending';
		}

		return $this->viewModerationQueue($view);
	}

	private function viewModerationQueue($tab)
	{
		global $L;
		global $security;

		$comments = $this->scanAllPagesByStatus($tab);

		// Sort newest first.
		usort($comments, function ($a, $b) {
			return strcmp($b['date'], $a['date']);
		});

		$total = count($comments);

		$html  = '<h2 class="pb-2 border-bottom"><i class="bi bi-chat-square-text" aria-hidden="true"></i> ' . $L->get('comments') . '</h2>';

		// Tabs — one scan per other-tab count (admin, acceptable cost)
		$tabs = array('pending', 'approved', 'spam', 'trash');
		$html .= '<ul class="nav nav-tabs my-3">';
		foreach ($tabs as $t) {
			$count = ($t === $tab) ? $total : $this->countByStatus($t);
			$badge = ($count > 0) ? ' <span class="badge text-bg-secondary">' . $count . '</span>' : '';
			$url = HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/' . $t;
			$active = ($t === $tab) ? ' active' : '';
			$html .= '<li class="nav-item"><a class="nav-link' . $active . '" href="' . $url . '">' . $L->get($t) . $badge . '</a></li>';
		}
		$html .= '<li class="nav-item ms-auto"><a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/blocked-ips">' . $L->get('blocked-ips') . '</a></li>';
		$html .= '</ul>';

		if ($total === 0) {
			$html .= '<div class="alert alert-secondary">' . $L->get('no-comments-in-this-view') . '</div>';
			return $html;
		}

		// Pagination
		$perPage = max(5, (int) $this->getValue('commentsPerPage'));
		$pages   = max(1, (int) ceil($total / $perPage));
		$current = brendyIntegerParameter($_GET, 'apage', 1, 1, $pages);
		if ($current === null) $current = 1;
		$offset  = ($current - 1) * $perPage;
		$slice   = array_slice($comments, $offset, $perPage);

		$token = $security->getTokenCSRF();

		// Bulk-actions form wraps the whole table.
		$html .= '<form method="post" action="' . HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/bulk">';
		$html .= '<input type="hidden" name="tokenCSRF" value="' . htmlspecialchars((string)$token, ENT_QUOTES, CHARSET) . '">';
		$html .= '<input type="hidden" name="returnTab" value="' . htmlspecialchars($tab, ENT_QUOTES, CHARSET) . '">';

		// Bulk-actions bar
		$html .= '<div class="d-flex align-items-center gap-2 my-2 brendy-comments-bulk-bar">';
		$html .= '<select name="bulkAction" class="form-select form-select-sm brendy-comments-bulk-select">';
		$html .= '<option value="">' . $L->get('bulk-actions') . '…</option>';
		if ($tab !== 'approved') $html .= '<option value="approve">' . $L->get('approve') . '</option>';
		if ($tab !== 'spam')     $html .= '<option value="spam">' . $L->get('mark-as-spam') . '</option>';
		if ($tab !== 'trash')    $html .= '<option value="trash">' . $L->get('move-to-trash') . '</option>';
		if ($tab === 'trash')    $html .= '<option value="restore">' . $L->get('restore') . '</option>';
		$html .= '<option value="delete">' . $L->get('delete-permanently') . '</option>';
		$html .= '</select>';
		$bulkConfirm = htmlspecialchars($L->get('confirm-bulk-action'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
		$html .= '<button type="submit" class="btn btn-sm btn-secondary" data-brendy-confirm="' . $bulkConfirm . '">' . $L->get('apply') . '</button>';
		$html .= '<span class="ms-auto text-muted small">' . $total . ' ' . $L->get('total') . '</span>';
		$html .= '</div>';

		// Table
		$html .= '<div class="table-responsive brendy-table-shell"><table class="table table-sm mb-0 align-middle">';
		$html .= '<thead><tr>';
		$html .= '<th class="brendy-comments-select-column"><input type="checkbox" id="brendy-comments-selectall" data-brendy-select-all-name="selected[]" aria-label="' . htmlspecialchars($L->get('select-all'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"></th>';
		$html .= '<th>' . $L->get('author') . '</th>';
		$html .= '<th>' . $L->get('content') . '</th>';
		$html .= '<th>' . $L->get('page') . '</th>';
		$html .= '<th>' . $L->get('date') . '</th>';
		$html .= '<th class="text-end">' . $L->get('actions') . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ($slice as $c) {
			$html .= $this->renderAdminRow($c, $tab);
		}

		$html .= '</tbody></table></div>';
		$html .= '</form>';

		// Admin paginator
		if ($pages > 1) {
			$html .= $this->renderAdminPaginator($tab, $current, $pages);
		}


		return $html;
	}

	private function renderAdminPaginator($tab, $current, $pages)
	{
		global $L;
		$pages = max(1, (int)$pages);
		$current = max(1, min($pages, (int)$current));
		$base = HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/' . rawurlencode((string)$tab);
		$aria = htmlspecialchars($L->get('comment-queue-pagination'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
		$html = '<nav aria-label="' . $aria . '"><ul class="pagination pagination-sm flex-wrap">';
		if ($current > 1) {
			$html .= '<li class="page-item"><a class="page-link" rel="prev" aria-label="' . htmlspecialchars($L->get('Previous'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '" href="' . $base . '?apage=' . ($current - 1) . '">&laquo;</a></li>';
		}

		$window = $this->paginationWindow($current, $pages);
		$previous = 0;
		foreach ($window as $i) {
			if ($previous && $i > $previous + 1) $html .= '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
			if ($i === $current) {
				$html .= '<li class="page-item active"><span class="page-link" aria-current="page">' . $i . '</span></li>';
			} else {
				$html .= '<li class="page-item"><a class="page-link" href="' . $base . '?apage=' . $i . '">' . $i . '</a></li>';
			}
			$previous = $i;
		}
		if ($current < $pages) {
			$html .= '<li class="page-item"><a class="page-link" rel="next" aria-label="' . htmlspecialchars($L->get('Next'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '" href="' . $base . '?apage=' . ($current + 1) . '">&raquo;</a></li>';
		}
		return $html . '</ul></nav>';
	}

	private function renderAdminRow($c, $tab)
	{
		global $L;
		global $security;

		$pageKeyHash = $c['pageKeyHash'];
		$uuid        = $c['uuid'];
		$author      = htmlspecialchars($c['authorName'], ENT_QUOTES, CHARSET);
		if (!empty($c['authorRole']) && $c['authorRole'] !== 'guest') {
			$author .= ' <span class="badge text-bg-info">' . htmlspecialchars($c['authorRole'], ENT_QUOTES, CHARSET) . '</span>';
		}
		$excerpt = htmlspecialchars(mb_substr($c['content'], 0, 200), ENT_QUOTES, CHARSET);
		if (mb_strlen($c['content']) > 200) {
			$excerpt .= '…';
		}
		$pageLink = htmlspecialchars($c['pageKey'], ENT_QUOTES, CHARSET);
		$pageUrl  = htmlspecialchars(DOMAIN_PAGES . $c['pageKey'], ENT_QUOTES, CHARSET);
		$date     = htmlspecialchars($c['date'], ENT_QUOTES, CHARSET);

		$base = HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/';

		// We're already inside the outer bulk <form>, so nested forms would be
		// invalid HTML. Use HTML5 formaction instead — each button posts to its
		// own URL but inherits the outer form's tokenCSRF field.
		$actionButton = function ($action, $label, $class, $confirm = false) use ($base, $pageKeyHash, $uuid, $L) {
			$url = $base . $action . '/' . $pageKeyHash . '/' . $uuid;
			$confirmAttr = $confirm
				? ' data-brendy-confirm="' . htmlspecialchars($L->get('confirm-delete'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"'
				: '';
			return '<button type="submit" formaction="' . htmlspecialchars($url, ENT_QUOTES, CHARSET) . '" formmethod="post" class="btn btn-sm ' . $class . '"' . $confirmAttr . '>' . $label . '</button>';
		};

		$selectionValue = htmlspecialchars($pageKeyHash . ':' . $uuid, ENT_QUOTES, CHARSET);

		$html  = '<tr>';
		$html .= '<td><input type="checkbox" name="selected[]" value="' . $selectionValue . '" aria-label="' . htmlspecialchars($L->get('select-comment'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"></td>';
		$html .= '<td>' . $author . '</td>';
		$html .= '<td>' . $excerpt . '</td>';
		$html .= '<td><a href="' . $pageUrl . '" target="_blank" rel="noopener noreferrer">' . $pageLink . '</a></td>';
		$html .= '<td><small>' . $date . '</small></td>';
		$html .= '<td class="text-end"><div class="btn-group btn-group-sm" role="group">';

		if ($tab === 'pending' || $tab === 'spam') {
			$html .= $actionButton('approve', $L->get('approve'), 'btn-success');
		}
		if ($tab !== 'spam') {
			$html .= $actionButton('spam', $L->get('mark-as-spam'), 'btn-warning');
		}
		if ($tab !== 'trash') {
			$html .= $actionButton('trash', $L->get('move-to-trash'), 'btn-secondary');
		} else {
			$html .= $actionButton('restore', $L->get('restore'), 'btn-success');
		}
		$html .= $actionButton('delete', $L->get('delete-permanently'), 'btn-danger', true);
		$html .= '</div></td>';
		$html .= '</tr>';
		return $html;
	}

	private function viewBlockedIps()
	{
		global $L;
		global $security;

		$list = $this->loadBlocklist();
		$token = $security->getTokenCSRF();
		$base  = HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments/blocked-ips';

		$html  = '<h2 class="pb-2 border-bottom"><i class="bi bi-slash-circle" aria-hidden="true"></i> ' . $L->get('blocked-ips') . '</h2>';
		$html .= '<p class="text-muted">' . $L->get('blocked-ips-description') . '</p>';

		// Add form
		$html .= '<form method="post" action="' . $base . '/add" class="mb-3 d-flex flex-wrap gap-2 brendy-comments-block-form">';
		$html .= '<input type="hidden" name="tokenCSRF" value="' . htmlspecialchars((string)$token, ENT_QUOTES, CHARSET) . '">';
		$html .= '<input type="text" class="form-control" name="ip" placeholder="192.0.2.1" inputmode="text" autocomplete="off" required>';
		$html .= '<button type="submit" class="btn btn-primary">' . $L->get('add-ip') . '</button>';
		$html .= '</form>';

		if (empty($list)) {
			$html .= '<div class="alert alert-secondary">' . $L->get('no-blocked-ips') . '</div>';
		} else {
			$html .= '<div class="table-responsive brendy-table-shell"><table class="table table-sm mb-0"><thead><tr><th>' . $L->get('ip-hash') . '</th><th>' . $L->get('added') . '</th><th></th></tr></thead><tbody>';
			foreach ($list as $hash => $meta) {
				$added = isset($meta['date']) ? $meta['date'] : '';
				$html .= '<tr>';
				$html .= '<td><code>' . htmlspecialchars($hash, ENT_QUOTES, CHARSET) . '</code></td>';
				$html .= '<td>' . htmlspecialchars($added, ENT_QUOTES, CHARSET) . '</td>';
				$html .= '<td class="text-end">';
				$html .= '<form method="post" action="' . $base . '/remove/' . $hash . '">';
				$html .= '<input type="hidden" name="tokenCSRF" value="' . htmlspecialchars((string)$token, ENT_QUOTES, CHARSET) . '">';
				$html .= '<button type="submit" class="btn btn-sm btn-outline-danger">' . $L->get('unblock-ip') . '</button>';
				$html .= '</form>';
				$html .= '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
		}

		// Back link
		$html .= '<p><a href="' . HTML_PATH_ADMIN_ROOT . 'plugin/BrendyPluginComments">&larr; ' . $L->get('moderation-queue') . '</a></p>';
		return $html;
	}

	// ------------------------------------------------------------------------
	// Site hooks
	// ------------------------------------------------------------------------

	public function siteHead()
	{
		global $WHERE_AM_I;
		if ($WHERE_AM_I !== 'page') {
			return false;
		}
		$html = $this->includeCSS('frontend.css');
		if ($this->getValue('turnstileEnabled') && !empty($this->getValue('turnstileSiteKey'))) {
			$html .= '<script' . $this->cspNonceAttribute() . ' src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . PHP_EOL;
		}
		return $html;
	}

	public function siteBodyEnd()
	{
		global $WHERE_AM_I;
		if ($WHERE_AM_I !== 'page') {
			return false;
		}
		return $this->includeJS('frontend.js');
	}

	public function pageEnd()
	{
		global $url;
		global $page;
		global $WHERE_AM_I;

		if ($url->notFound() || $WHERE_AM_I !== 'page' || !isset($page)) {
			return false;
		}
		if (!$this->shouldRenderOnPage($page)) {
			return false;
		}

		$pageKey = $page->key();
		$data    = $this->loadPageComments($pageKey);
		$closed  = $this->isClosed($page);

		return $this->renderCommentsSection($pageKey, $data, $closed);
	}

	public function beforeAll()
	{
		// Warm the session early — before the theme emits HTML — so the comment
		// form (rendered late via pageEnd) can detect logged-in admins/editors.
		// If we wait until pageEnd, session_start() fails because headers are
		// already sent, and the form falls back to the guest branch.
		$this->warmSessionIfAuthCookiePresent();

		// Intercept POST to /comments/submit. We use a fixed-prefix webhook match.
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			return;
		}
		if (!$this->webhook('comments/submit')) {
			return;
		}
		$this->handleSubmit();
	}

	private function warmSessionIfAuthCookiePresent()
	{
		$hasSession  = Session::hasCookie();
		$hasRemember = Login::hasRememberCookies();
		if (!$hasSession && !$hasRemember) {
			return;
		}
		// Instantiate Login — its constructor calls Session::start() with the
		// correct cookie params. At beforeAll time no HTML has been emitted yet.
		global $login;
		if (!isset($login) || !($login instanceof Login)) {
			$login = new Login();
		}
		// If the session didn't carry an identity but remember-me cookies are
		// valid, re-establish the session from them.
		if (!$login->isLogged() && $hasRemember) {
			$login->verifyUserByRemember();
		}
	}

	public function afterPageDelete($key)
	{
		if (!is_string($key) || $key === '') return false;
		// Serialize page deletion with comment submission/moderation. Without the
		// same per-page lock a concurrent writer could recreate an orphan comments
		// file immediately after the page had been removed.
		$lock = $this->acquirePageCommentsLock($key);
		if ($lock === false) return false;
		$ok = true;
		foreach (array($this->pageFilename($key), $this->legacyPageFilename($key)) as $candidate) {
			if (is_link($candidate)) { $ok = false; continue; }
			if (is_file($candidate) && !Filesystem::rmfile($candidate)) $ok = false;
		}
		unset(self::$pageCache[$this->pageHash($key)]);
		$this->releasePageCommentsLock($lock);
		return $ok;
	}

	// ------------------------------------------------------------------------
	// Page eligibility
	// ------------------------------------------------------------------------

	private function shouldRenderOnPage($page)
	{
		global $pages;
		if (!($page instanceof Page) || !is_object($pages) || !method_exists($pages, 'isPubliclyAccessible') ||
			!$pages->isPubliclyAccessible($page->key())) return false;

		// Respect Brendy's per-page toggle: if the author disabled comments on
		// this page via the admin UI, skip rendering entirely.
		if (method_exists($page, 'allowComments') && $page->allowComments() === false) {
			return false;
		}

		if ($page->isStatic()) {
			return (bool) $this->getValue('enableOnStatic');
		}
		if ($page->sticky()) {
			return (bool) $this->getValue('enableOnSticky');
		}
		if ($page->published()) {
			return (bool) $this->getValue('enableOnPages');
		}
		return false;
	}

	private function isClosed($page)
	{
		$days = (int) $this->getValue('closedAfterDays');
		if ($days <= 0) {
			return false;
		}
		$dateRaw = $page->dateRaw();
		if (empty($dateRaw)) {
			return false;
		}
		try {
			$pageDate = new DateTime($dateRaw);
		} catch (Exception $e) {
			return false;
		}
		$now = new DateTime();
		if ($pageDate > $now) {
			return false;
		}
		$diffDays = (int) $now->diff($pageDate)->format('%a');
		return $diffDays >= $days;
	}

	/**
	 * Returns the Login instance warmed up in beforeAll(), or null if the
	 * visitor has no Brendy auth cookies and we skipped session work entirely.
	 */
	private function publicLogin()
	{
		global $login;
		return (isset($login) && $login instanceof Login) ? $login : null;
	}

	// ------------------------------------------------------------------------
	// Rendering — comments section + form
	// ------------------------------------------------------------------------

	private function renderCommentsSection($pageKey, $data, $closed)
	{
		global $L;
		global $page;

		$comments = isset($data['comments']) ? $data['comments'] : array();
		// Only approved comments visible on the public page.
		$approved = array_filter($comments, function ($c) {
			return is_array($c) && isset($c['status']) && $c['status'] === 'approved';
		});

		// Sort
		uasort($approved, function ($a, $b) {
			$aDate = isset($a['date']) && is_scalar($a['date']) ? (string) $a['date'] : '';
			$bDate = isset($b['date']) && is_scalar($b['date']) ? (string) $b['date'] : '';
			return strcmp($aDate, $bDate);
		});
		if ($this->getValue('sortOrder') === 'newest') {
			$approved = array_reverse($approved, true);
		}

		$html  = '<section id="brendy-comments" class="brendy-comments">';
		$html .= '<h3>' . $L->get('comments') . ' <small class="brendy-comments-count">(' . count($approved) . ')</small></h3>';

		// Flash message from previous submission (stored in a short-lived cookie).
		if (isset($_COOKIE['brendy_comment_flash'])) {
			$flash = is_string($_COOKIE['brendy_comment_flash']) ? $_COOKIE['brendy_comment_flash'] : '';
			$html .= '<div class="brendy-comments-flash">' . htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '</div>';
			$this->setShortCookie('brendy_comment_flash', '', time() - 3600);
		}

		// Guest-pending flag — set by handleSubmit() after a successful guest
		// post. While present, hide the form so the visitor doesn't see a blank
		// form under the "awaiting moderation" message. Cleared on this render,
		// so refreshing the page brings the form back.
		$justPosted = !empty($_COOKIE['brendy_comment_pending']);
		if ($justPosted) {
			$this->setShortCookie('brendy_comment_pending', '', time() - 3600);
		}

		if (empty($approved)) {
			$html .= '<p class="brendy-comments-empty">' . $L->get('no-comments-yet') . '</p>';
		} else {
			// Paginate — ?cpage=N, fragment-anchored so paginator links don't trigger the page scroll.
			$perPage = max(1, (int) $this->getValue('commentsPerPage'));
			$total   = count($approved);
			$pages   = max(1, (int) ceil($total / $perPage));
			$current = brendyIntegerParameter($_GET, 'cpage', 1, 1, $pages);
			if ($current === null) $current = 1;
			$offset  = ($current - 1) * $perPage;
			$slice   = array_slice($approved, $offset, $perPage, true);

			$html .= '<ol class="brendy-comments-list" start="' . ($offset + 1) . '">';
			foreach ($slice as $comment) {
				$html .= $this->renderSingleComment($comment);
			}
			$html .= '</ol>';

			if ($pages > 1) {
				$permalink = $page->permalink();
				$html .= $this->renderCommentPaginator($permalink, $current, $pages);
			}
		}

		if ($closed) {
			$html .= '<p class="brendy-comments-closed">' . $L->get('comments-are-closed') . '</p>';
		} elseif (!$justPosted) {
			$html .= $this->renderCommentForm($pageKey);
		}

		$html .= '</section>';
		return $html;
	}

	private function renderCommentPaginator($permalink, $current, $pages)
	{
		global $L;
		$linkFor = function ($n) use ($permalink) {
			$sep = (strpos($permalink, '?') === false) ? '?' : '&';
			return $permalink . $sep . 'cpage=' . $n . '#brendy-comments';
		};

		$html = '<nav class="brendy-comments-paginator" aria-label="' . htmlspecialchars($L->get('comment-pagination'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"><ul>';
		if ($current > 1) {
			$html .= '<li><a href="' . htmlspecialchars($linkFor($current - 1), ENT_QUOTES, CHARSET) . '" rel="prev">&laquo;</a></li>';
		}
		$previous = 0;
		foreach ($this->paginationWindow($current, $pages) as $i) {
			if ($previous && $i > $previous + 1) {
				$html .= '<li class="disabled" aria-hidden="true"><span>…</span></li>';
			}
			if ($i === $current) {
				$html .= '<li class="active"><span aria-current="page">' . $i . '</span></li>';
			} else {
				$html .= '<li><a href="' . htmlspecialchars($linkFor($i), ENT_QUOTES, CHARSET) . '">' . $i . '</a></li>';
			}
			$previous = $i;
		}
		if ($current < $pages) {
			$html .= '<li><a href="' . htmlspecialchars($linkFor($current + 1), ENT_QUOTES, CHARSET) . '" rel="next">&raquo;</a></li>';
		}
		$html .= '</ul></nav>';
		return $html;
	}

	private function renderSingleComment($comment)
	{
		global $L;

		$name = htmlspecialchars(isset($comment['authorName']) ? (string) $comment['authorName'] : '', ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
		$date = htmlspecialchars(isset($comment['date']) ? (string) $comment['date'] : '', ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
		$body = $this->formatContent(isset($comment['content']) ? (string) $comment['content'] : '');

		$role = isset($comment['authorRole']) ? $comment['authorRole'] : 'guest';
		$badge = '';
		if ($role === 'admin') {
			$badge = ' <span class="brendy-comment-badge brendy-comment-badge-admin">' . $L->get('admin-badge') . '</span>';
		} elseif ($role === 'editor') {
			$badge = ' <span class="brendy-comment-badge brendy-comment-badge-editor">' . $L->get('editor-badge') . '</span>';
		}

		$uuid = isset($comment['uuid']) && is_string($comment['uuid']) && preg_match('/^c-[a-f0-9]{16,64}$/D', $comment['uuid'])
			? $comment['uuid']
			: '';
		$html  = '<li class="brendy-comment" id="comment-' . htmlspecialchars($uuid, ENT_QUOTES, CHARSET) . '">';
		$html .= '<div class="brendy-comment-header">';
		$html .= '<span class="brendy-comment-author"><strong>' . $name . '</strong>' . $badge . '</span>';
		$html .= '<time class="brendy-comment-date" datetime="' . $date . '">' . $date . '</time>';
		$html .= '</div>';
		$html .= '<div class="brendy-comment-body">' . $body . '</div>';
		$html .= '</li>';
		return $html;
	}

	private function renderCommentForm($pageKey)
	{
		global $L;

		$login   = $this->publicLogin();
		$logged  = ($login !== null) && $login->isLogged();
		$action  = HTML_PATH_ROOT . 'comments/submit';
		$ts      = time();
		$hmac    = $this->signFormToken($pageKey, $ts, $this->tokenIdentity($login));
		$hpField = $this->honeypotFieldName($pageKey);

		$html = '<form class="brendy-comment-form" method="post" action="' . $action . '">';
		$html .= '<h4>' . $L->get('leave-a-comment') . '</h4>';

		// Always-present hidden fields
		$html .= '<input type="hidden" name="pageKey" value="' . htmlspecialchars($pageKey, ENT_QUOTES, CHARSET) . '">';
		$html .= '<input type="hidden" name="ts" value="' . $ts . '">';
		$html .= '<input type="hidden" name="form_hmac" value="' . $hmac . '">';

		if ($logged) {
			// Logged-in user — no name/email inputs. storeLoggedInComment() reads
			// the author's name and email from the user DB, so the form only
			// needs the content textarea. A small "Posting as X" hint tells the
			// user which identity will be attached to the comment.
			global $users;
			$userDB = $users->getUserDB($login->username());
			$userDB = is_array($userDB) ? $userDB : array();
			$nickname = isset($userDB['nickname']) && is_scalar($userDB['nickname']) ? (string)$userDB['nickname'] : '';
			$firstName = isset($userDB['firstName']) && is_scalar($userDB['firstName']) ? (string)$userDB['firstName'] : '';
			$lastName = isset($userDB['lastName']) && is_scalar($userDB['lastName']) ? (string)$userDB['lastName'] : '';
			$displayName = $nickname !== '' ? $nickname : trim($firstName . ' ' . $lastName);
			if ($displayName === '') {
				$displayName = $login->username();
			}
			$html .= '<p class="brendy-comment-form-logged"><i class="bi bi-person" aria-hidden="true"></i> ' . htmlspecialchars($displayName, ENT_QUOTES, CHARSET) . '</p>';
		} else {
			// Guest — name + email + honeypot + Turnstile.
			$html .= '<div class="brendy-comment-form-row">';
			$html .= '<label for="brendy-comment-author-name">' . $L->get('your-name') . ' <span class="req">*</span></label>';
			$html .= '<input id="brendy-comment-author-name" type="text" name="authorName" maxlength="60" autocomplete="name" required>';
			$html .= '</div>';

			$html .= '<div class="brendy-comment-form-row">';
			$html .= '<label for="brendy-comment-author-email">' . $L->get('your-email') . ' <span class="req">*</span></label>';
			$html .= '<input id="brendy-comment-author-email" type="email" name="authorEmail" maxlength="120" autocomplete="email" required>';
			$html .= '<small>' . $L->get('email-never-shown') . '</small>';
			$html .= '</div>';

			// Honeypot — hidden from humans via CSS + aria-hidden, filled = bot.
			if ($this->getValue('honeypotEnabled')) {
				$html .= '<div class="brendy-comment-hp" aria-hidden="true">';
				$hpId = 'brendy-comment-' . $hpField;
				$html .= '<label for="' . htmlspecialchars($hpId, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '">' . $L->get('leave-this-field-empty') . '</label>';
				$html .= '<input id="' . htmlspecialchars($hpId, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '" type="text" name="' . htmlspecialchars($hpField, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '" value="" autocomplete="off" tabindex="-1">';
				$html .= '</div>';
			}
		}

		// Content
		$html .= '<div class="brendy-comment-form-row">';
		$html .= '<label for="brendy-comment-content">' . $L->get('comment-content') . ' <span class="req">*</span></label>';
		$html .= '<textarea id="brendy-comment-content" name="content" rows="5" maxlength="' . (int) $this->getValue('maxContentLength') . '" required></textarea>';
		$html .= '</div>';

		// Turnstile widget — only for guests, only when enabled + configured.
		if (!$logged && $this->getValue('turnstileEnabled') && !empty($this->getValue('turnstileSiteKey'))) {
			$html .= '<div class="brendy-comment-turnstile cf-turnstile" data-sitekey="' . htmlspecialchars((string) $this->getValue('turnstileSiteKey', false), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET) . '"></div>';
		}

		$html .= '<div class="brendy-comment-form-actions">';
		$html .= '<button type="submit" class="btn btn-primary">' . $L->get('post-comment') . '</button>';
		$html .= '</div>';

		$html .= '</form>';
		return $html;
	}

	// ------------------------------------------------------------------------
	// Submission handler
	// ------------------------------------------------------------------------

	private function handleSubmit()
	{
		global $pages;
		global $L;
		global $security;
		$login = $this->publicLogin();

		if (!is_object($security) || !method_exists($security, 'sameOriginRequest') || !$security->sameOriginRequest()) {
			Log::set(__METHOD__ . ' rejected: cross-origin-request');
			$this->flash($L->get('comment-rejected'));
			Redirect::url(HTML_PATH_ROOT);
			return;
		}

		$pageKey = isset($_POST['pageKey']) && is_string($_POST['pageKey']) ? trim($_POST['pageKey']) : '';
		$content = isset($_POST['content']) && is_string($_POST['content']) ? trim($_POST['content']) : '';

		// Resolve redirect target up-front so every exit path goes somewhere useful.
		$redirectTo = HTML_PATH_ROOT;

		if ($pageKey === '' || strlen($pageKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $pageKey) ||
			$content === '' || !$pages->exists($pageKey)) {
			$this->flash($L->get('comment-rejected'));
			Redirect::url($redirectTo);
			return;
		}
		try {
			$targetPage = new Page($pageKey);
		} catch (Exception $e) {
			$this->flash($L->get('comment-rejected'));
			Redirect::url($redirectTo);
			return;
		}
		$redirectTo = $targetPage->permalink();
		if (!$this->shouldRenderOnPage($targetPage) || $this->isClosed($targetPage)) {
			$this->flash($L->get('comments-are-closed'));
			Redirect::url($redirectTo);
			return;
		}

		// HMAC form token is required for EVERYONE. Brendy's built-in tokenCSRF
		// only runs on admin routes, and /comments/submit is a public route,
		// so the HMAC is the CSRF protection for both guest and logged-in users.
		$ts   = isset($_POST['ts']) && is_scalar($_POST['ts']) ? (string) $_POST['ts'] : '';
		$hmac = isset($_POST['form_hmac']) && is_string($_POST['form_hmac']) ? $_POST['form_hmac'] : '';
		if (!$this->verifyFormToken($pageKey, $ts, $hmac, $this->tokenIdentity($login))) {
			Log::set(__METHOD__ . ' rejected: bad-form-token');
			$this->flash($L->get('comment-rejected'));
			Redirect::url($redirectTo);
			return;
		}

		// --- Logged-in path: skip bot gates (honeypot/time/Turnstile) -----
		if ($login !== null && $login->isLogged()) {
			// Still validate content length / link limit / spam words — admins
			// can make mistakes too, and this keeps the pipeline uniform.
			$reason = $this->analyzeContent($content);
			if ($reason !== true) {
				Log::set(__METHOD__ . ' logged-in comment rejected: ' . $reason);
				$this->flash($L->get('comment-rejected'));
				Redirect::url($redirectTo);
				return;
			}
			if ($this->storeLoggedInComment($pageKey, $content) === null) {
				$this->flash($L->get('comment-rejected'));
				Redirect::url($redirectTo);
				return;
			}
			// Scroll to the Comments heading on return.
			Redirect::url($redirectTo . '#brendy-comments');
			return;
		}

		// --- Guest path: full bot gauntlet, then pending -------------------
		$check = $this->runSpamChecks($pageKey, $_POST, $content);
		if ($check !== true) {
			Log::set(__METHOD__ . ' rejected guest comment: ' . $check);
			$this->flash($L->get('comment-rejected'));
			Redirect::url($redirectTo);
			return;
		}
		if (!$this->consumeGuestRateLimit($this->currentIp())) {
			$this->flash($L->get('Please wait before posting another comment'));
			Redirect::url($redirectTo);
			return;
		}

		if (!$this->storeGuestComment($pageKey, $_POST, $content)) {
			$this->flash($L->get('comment-rejected'));
			Redirect::url($redirectTo);
			return;
		}
		$this->flash($L->get('comment-awaiting-moderation'));
		// One-shot cookie consumed on next render; suppresses the form so the
		// visitor doesn't see an empty form under the moderation notice.
		$this->setShortCookie('brendy_comment_pending', '1', time() + 60);
		// Guest comments are pending (not visible yet) — scroll to the flash
		// message at the top of the comments section instead.
		Redirect::url($redirectTo . '#brendy-comments');
	}

	private function storeLoggedInComment($pageKey, $content)
	{
		global $users;

		$login = $this->publicLogin();
		if ($login === null || !$login->isLogged()) {
			// Shouldn't happen — caller already checks — but be defensive.
			return null;
		}

		$userDB = $users->getUserDB($login->username());
		if (!is_array($userDB)) {
			return null;
		}
		$nicknameValue = isset($userDB['nickname']) && is_scalar($userDB['nickname']) ? (string)$userDB['nickname'] : '';
		$firstName = isset($userDB['firstName']) && is_scalar($userDB['firstName']) ? (string)$userDB['firstName'] : '';
		$lastName = isset($userDB['lastName']) && is_scalar($userDB['lastName']) ? (string)$userDB['lastName'] : '';
		$nickname = $nicknameValue !== '' ? $nicknameValue : trim($firstName . ' ' . $lastName);
		if ($nickname === '') {
			$nickname = $login->username();
		}

		$email = isset($userDB['email']) && is_string($userDB['email']) &&
			filter_var($userDB['email'], FILTER_VALIDATE_EMAIL) ? $userDB['email'] : '';
		$uuid  = $this->newUuid();
		$ip    = $this->currentIp();

		$comment = array(
			'uuid'            => $uuid,
			'authorName'      => mb_substr($nickname, 0, 60),
			'authorEmail'     => $email,
			'authorIpHash'    => !empty($ip) ? $this->hashIp($ip) : '',
			'authorUaHash'    => isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? hash('sha256', $_SERVER['HTTP_USER_AGENT']) : '',
			'authorRole'      => $login->role(),
			'authorUsername'  => $login->username(),
			'content'         => $this->truncateContent($content),
			'contentHtml'     => $this->formatContent($content),
			'date'            => Date::current(DB_DATE_FORMAT),
			'status'          => 'approved',
			'votes'           => 0
		);

		return $this->addComment($pageKey, $comment) ? $uuid : null;
	}

	private function storeGuestComment($pageKey, $post, $content)
	{
		$ip = $this->currentIp();

		$name  = isset($post['authorName']) && is_string($post['authorName']) ? trim($post['authorName']) : '';
		$email = isset($post['authorEmail']) && is_string($post['authorEmail']) ? strtolower(trim($post['authorEmail'])) : '';

		$comment = array(
			'uuid'            => $this->newUuid(),
			'authorName'      => mb_substr($name, 0, 60),
			'authorEmail'     => mb_substr($email, 0, 120),
			'authorIpHash'    => !empty($ip) ? $this->hashIp($ip) : '',
			'authorUaHash'    => isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? hash('sha256', $_SERVER['HTTP_USER_AGENT']) : '',
			'authorRole'      => 'guest',
			'authorUsername'  => '',
			'content'         => $this->truncateContent($content),
			'contentHtml'     => $this->formatContent($content),
			'date'            => Date::current(DB_DATE_FORMAT),
			'status'          => 'pending',
			'votes'           => 0
		);

		if (!$this->addComment($pageKey, $comment)) {
			return false;
		}

		// Fire-and-forget admin notification.
		if ($this->getValue('notifyAdmin')) {
			$this->notifyAdmin($pageKey, $comment);
		}
		return true;
	}

	private function truncateContent($content)
	{
		$max = (int) $this->getValue('maxContentLength');
		if ($max > 0 && mb_strlen($content) > $max) {
			$content = mb_substr($content, 0, $max);
		}
		return $content;
	}

	// ------------------------------------------------------------------------
	// Spam gates
	// ------------------------------------------------------------------------

	/**
	 * Guest-only bot gates. The HMAC form token is already verified
	 * upstream in handleSubmit(), so it's not repeated here.
	 * Returns true on pass, or a short string reason on reject.
	 */
	private function runSpamChecks($pageKey, $post, $content)
	{
		// 1. Blocked IP
		$ip = $this->currentIp();
		if (!empty($ip) && $this->isBlocked($this->hashIp($ip))) {
			return 'blocked-ip';
		}

		// 2. Honeypot
		if ($this->getValue('honeypotEnabled')) {
			$fieldName = $this->honeypotFieldName($pageKey);
			if (!array_key_exists($fieldName, $post)) return 'honeypot-missing';
			$honeypot = $post[$fieldName];
			// A real browser submits the hidden text input as an empty string.
			// PHP empty() would incorrectly treat "0" as empty, allowing a bot to
			// fill the trap with that value without tripping the gate.
			if (!is_string($honeypot) || $honeypot !== '') return 'honeypot-filled';
		}

		// 3. Time check (too fast = bot)
		if ($this->getValue('timecheckEnabled')) {
			$elapsed = time() - (int) $post['ts'];
			if ($elapsed < (int) $this->getValue('timecheckSeconds')) {
				return 'too-fast';
			}
		}

		// 4. Required fields (guests must provide name + email; email must validate)
		if (!isset($post['authorName'], $post['authorEmail']) ||
			!is_string($post['authorName']) || !is_string($post['authorEmail']) ||
			trim($post['authorName']) === '' || trim($post['authorEmail']) === '') {
			return 'missing-fields';
		}
		if (!filter_var($post['authorEmail'], FILTER_VALIDATE_EMAIL)) {
			return 'bad-email';
		}

		// 5. Content analysis
		$reason = $this->analyzeContent($content);
		if ($reason !== true) {
			return $reason;
		}

		// 6. Turnstile (only if enabled + configured)
		if ($this->getValue('turnstileEnabled') && !empty($this->getValue('turnstileSecretKey'))) {
			$tsResponse = isset($post['cf-turnstile-response']) ? $post['cf-turnstile-response'] : '';
			if (!$this->validateTurnstile($tsResponse, $ip)) {
				return 'turnstile-failed';
			}
		}

		return true;
	}

	private function analyzeContent($content)
	{
		// Length
		$max = (int) $this->getValue('maxContentLength');
		if ($max > 0 && mb_strlen($content) > $max) {
			return 'content-too-long';
		}
		if (mb_strlen($content) < 2) {
			return 'content-too-short';
		}

		// Link limit
		$linkLimit = (int) $this->getValue('linkLimit');
		$linkCount = preg_match_all('#https?://#i', $content);
		if ($linkLimit >= 0 && $linkCount > $linkLimit) {
			return 'too-many-links';
		}

		// Spam words (case-insensitive substring match)
		$spamWords = trim((string) $this->getValue('spamWords', false));
		if ($spamWords !== '') {
			$words = preg_split('/\r\n|\r|\n/', $spamWords);
			$lower = mb_strtolower($content);
			foreach ($words as $w) {
				$w = trim($w);
				if ($w !== '' && mb_strpos($lower, mb_strtolower($w)) !== false) {
					return 'spam-word';
				}
			}
		}

		return true;
	}

	private function validateTurnstile($response, $ip)
	{
		if (!is_string($response) || $response === '' || strlen($response) > 4096) {
			return false;
		}

		$url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		$secret = $this->getValue('turnstileSecretKey', false);
		if (!is_string($secret) || $secret === '' || strlen($secret) > 8192) return false;

		$fields = array(
			'secret' => $secret,
			'response' => $response
		);
		if (is_string($ip) && $ip !== '') $fields['remoteip'] = $ip;

		// Reuse Brendy's DNS-pinned HTTPS client instead of maintaining a second
		// cURL implementation inside this plugin. This keeps TLS, SSRF, timeout and
		// response-size policy identical across remote integrations.
		$body = TCP::postForm($url, $fields, 5, 65536);
		if (!is_string($body)) {
			Log::set(__METHOD__ . LOG_SEP . 'Turnstile verification failed closed.', LOG_TYPE_WARN);
			return false;
		}
		try {
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return false;
		}
		return is_array($decoded) && isset($decoded['success']) && $decoded['success'] === true;
	}

	// ------------------------------------------------------------------------
	// Storage
	// ------------------------------------------------------------------------

	private function pageHash($pageKey)
	{
		return hash('sha256', (string)$pageKey);
	}

	private function legacyPageHash($pageKey)
	{
		// Read-only compatibility for comment databases created before Brendy 1.9.
		return sha1((string)$pageKey);
	}

	private function pageFilename($pageKey)
	{
		return $this->workspace() . 'pages' . DS . $this->pageHash($pageKey) . '.php';
	}

	private function legacyPageFilename($pageKey)
	{
		return $this->workspace() . 'pages' . DS . $this->legacyPageHash($pageKey) . '.php';
	}

	private function existingPageFilename($pageKey)
	{
		$current = $this->pageFilename($pageKey);
		if (is_file($current) && !is_link($current)) return $current;
		$legacy = $this->legacyPageFilename($pageKey);
		if (is_file($legacy) && !is_link($legacy)) return $legacy;
		return $current;
	}

	private function loadPageComments($pageKey)
	{
		$hash = $this->pageHash($pageKey);
		if (array_key_exists($hash, self::$pageCache)) {
			return self::$pageCache[$hash];
		}

		$file = $this->existingPageFilename($pageKey);
		if (!file_exists($file)) {
			self::$pageCache[$hash] = array('pageKey' => $pageKey, 'commentCount' => 0, 'comments' => array());
			return self::$pageCache[$hash];
		}

		$store = new BrendyJsonDatabase($file);
		$db    = $store->db;
		if (!isset($db['comments']) || !is_array($db['comments']) ||
			!isset($db['pageKey']) || !is_string($db['pageKey']) || !hash_equals($pageKey, $db['pageKey'])) {
			$db = array('pageKey' => $pageKey, 'commentCount' => 0, 'comments' => array());
		}
		$normalized = array();
		foreach (array_slice($db['comments'], 0, 2000, true) as $uuid => $comment) {
			$comment = $this->normalizeStoredComment($uuid, $comment);
			if ($comment !== false) $normalized[$uuid] = $comment;
		}
		$db['comments'] = $normalized;
		$db['commentCount'] = count($normalized);
		self::$pageCache[$hash] = $db;
		return $db;
	}

	private function normalizeStoredComment($uuid, $comment)
	{
		if (!is_string($uuid) || preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid) !== 1 || !is_array($comment)) {
			return false;
		}
		$status = isset($comment['status']) && is_string($comment['status']) ? $comment['status'] : '';
		if (!in_array($status, array('pending', 'approved', 'spam', 'trash'), true)) return false;
		$role = isset($comment['authorRole']) && is_string($comment['authorRole']) ? $comment['authorRole'] : 'guest';
		if (!in_array($role, array('guest', 'admin', 'editor', 'author'), true)) $role = 'guest';
		$content = isset($comment['content']) && is_string($comment['content']) ? $comment['content'] : '';
		if (strlen($content) > 200000) return false;
		$name = isset($comment['authorName']) && is_scalar($comment['authorName'])
			? Sanitize::htmlDecode((string)$comment['authorName']) : '';
		$email = isset($comment['authorEmail']) && is_string($comment['authorEmail']) ? $comment['authorEmail'] : '';
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
		return array(
			'uuid' => $uuid,
			'authorName' => mb_substr($name, 0, 60, CHARSET),
			'authorEmail' => mb_substr($email, 0, 120, CHARSET),
			'authorIpHash' => isset($comment['authorIpHash']) && is_string($comment['authorIpHash']) ? mb_substr($comment['authorIpHash'], 0, 64, CHARSET) : '',
			'authorUaHash' => isset($comment['authorUaHash']) && is_string($comment['authorUaHash']) ? mb_substr($comment['authorUaHash'], 0, 64, CHARSET) : '',
			'authorRole' => $role,
			'authorUsername' => isset($comment['authorUsername']) && is_string($comment['authorUsername']) ? mb_substr($comment['authorUsername'], 0, 200, CHARSET) : '',
			'content' => $content,
			'contentHtml' => '',
			'date' => isset($comment['date']) && is_string($comment['date']) ? mb_substr($comment['date'], 0, 64, CHARSET) : '',
			'status' => $status,
			'votes' => isset($comment['votes']) && is_numeric($comment['votes']) ? (int)$comment['votes'] : 0
		);
	}

	private function savePageComments($pageKey, $data)
	{
		$file = $this->pageFilename($pageKey);
		$store = new BrendyJsonDatabase($file);
		$store->db = $data;
		$ok = $store->save();
		if ($ok) {
			self::$pageCache[$this->pageHash($pageKey)] = $data;
			$this->commentStatusCountsCache = null;
			// Successful writes opportunistically migrate legacy SHA-1 filenames.
			$legacyFile = $this->legacyPageFilename($pageKey);
			if (is_file($legacyFile) && !is_link($legacyFile)) Filesystem::rmfile($legacyFile);
		}
		return $ok;
	}

	private function addComment($pageKey, $comment)
	{
		return $this->mutatePageComments($pageKey, function (&$data) use ($pageKey, $comment) {
			if (count($data['comments']) >= 2000) {
				return false;
			}
			$data['pageKey'] = $pageKey;
			$data['comments'][$comment['uuid']] = $comment;
			$data['commentCount'] = count($data['comments']);
			return true;
		});
	}

	private function updateCommentStatus($pageKey, $uuid, $status)
	{
		if (!in_array($status, array('pending', 'approved', 'spam', 'trash'), true) ||
			!is_string($uuid) || !preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid)) {
			return false;
		}
		return $this->mutatePageComments($pageKey, function (&$data) use ($uuid, $status) {
			if (!isset($data['comments'][$uuid]) || !is_array($data['comments'][$uuid])) {
				return false;
			}
			$data['comments'][$uuid]['status'] = $status;
			return true;
		});
	}

	private function deleteComment($pageKey, $uuid)
	{
		if (!is_string($uuid) || !preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid)) {
			return false;
		}
		return $this->mutatePageComments($pageKey, function (&$data) use ($uuid) {
			if (!isset($data['comments'][$uuid])) {
				return false;
			}
			unset($data['comments'][$uuid]);
			$data['commentCount'] = count($data['comments']);
			return true;
		});
	}

	private function mutatePageComments($pageKey, $mutator)
	{
		if (!is_string($pageKey) || $pageKey === '' || strlen($pageKey) > 1000 || !is_callable($mutator)) return false;
		$lockName = 'page-' . $this->pageHash($pageKey);
		return $this->withWorkspaceLock($lockName, function () use ($pageKey, $mutator) {
			// Revalidate after acquiring the lock. A submit request may have validated
			// the page just before an administrator deleted it and then waited here;
			// without this second check it could recreate an orphan comments database.
			global $pages;
			if (!isset($pages) || !is_object($pages) || !method_exists($pages, 'exists') || !$pages->exists($pageKey)) {
				return false;
			}
			unset(self::$pageCache[$this->pageHash($pageKey)]);
			$data = $this->loadPageComments($pageKey);
			$changed = $mutator($data);
			return ($changed === true) ? $this->savePageComments($pageKey, $data) : false;
		});
	}

	/**
	 * Admin-only. Scans pages/*.php, returns flat array of matching comments
	 * annotated with pageKey + pageKeyHash so the moderation UI can link back.
	 */
	private function scanAllPagesByStatus($status)
	{
		$statuses = array('pending', 'approved', 'spam', 'trash');
		if (!in_array($status, $statuses, true)) return array();
		$dir = $this->workspace() . 'pages';
		if (!Filesystem::directoryExists($dir)) {
			$this->commentStatusCountsCache = array_fill_keys($statuses, 0);
			return array();
		}
		$out = array();
		$counts = array_fill_keys($statuses, 0);
		$seenPageKeys = array();
		foreach ($this->commentDataFiles() as $file) {
			$hash = basename($file, '.php');
			$store = new BrendyJsonDatabase($file);
			$db = $store->db;
			if (!isset($db['comments']) || !is_array($db['comments'])) continue;
			$pageKey = isset($db['pageKey']) && is_string($db['pageKey']) ? $db['pageKey'] : '';
			if ($pageKey === '' || isset($seenPageKeys[$pageKey])) continue;
			$seenPageKeys[$pageKey] = true;
			foreach (array_slice($db['comments'], 0, 2000, true) as $uuid => $c) {
				if (!is_array($c) || !is_string($uuid) || preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid) !== 1) continue;
				$commentStatus = isset($c['status']) && is_string($c['status']) ? $c['status'] : '';
				if (!array_key_exists($commentStatus, $counts)) continue;
				$counts[$commentStatus]++;
				if ($commentStatus !== $status) continue;
				$c['uuid'] = $uuid;
				$c['authorName'] = isset($c['authorName']) && is_scalar($c['authorName']) ? (string)$c['authorName'] : '';
				$c['authorRole'] = isset($c['authorRole']) && is_string($c['authorRole']) ? $c['authorRole'] : 'guest';
				$c['content'] = isset($c['content']) && is_scalar($c['content']) ? (string)$c['content'] : '';
				$c['date'] = isset($c['date']) && is_scalar($c['date']) ? (string)$c['date'] : '';
				$c['pageKey'] = $pageKey;
				$c['pageKeyHash'] = $hash;
				$out[] = $c;
			}
		}
		$this->commentStatusCountsCache = $counts;
		return $out;
	}

	/**
	 * Admin-only. Counts all four moderation states in one bounded pass and
	 * reuses the result for sidebar, dashboard and moderation tab badges.
	 */
	private function countByStatus($status)
	{
		$statuses = array('pending', 'approved', 'spam', 'trash');
		if (!in_array($status, $statuses, true)) return 0;
		if (is_array($this->commentStatusCountsCache)) {
			return isset($this->commentStatusCountsCache[$status]) ? (int)$this->commentStatusCountsCache[$status] : 0;
		}
		$counts = array_fill_keys($statuses, 0);
		$dir = $this->workspace() . 'pages';
		if (!Filesystem::directoryExists($dir)) {
			$this->commentStatusCountsCache = $counts;
			return 0;
		}
		$seenPageKeys = array();
		foreach ($this->commentDataFiles() as $file) {
			$store = new BrendyJsonDatabase($file);
			$db = $store->db;
			if (!isset($db['comments']) || !is_array($db['comments'])) continue;
			$pageKey = isset($db['pageKey']) && is_string($db['pageKey']) ? $db['pageKey'] : '';
			if ($pageKey === '' || isset($seenPageKeys[$pageKey])) continue;
			$seenPageKeys[$pageKey] = true;
			foreach (array_slice($db['comments'], 0, 2000, true) as $uuid => $c) {
				if (!is_string($uuid) || preg_match('/^c-[a-f0-9]{16,64}$/D', $uuid) !== 1 || !is_array($c)) continue;
				$commentStatus = isset($c['status']) && is_string($c['status']) ? $c['status'] : '';
				if (array_key_exists($commentStatus, $counts)) $counts[$commentStatus]++;
			}
		}
		$this->commentStatusCountsCache = $counts;
		return $counts[$status];
	}

	private function resolvePageKeyFromHash($hash)
	{
		if (!is_string($hash) || !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $hash)) return null;
		$file = $this->workspace() . 'pages' . DS . $hash . '.php';
		if (!is_file($file) || is_link($file)) return null;
		$size = @filesize($file);
		if ($size === false || $size > 33554432) return null;
		$store = new BrendyJsonDatabase($file);
		if (!isset($store->db['pageKey']) || !is_string($store->db['pageKey'])) return null;
		$expected = strlen($hash) === 64
			? $this->pageHash($store->db['pageKey'])
			: $this->legacyPageHash($store->db['pageKey']);
		return hash_equals($hash, $expected) ? $store->db['pageKey'] : null;
	}

	private function commentDataFiles()
	{
		$dir = $this->workspace() . 'pages';
		if (!Filesystem::directoryExists($dir)) return array();
		$files = glob($dir . DS . '*.php');
		if ($files === false) return array();
		$out = array();
		foreach (array_slice($files, 0, 10000) as $file) {
			if (!is_file($file) || is_link($file) ||
				preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})\.php$/D', basename($file)) !== 1) continue;
			$size = @filesize($file);
			if ($size === false || $size > 33554432) continue;
			$out[] = $file;
		}
		// Prefer the SHA-256 representation when both legacy and new files happen
		// to coexist after an interrupted migration.
		usort($out, static function ($a, $b) {
			$lengthA = strlen(basename($a, '.php'));
			$lengthB = strlen(basename($b, '.php'));
			return $lengthA === $lengthB ? strcmp($a, $b) : ($lengthB <=> $lengthA);
		});
		return $out;
	}

	// ------------------------------------------------------------------------
	// Blocklist
	// ------------------------------------------------------------------------

	private function loadBlocklist()
	{
		if (self::$blocklistCache !== null) {
			return self::$blocklistCache;
		}
		$file = $this->workspace() . 'blocked-ips.php';
		if (!file_exists($file)) {
			self::$blocklistCache = array();
			return self::$blocklistCache;
		}
		$store = new BrendyJsonDatabase($file);
		$list = array();
		if (is_array($store->db)) {
			foreach (array_slice($store->db, 0, 10000, true) as $hash => $meta) {
				if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 &&
					is_array($meta) && isset($meta['date']) && is_string($meta['date'])) {
					$list[$hash] = array('date' => mb_substr($meta['date'], 0, 64, CHARSET));
				}
			}
		}
		self::$blocklistCache = $list;
		return self::$blocklistCache;
	}

	private function saveBlocklist($list)
	{
		if (!is_array($list) || count($list) > 10000) return false;
		$store = new BrendyJsonDatabase($this->workspace() . 'blocked-ips.php');
		$store->db = $list;
		$ok = $store->save();
		if ($ok) self::$blocklistCache = $list;
		return $ok;
	}

	private function addToBlocklist($ipHash)
	{
		if (!is_string($ipHash) || preg_match('/^[a-f0-9]{64}$/D', $ipHash) !== 1) return false;
		return $this->mutateBlocklist(function (&$list) use ($ipHash) {
			if (count($list) >= 10000 && !isset($list[$ipHash])) return false;
			$list[$ipHash] = array('date' => Date::current(DB_DATE_FORMAT));
			return true;
		});
	}

	private function removeFromBlocklist($ipHash)
	{
		if (!is_string($ipHash) || preg_match('/^[a-f0-9]{64}$/D', $ipHash) !== 1) return false;
		return $this->mutateBlocklist(function (&$list) use ($ipHash) {
			if (!isset($list[$ipHash])) return false;
			unset($list[$ipHash]);
			return true;
		});
	}

	private function mutateBlocklist($mutator)
	{
		if (!is_callable($mutator)) return false;
		return $this->withWorkspaceLock('blocked-ips', function () use ($mutator) {
			self::$blocklistCache = null;
			$list = $this->loadBlocklist();
			$changed = $mutator($list);
			return $changed === true ? $this->saveBlocklist($list) : false;
		});
	}

	private function isBlocked($ipHash)
	{
		$list = $this->loadBlocklist();
		return isset($list[$ipHash]);
	}

	// ------------------------------------------------------------------------
	// Content formatting (plaintext + auto-link)
	// ------------------------------------------------------------------------

	private function formatContent($raw)
	{
		// Split raw text first, then escape each plain or linked segment exactly once.
		$raw = is_string($raw) ? $raw : '';
		$escape = static function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); };
		$parts = preg_split('#(https?://[^\s<>"\']+)#i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
		$linked = '';
		if (!is_array($parts)) $parts = array($raw);
		foreach ($parts as $index => $part) {
			if ($index % 2 === 0) { $linked .= $escape($part); continue; }
			$url = rtrim($part, '.,;:!?)');
			$trailing = substr($part, strlen($url));
			if (!brendyIsSafeHttpUrl($url)) { $linked .= $escape($part); continue; }
			$safe = $escape($url);
			$linked .= '<a rel="nofollow noopener ugc" href="' . $safe . '">' . $safe . '</a>' . $escape($trailing);
		}

		// 3. Turn paragraph breaks into <p>, single newlines into <br>.
		$paragraphs = preg_split('/\n\s*\n/', $linked);
		$html = '';
		foreach ($paragraphs as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			$html .= '<p>' . nl2br($p) . '</p>';
		}
		return $html;
	}

	// ------------------------------------------------------------------------
	// Crypto / IP / tokens
	// ------------------------------------------------------------------------

	private function currentIp()
	{
		global $security;
		$ip = $security->getUserIp();
		if (empty($ip)) {
			return '';
		}
		return filter_var($ip, FILTER_VALIDATE_IP) ?: '';
	}

	private function hashIp($ip)
	{
		return hash_hmac('sha256', $ip, $this->getValue('signingSecret', false));
	}

	private function tokenIdentity($login)
	{
		if ($login !== null && $login->isLogged()) {
			return 'user:' . $login->username();
		}
		return 'guest';
	}

	private function signFormToken($pageKey, $ts, $identity)
	{
		return hash_hmac('sha256', $pageKey . '|' . $ts . '|' . $identity, $this->getValue('signingSecret', false));
	}

	private function verifyFormToken($pageKey, $ts, $providedHmac, $identity)
	{
		$ts = (int) $ts;
		if ($ts <= 0 || !is_string($providedHmac) || !preg_match('/^[a-f0-9]{64}$/D', $providedHmac)) {
			return false;
		}
		// Expire forms after 24h to cap replay attacks.
		if ($ts > (time() + 300) || (time() - $ts) > 86400) {
			return false;
		}
		$expected = $this->signFormToken($pageKey, (string) $ts, $identity);
		return hash_equals($expected, (string) $providedHmac);
	}

	private function honeypotFieldName($pageKey)
	{
		return 'hp_' . substr(hash_hmac('sha256', $pageKey, $this->getValue('signingSecret', false)), 0, 12);
	}

	private function newUuid()
	{
		return 'c-' . bin2hex(random_bytes(16));
	}

	private function consumeGuestRateLimit($ip)
	{
		$seconds = max(0, min(3600, (int) $this->getValue('rateLimitSeconds')));
		if ($seconds === 0) return true;
		if (!is_string($ip) || $ip === '') return false;

		return $this->withWorkspaceLock('rate-limits', function () use ($ip, $seconds) {
			$file = $this->workspace() . 'rate-limits.php';
			$store = new BrendyJsonDatabase($file);
			$list = is_array($store->db) ? $store->db : array();
			$now = time();
			foreach ($list as $hash => $timestamp) {
				if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 ||
					!is_int($timestamp) || $timestamp <= 0 || ($now - $timestamp) > 3600 || $timestamp > ($now + 300)) {
					unset($list[$hash]);
				}
			}
			$key = $this->hashIp($ip);
			if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) return false;
			$allowed = !isset($list[$key]) || ($now - $list[$key]) >= $seconds;
			if (!$allowed) return false;
			if (count($list) >= 10000 && !isset($list[$key])) return false;
			$list[$key] = $now;
			$store->db = $list;
			return $store->save();
		});
	}

	// ------------------------------------------------------------------------
	// Notifications
	// ------------------------------------------------------------------------

	private function notifyAdmin($pageKey, $comment)
	{
		global $site;
		global $L;
		$to = $this->getValue('notifyAdminEmail', false);
		if (empty($to)) {
			$to = $site->emailFrom();
		}
		$to = filter_var($to, FILTER_VALIDATE_EMAIL);
		if ($to === false) {
			return false;
		}
		$title = preg_replace('/[\r\n]+/', ' ', (string) $site->title());
		$safePageKey = preg_replace('/[\r\n]+/', '', (string) $pageKey);
		$subject = mb_substr('[' . $title . '] ' . sprintf($L->get('New pending comment on %s'), $safePageKey), 0, 200);
		$body  = $L->get('A new comment is awaiting moderation') . "\n\n";
		$body .= $L->get('Page') . ': ' . DOMAIN_PAGES . $safePageKey . "\n";
		$body .= $L->get('Author') . ': ' . $comment['authorName'] . ' <' . $comment['authorEmail'] . '>' . "\n";
		$body .= $L->get('Date') . ': ' . $comment['date'] . "\n\n";
		$body .= strip_tags($comment['contentHtml']) . "\n\n";
		$body .= $L->get('Moderate') . ': ' . DOMAIN_ADMIN . 'plugin/BrendyPluginComments' . "\n";
		$from = filter_var($site->emailFrom(), FILTER_VALIDATE_EMAIL);
		$headers = ($from === false) ? '' : 'From: ' . $from;
		return @mail($to, $subject, $body, $headers);
	}

	// ------------------------------------------------------------------------
	// Flash cookie
	// ------------------------------------------------------------------------

	private function flash($message)
	{
		$this->setShortCookie('brendy_comment_flash', mb_substr((string) $message, 0, 250), time() + 60);
	}

	private function setShortCookie($name, $value, $expires)
	{
		$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
			(isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
		if (isset($GLOBALS['site']) && method_exists($GLOBALS['site'], 'isHTTPS')) {
			$https = $https || $GLOBALS['site']->isHTTPS();
		}
		$path = defined('HTML_PATH_ROOT') && HTML_PATH_ROOT !== '' ? HTML_PATH_ROOT : '/';
		return setcookie($name, $value, array(
			'expires' => $expires,
			'path' => $path,
			'secure' => $https,
			'httponly' => true,
			'samesite' => 'Lax'
		));
	}
}
