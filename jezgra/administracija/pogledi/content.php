<?php defined('BRENDY') or die('Brendy CMS.');

echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Content'), 'icon'=>'archive'));

function moveTypeIcon($type) {
	$icons = array(
		'published' => 'bi-file-earmark-text',
		'sticky'    => 'bi-pin-angle-fill',
		'static'    => 'bi-file-earmark',
		'draft'     => 'bi-pencil',
	);
	return isset($icons[$type]) ? $icons[$type] : 'bi-file-earmark';
}

function moveTypeLabel($current, $target, $L) {
	if ($target === 'sticky') {
		return $L->g('Sticky');
	}
	if ($current === 'sticky' && $target === 'published') {
		return $L->g('Unstick');
	}
	$labels = array(
		'published' => $L->g('Move to Page'),
		'static'    => $L->g('Move to Static'),
		'draft'     => $L->g('Move to Draft'),
	);
	return isset($labels[$target]) ? $labels[$target] : $target;
}

// Render one content row. When nesting is enabled the same renderer is reused
// recursively, avoiding a separate (and historically divergent) child-row path.
function tableRow($pageKey, $type, $isSticky = false, $renderChildren = false, $depth = 0, $allowedKeys = null) {
	global $url;
	global $L;
	global $pages;

	if (!is_string($pageKey) || $pageKey === '' || $depth < 0 || $depth > 32) {
		return;
	}
	if (is_array($allowedKeys) && !isset($allowedKeys[$pageKey])) {
		return;
	}

	try {
		$page = new Page($pageKey);
	} catch (Throwable $e) {
		return;
	}

	$showURL = ($type === 'published' || $type === 'static' || $type === 'sticky');

	// Allowed "Move to" transitions per current type.
	$moves = array(
		'published' => array('sticky', 'static', 'draft'),
		'sticky'    => array('published', 'static', 'draft'),
		'draft'     => array('published', 'static'),
		'static'    => array('published', 'draft'),
	);

	$dateLabel = '';
	if ($type === 'scheduled') {
		$dateLabel = $L->g('Scheduled').': '.$page->date(SCHEDULED_DATE_FORMAT);
	} elseif ((ORDER_BY === 'position') || ($type !== 'published' && $type !== 'sticky')) {
		$dateLabel = $L->g('Position').': '.$page->position();
	} else {
		$dateLabel = $page->date(MANAGE_CONTENT_DATE_FORMAT);
	}

	echo '<tr>';
	echo '<td class="pt-3">';
	echo '<div>';
	if ($depth > 0) {
		$prefixDepth = min($depth, 8);
		echo '<span class="text-muted me-1" aria-hidden="true">'.str_repeat('↳ ', $prefixDepth).'</span>';
	}
	echo '<a class="brendy-content-title-link" href="'.Theme::escape(HTML_PATH_ADMIN_ROOT.'edit-content/'.brendyPageKeyUrlPath($page->key())).'">';
	$pageTitle = $page->title();
	echo ((is_string($pageTitle) && $pageTitle !== '') ? Theme::escape($pageTitle) : '<span class="label-empty-title">'.Theme::escape($L->g('Empty title')).'</span> ');
	echo '</a>';
	if ($isSticky) {
		echo ' <span class="badge text-bg-warning align-middle ms-1" title="'.Theme::escape($L->g('Sticky')).'"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i> '.Theme::escape($L->g('Sticky')).'</span>';
	}
	echo '</div>';
	echo '<div><p class="m-0 text-uppercase text-muted brendy-content-date">'.Theme::escape($dateLabel).'</p></div>';
	echo '</td>';

	if ($showURL) {
		$friendlyURL = Text::isEmpty($url->filters('page')) ? '/'.$page->key() : '/'.$url->filters('page').'/'.$page->key();
		echo '<td class="pt-3 d-none d-xl-table-cell contentURL"><a target="_blank" rel="noopener noreferrer" href="'.Theme::escape($page->permalink()).'" title="'.Theme::escape($friendlyURL).'">'.Theme::escape($friendlyURL).'</a></td>';
	}

	echo '<td class="contentTools pt-3 text-center align-middle">'.PHP_EOL;
	echo '<div class="dropdown actionsDropdown">';
	echo '<button class="btn btn-link text-secondary p-1 actionsDropdownToggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-haspopup="true" aria-expanded="false" title="'.Theme::escape($L->g('Actions')).'"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>';
	echo '<div class="dropdown-menu dropdown-menu-end">';

	// View / Edit
	if ($showURL) {
		echo '<a class="dropdown-item" target="_blank" rel="noopener noreferrer" href="'.Theme::escape($page->permalink()).'"><i class="bi bi-box-arrow-up-right me-2" aria-hidden="true"></i>'.Theme::escape($L->g('View')).'</a>';
	}
	echo '<a class="dropdown-item" href="'.Theme::escape(HTML_PATH_ADMIN_ROOT.'edit-content/'.brendyPageKeyUrlPath($page->key())).'"><i class="bi bi-pencil-square me-2" aria-hidden="true"></i>'.Theme::escape($L->g('Edit')).'</a>';

	// Sticky / Unstick toggle, between View/Edit and Move-to.
	$stickyToggleTarget = false;
	if ($type === 'published') {
		$stickyToggleTarget = 'sticky';
	} elseif ($type === 'sticky') {
		$stickyToggleTarget = 'published';
	}
	if ($stickyToggleTarget) {
		echo '<div class="dropdown-divider"></div>';
		echo '<button type="button" class="dropdown-item changeTypeButton" data-key="'.Theme::escape($page->key()).'" data-type="'.Theme::escape($stickyToggleTarget).'"><i class="bi '.Theme::escape(moveTypeIcon($stickyToggleTarget)).' me-2" aria-hidden="true"></i>'.Theme::escape(moveTypeLabel($type, $stickyToggleTarget, $L)).'</button>';
	}

	// Move to ... (everything except the sticky toggle target rendered above).
	if (isset($moves[$type])) {
		$remaining = array();
		foreach ($moves[$type] as $target) {
			if ($target !== $stickyToggleTarget) {
				$remaining[] = $target;
			}
		}
		if (!empty($remaining)) {
			echo '<div class="dropdown-divider"></div>';
			foreach ($remaining as $target) {
				echo '<button type="button" class="dropdown-item changeTypeButton" data-key="'.Theme::escape($page->key()).'" data-type="'.Theme::escape($target).'"><i class="bi '.Theme::escape(moveTypeIcon($target)).' me-2" aria-hidden="true"></i>'.Theme::escape(moveTypeLabel($type, $target, $L)).'</button>';
			}
		}
	}

	// A page with descendants must not offer a delete action that the server will reject.
	if (empty($pages->getDescendants($page->key()))) {
		echo '<div class="dropdown-divider"></div>';
		echo '<button type="button" class="dropdown-item text-danger deletePageButton" data-bs-toggle="modal" data-bs-target="#jsdeletePageModal" data-key="'.Theme::escape($page->key()).'"><i class="bi bi-trash me-2" aria-hidden="true"></i>'.Theme::escape($L->g('Delete')).'</button>';
	}
	echo '</div></div>';
	echo '</td>';
	echo '</tr>';

	if (!$renderChildren || $depth >= 32) {
		return;
	}

	$children = $pages->getDirectChildrenByTypes($page->key(), array($type));
	foreach ($children as $childKey) {
		if (is_array($allowedKeys) && !isset($allowedKeys[$childKey])) {
			continue;
		}
		tableRow($childKey, $type, $isSticky, true, $depth + 1, $allowedKeys);
	}
}

