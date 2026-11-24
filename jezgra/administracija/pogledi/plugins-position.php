<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id'=>'jsform', 'class'=>'tab-content')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="button" class="btn btn-primary btn-sm jsbuttonSave" name="save"><?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo HTML_PATH_ADMIN_ROOT.'plugins' ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Plugins position'), 'icon'=>'tags')); ?>
</div>

<div class="alert alert-primary"><?php $L->p('Drag and Drop to sort the plugins') ?></div>

<?php
	// Token CSRF
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'tokenCSRF',
		'value'=>$security->getTokenCSRF()
	));

	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'plugin-list',
		'value'=>''
	));

	echo '<ul class="list-group list-group-sortable">';
	foreach ($plugins['siteSidebar'] as $brendyPlugin) {
		echo '<li class="list-group-item" data-plugin="'.Theme::escape($brendyPlugin->className()).'"><i class="bi bi-grip-vertical me-2" aria-hidden="true"></i> '.Theme::escape($brendyPlugin->name()).'<span class="float-end btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary jsplugin-up" aria-label="'.Theme::escape($L->g('Move up')).'"><i class="bi bi-arrow-up" aria-hidden="true"></i></button><button type="button" class="btn btn-outline-secondary jsplugin-down" aria-label="'.Theme::escape($L->g('Move down')).'"><i class="bi bi-arrow-down" aria-hidden="true"></i></button></span></li>';
	}
	echo '</ul>';
?>

<?php echo BrendyAdminUi::formClose(); ?>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
$(function() {
	if (typeof $.fn.sortable === 'function') $('.list-group-sortable').sortable({
		items: '> li[data-plugin]',
		placeholder: 'list-group-item brendy-sort-placeholder',
		forcePlaceholderSize: true,
		axis: 'y',
		handle: '.bi-grip-vertical'
	});

	$('.list-group-sortable').on('click', '.jsplugin-up,.jsplugin-down', function () {
		var $item = $(this).closest('li[data-plugin]');
		var $other = $(this).hasClass('jsplugin-up') ? $item.prev('li[data-plugin]') : $item.next('li[data-plugin]');
		if (!$other.length) return;
		if ($(this).hasClass('jsplugin-up')) $item.insertBefore($other); else $item.insertAfter($other);
		this.focus();
	});
	$(".jsbuttonSave").on("click", function() {
		var tmp = [];
		$(".list-group-sortable > li[data-plugin]").each(function() {
			tmp.push( $(this).attr("data-plugin") );
		});
		$("#jsplugin-list").attr("value", tmp.join(",") );
		var form = document.getElementById("jsform");
		if (form && typeof form.requestSubmit === "function") form.requestSubmit();
		else if (form) form.submit();
	});
});
</script>
