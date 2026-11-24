<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id'=>'jsform', 'class'=>'tab-content')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo HTML_PATH_ADMIN_ROOT.'categories' ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('New category'), 'icon'=>'tag')); ?>
</div>

<?php
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'tokenCSRF',
		'value'=>$security->getTokenCSRF()
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'name',
		'label'=>$L->g('Name'),
		'value'=>$newCategoryName,
		'class'=>'',
		'placeholder'=>'',
		'tip'=>''
	));

	echo BrendyAdminUi::formTextarea(array(
		'name'=>'description',
		'label'=>$L->g('Description'),
		'value'=>$newCategoryDescription,
		'class'=>'',
		'placeholder'=>'',
		'tip'=>'',
		'rows'=>3
	));
?>

<?php echo BrendyAdminUi::formClose(); ?>