// Render rows for a list. When hierarchy nesting is active, a page is a root
// for this table whenever its immediate same-type parent is not in the current
// list. This preserves pagination/author filtering while still showing orphaned
// or legacy mixed-type content instead of silently hiding it.
function tableRows($list, $type, $isSticky = false) {
	if (!is_array($list) || empty($list)) {
		return;
	}

	$nestChildren = ($type === 'static') || (ORDER_BY === 'position');
	if (!$nestChildren) {
		foreach ($list as $pageKey) {
			tableRow($pageKey, $type, $isSticky, false);
		}
		return;
	}

	$allowedKeys = array();
	foreach ($list as $pageKey) {
		if (is_string($pageKey) && $pageKey !== '') {
			$allowedKeys[$pageKey] = true;
		}
	}

	foreach (array_keys($allowedKeys) as $pageKey) {
		try {
			$page = new Page($pageKey);
		} catch (Throwable $e) {
			continue;
		}
		$parentKey = $page->parentKey();
		if ($parentKey !== false && isset($allowedKeys[$parentKey])) {
			continue;
		}
		tableRow($pageKey, $type, $isSticky, true, 0, $allowedKeys);
	}
}

// Render a full table for a single tab (Static, Scheduled, Draft, Autosave).
function table($type) {
	global $L;
	global $drafts;
	global $scheduled;
	global $static;
	global $autosave;

	if ($type === 'draft') {
		$list = $drafts;
		$emptyMessage = $L->g('There are no draft pages at this moment.');
	} elseif ($type === 'scheduled') {
		$list = $scheduled;
		$emptyMessage = $L->g('There are no scheduled pages at this moment.');
	} elseif ($type === 'static') {
		$list = $static;
		$emptyMessage = $L->g('There are no static pages at this moment.');
	} elseif ($type === 'autosave') {
		$list = $autosave;
		$emptyMessage = '';
	} else {
		return;
	}

	if (empty($list) && $type !== 'autosave') {
		echo '<p class="mt-4 text-muted">'.$emptyMessage.'</p>';
		return;
	}

	echo '<div class="table-responsive brendy-table-shell mt-3"><table class="table mb-0"><thead><tr>';
	echo '<th class="border-0" scope="col">'.$L->g('Title').'</th>';
	if ($type === 'static') {
		echo '<th class="border-0 d-none d-xl-table-cell" scope="col">'.$L->g('URL').'</th>';
	}
	echo '<th class="border-0 text-center d-sm-table-cell" scope="col">'.$L->g('Actions').'</th>';
	echo '</tr></thead><tbody>';
	tableRows($list, $type);
	echo '</tbody></table></div>';
}

