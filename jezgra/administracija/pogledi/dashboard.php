<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<?php
$dashboardCanManageContent = checkRole(array('admin', 'editor', 'author'), false);
$dashboardIsAdmin = checkRole(array('admin'), false);
$dashboardIsAuthor = checkRole(array('author'), false);
$dashboardContentCounts = array('published' => 0, 'draft' => 0, 'scheduled' => 0);
if ($dashboardCanManageContent) {
	if ($dashboardIsAuthor) {
		$ownedPublished = $pages->getList(1, -1, true, false, false, false, false, false, $login->username());
		$countOwned = static function ($keys) use ($pages, $login) {
			$count = 0;
			foreach (is_array($keys) ? $keys : array() as $key) {
				if (!is_string($key) || !isset($pages->db[$key]) || !is_array($pages->db[$key])) continue;
				if (isset($pages->db[$key]['username']) && is_string($pages->db[$key]['username']) && $pages->db[$key]['username'] === $login->username()) $count++;
			}
			return $count;
		};
		$dashboardContentCounts['published'] = is_array($ownedPublished) ? count($ownedPublished) : 0;
		$dashboardContentCounts['draft'] = $countOwned($pages->getDraftDB(true));
		$dashboardContentCounts['scheduled'] = $countOwned($pages->getScheduledDB(true));
	} else {
		$dashboardContentCounts['published'] = count($pages->getPublishedDB());
		$dashboardContentCounts['draft'] = count($pages->getDraftDB());
		$dashboardContentCounts['scheduled'] = count($pages->getScheduledDB());
	}
}

