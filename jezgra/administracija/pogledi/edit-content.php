<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php

// FORM START
echo BrendyAdminUi::formOpen(array(
	'id' => 'jsform',
	'class' => 'd-flex flex-column h-100'
));

// Token CSRF
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'tokenCSRF',
	'value' => $security->getTokenCSRF()
));

// UUID
// The UUID is generated in the controller
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'uuid',
	'value' => $page->uuid()
));

// Type = published, draft, sticky, static
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'type',
	'value' => $page->type()
));

// Cover image
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'coverImage',
	'value' => $page->coverImage(false)
));

// Content
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'content',
	'value' => ''
));

// Current page key
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'key',
	'value' => $page->key()
));

// Optimistic concurrency marker: prevents an older open editor tab from
// silently overwriting a newer save made by another session/tab.
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'contentVersion',
	'value' => pageVersionFingerprint($page->key(), $pages->getPageDB($page->key()))
));
echo BrendyAdminUi::formInputHidden(array('name' => 'revisionId', 'value' => ''));
?>

<!-- TOOLBAR -->
<div id="jseditorToolbar" class="mb-1">
	<div id="jseditorToolbarRight" class="btn-group btn-group-sm" role="group" aria-label="<?php echo Theme::escape($L->get('Toolbar actions')); ?>">
		<button type="button" class="btn btn-light" id="jsmediaManagerOpenModal" data-bs-toggle="modal" data-bs-target="#jsmediaManagerModal"><i class="bi bi-images" aria-hidden="true"></i> <?php $L->p('Images') ?></button>
		<?php Theme::plugins('editorToolbar') ?>
		<button type="button" class="btn btn-light" id="jsoptionsSidebar" aria-controls="jseditorSidebar" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i> <?php $L->p('Options') ?></button>
	</div>

	<div id="jseditorToolbarLeft">
		<button type="button" class="btn btn-sm btn-primary" id="jsbuttonSave"><?php echo $L->g('Save') ?></button>
		<button id="jsbuttonPreview" type="button" class="btn btn-sm btn-secondary"><?php $L->p('Preview') ?></button>
		<button type="button" id="jsswitchButton" data-switch="<?php echo ($page->draft() ? 'draft' : 'publish') ?>" data-brendy-content-status-switch data-draft-label="<?php echo Theme::escape($L->g('Draft')) ?>" data-publish-label="<?php echo Theme::escape($L->g('Publish')) ?>" class="ms-2 text-secondary switch-button btn btn-link p-0 align-baseline" aria-pressed="<?php echo $page->draft() ? 'true' : 'false' ?>"><i class="bi bi-square-fill switch-icon-<?php echo ($page->draft() ? 'draft' : 'publish') ?>" aria-hidden="true"></i> <?php echo ($page->draft() ? $L->g('Draft') : $L->g('Publish')) ?></button>
	</div>

	<?php if ($page->scheduled()) : ?>
		<div class="alert alert-warning p-1 mt-1 mb-0"><?php $L->p('scheduled') ?>: <?php echo Theme::escape($page->date(SCHEDULED_DATE_FORMAT)) ?></div>
	<?php endif; ?>
</div>