// Render the Pages tab: sticky rows first, then the paginated published list,
// in a single table.
function tablePages() {
	global $L;
	global $published;
	global $sticky;
	global $url;

	$isFirstPage = ($url->pageNumber() <= 1);

	if (empty($published) && (empty($sticky) || !$isFirstPage)) {
		echo '<p class="mt-4 text-muted">'.$L->g('There are no pages at this moment.').'</p>';
		return;
	}

	echo '<div class="table-responsive brendy-table-shell mt-3"><table class="table mb-0"><thead><tr>';
	echo '<th class="border-0" scope="col">'.$L->g('Title').'</th>';
	echo '<th class="border-0 d-none d-xl-table-cell" scope="col">'.$L->g('URL').'</th>';
	echo '<th class="border-0 text-center d-sm-table-cell" scope="col">'.$L->g('Actions').'</th>';
	echo '</tr></thead><tbody>';
	if (!empty($sticky) && $isFirstPage) {
		tableRows($sticky, 'sticky', true);
	}
	if (!empty($published)) {
		tableRows($published, 'published', false);
	}
	echo '</tbody></table></div>';
}

?>

<!-- TABS -->
<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item">
		<a class="nav-link active" id="pages-tab" data-bs-toggle="tab" href="#pages" role="tab" aria-controls="pages" aria-selected="true"><?php $L->p('Pages') ?></a>
	</li>
	<li class="nav-item">
		<a class="nav-link" id="static-tab" data-bs-toggle="tab" href="#static" role="tab" aria-controls="static" aria-selected="false"><?php $L->p('Static') ?></a>
	</li>
	<li class="nav-item">
		<a class="nav-link" id="scheduled-tab" data-bs-toggle="tab" href="#scheduled" role="tab" aria-controls="scheduled" aria-selected="false"><?php $L->p('Scheduled') ?> <?php if (count($scheduled)>0) { echo '<span class="badge text-bg-danger">'.count($scheduled).'</span>'; } ?></a>
	</li>
	<li class="nav-item">
		<a class="nav-link" id="draft-tab" data-bs-toggle="tab" href="#draft" role="tab" aria-controls="draft" aria-selected="false"><?php $L->p('Draft') ?></a>
	</li>
	<?php if (!empty($autosave)): ?>
	<li class="nav-item">
		<a class="nav-link" id="autosave-tab" data-bs-toggle="tab" href="#autosave" role="tab" aria-controls="autosave" aria-selected="false"><?php $L->p('Autosave') ?></a>
	</li>
	<?php endif; ?>