$dashboardLogs = is_array($syslog->db) ? $syslog->db : array();
if (!$dashboardIsAdmin) {
	$currentUsername = $login->username();
	$dashboardLogs = array_values(array_filter($dashboardLogs, static function ($log) use ($currentUsername) {
		return is_array($log) && isset($log['username']) && is_scalar($log['username']) && (string)$log['username'] === $currentUsername;
	}));
}
$dashboardLogs = array_slice($dashboardLogs, 0, NOTIFICATIONS_AMOUNT);
?>
<div id="dashboard" class="container">

	<?php if (!empty($updatePending) && checkRole(array('admin'), false)): ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
		<span><i class="bi bi-shield-check" aria-hidden="true"></i> <?php echo Theme::escape('Dostupno je ažuriranje jezgre Brendy CMS-a. Pokrenite sigurnu migraciju sustava.') ?></span>
		<form method="post" action="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'dashboard') ?>" class="m-0">
			<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
			<button type="submit" name="applySystemUpdate" value="1" class="btn btn-sm btn-primary">
				<i class="bi bi-arrow-repeat" aria-hidden="true"></i> <?php echo Theme::escape('Ažuriraj sustav') ?>
			</button>
		</form>
	</div>
	<?php endif ?>

            <?php if ($dashboardCanManageContent): ?>
            <!-- Search with welcome message -->
            <?php
            $username = $login->username();
            $user = new User($username);
            $name = '';
            $nickname = $user->nickname();
            $firstName = $user->firstName();
            if (is_string($nickname) && $nickname !== '') {
                $name = $nickname;
            } elseif (is_string($firstName) && $firstName !== '') {
                $name = $firstName;
            }
            ?>
            <div class="quick-search-trigger mb-4" id="searchTrigger" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="searchModal" aria-keyshortcuts="Control+Shift+K Meta+Shift+K">
                <div class="quick-search-icon">
					<i class="bi bi-stars" id="hello-icon" aria-hidden="true"></i>
                </div>
                <span class="quick-search-text">
                    <span id="hello-text"><?php echo Theme::escape($L->g('welcome') . ($name !== '' ? ', ' . $name : '')) ?></span>
                    <small class="quick-search-hint"><?php $L->p('click-here-for-quick-search') ?></small>
                </span>
                <span class="quick-search-shortcut">Ctrl/⌘ Shift K</span>
            </div>
            <script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
                $(function() {
					var date = new Date();
					var hours = date.getHours();
					var icon, greeting;
					var suffix = <?php echo json_encode($name !== '' ? ', ' . $name : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
                    if (hours >= 6 && hours < 12) {
						icon = 'bi-sun'; greeting = <?php echo json_encode($L->g('good-morning'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> + suffix;
                    } else if (hours >= 12 && hours < 18) {
						icon = 'bi-sun'; greeting = <?php echo json_encode($L->g('good-afternoon'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> + suffix;
                    } else if (hours >= 18 && hours < 22) {
						icon = 'bi-moon-stars'; greeting = <?php echo json_encode($L->g('good-evening'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> + suffix;
                    } else {
						icon = 'bi-moon-stars'; greeting = <?php echo json_encode($L->g('good-night'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> + suffix;
                    }
					$('#hello-icon').attr('class', 'bi ' + icon);
					$('#hello-text').text(greeting);
                });
            </script>

            <!-- Quick Search Modal -->
            <div class="quick-search-modal" id="searchModal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php echo Theme::escape($L->g('Search')) ?>">
                <div class="quick-search-overlay" id="searchOverlay"></div>
                <div class="quick-search-content">
                    <div class="quick-search-header">
						<i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="jsclippy" class="quick-search-input" aria-label="<?php echo Theme::escape($L->g('Search')) ?>" placeholder="<?php echo Theme::escape($L->g('search-placeholder')) ?>" autocomplete="off">
						<button type="button" class="btn btn-sm btn-light quick-search-close" id="searchClose" aria-label="<?php echo Theme::escape($L->g('Close')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                    <div id="searchResults" class="quick-search-results"></div>
                </div>
            </div>

            <script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
                $(function() {
                    var searchInput = $('#jsclippy');
                    var searchResults = $('#searchResults');
                    var modal = $('#searchModal');
                    var trigger = $('#searchTrigger');
                    var overlay = $('#searchOverlay');
                    var closeButton = $('#searchClose');
                    var searchTimeout;
                    var searchRequest = null;
					var searchLabels = <?php echo json_encode(array(
						'view' => $L->g('view'),
						'edit' => $L->g('edit'),
						'noResults' => $L->g('no-results-found'),
						'requestFailed' => $L->g('request-failed')
					), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

                    function openSearch() {
                        modal.addClass('active').attr('aria-hidden', 'false');
                        $('body').css('overflow', 'hidden');
                        setTimeout(function() {
                            searchInput.focus();
                        }, 150);
                    }

                    function closeSearch() {
                        modal.removeClass('active').attr('aria-hidden', 'true');
                        $('body').css('overflow', '');
                        if (searchRequest && searchRequest.readyState !== 4) searchRequest.abort();
                        searchRequest = null;
                        searchInput.val('');
                        searchResults.empty();
                        trigger.trigger('focus');
                    }

                    function performSearch(query) {
                        if (!query) {
                            searchResults.empty();
                            return;
                        }

                        if (searchRequest && searchRequest.readyState !== 4) searchRequest.abort();
                        searchRequest = $.ajax({
                            url: HTML_PATH_ADMIN_ROOT + "ajax/clippy",
                            data: { query: query },
                            success: function(data) {
                                searchResults.empty();

                                if (data && data.results && data.results.length > 0) {
									data.results.slice(0, 100).forEach(function(item) {
										if (!item || typeof item.text !== 'string' || item.text.length > 1000) return;
										if (item.type === 'menu') {
											if (typeof item.url !== 'string' || !item.url.startsWith(HTML_PATH_ADMIN_ROOT) ||
												/[\x00-\x1F\x7F]/.test(item.url)) return;
											var menuLink = $('<a>', {href: item.url, 'class': 'search-suggestion'});
											var icon = typeof item.icon === 'string' && /^[a-z0-9-]{1,30}$/.test(item.icon)
												? item.icon : 'link';
											$('<i>', {'class': 'bi bi-' + icon, 'aria-hidden': 'true'}).appendTo(menuLink);
											menuLink.append(document.createTextNode(item.text));
											searchResults.append(menuLink);
											return;
										}
										if (typeof item.id !== 'string' || item.id.length > 200 ||
											/[\x00-\x1F\x7F\\]/.test(item.id)) return;
										var idParts = item.id.split('/');
										if (idParts.some(function(part) { return !part || part === '.' || part === '..'; })) return;
										var safeId = idParts.map(encodeURIComponent).join('/');
										var suggestion = $('<div>', {'class': 'search-suggestion'});
										var suggestionItem = $('<div>', {'class': 'search-suggestion-item'}).text(item.text + ' ');
										$('<span>', {'class': 'badge rounded-pill text-bg-light'})
											.text(typeof item.type === 'string' ? item.type : '').appendTo(suggestionItem);
										var options = $('<div>', {'class': 'search-suggestion-options'});
										$('<a>', {target: '_blank', rel: 'noopener', href: DOMAIN_PAGES + safeId})
											.text(searchLabels.view).appendTo(options);
										$('<a>', {'class': 'ms-2', href: DOMAIN_ADMIN + 'edit-content/' + safeId})
											.text(searchLabels.edit).appendTo(options);
										suggestion.append(suggestionItem, options).appendTo(searchResults);
                                    });
                                } else {
									searchResults.append($('<div>', {'class': 'search-no-results'}).text(searchLabels.noResults));
                                }
                            },
                            error: function(xhr, status) {
                                if (status === 'abort') return;
                                searchResults.empty().append($('<div>', {'class': 'search-no-results'}).text(searchLabels.requestFailed));
                            },
                            complete: function() { searchRequest = null; }
                        });
                    }

                    searchInput.on('input', function() {
                        clearTimeout(searchTimeout);
                        var query = $(this).val();
                        searchTimeout = setTimeout(function() {
                            performSearch(query);
                        }, 300);
                    });

                    trigger.on('click', openSearch);
                    trigger.on('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            openSearch();
                        }
                    });
                    overlay.on('click', closeSearch);
                    closeButton.on('click', closeSearch);

                    $(document).on('keydown', function(e) {
                        if (e.key === 'Escape' && modal.hasClass('active')) {
                            closeSearch();
                        }
                        if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            openSearch();
                        }
                        if (e.key === 'Tab' && modal.hasClass('active')) {
                            var focusable = modal.find('input, button, a[href]').filter(':visible').toArray();
                            if (!focusable.length) return;
                            var first = focusable[0];
                            var last = focusable[focusable.length - 1];
                            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                        }
                    });
                });
            </script>

            <?php endif; ?>

            <!-- Dashboard Metric Cards -->
            <div class="container px-0">
                <div class="row">

                    <?php if ($dashboardCanManageContent): ?>
                    <!-- Content Metrics Card -->
                    <div class="col-lg col-12 mb-4">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="brendy-plugin-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><h5 class="card-title mb-0"><?php $L->p('Content') ?></h5>
                                </div>
                                <div class="mt-3 metric-card-list">
                                    <div class="list-group list-group-flush">
                                        <a href="<?php echo HTML_PATH_ADMIN_ROOT.'content' ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Published') ?></span>
                                            <span class="badge text-bg-primary rounded-pill"><?php echo (int)$dashboardContentCounts['published']; ?></span>
                                        </a>
                                        <a href="<?php echo HTML_PATH_ADMIN_ROOT.'content#draft' ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Drafts') ?></span>
                                            <span class="badge text-bg-primary rounded-pill"><?php echo (int)$dashboardContentCounts['draft']; ?></span>
                                        </a>
                                        <a href="<?php echo HTML_PATH_ADMIN_ROOT.'content#scheduled' ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Scheduled') ?></span>
                                            <span class="badge text-bg-primary rounded-pill"><?php echo (int)$dashboardContentCounts['scheduled']; ?></span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <?php if ($dashboardIsAdmin): ?>
                    <!-- Security posture card -->
                    <div class="col-lg col-12 mb-4">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="brendy-plugin-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                                    <h5 class="card-title mb-0"><?php $L->p('Security') ?></h5>
                                </div>
                                <div class="mt-3 metric-card-list">
                                    <div class="list-group list-group-flush">
                                        <a href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'two-factor') ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Two-factor authentication') ?></span>
                                            <span class="badge <?php echo $user->twoFactorEnabled() ? 'text-bg-success' : 'text-bg-warning' ?> rounded-pill">
                                                <?php echo Theme::escape($user->twoFactorEnabled() ? $L->g('Enabled') : $L->g('Disabled')) ?>
                                            </span>
                                        </a>
                                        <a href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'edit-user/' . rawurlencode($login->username()) . '#security') ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Password reuse protection') ?></span>
                                            <span class="badge text-bg-primary rounded-pill"><?php echo (int)(PASSWORD_HISTORY_LIMIT + 1) ?></span>
                                        </a>
                                        <?php
                                        $dashboardAuditState = method_exists($syslog, 'verifyChain') ? $syslog->verifyChain() : array('ok'=>false);
                                        $dashboardAuditOk = is_array($dashboardAuditState) && !empty($dashboardAuditState['ok']);
                                        ?>
                                        <a href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'audit-log') ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Activity log integrity') ?></span>
                                            <span class="badge <?php echo $dashboardAuditOk ? 'text-bg-success' : 'text-bg-danger' ?> rounded-pill">
                                                <?php echo Theme::escape($dashboardAuditOk ? $L->g('Verified') : $L->g('Attention')) ?>
                                            </span>
                                        </a>
                                        <a href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'system-health') ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('Privileged action protection') ?></span>
                                            <span class="badge text-bg-primary rounded-pill"><?php echo (int)(STEP_UP_AUTH_TTL / 60) ?> min</span>
                                        </a>
                                        <a href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'system-health') ?>" class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php $L->p('System health') ?></span>
                                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>


                    <?php
                    // Categories Card - Only show if Categories plugin is active
                    if ($dashboardCanManageContent && pluginActivated('BrendyPluginCategories')) {
                        $categoryList = array_slice($categories->keys(), 0, 100);
                    ?>
                    <!-- Categories Card -->
                    <div class="col-lg col-12 mb-4">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="brendy-plugin-icon"><i class="bi bi-folder2-open" aria-hidden="true"></i></span><h5 class="card-title mb-0"><?php $L->p('Categories') ?></h5>
                                </div>
                                <div class="mt-3 metric-card-list">
                                    <?php if (!empty($categoryList)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($categoryList as $categoryKey): ?>
                                                <?php
                                                try {
                                                    $category = new Category($categoryKey);
                                                    $categoryPages = $category->pages();
                                                    $pageCount = is_array($categoryPages) ? count($categoryPages) : 0;
                                                } catch (Throwable $e) {
                                                    Log::set(__FILE__ . LOG_SEP . 'Skipping invalid dashboard category [' . (is_scalar($categoryKey) ? (string)$categoryKey : 'invalid') . '].', LOG_TYPE_ERROR);
                                                    continue;
                                                }
                                                $categoryDashboardUrl = $dashboardIsAdmin ? HTML_PATH_ADMIN_ROOT . 'edit-category/' . rawurlencode($categoryKey) : DOMAIN_CATEGORIES . rawurlencode($categoryKey);
                                                ?>
                                                <a href="<?php echo Theme::escape($categoryDashboardUrl) ?>" class="list-group-item d-flex justify-content-between align-items-center"<?php if (!$dashboardIsAdmin) : ?> target="_blank" rel="noopener noreferrer"<?php endif ?>>
                                                    <span><?php echo Theme::escape($category->name()) ?></span>
                                                    <span class="badge text-bg-primary rounded-pill"><?php echo (int) $pageCount ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted text-center py-3"><?php $L->p('No categories') ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>

                    <?php
                    // Tags Card - Only show if Tags plugin is active
                    if ($dashboardCanManageContent && pluginActivated('BrendyPluginTags')) {
                        $tagList = array_slice($tags->keys(), 0, 100);
                    ?>
                    <!-- Tags Card -->
                    <div class="col-lg col-12 mb-4">
                        <div class="card metric-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="brendy-plugin-icon"><i class="bi bi-tags" aria-hidden="true"></i></span><h5 class="card-title mb-0"><?php $L->p('Tags') ?></h5>
                                </div>
                                <div class="mt-3 metric-card-list">
                                    <?php if (!empty($tagList)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($tagList as $tagKey): ?>
                                                <?php
                                                try {
                                                    $tag = new Tag($tagKey);
                                                    $tagPages = $tag->pages();
                                                    $pageCount = is_array($tagPages) ? count($tagPages) : 0;
                                                } catch (Throwable $e) {
                                                    Log::set(__FILE__ . LOG_SEP . 'Skipping invalid dashboard tag [' . (is_scalar($tagKey) ? (string)$tagKey : 'invalid') . '].', LOG_TYPE_ERROR);
                                                    continue;
                                                }
                                                ?>
                                                <a href="<?php echo Theme::escape(DOMAIN_TAGS . rawurlencode($tagKey)) ?>" class="list-group-item d-flex justify-content-between align-items-center" target="_blank" rel="noopener noreferrer">
                                                    <span><?php echo Theme::escape($tag->name()) ?></span>
                                                    <span class="badge text-bg-primary rounded-pill"><?php echo (int) $pageCount ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted text-center py-3"><?php $L->p('No tags') ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>

                </div>
            </div>

            <!-- Notifications -->
            <div class="mt-4">
                <ul class="list-group list-group-striped b-0">
                    <li class="list-group-item">
                        <h4 class="m-0"><i class="bi bi-bell" aria-hidden="true"></i> <?php $L->p('Notifications') ?></h4>
                    </li>
                    <?php
                    if (empty($dashboardLogs)) {
                        echo '<li class="list-group-item text-muted">' . Theme::escape($L->g('No notifications')) . '</li>';
                    }
                    foreach ($dashboardLogs as $log) {
						if (!is_array($log)) continue;
						$dictionaryKey = isset($log['dictionaryKey']) && is_string($log['dictionaryKey']) ? $log['dictionaryKey'] : '';
						$notes = isset($log['notes']) && is_scalar($log['notes']) ? (string)$log['notes'] : '';
						$date = isset($log['date']) && is_string($log['date']) && Valid::date($log['date'], DB_DATE_FORMAT) ? $log['date'] : '';
						$username = isset($log['username']) && is_scalar($log['username']) ? (string)$log['username'] : '';
						$phrase = Sanitize::html($L->g($dictionaryKey));
                        echo '<li class="list-group-item">';
                        echo $phrase;
						if ($notes !== '') {
							echo ' « <b>' . Sanitize::html(Sanitize::htmlDecode($notes)) . '</b> »';
                        }
                        echo '<br><span class="notification-date"><small>';
						echo $date === '' ? '' : Sanitize::html(Date::format($date, DB_DATE_FORMAT, NOTIFICATIONS_DATE_FORMAT));
						echo ' [ ' . Sanitize::html(Sanitize::htmlDecode($username)) . ' ]';
                        echo '</small></span>';
                        echo '</li>';
                    }
                    ?>
                </ul>
            </div>

            <div class="brendy-dashboard-plugin-region">
                <?php Theme::plugins('dashboard') ?>
            </div>

</div>
