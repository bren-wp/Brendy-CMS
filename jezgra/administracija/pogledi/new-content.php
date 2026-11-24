<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php

// Start form
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
	'value' => $uuid
));

// Type = published, draft, sticky, static
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'type',
	'value' => $site->defaultContentStatus()
));

// Cover image
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'coverImage',
	'value' => ''
));

// Content
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'content',
	'value' => ''
));
?>

<!-- TOOLBAR -->
<div id="jseditorToolbar" class="mb-1">
	<div id="jseditorToolbarRight" class="btn-group btn-group-sm" role="group" aria-label="<?php echo Theme::escape($L->get('Toolbar actions')); ?>">
		<button type="button" class="btn btn-light" id="jsmediaManagerOpenModal" data-bs-toggle="modal" data-bs-target="#jsmediaManagerModal"><i class="bi bi-images" aria-hidden="true"></i> <?php $L->p('Images') ?></button>
		<?php Theme::plugins('editorToolbar') ?>
		<button type="button" class="btn btn-light" id="jsoptionsSidebar" aria-controls="jseditorSidebar" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i> <?php $L->p('Options') ?></button>
	</div>

	<div id="jseditorToolbarLeft">
		<button id="jsbuttonSave" type="button" class="btn btn-sm btn-primary"><?php $L->p('Save') ?></button>
		<button id="jsbuttonPreview" type="button" class="btn btn-sm btn-secondary"><?php $L->p('Preview') ?></button>
		<?php if ($site->defaultContentStatus() === 'draft'): ?>
		<button type="button" id="jsbuttonSwitch" data-switch="draft" data-brendy-content-status-switch data-draft-label="<?php echo Theme::escape($L->g('Draft')) ?>" data-publish-label="<?php echo Theme::escape($L->g('Publish')) ?>" class="ms-2 text-secondary switch-button btn btn-link p-0 align-baseline" aria-pressed="true"><i class="bi bi-square-fill switch-icon-draft" aria-hidden="true"></i> <?php $L->p('Draft') ?></button>
		<?php else: ?>
		<button type="button" id="jsbuttonSwitch" data-switch="publish" data-brendy-content-status-switch data-draft-label="<?php echo Theme::escape($L->g('Draft')) ?>" data-publish-label="<?php echo Theme::escape($L->g('Publish')) ?>" class="ms-2 text-secondary switch-button btn btn-link p-0 align-baseline" aria-pressed="false"><i class="bi bi-square-fill switch-icon-publish" aria-hidden="true"></i> <?php $L->p('Publish') ?></button>
		<?php endif; ?>
	</div>
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
		</div>
	</nav>

	<div class="tab-content pe-3 ps-3 pb-3">
		<div id="nav-general" class="tab-pane fade show active" role="tabpanel" aria-labelledby="nav-general-tab">
			<?php
			// Category
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'category',
				'label' => $L->g('Category'),
				'selected' => '',
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
				'value' => '',
				'rows' => 5,
				'placeholder' => $L->get('this-field-can-help-describe-the-content')
			));
			?>

			<!-- Cover Image -->
			<div class="mt-4 mb-2 pb-2 border-bottom text-uppercase w-100 fw-bold"><?php $L->p('Cover Image') ?></div>
			<div>
				<img id="jscoverImagePreview" class="mx-auto d-block w-100" alt="<?php echo Theme::escape($L->g('Cover image preview')) ?>" src="<?php echo HTML_PATH_CORE_IMG ?>default.svg" />
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
				'value' => Date::current(DB_DATE_FORMAT),
				'tip' => $L->g('date-local-time')
			));

			// Type
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'typeSelector',
				'label' => $L->g('Type'),
				'selected' => '',
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
				'value' => $pages->nextPositionNumber()
			));

			// Tags
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'tags',
				'label' => $L->g('Tags'),
				'placeholder' => '',
				'tip' => $L->g('Write the tags separated by commas')
			));

			// Parent
			echo BrendyAdminUi::formSelectBlock(array(
				'name' => 'parent',
				'label' => $L->g('Parent'),
				'options' => array(),
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
				'value' => '',
				'tip' => $L->g('Write a template name to filter the page in the theme and change the style of the page.')
			));

			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => 'externalCoverImage',
				'label' => $L->g('External cover image'),
				'placeholder' => "https://",
				'value' => '',
				'tip' => $L->g('Set a cover image from external URL, such as a CDN or some server dedicated for images.')
			));

			// Username
			echo BrendyAdminUi::formInputTextBlock(array(
				'name' => '',
				'label' => $L->g('Author'),
				'placeholder' => '',
				'value' => $login->username(),
				'tip' => '',
				'disabled' => true
			));
			?>


		</div>
		<?php if (!empty($site->customFields())) : ?>
			<div id="nav-custom" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-custom-tab">
				<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'tab'); ?>
			</div>
		<?php endif ?>
		<div id="nav-seo" class="tab-pane fade" role="tabpanel" aria-labelledby="nav-seo-tab">
			<?php echo BrendyAdminUi::contentSeoFields(); ?>
		</div>
	</div>
</aside>

<!-- Custom fields: TOP -->
<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'top'); ?>

<!-- Title -->
<div id="jseditorTitle" class="form-group mb-1">
	<input id="jstitle" name="title" type="text" dir="auto" class="form-control form-control-lg rounded-0" aria-label="<?php echo Theme::escape($L->g('Title')) ?>" value="" placeholder="<?php echo Theme::escape($L->g('Enter title')) ?>">
</div>

<!-- Editor -->
<textarea id="jseditor" class="editable h-100 mb-1"></textarea>

<!-- Custom fields: BOTTOM -->
<?php echo BrendyAdminUi::contentCustomFields($site->customFields(), 'bottom'); ?>

</form>

<!-- Modal for Media Manager -->
<?php include(PATH_ADMIN_THEMES . 'brendy-admin/html/media.php'); ?>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
	$(function() {

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
			brendyAjax.saveAsDraft(uuid, title, content).then(function(data) {
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
			let actionParameters = '';

			// If the switch is setted to "published", get the value from the selector
			if ($("#jsbuttonSwitch").attr("data-switch") === "publish") {
				var value = $("#jstypeSelector option:selected").val();
				$("#jstype").val(value);
				actionParameters = '#' + value;
			} else {
				$("#jstype").val("draft");
				actionParameters = '#draft';
			}

			// Get the content
			$("#jscontent").val(editorGetContent());

			// Submit the form
			$("#jsform").attr('action', actionParameters);
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
			brendyAjax.saveAsDraft(uuid, rawTitle + " [<?php $L->p('Autosave') ?>]", content).then(function(data) {
				if (data && data.status === 0) {
					currentAutosaveSnapshot = nextAutosaveSnapshot;
					showAlert(<?php echo json_encode($L->g('Autosave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>);
				}
			});
		}, 1000 * 60 * AUTOSAVE_INTERVAL);

	});
</script>
