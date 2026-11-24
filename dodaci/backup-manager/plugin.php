<?php defined('BRENDY') or die('Brendy CMS.');
/*
	BACKUP MANAGER
	This plugin is included with Brendy CMS.
	This plugin is NOT FREE.
	This plugin is NOT Open Source.
	You can NOT distribute this plugin.
	You can NOT modify this plugin.

	Copyright 2026
	Author: Diego Najar - dignajar@gmail.com
*/
class BrendyPluginBackupManager extends BrendyPlugin {

	const BACKUP_CONTENT_SCHEMA = 1;

	private $zipAvailable = false;
	private $restoreWarnings = array();
    private $restoreTempIdentity = null;


	public function requiresRecentAuthentication()
	{
		return true;
	}

	public function init()
	{
		$this->dbFields = array(
			'maxBackups' => 10,
			'backupWarningDays' => 30
		);

		$this->zipAvailable = extension_loaded('zip');
	}

	public function prepare()
	{
		// Brendy <= 1.5.0 could persist the AES passphrase in the plugin JSON.
		// Secrets are now request-only: remove the legacy field during upgrade so
		// compromise of the content directory cannot reveal backup passwords.
		if (array_key_exists('backupPassword', $this->db)) {
			$before = $this->db;
			unset($this->db['backupPassword']);
			if (!$this->save()) {
				$this->db = $before;
				Log::set(__METHOD__ . LOG_SEP . 'Unable to remove the legacy stored backup password.', LOG_TYPE_ERROR);
				return false;
			}
		}
		return true;
	}

	public function install($position = 1)
	{
		if (!parent::install($position)) {
			return false;
		}
		$workspace = $this->workspace();
		if (!Filesystem::directoryExists($workspace) && !Filesystem::mkdir($workspace, true)) {
			parent::uninstall();
			return false;
		}
		return true;
	}

	public function adminSidebar()
	{
		global $login;
		if (!$login || $login->role() !== 'admin') {
			return '';
		}
		return '<a class="nav-link" href="' . HTML_PATH_ADMIN_ROOT . 'configure-plugin/' . $this->className() . '"><i class="bi bi-archive" aria-hidden="true"></i><span>' . Theme::escape($this->name()) . '</span></a>';
	}

	private function copyContentExcludingBackups($source, $destination)
	{
		return Filesystem::copyRecursive($source, $destination, array(
			rtrim($this->workspace(), DS),
			rtrim(PATH_TMP, DS)
		));
	}

	private function createEncryptedZip($sourcePath, $zipPath, $password)
	{
		return is_string($password) && $password !== '' && Filesystem::zip($sourcePath, $zipPath, $password);
	}

		private function extractEncryptedZip($zipPath, $destination, $password)
		{
			return Filesystem::unzip($zipPath, $destination, $password, 1073741824, 100000);
		}
	
		private function writeMetadata($path, $metadata)
		{
		try {
				$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
					JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				Log::set(__METHOD__ . LOG_SEP . 'Backup metadata contains invalid UTF-8.', LOG_TYPE_ERROR);
				return false;
			}
			if (!is_string($path) || !is_string($json) || strlen($json) > 1048576 || !is_dir(dirname($path)) ||
				is_link(dirname($path)) || is_link($path) || (file_exists($path) && !is_file($path))) {
				return false;
			}
			return Filesystem::atomicWrite($path, $json, 0600);
		}
	
		private function backupHealthState($backups = null)
		{
			$warningDays = filter_var($this->getValue('backupWarningDays'), FILTER_VALIDATE_INT);
			$warningDays = $warningDays !== false ? max(1, min(365, (int)$warningDays)) : 30;
			if (!is_array($backups)) $backups = $this->getBackupList(false);
			foreach ($backups as $backup) {
				if (!is_array($backup) || !isset($backup['id'], $backup['createdAt']) || !is_string($backup['id']) ||
					strpos($backup['id'], 'emergency-') === 0) continue;
				$createdAt = (int)$backup['createdAt'];
				if ($createdAt <= 0) continue;
				return array(
					'state' => $createdAt < (time() - ($warningDays * 86400)) ? 'stale' : 'ok',
					'warningDays' => $warningDays,
					'createdAt' => $createdAt
				);
			}
			return array('state' => 'missing', 'warningDays' => $warningDays, 'createdAt' => 0);
		}

		public function healthStatus()
		{
			$health = parent::healthStatus();
			$issues = isset($health['issues']) && is_array($health['issues']) ? $health['issues'] : array();
			$status = isset($health['status']) && is_string($health['status']) ? $health['status'] : 'ok';
			if (!$this->installed()) return array('status' => $status, 'issues' => array_values(array_unique($issues)));

			$backupHealth = $this->backupHealthState();
			if ($backupHealth['state'] === 'missing') $issues[] = 'backup-missing';
			elseif ($backupHealth['state'] === 'stale') $issues[] = 'backup-stale-' . $backupHealth['warningDays'] . 'd';
			if ($backupHealth['state'] !== 'ok' && $status === 'ok') $status = 'warning';
			return array('status' => $status, 'issues' => array_values(array_unique($issues)));
		}