<!-- SIDEBAR OPTIONS -->
<aside id="jseditorSidebar" class="brendy-editor-sidebar" role="dialog" aria-modal="true" aria-labelledby="jseditorSidebarTitle" tabindex="-1" hidden>
	<div class="brendy-editor-sidebar__header"><strong id="jseditorSidebarTitle"><?php echo Theme::escape($L->g('Options')) ?></strong><button type="button" class="btn btn-sm btn-light" id="jsoptionsSidebarClose" aria-label="<?php echo Theme::escape($L->g('Close')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
	<nav>
		<div class="nav nav-tabs" id="nav-tab" role="tablist">
			<a class="nav-link active show" id="nav-general-tab" data-bs-toggle="tab" href="#nav-general" role="tab" aria-controls="nav-general"><?php $L->p('General') ?></a>
			<a class="nav-link" id="nav-advanced-tab" data-bs-toggle="tab" href="#nav-advanced" role="tab" aria-controls="nav-advanced"><?php $L->p('Advanced') ?></a>
			<?php if (!empty($site->customFields())) : ?>
				<a class="nav-link" id="nav-custom-tab" data-bs-toggle="tab" href="#nav-custom" role="tab" aria-controls="nav-custom"><?php $L->p('Custom') ?></a>
			<?php endif ?>
			<a class="nav-link" id="nav-seo-tab" data-bs-toggle="tab" href="#nav-seo" role="tab" aria-controls="nav-seo"><?php $L->p('SEO') ?></a>
			<a class="nav-link" id="nav-revisions-tab" data-bs-toggle="tab" href="#nav-revisions" role="tab" aria-controls="nav-revisions"><?php $L->p('Revisions') ?></a>
		</div>
	</nav>

	<div class="tab-content pe-3 ps-3 pb-3">
		<div id="nav-general" class="tab-pane fade show active" role="tabpanel" aria-labelledby="nav-general-tab">
			<?php
			// Category
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'category',
				'label' => $L->g('Category'),
				'selected' => $page->categoryKey(),
				'class' => '',
				'emptyOption' => '- ' . $L->g('Uncategorized') . ' -',
				'options' => $categories->getKeyNameArray()
			));

			// Description
			echo BrendyAdminUi::formTextareaBlock(array(
				'name' => 'description',
				'label' => $L->g('Description'),
				'selected' => '',
				'class' => '',
				'value' => $page->description(),
				'rows' => 5,
				'placeholder' => $L->get('this-field-can-help-describe-the-content')
			));
			?>

			<!-- Cover Image -->
			<?php
			$coverImage = $page->coverImage(false);
			$externalCoverImage = '';
			$coverPreviewUrl = HTML_PATH_CORE_IMG . 'default.svg';
			if (is_string($coverImage) && filter_var($coverImage, FILTER_VALIDATE_URL)) {
				$externalCoverImage = $coverImage;
				$coverPreviewUrl = $coverImage;
				$coverImage = '';
			} elseif (is_string($coverImage) && $coverImage !== '') {
				$absoluteCoverImage = $page->coverImage(true);
				if (is_string($absoluteCoverImage) && $absoluteCoverImage !== '') {
					$coverPreviewUrl = $absoluteCoverImage;
				}
			}
			?>
			<div class="mt-4 mb-2 pb-2 border-bottom text-uppercase w-100 fw-bold"><?php $L->p('Cover Image') ?></div>
			<div>
				<img id="jscoverImagePreview" class="mx-auto d-block w-100" alt="<?php echo Theme::escape($L->g('Cover image preview')) ?>" src="<?php echo Theme::escape($coverPreviewUrl) ?>" />
			</div>
			<div class="mt-2 text-center">
				<button type="button" id="jsbuttonSelectCoverImage" class="btn btn-primary btn-sm"><?php echo $L->g('Select cover image') ?></button>
				<button type="button" id="jsbuttonRemoveCoverImage" class="btn btn-secondary btn-sm"><?php echo $L->g('Remove cover image') ?></button>
			</div>

		</div>
		<div id="nav-advanced" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-advanced-tab">
			<?php
			// Date
			echo BrendyAdminUi::formInputDateTime(array(
				'name' => 'date',
				'label' => $L->g('Date'),
				'placeholder' => '',
				'value' => $page->dateRaw(),
				'tip' => $L->g('date-local-time')
			));

			// Type
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'typeSelector',
				'label' => $L->g('Type'),
				'selected' => $page->type(),
				'options' => array(
					'published' => '- ' . $L->g('Default') . ' -',
					'sticky' => $L->g('Sticky'),
					'static' => $L->g('Static')
				),
				'tip' => ''
			));

			// Position
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'position',
				'label' => $L->g('Position'),
				'tip' => $L->g('Field used when ordering content by position'),
				'value' => $page->position()
			));

			// Tags
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'tags',
				'label' => $L->g('Tags'),
				'placeholder' => '',
				'tip' => $L->g('Write the tags separated by commas'),
				'value' => $page->tags()
			));

			// Parent
			try {
				$options = array();
				$parentKey = $page->parent();
				if ($parentKey !== false) {
					$parent = new Page($parentKey);
					$options = array($parentKey => $parent->title());
				}
			} catch (Throwable $e) {
				// continue
			}
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'parent',
				'label' => $L->g('Parent'),
				'options' => $options,
				'selected' => false,
				'class' => '',
				'tip' => $L->g('Start typing a page title to see a list of suggestions.'),
			));

			?>