</ul>
<div class="tab-content">
	<!-- TABS PAGES (includes sticky on top) -->
	<div class="tab-pane show active" id="pages" role="tabpanel" aria-labelledby="pages-tab">

		<?php tablePages(); ?>

		<?php if (Paginator::numberOfPages() > 1): ?>
		<!-- Paginator -->
		<nav class="paginator">
			<ul class="pagination flex-wrap justify-content-center">

			<!-- First button -->
			<li class="page-item <?php if (!Paginator::showPrev()) echo 'disabled' ?>">
				<a class="page-link" href="<?php echo Theme::escape(Paginator::firstPageUrl()) ?>"><i class="bi bi-skip-backward-fill" aria-hidden="true"></i> <?php echo Theme::escape($L->get('First')) ?></a>
			</li>

			<!-- Previous button -->
			<li class="page-item <?php if (!Paginator::showPrev()) echo 'disabled' ?>">
				<a class="page-link" href="<?php echo Theme::escape(Paginator::previousPageUrl()) ?>"><?php echo Theme::escape($L->get('Previous')) ?></a>
			</li>

			<!-- Next button -->
			<li class="page-item <?php if (!Paginator::showNext()) echo 'disabled' ?>">
				<a class="page-link" href="<?php echo Theme::escape(Paginator::nextPageUrl()) ?>"><?php echo Theme::escape($L->get('Next')) ?></a>
			</li>

			<!-- Last button -->
			<li class="page-item <?php if (!Paginator::showNext()) echo 'disabled' ?>">
				<a class="page-link" href="<?php echo Theme::escape(Paginator::lastPageUrl()) ?>"><?php echo Theme::escape($L->get('Last')) ?> <i class="bi bi-skip-forward-fill" aria-hidden="true"></i></a>
			</li>

			</ul>
		</nav>
		<?php endif; ?>
	</div>

	<!-- TABS STATIC -->
	<div class="tab-pane" id="static" role="tabpanel" aria-labelledby="static-tab">
	<?php table('static'); ?>
	</div>

	<!-- TABS SCHEDULED -->
	<div class="tab-pane" id="scheduled" role="tabpanel" aria-labelledby="scheduled-tab">
	<?php table('scheduled'); ?>
	</div>

	<!-- TABS DRAFT -->
	<div class="tab-pane" id="draft" role="tabpanel" aria-labelledby="draft-tab">
	<?php table('draft'); ?>
	</div>

	<!-- TABS AUTOSAVE -->
	<?php if (!empty($autosave)): ?>
	<div class="tab-pane" id="autosave" role="tabpanel" aria-labelledby="autosave-tab">
	<?php table('autosave'); ?>
	</div>
	<?php endif; ?>
</div>

<!-- Modal for delete page -->
<?php
	echo BrendyAdminUi::modal(array(
		'buttonPrimary'=>$L->g('Delete'),
		'buttonPrimaryClass'=>'btn-danger deletePageModalAcceptButton',
		'buttonSecondary'=>$L->g('Cancel'),
		'buttonSecondaryClass'=>'btn-link',
		'modalTitle'=>$L->g('Delete content'),
		'modalText'=>$L->g('Are you sure you want to delete this page'),
		'modalId'=>'jsdeletePageModal'
	));
?>
<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
$(function() {
	var key = false;

	// Button for delete a page in the table
	$(document).on("click", ".deletePageButton", function() {
		key = $(this).data('key');
	});

	// Event from button accept from the modal
	$(".deletePageModalAcceptButton").on("click", function() {
		var currentTab = window.location.hash ? window.location.hash.substring(1) : 'pages';
		var keyPath = String(key || '').split('/').map(function(segment) { return encodeURIComponent(segment); }).join('/');

		var form = jQuery('<form>', {
			'action': HTML_PATH_ADMIN_ROOT+'edit-content/'+keyPath,
			'method': 'post',
			'target': '_top'
		}).append(jQuery('<input>', {
			'type': 'hidden',
			'name': 'tokenCSRF',
			'value': tokenCSRF
		})).append(jQuery('<input>', {
			'type': 'hidden',
			'name': 'key',
			'value': key
		})).append(jQuery('<input>', {
			'type': 'hidden',
			'name': 'type',
			'value': 'delete'
		})).append(jQuery('<input>', {
			'type': 'hidden',
			'name': 'tab',
			'value': currentTab
		}));

		form.hide().appendTo("body").trigger("submit");
	});

	// Move-to: change page type via AJAX
	$(document).on("click", ".changeTypeButton", function(e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.data('busy')) { return; }
		$btn.data('busy', true).css('opacity', 0.5);

		$.ajax({
			type: "POST",
			url: HTML_PATH_ADMIN_ROOT + "ajax/change-type",
			data: {
				tokenCSRF: tokenCSRF,
				key: $btn.data('key'),
				type: $btn.data('type')
			},
			dataType: "json"
		}).done(function(response) {
			if (response && response.status === 0) {
				window.location.reload();
			} else {
				$btn.data('busy', false).css('opacity', 1);
				alert((response && response.message) ? response.message : <?php echo json_encode($L->g('Failed to change type.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
			}
		}).fail(function() {
			$btn.data('busy', false).css('opacity', 1);
			alert(<?php echo json_encode($L->g('Failed to change type.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
		});
	});
});
</script>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
	// Open the tab defined in the URL
	const anchor = window.location.hash;
	const allowedTabs = ['#pages', '#static', '#scheduled', '#draft', '#autosave'];
	if (allowedTabs.indexOf(anchor) !== -1) {
		const tab = Array.from(document.querySelectorAll('a[data-bs-toggle="tab"]')).find(function(item) { return item.getAttribute('href') === anchor; });
		if (tab && window.bootstrap) bootstrap.Tab.getOrCreateInstance(tab).show();
	}
</script>