		public function post()
		{
			global $syslog, $language;
	
			// Default Save button (top-right) uses the admin wrapper form.
			// Backup actions (create/restore/delete) are submitted via action buttons.
			$isSave = isset($_POST['save']);
			$hasAction = isset($_POST['backup-action']);
			if ($isSave || !$hasAction) {
				$before = $this->db;
				if (array_key_exists('maxBackups', $_POST)) {
					$maxBackups = brendyIntegerParameter($_POST, 'maxBackups', null, 1, 100);
					if ($maxBackups === null) return false;
					$this->db['maxBackups'] = $maxBackups;
				}
				if (array_key_exists('backupWarningDays', $_POST)) {
					$warningDays = brendyIntegerParameter($_POST, 'backupWarningDays', null, 1, 365);
					if ($warningDays === null) return false;
					$this->db['backupWarningDays'] = $warningDays;
				}
				// Never persist backupPassword. It is intentionally request-only.
				unset($this->db['backupPassword']);
				if (!$this->save()) {
					$this->db = $before;
					return false;
				}
				return true;
			}
			if (!$this->acquireOperationLock('brendy-backup-manager.lock')) {
				Alert::set($language->get('backup-operation-in-progress'), ALERT_STATUS_FAIL);
				return false;
			}
	
		try {
			if (!is_string($_POST['backup-action'])) {
				return false;
			}
			$actionRaw = $_POST['backup-action'];
			$action = $actionRaw;
			$backupId = '';
			if (strpos($actionRaw, ':') !== false) {
				$parts = explode(':', $actionRaw, 2);
				$action = $parts[0];
				$backupId = $parts[1];
			}
	
            if (in_array($action, ['recovery-cleanup','recovery-purge'],true)) {
                // Existing configure-plugin controller enforces admin role, fresh
                // authentication and the global POST CSRF guard. The exclusive
                // gate additionally rules out active backup/restore requests.
                global $login;
                if (!$login || $login->role() !== 'admin' ||
                    !preg_match('/^(?:restore-|backup-restore-rollback-)[a-f0-9]{32}$/D',$backupId)) return false;
                $token = $_POST['recovery-token'] ?? null;
                if (!is_array($token) || !is_string($token[$backupId] ?? null)) return false;
                brendyRestoreExclusive();
                $journal = new RestoreJournal(PATH_CONTENT);
                $ok = $action==='recovery-purge' ? $journal->cleanupVerified($backupId,$token[$backupId]) : $journal->cleanupEmpty($backupId, $token[$backupId]);
                if ($ok) $syslog->add(['dictionaryKey'=>$action==='recovery-purge' ? 'recovery-purged' : 'recovery-cleaned','notes'=>'Recovery directory: '.$backupId]);
                Alert::set($language->get($ok ? ($action==='recovery-purge' ? 'recovery-purged' : 'recovery-cleaned') : 'recovery-cleanup-failed'), $ok ? ALERT_STATUS_OK : ALERT_STATUS_FAIL);
                return $ok;
            }

			if ($action === 'create') {
				$password = isset($_POST['backupPassword']) && is_string($_POST['backupPassword']) ? $_POST['backupPassword'] : '';
				if (!$this->validBackupPassword($password)) {
					Alert::set($language->get('password-required'), ALERT_STATUS_FAIL);
					return false;
				}
	
				$backupId = $this->createBackup(false, $password);
				if ($backupId !== false) {
					$this->removeOldBackups();
					$syslog->add(array(
						'dictionaryKey' => 'backup-created',
						'notes' => 'Backup ID: ' . $backupId
					));
	
					Alert::set($language->get('backup-created'), ALERT_STATUS_OK);
					return true;
				} else {
					Alert::set($language->get('backup-failed'), ALERT_STATUS_FAIL);
					return false;
				}
			}
			elseif ($action === 'restore') {
				if (empty($backupId) && isset($_POST['backup-id']) && is_string($_POST['backup-id'])) {
					$backupId = trim($_POST['backup-id']);
				}
	
				if (empty($backupId)) {
					Alert::set($language->get('restore-failed'), ALERT_STATUS_FAIL);
					return false;
				}
	
				if (!$this->validBackupId($backupId)) {
					Alert::set($language->get('restore-failed'), ALERT_STATUS_FAIL);
					return false;
				}
	
				$restorePassword = null;
				if (isset($_POST['backupPassword']) && is_string($_POST['backupPassword']) && $_POST['backupPassword'] !== '') {
					if (!$this->validBackupPassword($_POST['backupPassword'])) {
						Alert::set($language->get('password-required'), ALERT_STATUS_FAIL);
						return false;
					}
					$restorePassword = $_POST['backupPassword'];
				}
				if ($this->restoreBackup($backupId, $restorePassword)) {
					$this->removeOldBackups();
					// The restore replaced DB_SYSLOG on disk. Discard the pre-restore
					// in-memory object before recording anything, otherwise optimistic
					// persistence can reject the audit entry (or a legacy implementation
					// could write stale state back over the restored log).
					$syslog = new Syslog();
					$syslog->add(array(
						'dictionaryKey' => 'backup-restored',
						'notes' => 'Restored from backup ID: ' . $backupId . (!empty($this->restoreWarnings) ? ' (warnings: ' . implode(', ', $this->restoreWarnings) . ')' : '')
					));
	
					$message = $language->get('backup-restored');
					foreach ($this->restoreWarnings as $warning) {
						if ($warning === 'rollback-cleanup') $message .= ' ' . $language->get('restore-rollback-cleanup-warning');
						elseif ($warning === 'upload-aliases') $message .= ' ' . $language->get('restore-upload-alias-warning');
					}
					Alert::set($message, ALERT_STATUS_OK);
					return true;
				} else {
					Alert::set($language->get('restore-failed'), ALERT_STATUS_FAIL);
					return false;
				}
			}
			elseif ($action === 'delete') {
				if (empty($backupId) && isset($_POST['backup-id']) && is_string($_POST['backup-id'])) {
					$backupId = trim($_POST['backup-id']);
				}
	
				if (empty($backupId)) {
					Alert::set($language->get('delete-failed'), ALERT_STATUS_FAIL);
					return false;
				}
	
				if (!$this->validBackupId($backupId)) {
					Alert::set($language->get('delete-failed'), ALERT_STATUS_FAIL);
					return false;
				}
	
				if ($this->deleteBackup($backupId)) {
					$syslog->add(array(
						'dictionaryKey' => 'backup-deleted',
						'notes' => 'Deleted backup ID: ' . $backupId
					));
	
					Alert::set($language->get('backup-deleted'), ALERT_STATUS_OK);
					return true;
				} else {
					Alert::set($language->get('delete-failed'), ALERT_STATUS_FAIL);
					return false;
				}
			}
	
			return false;
		} finally {
			$this->releaseOperationLock();
		}
	}