<?php

			// Template
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'template',
				'label' => $L->g('Template'),
				'placeholder' => '',
				'value' => $page->template(),
				'tip' => $L->g('Write a template name to filter the page in the theme and change the style of the page.')
			));

			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'externalCoverImage',
				'label' => $L->g('External cover image'),
				'placeholder' => "https://",
				'value' => $externalCoverImage,
				'tip' => $L->g('Set a cover image from external URL, such as a CDN or some server dedicated for images.')
			));

			// Username
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => '',
				'label' => $L->g('Author'),
				'placeholder' => '',
				'value' => $page->username(),
				'tip' => '',
				'disabled' => true
			));
			?>

		</div>

		<?php if (!empty($site->customFields())) : ?>
			<div id="nav-custom" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-custom-tab">
				<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'tab', $page); ?>
			</div>
		<?php endif ?>
		<div id="nav-seo" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-seo-tab">
			<?php echo BrendyAdminUi::contentSeoFields(array('slug'=>$page->slug(), 'noindex'=>$page->noindex(), 'nofollow'=>$page->nofollow(), 'noarchive'=>$page->noarchive())); ?>
		</div>
		<div id="nav-revisions" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-revisions-tab">
			<?php $revisionList = listPageRevisions($page->uuid(), 20); ?>
			<p class="small text-muted mt-3 mb-3"><?php $L->p('Brendy stores the previous state before each manual save. Autosaves are managed separately.') ?></p>
			<?php if (empty($revisionList)) : ?>
				<div class="alert alert-light border small"><?php $L->p('No revisions yet') ?></div>
			<?php else : ?>
				<div class="list-group list-group-flush brendy-revision-list">
				<?php foreach ($revisionList as $revision) :
					$revisionId = isset($revision['id']) && is_string($revision['id']) ? $revision['id'] : '';
					$revisionDate = isset($revision['createdAt']) && is_string($revision['createdAt']) ? $revision['createdAt'] : '';
					$revisionActor = isset($revision['actor']) && is_string($revision['actor']) ? $revision['actor'] : '';
					$revisionBytes = isset($revision['contentBytes']) ? (int)$revision['contentBytes'] : 0;
				?>
					<div class="list-group-item px-0">
						<div class="d-flex justify-content-between align-items-start">
							<div class="pe-2">
								<strong class="d-block small"><?php echo Theme::escape($revisionDate) ?></strong>
								<span class="small text-muted"><?php echo Theme::escape($revisionActor) ?> · <?php echo Theme::escape(Filesystem::bytesToHumanFileSize($revisionBytes)) ?></span>
							</div>
							<button type="button" class="btn btn-sm btn-outline-primary js-restore-revision" data-revision-id="<?php echo Theme::escape($revisionId) ?>"><?php $L->p('Restore') ?></button>
						</div>
					</div>
				<?php endforeach ?>
				</div>
			<?php endif ?>
		</div>
	</div>
</aside>

<!-- Custom fields: TOP -->
<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'top', $page); ?>

<!-- Title -->
<div class="form-group mb-1">
	<input id="jstitle" name="title" type="text" dir="auto" class="form-control form-control-lg rounded-0" aria-label="<?php echo Theme::escape($L->g('Title')) ?>" value="<?php echo Theme::escape($page->title()) ?>" placeholder="<?php echo Theme::escape($L->g('Enter title')) ?>">
</div>

<!-- Editor -->
<textarea id="jseditor" class="editable h-100"><?php echo $page->contentRaw(true) ?></textarea>

<!-- Custom fields: BOTTOM -->
<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'bottom', $page); ?>

</form>

