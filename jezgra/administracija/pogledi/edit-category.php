<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id'=>'jsform')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><?php $L->p('Save') ?></button>
		<button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#jsdeleteModal"><?php $L->p('Delete') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo HTML_PATH_ADMIN_ROOT.'categories' ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Edit Category'), 'icon'=>'cog')); ?>
</div>

<?php
	// Token CSRF
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'tokenCSRF',
		'value'=>$security->getTokenCSRF()
	));

	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'action',
		'value'=>'edit'
	));

	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'oldKey',
		'value'=>$categoryMap['key']
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'name',
		'label'=>$L->g('Name'),
		'value'=>$categoryMap['name'],
		'class'=>'',
		'placeholder'=>'',
		'tip'=>''
	));

	echo BrendyAdminUi::formTextarea(array(
		'name'=>'description',
		'label'=>$L->g('Description'),
		'value'=>isset($categoryMap['description'])?$categoryMap['description']:'',
		'class'=>'',
		'placeholder'=>'',
		'tip'=>'',
		'rows'=>3
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'template',
		'label'=>$L->g('Template'),
		'value'=>isset($categoryMap['template'])?$categoryMap['template']:'',
		'class'=>'',
		'placeholder'=>'',
		'tip'=>''
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'newKey',
		'label'=>$L->g('Friendly URL'),
		'value'=>$categoryMap['key'],
		'class'=>'',
		'placeholder'=>'',
		'tip'=>DOMAIN_CATEGORIES.$categoryMap['key']
	));

echo BrendyAdminUi::formClose();

?>

<!-- Modal for delete category -->
<?php
	echo BrendyAdminUi::modal(array(
		'buttonPrimary'=>$L->g('Delete'),
		'buttonPrimaryClass'=>'btn-danger jsbuttonDeleteAccept',
		'buttonSecondary'=>$L->g('Cancel'),
		'buttonSecondaryClass'=>'btn-link',
		'modalTitle'=>$L->g('Delete category'),
		'modalText'=>$L->g('Are you sure you want to delete this category?'),
		'modalId'=>'jsdeleteModal'
	));
?>
<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
$(function() {
	// Delete content
	$(".jsbuttonDeleteAccept").on("click", function() {
		$("#jsaction").val("delete");
		$("#jsform").trigger("submit");
	});
});
</script>