	private function validBackupId($backupId)
	{
		return is_string($backupId) && preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $backupId) === 1;
	}

	private function validBackupPassword($password)
	{
		return is_string($password) && strlen($password) >= 12 && strlen($password) <= 128 &&
			strpos($password, "\0") === false && !preg_match('/[\r\n]/', $password);
	}


	public function form()
	{
		global $language;

		$html = '';

		$html .= $this->settingsIntro();

		if (!$this->zipAvailable) {
			$html .= '<div class="alert alert-warning" role="alert">';
			$html .= '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' . $language->get('zip-unavailable');
			$html .= '</div>';
		}

		$collapseId = 'backupManagerSettings';

		$html .= '<div class="alert alert-info mb-4" role="alert">';
		$html .= '<i class="bi bi-shield-lock" aria-hidden="true"></i> ' . $language->get('password-not-stored');
		$html .= '</div>';

		$html .= '<div class="card metric-card mb-4">';
		$html .= '<div class="card-header">';
		$html .= '<i class="bi bi-gear" aria-hidden="true"></i> ' . $language->get('settings');
		$html .= '</div>';
		$html .= '<div class="card-body">';

		$html .= '<div class="form-group">';
		$html .= '<label for="backupPassword">' . $language->get('backup-password') . '</label>';
		$html .= '<input type="password" class="form-control" id="backupPassword" name="backupPassword" value="" autocomplete="new-password" minlength="12" maxlength="128" placeholder="' . $language->get('backup-password-placeholder') . '">';
		$html .= '<small class="form-text text-danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' . $language->get('backup-password-warning') . '</small>';
		$html .= '<small class="form-text text-muted">' . $language->get('password-request-only') . '</small>';
		$html .= '</div>';

		$html .= '<div class="form-group">';
		$html .= '<label for="maxBackups">' . $language->get('max-backups') . '</label>';
		$html .= '<input type="number" class="form-control" id="maxBackups" name="maxBackups" value="' . $this->getValue('maxBackups') . '" min="1" max="100">';
		$html .= '<small class="form-text text-muted">' . $language->get('max-backups-help') . '</small>';
		$html .= '</div>';

		$html .= '<div class="form-group">';
		$html .= '<label for="backupWarningDays">' . $language->get('backup-warning-days') . '</label>';
		$html .= '<input type="number" class="form-control" id="backupWarningDays" name="backupWarningDays" value="' . (int)$this->getValue('backupWarningDays') . '" min="1" max="365" inputmode="numeric">';
		$html .= '<small class="form-text text-muted">' . $language->get('backup-warning-days-help') . '</small>';
		$html .= '</div>';

		$html .= '</div>';
		$html .= '</div>';

		if ($this->zipAvailable && method_exists('ZipArchive', 'setEncryptionName') && defined('ZipArchive::EM_AES_256')) {
			$html .= '<div class="mb-4">';
			$html .= '<button type="submit" class="btn btn-primary" name="backup-action" value="create" formnovalidate>';
			$html .= '<i class="bi bi-download" aria-hidden="true"></i> ' . $language->get('create-backup');
			$html .= '</button>';
			$html .= '</div>';
		}

		$html .= '<h5 class="mt-4 mb-3">' . $language->get('backup-list') . '</h5>';

			$backups = $this->getBackupList();
			$backupHealth = $this->backupHealthState($backups);
			if ($backupHealth['state'] === 'missing') {
				$html .= '<div class="alert alert-warning" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' .
					$language->get('backup-health-missing') . '</div>';
			} elseif ($backupHealth['state'] === 'stale') {
				$html .= '<div class="alert alert-warning" role="status"><i class="bi bi-clock-history" aria-hidden="true"></i> ' .
					$language->get('backup-health-stale') . ' <strong>' . (int)$backupHealth['warningDays'] . '</strong> ' . $language->get('days') . '.</div>';
			}
			// Safe JS string literals inside HTML attributes
			$confirmRestore = htmlspecialchars($language->get('confirm-restore'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);
			$confirmDelete = htmlspecialchars($language->get('confirm-delete'), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET);

			if (empty($backups)) {
				$html .= '<div class="alert alert-info" role="alert">';
				$html .= $language->get('no-backups');
				$html .= '</div>';
			} else {
				$html .= '<div class="table-responsive">';
				$html .= '<table class="table table-striped">';
				$html .= '<thead>';
				$html .= '<tr>';
				$html .= '<th>' . $language->get('date') . '</th>';
				$html .= '<th>' . $language->get('type') . '</th>';
				$html .= '<th>' . $language->get('size') . '</th>';
				$html .= '<th class="text-center">' . $language->get('actions') . '</th>';
				$html .= '</tr>';
				$html .= '</thead>';
				$html .= '<tbody>';

				foreach ($backups as $backup) {
					$html .= '<tr>';

					$backupIdRaw = $backup['id'];
					$backupId = Sanitize::html($backupIdRaw);

					$html .= '<td>';
					$html .= '<strong>' . $backup['date'] . '</strong><br>';
					$html .= '<small class="text-muted">' . $backupId . '</small>';
					$html .= '</td>';

					$html .= '<td>';
					if ($backup['isZip']) {
						$html .= '<span class="badge text-bg-success">' . $language->get('compressed') . '</span>';
						if (isset($backup['encrypted']) && $backup['encrypted']) {
							$html .= ' <span class="badge text-bg-warning">' . $language->get('encrypted') . '</span>';
						}
					} else {
						$html .= '<span class="badge text-bg-secondary">' . $language->get('folder') . '</span>';
					}

					if (strpos($backupIdRaw, 'emergency-') === 0) {
						$html .= '<br><small class="text-warning">' . $language->get('emergency-backup') . '</small>';
					} else {
						$html .= '<br><small class="text-muted">' . $language->get('manual-backup') . '</small>';
					}
					$html .= '</td>';

					$html .= '<td>' . $backup['size'] . '</td>';

					$html .= '<td class="text-center">';

					$html .= '<button type="submit" class="btn btn-sm btn-primary" title="' . $language->get('restore') . '" name="backup-action" value="restore:' . $backupId . '" formnovalidate data-brendy-confirm="' . $confirmRestore . '">';
					$html .= '<i class="bi bi-upload" aria-hidden="true"></i> ' . $language->get('restore');
					$html .= '</button>';
					$html .= ' ';

					$html .= '<button type="submit" class="btn btn-sm btn-danger" title="' . $language->get('delete') . '" name="backup-action" value="delete:' . $backupId . '" formnovalidate data-brendy-confirm="' . $confirmDelete . '">';
					$html .= '<i class="bi bi-trash" aria-hidden="true"></i> ' . $language->get('delete');
					$html .= '</button>';

					$html .= '</td>';
					$html .= '</tr>';
				}

				$html .= '</tbody>';
				$html .= '</table>';
				$html .= '</div>';
			}

		return $html . $this->recoveryPanel();
	}

    private function recoveryPanel(): string
    {
        global $language, $login;
        if (!$login || $login->role() !== 'admin') return '';
        $requestedPage=filter_var($_GET['recovery-page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
        $inventory = (new RestoreJournal(PATH_CONTENT))->inventory($requestedPage);
        $t = static fn($key) => Theme::escape($language->get($key));
        $html = '<section class="card metric-card mt-4"><div class="card-header"><i class="bi bi-shield-check" aria-hidden="true"></i> '.$t('recovery-title').'</div><div class="card-body">';
        $html .= '<p>'.$t('recovery-explanation').'</p><p role="status">'.$t($inventory['pending'] ? 'recovery-pending' : 'recovery-idle').'</p>';
        $html .= '<p>'.$t('recovery-mirror').': '.$t('recovery-mirror-'.$inventory['mirror']).'<br>'.$t($inventory['checkpointConfigured'] ? 'recovery-checkpoint-configured' : 'recovery-checkpoint-unconfigured').'</p>';
        $last = $inventory['last'];
        if (is_array($last)) {
            $html .= '<p><strong>'.$t('recovery-last').':</strong> '.$t('recovery-'.$last['result']).' · '.Theme::escape(gmdate('Y-m-d H:i:s',$last['time']).' UTC').'<br><code>'.Theme::escape($last['id']).'</code><br>'.$t($last['databaseVerified'] ? 'recovery-db-verified' : 'recovery-db-legacy').'</p>';
        } else $html .= '<p>'.$t('recovery-no-result').'</p>';
        if ($inventory['history']) {
            $pages=(int)ceil($inventory['historyTotal']/50);
            $page=$inventory['historyPage'];
            $html .= '<details class="mb-3"><summary>'.$t('recovery-history').' ('.$inventory['historyTotal'].')</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>'.$t('recovery-last').'</th><th>'.$t('recovery-date').'</th><th>ID</th></tr></thead><tbody>';
            foreach ($inventory['history'] as $receipt) $html .= '<tr><td>'.(int)$receipt['seq'].'</td><td>'.$t('recovery-'.$receipt['result']).'</td><td>'.Theme::escape(gmdate('Y-m-d H:i:s',$receipt['time']).' UTC').'</td><td><code class="text-break">'.Theme::escape($receipt['id']).'</code></td></tr>';
            $html .= '</tbody></table></div>';
            if ($pages>1) {
                $html .= '<nav aria-label="'.$t('recovery-history').'"><p>'.$t('recovery-page').' '.$page.' / '.$pages.'</p>';
                if ($page>1) $html .= '<a class="btn btn-sm btn-outline-secondary me-2" href="?recovery-page='.($page-1).'">'.$t('recovery-prev').'</a>';
                if ($page<$pages) $html .= '<a class="btn btn-sm btn-outline-secondary" href="?recovery-page='.($page+1).'">'.$t('recovery-next').'</a>';
                $html .= '</nav>';
            }
            $html .= '</details>';
        }
        if (!$inventory['complete']) $html .= '<p class="alert alert-warning" role="alert">'.$t('recovery-incomplete').'</p>';
        if (!$inventory['items']) $html .= '<p>'.$t($inventory['complete'] ? 'recovery-empty' : 'recovery-no-visible').'</p>';
        else {
            $html .= '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>'.$t('recovery-directory').'</th><th>'.$t('recovery-state').'</th><th>'.$t('actions').'</th></tr></thead><tbody>';
            foreach ($inventory['items'] as $item) {
                $name = Theme::escape($item['name']);
                $html .= '<tr><td><code class="text-break">'.$name.'</code></td><td>'.$t(!$item['safeDirectory'] ? 'recovery-unsafe' : ($item['token'] === null ? 'recovery-retained' : ($item['verified'] ? (!empty($item['gcPending']) ? 'recovery-gc-pending' : 'recovery-purge-eligible') : 'recovery-eligible'))).'</td><td>';
                if ($item['token'] !== null && $inventory['complete']) {
                    $html .= '<input type="hidden" name="recovery-token['.$name.']" value="'.Theme::escape($item['token']).'">';
                    $html .= '<button type="submit" class="btn btn-sm btn-outline-danger" name="backup-action" value="'.($item['verified'] ? 'recovery-purge:' : 'recovery-cleanup:').$name.'" formnovalidate data-brendy-confirm="'.$t($item['verified'] ? 'recovery-purge-confirm' : 'recovery-confirm').'">'.$t($item['verified'] ? (!empty($item['gcPending']) ? 'recovery-gc-resume' : 'recovery-purge') : 'recovery-cleanup').'</button>';
                } else $html .= '—';
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html.'</div></section>';
    }

	private function createBackup($isEmergency = false, $password = null)
	{
		try {
				$timestamp = date('YmdHis');
				$backupId = ($isEmergency ? 'emergency-' : 'backup-') . $timestamp . '-' . bin2hex(random_bytes(4));
	
				$workspace = $this->workspace();
				$backupPath = $workspace . $backupId . DS;
				if ($password !== null && !$this->validBackupPassword($password)) return false;
				if (!empty($password) && (!$this->zipAvailable || !method_exists('ZipArchive', 'setEncryptionName') ||
					!defined('ZipArchive::EM_AES_256'))) {
					Log::set(__METHOD__ . LOG_SEP . 'Encrypted backups are unavailable on this server.');
					return false;
				}
	
				if (!Filesystem::mkdir($backupPath, true)) {
					Log::set(__METHOD__ . LOG_SEP . 'Failed to create backup directory: ' . $backupPath);
					return false;
				}
	
				$outermost = contentMutationLockDepth() === 0;
				if (!acquireContentMutationLock()) {
					Filesystem::deleteRecursive($backupPath);
					return false;
				}
				try {
					if ($outermost) refreshContentMutationState();
					$copied = $this->copyContentExcludingBackups(PATH_CONTENT, $backupPath . 'sadrzaj');
				} finally {
					releaseContentMutationLock();
				}
				if (!$copied) {
					Log::set(__METHOD__ . LOG_SEP . 'Failed to copy content to backup');
					Filesystem::deleteRecursive($backupPath);
					return false;
				}

				$contentDigest = Filesystem::treeDigest($backupPath . 'sadrzaj', 1073741824, 100000);
				if (!is_array($contentDigest)) {
					Log::set(__METHOD__ . LOG_SEP . 'Unable to calculate backup content digest.', LOG_TYPE_ERROR);
					Filesystem::deleteRecursive($backupPath);
					return false;
				}
	
				$metadata = array(
					'id' => $backupId,
					'timestamp' => $timestamp,
					'date' => date('Y-m-d H:i:s'),
					'cmsVersion' => defined('BRENDY_VERSION') ? BRENDY_VERSION : '',
					'cmsBuild' => defined('BRENDY_BUILD') ? (int)BRENDY_BUILD : 0,
					'contentSchema' => self::BACKUP_CONTENT_SCHEMA,
					'metadataVersion' => 2,
					'contentDigestAlgorithm' => $contentDigest['algorithm'],
					'contentDigest' => $contentDigest['hash'],
					'contentFiles' => $contentDigest['files'],
					'contentBytes' => $contentDigest['bytes'],
					'isEmergency' => $isEmergency,
					'compressed' => false,
					'encrypted' => false
				);
	
				if ($this->zipAvailable) {
					$zipPath = $workspace . $backupId . '.zip';
	
					if (!empty($password)) {
						if ($this->createEncryptedZip($backupPath, $zipPath, $password)) {
							if (!Filesystem::deleteRecursive($backupPath)) {
								@unlink($zipPath);
								Log::set(__METHOD__ . LOG_SEP . 'Unable to remove unencrypted staging data.');
								return false;
							}
							$metadata['compressed'] = true;
							$metadata['encrypted'] = true;
							Log::set(__METHOD__ . LOG_SEP . 'Backup created with AES-256 encryption: ' . $backupId);
						} else {
							Log::set(__METHOD__ . LOG_SEP . 'Encrypted ZIP failed; removing unencrypted staging data: ' . $backupId);
							if (file_exists($zipPath)) @unlink($zipPath);
							Filesystem::deleteRecursive($backupPath);
							return false;
						}
					} else {
						if (Filesystem::zip($backupPath, $zipPath)) {
							if (!Filesystem::deleteRecursive($backupPath)) {
								@unlink($zipPath);
								return false;
							}
							$metadata['compressed'] = true;
							Log::set(__METHOD__ . LOG_SEP . 'Backup created and compressed: ' . $backupId);
						} else {
							Log::set(__METHOD__ . LOG_SEP . 'ZIP compression failed, using folder backup: ' . $backupId);
							$metadataPath = $backupPath . 'metadata.json';
							if ($this->writeMetadata($metadataPath, $metadata)) {
								return $backupId;
							}
							Filesystem::deleteRecursive($backupPath);
							return false;
						}
					}
	
					$metadataPath = $workspace . $backupId . '.json';
					if (!$this->writeMetadata($metadataPath, $metadata)) {
						@unlink($zipPath);
						return false;
					}
				} else {
					$metadataPath = $backupPath . 'metadata.json';
					if (!$this->writeMetadata($metadataPath, $metadata)) {
						Filesystem::deleteRecursive($backupPath);
						return false;
					}
	
					Log::set(__METHOD__ . LOG_SEP . 'Backup created as folder: ' . $backupId);
				}
	
				return $backupId;
			} catch (Throwable $e) {
				Log::set(__METHOD__ . LOG_SEP . 'Exception during backup: ' . $e->getMessage());
				return false;
			}
		}
	
		private function restoreBackup($backupId, $passwordOverride = null)
		{
			brendyRestoreExclusive();
			$outermost = contentMutationLockDepth() === 0;
			if (!acquireContentMutationLock()) return false;
		try {
				if ($outermost) refreshContentMutationState();
				return $this->restoreBackupUnlocked($backupId, $passwordOverride);
		} finally {
			releaseContentMutationLock();
		}
	}

	private function restoreBackupUnlocked($backupId, $passwordOverride = null)
	{
		$this->restoreWarnings = array();
        $this->restoreTempIdentity = null;
		$tempPath = null;
		$contentCommitted = false;
		try {
				if (!$this->validBackupId($backupId)) {
					return false;
				}
				$workspace = $this->workspace();
				$backupPath = $workspace . $backupId . DS;
				$zipPath = $workspace . $backupId . '.zip';
	
				$isZip = is_file($zipPath) && !is_link($zipPath);
				$isFolder = Filesystem::directoryExists($backupPath);
	
				if (!$isZip && !$isFolder) {
					Log::set(__METHOD__ . LOG_SEP . 'Backup not found: ' . $backupId);
					return false;
				}

				$metadataPath = $isZip ? ($workspace . $backupId . '.json') : ($backupPath . 'metadata.json');
				$restoreMetadata = null;
				if (file_exists($metadataPath) || is_link($metadataPath)) {
					if (!is_file($metadataPath) || is_link($metadataPath) || !is_readable($metadataPath)) {
						Log::set(__METHOD__ . LOG_SEP . 'Backup metadata exists but is not a safe readable file.', LOG_TYPE_ERROR);
						return false;
					}
					$metadataRaw = Filesystem::readFileBounded($metadataPath, 1048576, 2);
					if (!is_string($metadataRaw)) {
						Log::set(__METHOD__ . LOG_SEP . 'Backup metadata size/read state is invalid.', LOG_TYPE_ERROR);
						return false;
					}
					try {
						$restoreMetadata = is_string($metadataRaw)
							? json_decode($metadataRaw, true, 64, JSON_THROW_ON_ERROR) : null;
					} catch (JsonException $e) {
						$restoreMetadata = null;
					}
					if (!is_array($restoreMetadata)) {
						Log::set(__METHOD__ . LOG_SEP . 'Backup metadata is malformed.', LOG_TYPE_ERROR);
						return false;
					}
				}
				if (!$this->backupMetadataCompatible($restoreMetadata) ||
					(is_array($restoreMetadata) && isset($restoreMetadata['id']) &&
					 (!is_string($restoreMetadata['id']) || !hash_equals($backupId, $restoreMetadata['id'])))) {
					Log::set(__METHOD__ . LOG_SEP . 'Backup metadata is incompatible, mismatched or invalid.', LOG_TYPE_ERROR);
					return false;
				}
				if (is_array($restoreMetadata) && isset($restoreMetadata['metadataVersion']) &&
					(int)$restoreMetadata['metadataVersion'] >= 2) {
					if ($restoreMetadata['compressed'] !== $isZip || (!$isZip && $restoreMetadata['encrypted'] === true)) {
						Log::set(__METHOD__ . LOG_SEP . 'Backup metadata does not match the stored backup format.', LOG_TYPE_ERROR);
						return false;
					}
				}
	
				$backupManagerWorkspace = $this->workspace();
				$restoreEncrypted = false;
	
				$tempPath = PATH_TMP . 'restore-' . bin2hex(random_bytes(16)) . DS;
				if (!@mkdir($tempPath, 0700)) {
					$this->cleanupRestoreTemp(null);
					return false;
				}
	
                $this->restoreTempIdentity = RestoreJournal::identity(rtrim($tempPath,DS));
                if (!is_array($this->restoreTempIdentity) || $this->restoreTempIdentity[2] !== 0040000) return false;
				if ($isZip) {
					$restoreEncrypted = is_array($restoreMetadata) && isset($restoreMetadata['encrypted']) && $restoreMetadata['encrypted'] === true;
	
					if ($restoreEncrypted) {
						$password = $passwordOverride;
						$extracted = is_string($password) && $password !== '' && $this->extractEncryptedZip($zipPath, $tempPath, $password);
						if (!$extracted && is_string($password) && $password !== '') {
							// Brendy <=1.4.1 accidentally passed HTML-escaped plugin values to
							// ZipArchive. Retry that exact legacy representation so existing
							// encrypted backups remain restorable after correcting password handling.
							$legacyPassword = Sanitize::html($password);
							if ($legacyPassword !== $password) {
								$extracted = $this->extractEncryptedZip($zipPath, $tempPath, $legacyPassword);
							}
						}
						if (!$extracted) {
							Log::set(__METHOD__ . LOG_SEP . 'Failed to extract encrypted ZIP backup');
							$this->cleanupRestoreTemp($tempPath);
							return false;
						}
					} else {
						if (!Filesystem::unzip($zipPath, $tempPath, null, 1073741824, 100000)) {
							Log::set(__METHOD__ . LOG_SEP . 'Failed to extract ZIP backup');
							$this->cleanupRestoreTemp($tempPath);
							return false;
						}
					}
				} else {
					if (!Filesystem::copyRecursive($backupPath . 'sadrzaj', $tempPath . 'sadrzaj', false)) {
						Log::set(__METHOD__ . LOG_SEP . 'Failed to copy folder backup');
						$this->cleanupRestoreTemp($tempPath);
						return false;
					}
				}
	
				$restoredContent = $tempPath . 'sadrzaj' . DS;
				if (!$this->validateRestoredContent($restoredContent) ||
					!$this->verifyBackupContentDigest($restoredContent, $restoreMetadata)) {
					Log::set(__METHOD__ . LOG_SEP . 'Restored content is invalid or failed integrity verification.', LOG_TYPE_ERROR);
					$this->cleanupRestoreTemp($tempPath);
					return false;
				}

				// Only create the emergency snapshot after the source backup has been
				// successfully extracted and validated. A wrong password/corrupt archive
				// therefore has no persistent side effects. When the source is encrypted,
				// reuse the now-verified password for the emergency copy.
				$emergencyPassword = $restoreEncrypted && is_string($passwordOverride) && $passwordOverride !== ''
					? $passwordOverride : null;
				$emergencyBackupId = $this->createBackup(true, $emergencyPassword);
				if ($emergencyBackupId === false) {
					Log::set(__METHOD__ . LOG_SEP . 'Failed to create emergency backup before restore', LOG_TYPE_ERROR);
					$this->cleanupRestoreTemp($tempPath);
					return false;
				}

                // Preserve the workspace INSIDE the incoming tree before the journal
                // is published. No second, unjournaled live replacement after commit.
                foreach (array('workspaces', 'revisions') as $directory) {
                    $target = $restoredContent . $directory;
                    if (!Filesystem::directoryExists($target) && !@mkdir($target, 0700)) {
                        $this->cleanupRestoreTemp($tempPath);
                        return false;
                    }
                }
                $incomingWorkspace = $restoredContent . 'workspaces' . DS . 'backup-manager';
                if ((file_exists($incomingWorkspace) || is_link($incomingWorkspace)) &&
                    !Filesystem::deleteRecursive($incomingWorkspace)) {
                    $this->cleanupRestoreTemp($tempPath);
                    return false;
                }
                if (Filesystem::directoryExists($backupManagerWorkspace) &&
                    !Filesystem::copyRecursive($backupManagerWorkspace, $incomingWorkspace, false)) {
                    $this->cleanupRestoreTemp($tempPath);
                    return false;
                }
                if (!$this->replaceContentAtomically($restoredContent)) {
                    $this->cleanupRestoreTemp($tempPath);
                    return false;
                }
                $contentCommitted = true;
                if (!$this->cleanupRestoreTemp($tempPath)) {
                    $this->restoreWarnings[] = 'rollback-cleanup';
                }

				Log::set(__METHOD__ . LOG_SEP . 'Backup restored successfully: ' . $backupId);
				return true;
			} catch (Throwable $e) {
				if ($contentCommitted) {
					$this->restoreWarnings[] = 'rollback-cleanup';
					Log::set(__METHOD__ . LOG_SEP . 'Post-commit restore failure; recovery staging retained.', LOG_TYPE_ERROR);
					return true;
				}
				$this->cleanupRestoreTemp($tempPath);
				Log::set(__METHOD__ . LOG_SEP . 'Exception during restore: ' . $e->getMessage());
				return false;
			}
		}
	
		private function backupMetadataCompatible($metadata)
		{
			if ($metadata === null) return true; // Legacy backups may genuinely have no metadata file.
			if (!is_array($metadata)) return false;
			if (isset($metadata['contentSchema'])) {
				$schema = filter_var($metadata['contentSchema'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
				if ($schema === false || $schema > self::BACKUP_CONTENT_SCHEMA) return false;
			}
			foreach (array('compressed', 'encrypted', 'isEmergency') as $booleanField) {
				if (array_key_exists($booleanField, $metadata) && !is_bool($metadata[$booleanField])) return false;
			}
			if (isset($metadata['id']) && (!is_string($metadata['id']) ||
				!$this->validBackupId($metadata['id']))) return false;
			$metadataVersion = null;
			if (isset($metadata['metadataVersion'])) {
				$metadataVersion = filter_var($metadata['metadataVersion'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
				if ($metadataVersion === false || $metadataVersion > 2) return false;
			}

			$digestFields = array('contentDigestAlgorithm', 'contentDigest', 'contentFiles', 'contentBytes');
			$digestCount = 0;
			foreach ($digestFields as $field) if (array_key_exists($field, $metadata)) $digestCount++;
			if ($metadataVersion !== null && $metadataVersion >= 2) {
				foreach (array('id', 'contentSchema', 'compressed', 'encrypted', 'isEmergency') as $requiredField) {
					if (!array_key_exists($requiredField, $metadata)) return false;
				}
				if ($digestCount !== count($digestFields)) return false;
			}
			if ($digestCount !== 0 && $digestCount !== count($digestFields)) return false;
			if ($digestCount === count($digestFields)) {
				if ($metadata['contentDigestAlgorithm'] !== 'sha256-tree-v1' || !is_string($metadata['contentDigest']) ||
					preg_match('/^[a-f0-9]{64}$/D', strtolower($metadata['contentDigest'])) !== 1) return false;
				$files = filter_var($metadata['contentFiles'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 100000)));
				$bytes = filter_var($metadata['contentBytes'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 1073741824)));
				if ($files === false || $bytes === false) return false;
			}
			return true;
		}

		private function verifyBackupContentDigest($restoredContent, $metadata)
		{
			if (!is_array($metadata) || !array_key_exists('contentDigest', $metadata)) return true;
			$digest = Filesystem::treeDigest($restoredContent, 1073741824, 100000);
			if (!is_array($digest)) return false;
			return $digest['algorithm'] === $metadata['contentDigestAlgorithm'] &&
				hash_equals(strtolower($metadata['contentDigest']), $digest['hash']) &&
				(int)$metadata['contentFiles'] === $digest['files'] &&
				(int)$metadata['contentBytes'] === $digest['bytes'];
		}

        private function cleanupRestoreTemp($restorePath)
        {
            // Never remove recovery evidence while any journal is pending.
            if ((new RestoreJournal(PATH_CONTENT))->pending()) brendyRestoreUnavailable();
            if (!is_string($restorePath) || $restorePath === '') return true;
            $path = rtrim($restorePath,DS);
            if (dirname($path) !== rtrim(PATH_TMP,DS) || !preg_match('/^restore-[a-f0-9]{32}$/D',basename($path))) return false;
            $id = RestoreJournal::identity($path);
            if ($id === null) return true;
            foreach ((new RestoreJournal(PATH_CONTENT))->history() as $receipt) {
                if ($receipt['stage']===basename($path) && $receipt['result']==='rolled-back') return true;
            }
            return is_array($this->restoreTempIdentity) && $id === $this->restoreTempIdentity &&
                Filesystem::deleteRecursive($path,true,$this->restoreTempIdentity);
        }

		private function validateRestoredContent($path)
		{
			if (!is_dir($path) || is_link($path) || !is_dir($path . 'databases') || !is_dir($path . 'pages') ||
				!is_dir($path . 'uploads') || !is_file($path . 'databases' . DS . 'pages.php') ||
				!is_file($path . 'databases' . DS . 'site.php') ||
				!is_file($path . 'databases' . DS . 'users.php') ||
				!is_file($path . 'databases' . DS . 'security.php') ||
				!is_file($path . 'databases' . DS . 'syslog.php')) {
				return false;
			}
	
			// Treat backups as untrusted input even when they were created locally.
			// The shared snapshot validator rejects symlinks/special files, nested
			// server-control files and executable payloads before any content swap.
			if (!Filesystem::validateContentSnapshot($path, 1073741824, 100000)) return false;

			$pageCheck = BrendyJsonDatabase::inspectFile($path . 'databases' . DS . 'pages.php', true);
			$siteCheck = BrendyJsonDatabase::inspectFile($path . 'databases' . DS . 'site.php', true);
			$userCheck = BrendyJsonDatabase::inspectFile($path . 'databases' . DS . 'users.php', true);
			$securityCheck = BrendyJsonDatabase::inspectFile($path . 'databases' . DS . 'security.php', true, 2097152);
			$syslogCheck = BrendyJsonDatabase::inspectFile($path . 'databases' . DS . 'syslog.php', true, 33554432);
			return $pageCheck['ok'] === true && $siteCheck['ok'] === true && $siteCheck['entries'] > 0 &&
				$userCheck['ok'] === true && $userCheck['entries'] > 0 &&
				$securityCheck['ok'] === true && $securityCheck['entries'] > 0 && $syslogCheck['ok'] === true;
		}
	
        private function replaceContentAtomically($restoredContent)
        {
            $deploymentIntegrity = captureBrendyDeploymentIntegrityState();
            if (!is_array($deploymentIntegrity)) return false;
            $journal = new RestoreJournal(PATH_CONTENT);
            $stage = basename(dirname(rtrim($restoredContent, DS)));
            $plan = false;
            try {
                $plan = $journal->prepare($stage);
                if ($plan !== false && $journal->apply($plan) && protectContentDirectories() &&
                    restoreBrendyDeploymentIntegrityState($deploymentIntegrity)) {
                    // Rebuild aliases before the durable commit. A crash before
                    // this marker still restores the untouched previous tree.
                    try { $aliasesOk = $this->rebuildUploadAliases(); } catch (Throwable $e) { $aliasesOk = false; }
                    if (!$aliasesOk) $this->restoreWarnings[] = 'upload-aliases';
                    $journal->commit($plan,true);
                }
            } catch (Throwable $e) {
                Log::set(__METHOD__ . LOG_SEP . 'Restore transaction interrupted.', LOG_TYPE_ERROR);
            }
            try { $result = $journal->recover(); } catch (Throwable $e) { $result = 'blocked'; }
            if ($result === 'blocked') {
                // Do not let the outer catch or request shutdown clean the
                // source tree or write stale data over an ambiguous transaction.
                error_log('Brendy CMS: restore transaction blocked; staging retained.');
                brendyRestoreUnavailable();
            }
            // Keep nonempty rollback evidence until an explicit verified administrator purge.
            return $result === 'committed';
        }

		private function rebuildUploadAliases()
		{
			if (!is_dir(PATH_UPLOADS_PAGES) || is_link(PATH_UPLOADS_PAGES) || !is_file(DB_PAGES) || is_link(DB_PAGES)) {
				return false;
			}
			$database = new BrendyJsonDatabase(DB_PAGES);
			$stats = array();
			$ok = brendyRebuildPageUploadAliases($database->db, $stats);
			if (!$ok) {
				Log::set(__METHOD__ . LOG_SEP . 'Page upload alias rebuild incomplete: ' .
					(isset($stats['failed']) ? (int)$stats['failed'] : 1) . ' failure(s).', LOG_TYPE_ERROR);
			}
			return $ok;
		}

		private function deleteBackup($backupId)
		{
		try {
				if (!$this->validBackupId($backupId)) {
					return false;
				}
				$workspace = $this->workspace();
				$backupPath = $workspace . $backupId . DS;
				$zipPath = $workspace . $backupId . '.zip';
				$metadataPath = $workspace . $backupId . '.json';
	
				if (is_link($zipPath) || is_link($metadataPath) || is_link(rtrim($backupPath, DS))) {
					return false;
				}
				$hasZip = is_file($zipPath);
				$hasMetadata = is_file($metadataPath);
				$hasDirectory = Filesystem::directoryExists($backupPath);
				$found = $hasZip || $hasMetadata || $hasDirectory;
				$success = true;
				if ($hasZip) {
					$success = Filesystem::rmfile($zipPath) && $success;
				}
	
				if ($hasMetadata) {
					$success = Filesystem::rmfile($metadataPath) && $success;
				}
	
				if ($hasDirectory) {
					$success = Filesystem::deleteRecursive($backupPath) && $success;
				}
	
				if ($found && $success) Log::set(__METHOD__ . LOG_SEP . 'Backup deleted: ' . $backupId);
				return $found && $success;
			} catch (Throwable $e) {
				Log::set(__METHOD__ . LOG_SEP . 'Exception during delete: ' . $e->getMessage());
				return false;
			}
		}
	
		private function removeOldBackups()
		{
		try {
				$maxBackups = (int)$this->getValue('maxBackups');
				if ($maxBackups <= 0) {
					return;
				}
	
				$backups = $this->getBackupList(false);
	
				// Enforce the configured limit for regular backups and retain the five
				// newest emergency restore points to prevent unbounded disk growth.
				$regularBackups = array();
				$emergencyBackups = array();
				foreach ($backups as $backup) {
					if (strpos($backup['id'], 'emergency-') === 0) {
						$emergencyBackups[] = $backup;
						continue;
					}
					$regularBackups[] = $backup;
				}
	
				$backupCount = count($regularBackups);
				$backupsToRemove = $backupCount > $maxBackups
					? array_slice($regularBackups, -($backupCount - $maxBackups))
					: array();
				if (count($emergencyBackups) > 5) {
					$backupsToRemove = array_merge($backupsToRemove, array_slice($emergencyBackups, 5));
				}
				if (empty($backupsToRemove)) {
					return;
				}
				foreach ($backupsToRemove as $backup) {
					$this->deleteBackup($backup['id']);
				}
	
				Log::set(__METHOD__ . LOG_SEP . 'Removed ' . count($backupsToRemove) . ' old backup(s)');
			} catch (Throwable $e) {
				Log::set(__METHOD__ . LOG_SEP . 'Exception during cleanup: ' . $e->getMessage());
			}
		}
	
		private function getBackupList($includeSize = true)
		{
			$includeSize = $includeSize === true;
			$workspace = $this->workspace();
			$backups = array();
	
			if (!Filesystem::directoryExists($workspace)) {
				return $backups;
			}
	
			$directories = array_slice(Filesystem::listDirectories($workspace, '*', true), 0, 1000);
			foreach ($directories as $dir) {
				if (!Filesystem::directoryExists($dir) || !is_readable($dir) ||
					!is_dir($dir . DS . 'sadrzaj') || is_link($dir . DS . 'sadrzaj')) continue;
				$backupId = basename($dir);
				if (!$this->validBackupId($backupId)) continue;
				clearstatcache(true, $dir);
				$modified = @filemtime($dir);
				$size = $includeSize ? Filesystem::getSize($dir) : 0;
				// A concurrently removed/unreadable backup is not a valid list entry.
				// Never turn a failed stat() into 1970-01-01 or a misleading 0 B row.
				if (!is_int($modified) || $modified < 0 || ($includeSize && (!is_int($size) || $size < 0))) continue;
				$metadataPath = $dir . DS . 'metadata.json';
	
				$metadata = array(
					'id' => $backupId,
					'date' => date('Y-m-d H:i:s', $modified),
					'createdAt' => $modified,
					'isZip' => false,
					'size' => $includeSize ? Filesystem::bytesToHumanFileSize($size) : ''
				);
	
				$metadataRaw = Filesystem::readFileBounded($metadataPath, 1048576, 0);
				if (is_string($metadataRaw)) {
					$metadataContent = json_decode($metadataRaw, true, 64);
					if (is_array($metadataContent)) $metadata = $this->mergeBackupMetadata($metadata, $metadataContent);
				}
	
				// ID, ordering and retention age always come from the filesystem.
				$metadata['id'] = $backupId;
				$metadata['createdAt'] = $modified;
				$backups[] = $metadata;
			}
	
			$files = array_slice(Filesystem::listFiles($workspace, '*', 'zip', true), 0, 1000);
			foreach ($files as $file) {
				if (!is_file($file) || is_link($file) || !is_readable($file)) continue;
				$backupId = basename($file, '.zip');
				if (!$this->validBackupId($backupId)) continue;
				clearstatcache(true, $file);
				$modified = @filemtime($file);
				$size = @filesize($file);
				if (!is_int($modified) || $modified < 0 || !is_int($size) || $size <= 0) continue;
				$metadataPath = $workspace . $backupId . '.json';
	
				$metadata = array(
					'id' => $backupId,
					'date' => date('Y-m-d H:i:s', $modified),
					'createdAt' => $modified,
					'isZip' => true,
					'size' => $includeSize ? Filesystem::bytesToHumanFileSize($size) : ''
				);
	
				$metadataRaw = Filesystem::readFileBounded($metadataPath, 1048576, 0);
				if (is_string($metadataRaw)) {
					$metadataContent = json_decode($metadataRaw, true, 64);
					if (is_array($metadataContent)) $metadata = $this->mergeBackupMetadata($metadata, $metadataContent);
				}
	
				$metadata['id'] = $backupId;
				$metadata['createdAt'] = $modified;
				$backups[] = $metadata;
			}
	
			// Retention and health must not trust editable metadata dates. Ordering by
			// filesystem mtime also remains deterministic if metadata is stale/corrupt.
			usort($backups, static function($a, $b) {
				return ((int)$b['createdAt']) <=> ((int)$a['createdAt']);
			});
	
			return $backups;
		}
	
		private function mergeBackupMetadata($base, $stored)
		{
			if (isset($stored['date']) && is_string($stored['date']) &&
				preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $stored['date']) === 1) {
				$base['date'] = $stored['date'];
			}
			foreach (array('isEmergency', 'compressed', 'encrypted') as $field) {
				if (isset($stored[$field]) && is_bool($stored[$field])) {
					$base[$field] = $stored[$field];
				}
			}
			if (isset($stored['cmsVersion']) && is_string($stored['cmsVersion']) && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $stored['cmsVersion']) === 1) {
				$base['cmsVersion'] = $stored['cmsVersion'];
			}
			foreach (array('cmsBuild', 'contentSchema') as $field) {
				if (isset($stored[$field]) && is_int($stored[$field]) && $stored[$field] >= 0) $base[$field] = $stored[$field];
			}
			return $base;
		}
	}