<!-- Modal for Delete page -->
<div id="jsdeletePageModal" class="modal" tabindex="-1" role="dialog">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-body">
				<h3><?php $L->p('Delete content') ?></h3>
				<p><?php $L->p('Are you sure you want to delete this page') ?></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-link" data-bs-dismiss="modal"><?php $L->p('Cancel') ?></button>
				<button type="button" class="btn btn-danger" data-bs-dismiss="modal" id="jsbuttonDeleteAccept"><?php $L->p('Delete') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Modal for Media Manager -->
<?php include(PATH_ADMIN_THEMES . 'brendy-admin/html/media.php'); ?>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
	$(function() {

		$('.js-restore-revision').on('click', function() {
			var revisionId = String($(this).data('revision-id') || '');
			if (!/^[0-9]{14}-[a-f0-9]{12}$/.test(revisionId)) return;
			if (!window.confirm(<?php echo json_encode($L->g('Restore this revision? Your current version will be preserved as a new revision.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)) return;
			$('#jsrevisionId').val(revisionId);
			$('#jstype').val('restore-revision');
			$('#jscontent').val('');
			document.getElementById('jsform').requestSubmit();
		});

		// Button preview
		$("#jsbuttonPreview").on("click", function() {
			var uuid = $("#jsuuid").val();
			var title = $("#jstitle").val();
			var content = editorGetContent();
			// Open synchronously from the click so browser popup blockers do not
			// discard the preview while the draft is saved asynchronously.
			var preview = window.open("about:blank", "brendy-preview");
			if (preview) {
				preview.document.title = <?php echo json_encode(BRENDY_NAME . ' · ' . $L->g('Preview'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
				preview.document.body.textContent = "Priprema pregleda…";
			}
			brendyAjax.saveAsDraft(uuid, title, content, String($('input[name="contentVersion"]').val() || "")).then(function(data) {
				if (data && data.status === 0 && typeof data.previewUrl === "string") {
					if (preview) { preview.location.replace(data.previewUrl); preview.focus(); }
					else showAlert("Pregled je spremljen, ali preglednik je blokirao novi prozor.");
				} else if (preview) {
					preview.close();
				}
			});
		});

		// Button Save
		$("#jsbuttonSave").on("click", function() {
			if (this.disabled || !document.getElementById("jsform").reportValidity()) return;
			this.disabled = true;
			// If the switch is setted to "published", get the value from the selector
			if ($("#jsswitchButton").attr("data-switch") === "publish") {
				var value = $("#jstypeSelector option:selected").val();
				$("#jstype").val(value);
			} else {
				$("#jstype").val("draft");
			}

			// Get the content
			$("#jscontent").val(editorGetContent());

			// Submit the form
			document.getElementById("jsform").requestSubmit();
		});

		// Button Save as draft
		$("#jsbuttonDraft").on("click", function() {
			// Set the type as draft
			$("#jstype").val("draft");

			// Get the content
			$("#jscontent").val(editorGetContent());

			// Submit the form
			document.getElementById("jsform").requestSubmit();
		});

		// Autosave title + content changes. Short pages are valid content too, so
		// only skip a completely empty document instead of imposing a 100-char minimum.
		var currentAutosaveSnapshot = JSON.stringify([$("#jstitle").val(), editorGetContent()]);
		setInterval(function() {
			var uuid = $("#jsuuid").val();
			var rawTitle = String($("#jstitle").val() || "");
			var content = String(editorGetContent() || "");
			if (rawTitle.trim() === "" && content.trim() === "") return;
			var nextAutosaveSnapshot = JSON.stringify([rawTitle, content]);
			if (currentAutosaveSnapshot === nextAutosaveSnapshot) return;
			brendyAjax.saveAsDraft(uuid, rawTitle + " [<?php $L->p('Autosave') ?>]", content, String($('input[name="contentVersion"]').val() || "")).then(function(data) {
				if (data && data.status === 0) {
					currentAutosaveSnapshot = nextAutosaveSnapshot;
					showAlert(<?php echo json_encode($L->g('Autosave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>);
				}
			});
		}, 1000 * 60 * AUTOSAVE_INTERVAL);

	});
</script>
